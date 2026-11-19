<?php

namespace ErnestDefoe\Docket;

use Carbon\Carbon;
use ErnestDefoe\Docket\Crowd\Address;
use ErnestDefoe\Docket\Crowd\Crowd;
use ErnestDefoe\Docket\Integration\Tags;
use ErnestDefoe\Docket\Job\FileFlag;
use ErnestDefoe\Docket\Job\TellReporters;
use ErnestDefoe\Docket\Notification\ReportAssignedBlueprint;
use Flarum\Flags\Command\DeleteFlags;
use Flarum\Flags\Flag;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\NotificationSyncer;
use Flarum\Post\Post;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Queue as JobQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use WeakMap;

/**
 * Everything that changes a report: filing flags into it, routing it by the
 * rules, assigning, closing and reopening it, and the timeline lines for what
 * happens to its post.
 */
class Docket
{
    /**
     * Timeline lines that mean a moderator acted on the post. A report
     * closed by dismissing its flags counts as resolved when one of these
     * was logged, and rejected when none was.
     */
    public const ACTIONS = ['post_hidden', 'post_deleted', 'post_approved', 'warning', 'suspended'];

    /**
     * Posts whose flags this request is clearing as part of closing their
     * report, so the Cleared event that follows leaves the report alone.
     *
     * @var array<int, true>
     */
    protected array $closing = [];

    public function __construct(
        protected NotificationSyncer $notifications,
        protected Tags $tags,
        protected Bus $bus,
        protected Crowd $crowd,
        protected Address $address,
        protected Cache $cache,
        protected QueueAccess $access,
        protected JobQueue $jobs
    ) {
        $this->mayDismiss = new WeakMap();
    }

    /**
     * File a new flag: into the post's open report, or a new one, which the
     * rules then route.
     */
    public function file(Flag $flag, bool $notify = true, bool $wait = false, ?string $ipHash = null): void
    {
        if (! $flag->post_id) {
            return;
        }

        // Taken now, while this is still the request that raised the flag.
        if (! $wait && $flag->type === 'user') {
            $ipHash = $this->address->hash();
        }

        // Two flags on one post at the same moment must land in one report.
        // A cache lock per post holds on every database (SQLite has no row
        // locks), and every Laravel cache store can lock. A flag that finds
        // the lock taken is filed by a queued job, which
        // waits for it. On Flarum's default sync queue that job runs within
        // the request, which then waits as long as the other flag takes to
        // file (milliseconds), and at most 5 seconds: after that the flag is
        // filed without it. Either way the database keeps the post to one
        // open report (openReport).
        $store = $this->cache->getStore();
        $file = fn () => Post::query()->getConnection()->transaction(fn () => $this->fileLocked($flag, $notify, $ipHash));

        if (! $store instanceof LockProvider) {
            $file();

            return;
        }

        $lock = $store->lock("ernestdefoe-docket.file.$flag->post_id", 30);

        if ($wait) {
            try {
                $lock->block(5, $file);
            } catch (LockTimeoutException) {
                $file();
            }
        } elseif ($lock->get($file) === false) {
            $this->jobs->push(new FileFlag($flag, $notify, $ipHash));
        }
    }

    protected function fileLocked(Flag $flag, bool $notify, ?string $ipHash): void
    {
        // file() holds the post's cache lock while this runs.
        $post = Post::query()->whereKey($flag->post_id)->first();

        if (! $post) {
            return;
        }

        $report = $this->activeReport($post->id) ?? $this->openReport($post);
        $now = Carbon::now();

        $report->entry_count++;
        $report->save();

        $entry = new Entry();
        $entry->report_id = $report->id;
        $entry->flag_id = $flag->id;
        $entry->post_id = $post->id;
        $entry->user_id = $flag->user_id;
        $entry->type = (string) $flag->type;
        $entry->reason = $flag->reason;
        $entry->reason_detail = $flag->reason_detail;
        $entry->created_at = $flag->created_at ?? $now;

        if ($entry->type === 'user') {
            $entry->ip_hash = $ipHash;
        }

        $entry->save();

        if ($report->entry_count === 1) {
            $this->route($report, $flag, $post, $notify);
        }

        // Still holding the post's lock: of several reports at once, one acts.
        if ($entry->type === 'user') {
            $this->crowd->consider($report, $post);
        }
    }

