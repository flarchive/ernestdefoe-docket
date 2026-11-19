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
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
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
    /** The most entries, and the most timeline events, one report shows. */
    public const SHOWN = 200;

    /** @var WeakMap<Report, array<string, mixed>>|null */
    protected ?WeakMap $changes = null;

    public function __construct(
        protected TranslatorInterface $translator,
        protected Docket $docket,
        protected Crowd $crowd,
        protected ReporterStats $stats,
        protected PostPlace $places,
        protected Cache $cache
    ) {
        $this->posts = new Deferred();
    }

    /** @var Deferred<Report> */
    protected Deferred $posts;

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
                // The newest of each, however busy the report: entryCount
                // still counts them all.
                ->eagerLoadWhere('entries', fn ($query) => $query->orderByDesc('id')->limit(self::SHOWN))
                ->eagerLoadWhere('events', fn ($query) => $query->orderByDesc('id')->limit(self::SHOWN))
                ->meta([
                    // Each reporter's record, by time frame, keyed by user id.
                    Schema\Arr::make('reporterStats')
                        ->get(fn (Report $report, Context $context) => (object) $this->stats->forUsers(
                            $report->entries->where('type', 'user')->pluck('user_id')->filter()->all(),
                            $context->getActor()
                        )),
                ]),
            Endpoint\Update::make()
                ->authenticated()
                // The page loads the report again for its timeline, capped as Show caps it.
                ->defaultInclude(['assignee', 'queue', 'closedBy']),
        ];
    }

    public function fields(): array
    {
        return [...$this->attributes(), ...$this->relationships()];
    }

    /** @return array<Schema\Attribute> */
    protected function attributes(): array
    {
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
        ];
    }

    /**
     * No `discussion` relationship: notifications include
     * `subject.discussion` for every subject type that has one, and for a
     * report that would be a query per notification. It is post.discussion.
     *
     * @return array<Schema\Relationship\ToOne|Schema\Relationship\ToMany>
     */
    protected function relationships(): array
    {
        return [
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

    /**
     * The place of a report's post. Reports serialized without their post
     * (the subjects of notifications) load the posts and discussions of all
     * of them at once.
     */
    protected function withPost(Report $report): mixed
    {
        if ($report->relationLoaded('post') && (! $report->post || $report->post->relationLoaded('discussion'))) {
            return $this->places->of($report->post);
        }

        return $this->posts->later(
            $report,
            fn (array $reports) => (new Collection($reports))->load('post.discussion'),
            fn () => $this->places->of($report->post)
        );
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
        $lock = $this->lockFor($model);

        try {
            // Checked against the report as it is now, not as this request
            // first read it: another moderator may have just claimed or
            // closed it.
            $model->refresh();
            $this->check($model, $changes, $actor);
            $model->getConnection()->transaction(fn () => $this->apply($model, $changes, $actor));
        } finally {
            $lock?->release();
        }

        return $model->refresh();
    }

    /**
     * One change to a report at a time. Taken without waiting: when someone
     * else is changing it this moment, they are told to try again.
     */
    protected function lockFor(Report $report): ?Lock
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return null;
        }

        $lock = $store->lock("ernestdefoe-docket.report.$report->id", 30);

        if (! $lock->get()) {
            $this->fail('status', 'busy');
        }

        return $lock;
    }

    /**
     * @param array<string, mixed> $changes
     */
    protected function apply(Report $model, array $changes, User $actor): void
    {
        if (array_key_exists('queue', $changes)) {
            $this->docket->move($model, $changes['queue'], $actor);
        }

        if (array_key_exists('assignee', $changes)) {
            $this->docket->assign($model, $changes['assignee'], $actor);
        }

        if (isset($changes['status']) && $changes['status'] !== $model->status) {
            $status = $changes['status'];

            if (! in_array($status, Report::ACTIVE, true)) {
                // Rejecting what the crowd hid puts it back.
                if ($status === Report::REJECTED && in_array($model->crowd_state, Crowd::ACTED, true)) {
                    $this->crowd->restore($model, $actor);
                }

                $this->docket->close($model, $status, $actor, (bool) ($changes['notify'] ?? false), $changes['outcome'] ?? null);
            } elseif (! $model->isActive()) {
                $this->docket->reopen($model, $status, $actor);
            } elseif ($status === Report::OPEN) {
                $this->docket->assign($model, null, $actor);
            } else {
                $this->docket->assign($model, $actor, $actor);
            }
        }
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
            $this->checkAssignee($report, $changes['assignee'], $actor, $active, $othersMay);
        }

        if (isset($changes['status']) && $changes['status'] !== $report->status && ! $report->isActive() && ! $active) {
            // A closed report is reopened before its verdict changes: closing
            // it again would skip what closing checks (who may restore the
            // post) and tell its reporters twice.
            $this->fail('status', 'closed');
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
            && ! $this->crowd->mayRestore($report, $actor)
        ) {
            $this->fail('status', 'cannot_restore');
        }

        if ($active && ! $report->isActive() && $report->post_id && $this->docket->activeReport($report->post_id)) {
            // The post has a newer open report: that one is where to work.
            $this->fail('status', 'reopen_active');
        }

        if (array_key_exists('queue', $changes) && ! $report->isActive()) {
            $this->fail('queue', 'closed');
        }
    }

    /** Who the report may go to, and whether this actor may hand it over. */
    protected function checkAssignee(Report $report, ?User $to, User $actor, bool $active, bool $othersMay): void
    {
        $holder = $report->assignee_id;
        $takesFromOther = $holder && $holder !== $actor->id;

        if (! $report->isActive() || ! $active) {
            $this->fail('assignee', 'closed');
        }

        if ($to?->id !== $holder && ((($to && $to->id !== $actor->id) || $takesFromOther) && ! $othersMay)) {
            $this->fail('assignee', 'assign_others');
        }

        if ($to && $to->id !== $actor->id && ! $this->docket->canSee($to, $report)) {
            $this->fail('assignee', 'cannot_see');
        }
    }

    protected function fail(string $field, string $key): never
    {
        throw new ValidationException([$field => $this->translator->trans("ernestdefoe-docket.api.$key")]);
    }
}
