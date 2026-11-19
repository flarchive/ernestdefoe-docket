<?php

namespace ErnestDefoe\Docket\Crowd;

use Carbon\Carbon;
use ErnestDefoe\Docket\Integration\Approval;
use ErnestDefoe\Docket\Integration\Tags;
use ErnestDefoe\Docket\Notification\CrowdActionBlueprint;
use ErnestDefoe\Docket\Report;
use Flarum\Discussion\Discussion;
use Flarum\Notification\NotificationSyncer;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Crowd moderation: when enough trusted members report a post within a
 * window, it is hidden (or sent back to approval) until staff review it.
 *
 * Abuse resistance is the point, so a report only counts when its reporter
 * has been a member long enough, has posted enough and is in a group allowed
 * to count; reports from one address, or from accounts an alt-account
 * extension links, count once; and with trust on, each person counts by how
 * often their past reports were upheld. Everything is decided here, on the
 * server, under the post's lock, so a burst of reports acts once.
 */
class Crowd
{
    public const HIDDEN = 'hidden';
    public const APPROVAL = 'approval';
    public const RESTORED = 'restored';
    /** The threshold was reached but the author is exempt, or in the cool-down: left to staff. */
    public const HELD = 'held';

    /** Crowd states with the post out of view. */
    public const ACTED = [self::HIDDEN, self::APPROVAL];

    protected const SETTING = 'ernestdefoe-docket.crowd_';

