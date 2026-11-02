<?php

namespace ErnestDefoe\Docket\Api;

use ErnestDefoe\Docket\Crowd\Crowd;
use ErnestDefoe\Docket\Docket;
use ErnestDefoe\Docket\Queue;
use ErnestDefoe\Docket\Report;
use ErnestDefoe\Docket\ReporterStats;
use Flarum\Api\Context as FlarumContext;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\ValidationException;
use Flarum\Http\SlugManager;
use Flarum\Locale\TranslatorInterface;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Tobyz\JsonApiServer\Context;
use WeakMap;

/**
 * Reports, for the staff who may see them. Every change goes through an
 * update: claim or assign (assignee), file in a queue (queue), and move
 * through open / in progress / resolved / rejected (status), telling the
 * reporters (notifyReporters, outcome) when it closes.
 *
 * @extends AbstractDatabaseResource<Report>
 */
class ReportResource extends AbstractDatabaseResource
{
    /** @var WeakMap<Report, array<string, mixed>>|null */
    protected ?WeakMap $changes = null;

    public function __construct(
        protected TranslatorInterface $translator
    ) {
    }

    /**
     * Resolved on use: every API resource is built on every API request.
     */
    protected function docket(): Docket
    {
        return resolve(Docket::class);
    }

    public function type(): string
    {
        return 'docket-reports';
    }

    public function model(): string
    {
        return Report::class;
    }

    public function scope(Builder $query, Context $context): void
    {
        $query->whereVisibleTo($context->getActor());
    }

    public function endpoints(): array
    {
        return [
            // The list shows each post's discussion by title only: a plain
            // attribute, not the discussion resource, which other extensions
            // add fields to that cost queries per discussion.
            Endpoint\Index::make()
                ->authenticated()
                ->defaultInclude(['post', 'post.user', 'user', 'assignee', 'queue'])
                ->eagerLoad(['post.discussion', 'post.user.groups', 'user.groups', 'assignee.groups'])
                ->defaultSort('-updatedAt')
                ->paginate(20, 50),
            Endpoint\Show::make()
                ->authenticated()
                ->defaultInclude(['post', 'post.user', 'post.discussion', 'user', 'assignee', 'queue', 'closedBy', 'entries', 'entries.user', 'events', 'events.user'])
                ->meta([
                    // Each reporter's record, by time frame, keyed by user id.
                    Schema\Arr::make('reporterStats')
                        ->get(fn (Report $report, Context $context) => (object) resolve(ReporterStats::class)->forUsers(
                            $report->entries->where('type', 'user')->pluck('user_id')->filter()->all(),
                            $context->getActor()
                        )),
                ]),
            Endpoint\Update::make()
                ->authenticated()
                ->defaultInclude(['assignee', 'queue', 'closedBy', 'events', 'events.user']),
        ];
    }

    public function fields(): array
    {
        // No `discussion` relationship: notifications include
        // `subject.discussion` for every subject type that has one, and for a
        // report that would be a query per notification. It is post.discussion.
        return [
            Schema\Str::make('status')
                ->writable()
                ->in(Report::STATUSES)
                ->set(fn (Report $report, string $value) => $this->want($report, 'status', $value)),
            Schema\Boolean::make('notifyReporters')
                ->writable()
                ->visible(false)
                ->set(fn (Report $report, bool $value) => $this->want($report, 'notify', $value)),
            Schema\Str::make('outcome')
                ->writable()
                ->nullable()
                ->maxLength(2000)
                ->set(fn (Report $report, ?string $value) => $this->want($report, 'outcome', $value)),
            Schema\Boolean::make('reportersNotified')
                ->property('reporters_notified'),
            Schema\Integer::make('entryCount'),
            // What crowd moderation did to the post: hidden, approval,
            // restored or held.
            Schema\Str::make('crowdState')
                ->property('crowd_state')
                ->nullable(),
            Schema\DateTime::make('crowdAt')
                ->property('crowd_at')
                ->nullable(),
            // Where the post is: its discussion's id, slug and title. The
            // report is only seen by someone who can see the post, and so
            // its discussion.
            Schema\Arr::make('discussion')
                ->nullable()
                ->get(fn (Report $report) => $this->withPost($report)),
            Schema\DateTime::make('createdAt'),
            Schema\DateTime::make('updatedAt'),
            Schema\DateTime::make('closedAt'),
            Schema\Boolean::make('canAssignOthers')
                ->get(fn (Report $report, FlarumContext $context) => $context->getActor()->hasPermission('docket.assignOthers')),

            Schema\Relationship\ToOne::make('post')
                ->includable()
                ->nullable(),
            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable()
                ->nullable(),
            Schema\Relationship\ToOne::make('assignee')
                ->type('users')
                ->includable()
                ->nullable()
                ->writable()
                ->set(fn (Report $report, ?User $user) => $this->want($report, 'assignee', $user)),
            Schema\Relationship\ToOne::make('queue')
                ->type('docket-queues')
                ->includable()
                ->nullable()
                ->writable()
                ->set(fn (Report $report, ?Queue $queue) => $this->want($report, 'queue', $queue)),
            Schema\Relationship\ToOne::make('closedBy')
                ->type('users')
                ->includable()
                ->nullable(),
            Schema\Relationship\ToMany::make('entries')
                ->type('docket-entries')
                ->includable(),
            Schema\Relationship\ToMany::make('events')
                ->type('docket-events')
                ->includable(),
        ];
    }