    /**
     * A new report on the post, or the one another flag opened a moment ago:
     * the unique active_post_id lets only one be open. In a savepoint, so
     * the clash leaves the transaction usable on PostgreSQL.
     */
    protected function openReport(Post $post): Report
    {
        $report = new Report();
        $report->post_id = $post->id;
        $report->discussion_id = $post->discussion_id;
        $report->user_id = $post->user_id;
        $report->status = Report::OPEN;
        $report->entry_count = 0;

        try {
            $post->getConnection()->transaction(fn () => $report->save());

            return $report;
        } catch (UniqueConstraintViolationException $e) {
            return $this->activeReport($post->id) ?? throw $e;
        }
    }

    public function activeReport(int $postId): ?Report
    {
        return Report::query()
            ->where('post_id', $postId)
            ->whereIn('status', Report::ACTIVE)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Run a new report through the rules: the first enabled rule it matches
     * files it in a queue and/or assigns it.
     */
    protected function route(Report $report, Flag $flag, Post $post, bool $notify): void
    {
        $rules = Rule::query()->where('is_enabled', true)->orderBy('position')->orderBy('id')->get();

        if ($rules->isEmpty()) {
            return;
        }

        $facts = new RuleFacts($flag, $post, $this->tags);

        foreach ($rules as $rule) {
            if (! $facts->matches($rule)) {
                continue;
            }

            if ($rule->queue_id) {
                $report->queue_id = $rule->queue_id;
                $report->save();
            }

            $report->log('routed', null, ['rule' => $rule->name, 'queue' => $rule->queue?->name]);

            $assignee = $rule->assignee_id
                ? $this->eligible(User::query()->find($rule->assignee_id), $report)
                : ($rule->assignee_group_id ? $this->leastBusy($rule->assignee_group_id, $report) : null);

            if ($assignee) {
                $this->assign($report, $assignee, null, $notify);
            }

            return;
        }
    }

    /**
     * Posts already checked, for the flags of one dismissal, which arrive one
     * by one. Keyed by the actor's object, which is the request's own: Docket
     * is a singleton, so in a long-lived process no answer outlives it.
     *
     * @var WeakMap<User, array<int, true>>
     */
    protected WeakMap $mayDismiss;

    public function assertMayDismiss(int $postId, User $actor): void
    {
        if (isset($this->closing[$postId]) || isset($this->mayDismiss[$actor][$postId]) || ! $closed = $this->access->closedTo($actor)) {
            return;
        }

        $kept = Report::query()
            ->where('post_id', $postId)
            ->whereIn('status', Report::ACTIVE)
            ->whereIn('queue_id', $closed)
            ->exists();

        if ($kept) {
            throw new PermissionDeniedException();
        }

        $this->mayDismiss[$actor] = [$postId => true] + ($this->mayDismiss[$actor] ?? []);
    }

    /**
     * A deleted user's reports go back to open; rules that assigned to them
     * stop assigning. The foreign keys do the latter on most databases.
     */
    public function userDeleted(int $userId): void
    {
        Report::query()
            ->where('assignee_id', $userId)
            ->whereIn('status', Report::ACTIVE)
            ->update(['assignee_id' => null, 'status' => Report::OPEN]);

        Rule::query()->where('assignee_id', $userId)->update(['assignee_id' => null]);
    }

    public function canSee(User $user, Report $report): bool
    {
        return Report::query()->whereVisibleTo($user)->whereKey($report->id)->exists();
    }

    protected function eligible(?User $user, Report $report): ?User
    {
        return $user && $this->canSee($user, $report) ? $user : null;
    }

    /**
     * The member of a group with the fewest reports in hand who can see this
     * one. Looks at the 25 least busy, so a large group stays cheap.
     */
    protected function leastBusy(int $groupId, Report $report): ?User
    {
        $reports = (new Report())->getTable();

        $candidates = User::query()
            ->whereHas('groups', fn ($q) => $q->where('groups.id', $groupId))
            ->select('users.*')
            ->selectSub(
                Report::query()->selectRaw('count(*)')->whereColumn("$reports.assignee_id", 'users.id')->whereIn("$reports.status", Report::ACTIVE),
                'docket_load'
            )
            ->orderBy('docket_load')
            ->orderBy('users.id')
            ->limit(25)
            ->with('groups')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        // Who of them can see it, in one query: each member's own visibility
        // scope, unioned, rather than one query per member.
        $visible = $candidates
            ->map(fn (User $user) => Report::query()
                ->whereVisibleTo($user)
                ->whereKey($report->id)
                ->selectRaw((int) $user->id.' as docket_pick')
                ->toBase())
            ->reduce(fn ($union, $query) => $union ? $union->unionAll($query) : $query)
            ->pluck('docket_pick')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $candidates->first(fn (User $user) => in_array((int) $user->id, $visible, true));
    }

    /**
     * Give a report to someone, or to no one. A report someone holds is in
     * progress; one no one holds is open.
     */
    public function assign(Report $report, ?User $to, ?User $by, bool $notify = true): void
    {
        if ($report->assignee_id === $to?->id) {
            return;
        }

        $report->assignee_id = $to?->id;

        if ($report->isActive()) {
            $report->status = $to ? Report::IN_PROGRESS : Report::OPEN;
        }

        $report->save();
        $report->setRelation('assignee', $to);

        if ($to) {
            $report->log($by && $by->id === $to->id ? 'claimed' : 'assigned', $by, ['to' => $to->id, 'name' => $to->display_name]);
        } else {
            $report->log('unassigned', $by);
        }

        if ($notify && $to && $to->id !== $by?->id) {
            $this->send(new ReportAssignedBlueprint($report, $by), $to);
        }
    }

    public function move(Report $report, ?Queue $queue, User $by): void
    {
        if ($report->queue_id === $queue?->id) {
            return;
        }

        $from = $report->queue;
        $report->queue_id = $queue?->id;
        $report->save();
        $report->setRelation('queue', $queue);

        // Names as they were: a queue can be renamed or deleted later.
        $report->log('moved', $by, ['from' => $from?->name, 'to' => $queue?->name]);
    }

    /**
     * Resolve or reject a report: its flags are dismissed with it, and the
     * people who reported it are told when the moderator chose to tell them.
     */
    public function close(Report $report, string $status, User $by, bool $notify, ?string $message): void
    {
        $from = $report->status;
        $message = $notify && $message !== null && trim($message) !== '' ? trim($message) : null;
        $now = Carbon::now();

        $report->status = $status;
        $report->closed_at = $now;
        $report->closed_by_id = $by->id;
        $report->outcome = $message;
        $report->reporters_notified = $notify;
        $report->save();

        $report->log('status', $by, ['from' => $from, 'to' => $status, 'notified' => $notify], $message);

        if ($report->post_id && Flag::query()->where('post_id', $report->post_id)->exists()) {
            $this->closing[$report->post_id] = true;

            try {
                $this->bus->dispatch(new DeleteFlags($report->post_id, $by));
            } finally {
                unset($this->closing[$report->post_id]);
            }
        }

        if ($notify) {
            $this->tellReporters($report, $status, $message, $by);
        }
    }

    public function reopen(Report $report, string $status, User $by): void
    {
        $from = $report->status;

        $report->status = $status;
        $report->closed_at = null;
        $report->closed_by_id = null;
        $report->outcome = null;
        $report->reporters_notified = false;

        if ($status === Report::OPEN) {
            $report->assignee_id = null;
        } elseif (! $report->assignee_id) {
            $report->assignee_id = $by->id;
        }

        $report->save();

        $report->log('status', $by, ['from' => $from, 'to' => $status]);
    }

    /** Queued once the close is committed: nobody is told about a change that was rolled back. */
    protected function tellReporters(Report $report, string $status, ?string $message, User $by): void
    {
        Report::query()->getConnection()->afterCommit(fn () => $this->jobs->push(new TellReporters($report, $status, $message, $by->id)));
    }

    /**
     * Notify once the change is committed: a mail error must not undo it,
     * and nobody is told about a change that was rolled back.
     */
    protected function send(BlueprintInterface $blueprint, User $user): void
    {
        Report::query()->getConnection()->afterCommit(fn () => $this->notifications->sync($blueprint, [$user]));
    }

    /**
     * The flags on a post were dismissed, or the post was deleted, outside
     * the report centre: close its report with them.
     */
    public function cleared(Post $post, ?User $actor): void
    {
        if (isset($this->closing[$post->id])) {
            return;
        }

        $now = Carbon::now();

        foreach (Report::query()->where('post_id', $post->id)->whereIn('status', Report::ACTIVE)->get() as $report) {
            // Flags dismissed while the crowd's hide stands confirm it; once
            // staff restored the post they were wrong.
            $acted = match (true) {
                ! $post->exists, in_array($report->crowd_state, Crowd::ACTED, true) => true,
                $report->crowd_state === Crowd::RESTORED => false,
                default => $report->events()->whereIn('type', self::ACTIONS)->exists(),
            };

            $report->status = $acted ? Report::RESOLVED : Report::REJECTED;
            $report->closed_at = $now;
            $report->closed_by_id = $actor?->id;
            $report->save();

            $report->log('cleared', $actor, ['to' => $report->status]);
        }
    }

    /**
     * Something happened to a reported post: log it on its report, if it has
     * one open or closed in the last few minutes.
     *
     * Hiding a post from its flag bar sends the hide and the dismissal at the
     * same moment, and the dismissal can land first, closing the report as
     * rejected; the hide that follows turns that into resolved.
     *
     * @param array<string, mixed>|null $data
     */
    public function postAction(int $postId, string $type, ?User $actor, ?array $data = null): void
    {
        if ($this->crowd->isQuiet($postId)) {
            return;
        }

        $report = Report::query()->where('post_id', $postId)->orderByDesc('id')->first();

        if (! $report || (! $report->isActive() && (! $report->closed_at || $report->closed_at->lt(Carbon::now()->subMinutes(5))))) {
            return;
        }

        $report->log($type, $actor, $data);

        if (
            $report->status === Report::REJECTED
            && in_array($type, self::ACTIONS, true)
            && $report->events()->whereIn('type', ['status', 'cleared'])->orderByDesc('id')->value('type') === 'cleared'
        ) {
            $report->status = Report::RESOLVED;
        }

        // Moved up the list even when only a line was logged.
        $report->touch();

        // Staff put back by hand what the crowd hid.
        $undoes = ['post_restored' => Crowd::HIDDEN, 'post_approved' => Crowd::APPROVAL];

        if ($actor && isset($undoes[$type]) && $report->crowd_state === $undoes[$type]) {
            $this->crowd->restored($report, $actor);
        }
    }

    /**
     * Something happened to the author of reported posts (suspended, warned
     * without a post): log it on their open reports.
     *
     * @param array<string, mixed>|null $data
     */
    public function userAction(int $userId, string $type, ?User $actor, ?array $data = null): void
    {
        $ids = Report::query()
            ->where('user_id', $userId)
            ->whereIn('status', Report::ACTIVE)
            ->orderByDesc('id')
            ->limit(50)
            ->pluck('id');

        // One insert for all of them; data as the model's array cast stores it.
        $now = Carbon::now();
        ReportEvent::query()->insert($ids->map(fn ($id) => [
            'report_id' => (int) $id,
            'user_id' => $actor?->id,
            'type' => $type,
            'data' => $data === null ? null : json_encode($data),
            'created_at' => $now,
        ])->all());
    }
}