    /**
     * Posts crowd moderation is hiding or restoring itself: it logs that in
     * its own words, so Docket leaves the post events it raises unlogged.
     *
     * @var array<int, true>
     */
    protected array $quiet = [];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Approval $approval,
        protected Tags $tags,
        protected Dispatcher $events,
        protected NotificationSyncer $notifications,
        protected Reporters $reporters
    ) {
    }

    public function enabled(): bool
    {
        return (bool) $this->setting('enabled');
    }

    /**
     * After a member's flag is filed, under the post's lock: hide the
     * post when its reports now weigh enough. Acts once per report.
     */
    public function consider(Report $report, Post $post): void
    {
        if ($report->crowd_state !== null || ! $report->isActive() || ! $this->enabled() || $this->outOfView($post)) {
            return;
        }

        $threshold = $this->threshold($report, $post);

        if (! $threshold) {
            return;
        }

        $data = $this->reporters->weigh($report, $post, $threshold, max(1, (int) $this->setting('window')));

        if (! $data) {
            return;
        }

        if ($why = $this->held($post)) {
            $this->mark($report, self::HELD, 'crowd_held', null, $data + ['why' => $why]);

            return;
        }

        $this->act($report, $post, $data);
    }

    /**
     * Whether this actor may undo what the crowd did to the report's post:
     * the permission they would need to do it by hand.
     */
    public function mayRestore(Report $report, User $actor): bool
    {
        $post = $report->post;

        if (! $post) {
            return true;
        }

        $target = $this->target($post);

        // With flarum/approval disabled since, nobody holds its permission:
        // putting the post back is then for whoever may hide it.
        return $report->crowd_state === self::APPROVAL && $this->approval->enabled()
            ? $actor->can('approve', $post)
            : $actor->can('hide', $target);
    }

    /**
     * Staff put the post back: it counts against the people who reported it.
     */
    public function restore(Report $report, User $actor): void
    {
        $post = $report->post;

        if ($post) {
            $this->quietly($post, fn () => $report->crowd_state === self::APPROVAL ? $this->approve($post, $actor) : $this->unhide($post));
        }

        $this->restored($report, $actor);
    }

    /**
     * flarum/approval was just disabled: what the crowd sent back to approval
     * would stay private, out of everyone's sight and its report's too. Each
     * is hidden instead, which staff can see and restore without it.
     */
    public function approvalDisabled(): void
    {
        Report::query()
            ->where('crowd_state', self::APPROVAL)
            ->with('post.discussion')
            ->chunkById(100, function ($reports) {
                foreach ($reports as $report) {
                    $this->hideInstead($report);
                }
            });
    }

    protected function hideInstead(Report $report): void
    {
        $post = $report->post;

        if ($post) {
            $target = $this->target($post);

            $this->quietly($post, function () use ($post, $target) {
                // Approved and not private by its own columns, so it stays
                // that way if flarum/approval is enabled again; hidden instead.
                $post->setAttribute('is_approved', true);
                $post->is_private = false;

                if ($target instanceof Discussion) {
                    $post->save();
                    $target->setAttribute('is_approved', true);
                    $target->is_private = false;
                }

                $target->hide();
                $target->save();
                $this->dispatch($target);
                $this->recount($post, true);
            });
        }

        $this->mark($report, self::HIDDEN, 'crowd_moved', null);
    }

    /** Whether crowd moderation is hiding or restoring this post itself. */
    public function isQuiet(int $postId): bool
    {
        return isset($this->quiet[$postId]);
    }

    protected function quietly(Post $post, callable $action): void
    {
        $this->quiet[$post->id] = true;

        try {
            $action();
        } finally {
            unset($this->quiet[$post->id]);
        }
    }

    /**
     * Mark a report's crowd action undone: by the restore above, or by staff
     * restoring or approving the post by hand.
     */
    public function restored(Report $report, ?User $actor): void
    {
        $this->mark($report, self::RESTORED, 'crowd_restored', $actor);

        if ($report->post?->user) {
            $this->notify($report->post, self::RESTORED, null);
        }
    }

    protected function act(Report $report, Post $post, array $data): void
    {
        $mode = $this->setting('action') === self::APPROVAL && $this->approval->enabled() ? self::APPROVAL : self::HIDDEN;
        $target = $this->target($post);
        $this->quietly($post, function () use ($mode, $post, $target) {
            if ($mode === self::APPROVAL) {
                $this->unapprove($post);
            } else {
                $target->hide();
                $target->save();
                $this->dispatch($target);
            }
        });

        $this->mark($report, $mode, $mode === self::APPROVAL ? 'crowd_approval' : 'crowd_hidden', null, $data + ['discussion' => $target instanceof Discussion]);

        if ($post->user) {
            $this->notify($post, $mode, $data['reason']);
        }
    }

    /**
     * What the crowd acts on: the discussion when the post is its first.
     */
    protected function target(Post $post): Post|Discussion
    {
        $discussion = $post->discussion;

        return $discussion && $post->number === 1 && (int) $discussion->first_post_id === $post->id ? $discussion : $post;
    }

    protected function unhide(Post $post): void
    {
        $target = $this->target($post);

        if ($target instanceof CommentPost || $target instanceof Discussion) {
            $target->restore();
            $target->save();
            $this->dispatch($target);
        }
    }

    protected function unapprove(Post $post): void
    {
        // flarum/approval's column: set by name, as it isn't always there.
        $post->setAttribute('is_approved', false);
        $post->save();

        $this->recount($post, false);
    }

    /**
     * As flarum/approval approves: its listeners put the counts back. When
     * it has been disabled since the crowd acted, its event class may be
     * gone, so the post is approved here and the counts redone.
     */
    protected function approve(Post $post, User $actor): void
    {
        if ($post->getAttribute('is_approved')) {
            return;
        }

        $post->setAttribute('is_approved', true);
        $event = $this->approval->approvedEvent($post, $actor);

        if (! $event) {
            // Its privacy check went with it, so nothing else clears this;
            // another extension's check still runs as the models are saved.
            $post->is_private = false;
            $post->save();

            if ($this->target($post) instanceof Discussion) {
                $post->discussion->is_private = false;
            }

            $this->recount($post, true);

            return;
        }

        $post->raise($event);
        $post->save();
        $this->dispatch($post);
    }

    /** The post's discussion and author counts, after it left or came back. */
    protected function recount(Post $post, bool $approved): void
    {
        $discussion = $post->discussion;

        if ($discussion) {
            if ($this->target($post) instanceof Discussion) {
                $discussion->setAttribute('is_approved', $approved);
            }

            $discussion->refreshCommentCount();
            $discussion->refreshLastPost();
            $discussion->save();
        }

        if ($post->user) {
            $post->user->refreshCommentCount();
            $post->user->save();
        }
    }

    protected function dispatch(Post|Discussion $model): void
    {
        foreach ($model->releaseEvents() as $event) {
            $this->events->dispatch($event);
        }
    }

    /**
     * @param array<string, mixed>|null $data
     */
    protected function mark(Report $report, string $state, string $event, ?User $actor, ?array $data = null): void
    {
        $report->crowd_state = $state;
        $report->crowd_at = Carbon::now();
        $report->save();

        $report->log($event, $actor, $data);
    }

    protected function notify(Post $post, string $state, ?string $reason): void
    {
        $blueprint = new CrowdActionBlueprint($post, $state, $reason);
        $author = $post->user;

        Report::query()->getConnection()->afterCommit(fn () => $this->notifications->sync($blueprint, [$author]));
    }

    /** Already hidden, unapproved or in a hidden discussion: nothing to do. */
    protected function outOfView(Post $post): bool
    {
        // is_approved is flarum/approval's, null without it; false, 0 or "0" by driver.
        $unapproved = fn ($model) => in_array($model->getAttribute('is_approved'), [false, 0, '0'], true);

        if ($post->hidden_at || $unapproved($post)) {
            return true;
        }

        $discussion = $post->discussion;

        return ! $discussion || $discussion->hidden_at || $unapproved($discussion);
    }

    /**
     * Why the post is left to staff although the threshold was reached:
     * its author is staff or in an exempt group, or staff restored this
     * post within the cool-down.
     */
    protected function held(Post $post): ?string
    {
        $author = $post->user;

        if ($author) {
            if ($author->isAdmin() || $author->hasPermissionLike('discussion.viewFlags') || $author->hasPermissionLike('discussion.hidePosts')) {
                return 'staff';
            }

            if (array_intersect($this->reporters->ids('exempt_groups'), array_map('intval', $author->groups->pluck('id')->all()))) {
                return 'exempt';
            }
        }

        $cooldown = (int) $this->setting('cooldown');

        if ($cooldown > 0 && Report::query()
            ->where('post_id', $post->id)
            ->where('crowd_state', self::RESTORED)
            ->where('crowd_at', '>=', Carbon::now()->subHours($cooldown))
            ->exists()) {
            return 'cooldown';
        }

        return null;
    }

    /**
     * The weight of reports that acts: the report's queue's own, else the
     * strictest of its discussion's tags (where one says never, never),
     * else the forum's. Never below two people.
     */
    public function threshold(Report $report, Post $post): int
    {
        $queue = $report->queue_id ? $report->queue : null;

        if ($queue && $queue->crowd_threshold !== null) {
            return $this->atLeastTwo((int) $queue->crowd_threshold);
        }

        $byTag = $this->tagThresholds();

        if ($byTag) {
            $set = array_intersect_key($byTag, array_flip($this->tags->tagIds($post->discussion_id)));

            if ($set) {
                return in_array(0, $set, true) ? 0 : $this->atLeastTwo(max($set));
            }
        }

        return $this->atLeastTwo((int) $this->setting('threshold'));
    }

    protected function atLeastTwo(int $threshold): int
    {
        return $threshold <= 0 ? 0 : max(2, min($threshold, 1000));
    }

    /** @return array<int, int> threshold by tag id */
    public function tagThresholds(): array
    {
        $map = json_decode((string) $this->setting('tags'), true);
        $out = [];

        foreach (is_array($map) ? $map : [] as $tagId => $threshold) {
            if (is_numeric($tagId) && is_numeric($threshold)) {
                $out[(int) $tagId] = max(0, (int) $threshold);
            }
        }

        return $out;
    }

    protected function setting(string $key): mixed
    {
        return $this->settings->get(self::SETTING.$key);
    }
}
