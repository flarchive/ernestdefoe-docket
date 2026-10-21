<?php

namespace ErnestDefoe\Docket\Crowd;

use Carbon\Carbon;
use ErnestDefoe\Docket\Docket;
use ErnestDefoe\Docket\Entry;
use ErnestDefoe\Docket\Notification\CrowdActionBlueprint;
use ErnestDefoe\Docket\Report;
use Flarum\Approval\Event\PostWasApproved;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\Notification\NotificationSyncer;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Crowd moderation: when enough trusted members report a post within a
 * window, it is hidden (or sent back to approval) until staff review it.
 *
 * Abuse resistance is the point, so a report only counts when its reporter
 * has been a member long enough, has posted enough and is in a group allowed
 * to count; reports from one address, or from accounts an alt-account
 * extension links, count once; and with trust on, each person counts by how
 * often their past reports were upheld. Everything is decided here, on the
 * server, with the post's row locked, so a burst of reports acts once.
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

    /** The most one person can count for, with trust on. */
    public const MAX_WEIGHT = 1.5;

    protected const SETTING = 'ernestdefoe-docket.crowd_';

    /** The address of the request that is filing a flag, set by the flag endpoint. */
    public static ?string $address = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ExtensionManager $extensions,
        protected Dispatcher $events,
        protected NotificationSyncer $notifications,
        protected LinkedAccounts $links
    ) {
    }

    /**
     * Where a request came from. Flarum sees only the connection's address;
     * behind a reverse proxy on the same machine or network (Docker, a local
     * nginx) that is the proxy's for everyone. So when the connection is
     * from a private or loopback address, the proxy's X-Forwarded-For is
     * read from the right: the first public address in it is the one the
     * proxy saw. Entries to its left are whatever the client sent, and are
     * never trusted. A public connection's header is ignored.
     */
    public static function clientAddress(ServerRequestInterface $request): ?string
    {
        $remote = $request->getAttribute('ipAddress');

        if (! is_string($remote) || self::isPublic($remote)) {
            return is_string($remote) ? $remote : null;
        }

        $hops = array_reverse(array_map('trim', explode(',', $request->getHeaderLine('X-Forwarded-For'))));

        foreach ($hops as $hop) {
            if (! filter_var($hop, FILTER_VALIDATE_IP)) {
                break;
            }

            if (self::isPublic($hop)) {
                return $hop;
            }
        }

        return $remote;
    }

    protected static function isPublic(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    public function enabled(): bool
    {
        return (bool) $this->setting('enabled');
    }

    /**
     * A keyed hash of the current request's address, for a new entry. IPv6
     * by its /64, which one connection is usually given whole.
     */
    public function addressHash(): ?string
    {
        $ip = self::$address;

        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        $packed = (string) inet_pton($ip);

        if (strlen($packed) === 16) {
            $packed = str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")
                ? substr($packed, 12)
                : substr($packed, 0, 8);
        }

        return hash_hmac('sha256', $packed, (string) $this->settings->get('ernestdefoe-docket.crowd_salt'));
    }

    /**
     * After a member's flag is filed, with the post's row locked: hide the
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

        $since = Carbon::now()->subHours(max(1, (int) $this->setting('window')));

        $entries = $report->entries()
            ->where('type', 'user')
            ->whereNotNull('user_id')
            ->whereNotNull('ip_hash')
            ->where('created_at', '>=', $since)
            ->when($post->user_id, fn ($q) => $q->where('user_id', '!=', $post->user_id))
            ->get(['user_id', 'reason', 'ip_hash']);

        // Not enough people even if every one of them counted in full.
        $userIds = $entries->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        if (count($userIds) < 2 || count($userIds) * self::MAX_WEIGHT < $threshold) {
            return;
        }

        $reporters = $this->eligible($userIds);

        if (count($reporters) < 2) {
            return;
        }

        $weights = $this->weights(array_keys($reporters), $report->id);
        [$people, $weight] = $this->tally($entries->whereIn('user_id', array_keys($reporters)), $weights);

        if ($people < 2 || $weight < $threshold) {
            return;
        }

        $data = [
            'people' => $people,
            'weight' => round($weight, 2),
            'threshold' => $threshold,
            'reason' => $this->topReason($entries->whereIn('user_id', array_keys($reporters))),
        ];

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

        return $report->crowd_state === self::APPROVAL
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
            $docket = resolve(Docket::class);
            $docket->quiet[$post->id] = true;

            try {
                $report->crowd_state === self::APPROVAL ? $this->approve($post, $actor) : $this->unhide($post);
            } finally {
                unset($docket->quiet[$post->id]);
            }
        }

        $this->restored($report, $actor);
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
        $mode = $this->setting('action') === self::APPROVAL && $this->extensions->isEnabled('flarum-approval') ? self::APPROVAL : self::HIDDEN;
        $target = $this->target($post);
        $docket = resolve(Docket::class);
        $docket->quiet[$post->id] = true;

        try {
            if ($mode === self::APPROVAL) {
                $this->unapprove($post);
            } else {
                $target->hide();
                $target->save();
                $this->dispatch($target);
            }
        } finally {
            unset($docket->quiet[$post->id]);
        }

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

        $discussion = $post->discussion;

        if ($discussion) {
            if ($this->target($post) instanceof Discussion) {
                $discussion->setAttribute('is_approved', false);
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

    /** As flarum/approval approves: its listeners put the counts back. */
    protected function approve(Post $post, User $actor): void
    {
        if ($post->getAttribute('is_approved')) {
            return;
        }

        $post->setAttribute('is_approved', true);
        $post->raise(new PostWasApproved($post, $actor));
        $post->save();
        $this->dispatch($post);
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
        $report->updated_at = $report->crowd_at;
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

            if (array_intersect($this->ids('exempt_groups'), array_map('intval', $author->groups->pluck('id')->all()))) {
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

        if ($byTag && $post->discussion_id && $this->extensions->isEnabled('flarum-tags')) {
            $tagIds = $post->getConnection()->table('discussion_tag')->where('discussion_id', $post->discussion_id)->pluck('tag_id')->map(fn ($id) => (int) $id)->all();
            $set = array_intersect_key($byTag, array_flip($tagIds));

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

    /**
     * The reporters who count, by id: members long enough, with enough
     * posts, in a counting group, not suspended.
     *
     * @param int[] $userIds
     * @return array<int, User>
     */
    protected function eligible(array $userIds): array
    {
        $joinedBefore = Carbon::now()->subDays(max(0, (int) $this->setting('min_age')));
        $minPosts = max(0, (int) $this->setting('min_posts'));
        $groups = $this->ids('groups');
        $now = Carbon::now();

        return User::query()
            ->whereIn('id', $userIds)
            ->where('joined_at', '<=', $joinedBefore)
            ->where('comment_count', '>=', $minPosts)
            ->with('groups')
            ->get()
            ->filter(function (User $user) use ($groups, $now) {
                $until = $user->getAttribute('suspended_until');

                if ($until && Carbon::parse($until)->gt($now)) {
                    return false;
                }

                return ! $groups || array_intersect($groups, array_map('intval', $user->groups->pluck('id')->all()));
            })
            ->keyBy('id')
            ->all();
    }

    /**
     * Each reporter's weight. With trust on, from their closed reports:
     * 0.5 for someone always wrong, 1.5 for someone always right, 1 for
     * someone new. A report closed by restoring the post counts as wrong
     * even if staff later resolved it.
     *
     * @param int[] $userIds
     * @return array<int, float>
     */
    public function weights(array $userIds, ?int $exceptReport = null): array
    {
        $weights = array_fill_keys($userIds, 1.0);

        if (! $userIds || ! $this->setting('trust')) {
            return $weights;
        }

        $entries = (new Entry())->getTable();
        $reports = (new Report())->getTable();
        $grammar = Entry::query()->getQuery()->getGrammar();
        $status = $grammar->wrap("$reports.status");
        $state = $grammar->wrap("$reports.crowd_state");

        $rows = Entry::query()
            ->join($reports, "$reports.id", '=', "$entries.report_id")
            ->whereIn("$entries.user_id", $userIds)
            ->where("$entries.type", 'user')
            ->when($exceptReport, fn ($q) => $q->where("$entries.report_id", '!=', $exceptReport))
            ->groupBy("$entries.user_id")
            ->select("$entries.user_id")
            ->selectRaw("sum(case when $status = ? and ($state is null or $state <> ?) then 1 else 0 end) as upheld", [Report::RESOLVED, self::RESTORED])
            ->selectRaw("sum(case when $status = ? or $state = ? then 1 else 0 end) as wrong", [Report::REJECTED, self::RESTORED])
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $upheld = (int) $row->upheld;
            $wrong = (int) $row->wrong;
            $weights[(int) $row->user_id] = 0.5 + ($upheld + 1) / ($upheld + $wrong + 2);
        }

        return $weights;
    }

    /**
     * People and their weight. Reporters sharing an address, or linked by
     * an alt-account extension, are one person, counted at the weight of
     * the most trusted of them.
     *
     * @param iterable<Entry> $entries
     * @param array<int, float> $weights
     * @return array{0: int, 1: float}
     */
    protected function tally(iterable $entries, array $weights): array
    {
        $parent = [];
        $find = function (string $x) use (&$parent, &$find): string {
            if (! isset($parent[$x])) {
                $parent[$x] = $x;
            }

            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };
        $join = function (string $a, string $b) use (&$parent, $find) {
            $parent[$find($a)] = $find($b);
        };

        $userIds = [];

        foreach ($entries as $entry) {
            $userIds[] = (int) $entry->user_id;
            $join('u'.$entry->user_id, 'ip'.$entry->ip_hash);
        }

        $userIds = array_values(array_unique($userIds));

        foreach ($this->links->people($userIds) as $userId => $key) {
            if (in_array((int) $userId, $userIds, true)) {
                $join('u'.$userId, 'p'.$key);
            }
        }

        $people = [];

        foreach ($userIds as $userId) {
            $root = $find('u'.$userId);
            $people[$root] = max($people[$root] ?? 0, $weights[$userId] ?? 1.0);
        }

        return [count($people), array_sum($people)];
    }

    /** @param iterable<Entry> $entries */
    protected function topReason(iterable $entries): string
    {
        $counts = [];

        foreach ($entries as $entry) {
            $reason = $entry->reason ?: 'other';
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }

        arsort($counts);

        return (string) (array_key_first($counts) ?? 'other');
    }

    /** @return int[] */
    protected function ids(string $key): array
    {
        $ids = json_decode((string) $this->setting($key), true);

        return is_array($ids) ? array_values(array_diff(array_map('intval', array_filter($ids, 'is_numeric')), [Group::GUEST_ID])) : [];
    }

    protected function setting(string $key): mixed
    {
        return $this->settings->get(self::SETTING.$key);
    }
}