    /** @var Report[] reports serialized without their post loaded */
    protected array $waiting = [];

    /**
     * The place of a report's post. Reports serialized without their post
     * (the subjects of notifications) wait, and the first of them to be read
     * loads the posts and discussions of all of them: a getter that returns
     * a closure is resolved after every other getter.
     */
    protected function withPost(Report $report): mixed
    {
        if ($report->relationLoaded('post') && (! $report->post || $report->post->relationLoaded('discussion'))) {
            return self::place($report->post);
        }

        $this->waiting[] = $report;

        return function () use ($report) {
            if ($this->waiting) {
                (new Collection($this->waiting))->load('post.discussion');
                $this->waiting = [];
            }

            return self::place($report->post);
        };
    }

    /**
     * Where a post is, for a link to it: its discussion's slug (as the
     * forum's slug driver writes it) and title, and the post's number.
     *
     * @return array{slug: string, title: string, number: int}|null
     */
    public static function place(?Post $post): ?array
    {
        $discussion = $post?->discussion;

        if (! $discussion) {
            return null;
        }

        return [
            'slug' => resolve(SlugManager::class)->forResource(Discussion::class)->toSlug($discussion),
            'title' => (string) $discussion->title,
            'number' => (int) $post->number,
        ];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('createdAt'),
            SortColumn::make('updatedAt'),
        ];
    }

    protected function want(Report $report, string $key, mixed $value): void
    {
        $this->changes ??= new WeakMap();
        $this->changes[$report] = [$key => $value] + ($this->changes[$report] ?? []);
    }

    /**
     * Apply what was asked, in order: the queue, then who holds the report,
     * then its status. Each is checked first, so nothing is half done.
     */
    public function update(object $model, Context $context): object
    {
        $changes = $this->changes[$model] ?? [];
        unset($this->changes[$model]);

        /** @var User $actor */
        $actor = $context->getActor();
        $this->check($model, $changes, $actor);

        $model->getConnection()->transaction(function () use ($model, $changes, $actor) {
            if (array_key_exists('queue', $changes)) {
                $this->docket()->move($model, $changes['queue'], $actor);
            }

            if (array_key_exists('assignee', $changes)) {
                $this->docket()->assign($model, $changes['assignee'], $actor);
            }

            if (isset($changes['status']) && $changes['status'] !== $model->status) {
                $status = $changes['status'];

                if (! in_array($status, Report::ACTIVE, true)) {
                    // Rejecting what the crowd hid puts it back.
                    if ($status === Report::REJECTED && in_array($model->crowd_state, Crowd::ACTED, true)) {
                        resolve(Crowd::class)->restore($model, $actor);
                    }

                    $this->docket()->close($model, $status, $actor, (bool) ($changes['notify'] ?? false), $changes['outcome'] ?? null);
                } elseif (! $model->isActive()) {
                    $this->docket()->reopen($model, $status, $actor);
                } elseif ($status === Report::OPEN) {
                    $this->docket()->assign($model, null, $actor);
                } else {
                    $this->docket()->assign($model, $actor, $actor);
                }
            }
        });

        return $model->refresh();
    }

    /**
     * @param array<string, mixed> $changes
     */
    protected function check(Report $report, array $changes, User $actor): void
    {
        $status = $changes['status'] ?? $report->status;
        $active = in_array($status, Report::ACTIVE, true);
        $othersMay = $actor->hasPermission('docket.assignOthers');

        // Taking a report from whoever holds it, or giving it to someone else.
        $holder = $report->assignee_id;
        $takesFromOther = $holder && $holder !== $actor->id;

        if (array_key_exists('assignee', $changes)) {
            /** @var User|null $to */
            $to = $changes['assignee'];

            if (! $report->isActive() || ! $active) {
                $this->fail('assignee', 'closed');
            }

            if ($to?->id !== $holder && ((($to && $to->id !== $actor->id) || $takesFromOther) && ! $othersMay)) {
                $this->fail('assignee', 'assign_others');
            }

            if ($to && $to->id !== $actor->id && ! $this->docket()->canSee($to, $report)) {
                $this->fail('assignee', 'cannot_see');
            }
        }

        if (isset($changes['status']) && $changes['status'] !== $report->status && $report->isActive() && $active && $takesFromOther && ! $othersMay && ! array_key_exists('assignee', $changes)) {
            // Moving between open and in progress hands the report to you or
            // to no one, which is taking it from its holder.
            $this->fail('status', 'assign_others');
        }

        if (
            ($changes['status'] ?? null) === Report::REJECTED
            && $report->isActive()
            && in_array($report->crowd_state, Crowd::ACTED, true)
            && ! resolve(Crowd::class)->mayRestore($report, $actor)
        ) {
            $this->fail('status', 'cannot_restore');
        }

        if (array_key_exists('queue', $changes) && ! $report->isActive()) {
            $this->fail('queue', 'closed');
        }
    }

    protected function fail(string $field, string $key): never
    {
        throw new ValidationException([$field => $this->translator->trans("ernestdefoe-docket.api.$key")]);
    }
}
