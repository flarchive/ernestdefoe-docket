<?php

namespace ErnestDefoe\Docket;

use Carbon\Carbon;
use ErnestDefoe\Docket\Notification\ReportAssignedBlueprint;
use ErnestDefoe\Docket\Notification\ReportOutcomeBlueprint;
use Flarum\Extension\ExtensionManager;
use Flarum\Flags\Command\DeleteFlags;
use Flarum\Flags\Flag;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\NotificationSyncer;
use Flarum\Post\Post;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Cache\LockProvider;

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
        protected ExtensionManager $extensions,
        protected Bus $bus
    ) {
    }

    /**
     * File a new flag: into the post's open report, or a new one, which the
     * rules then route.
     */
    public function file(Flag $flag, bool $notify = true): void
    {
        if (! $flag->post_id) {
            return;
        }

        // Two flags on one post at the same moment must land in one report:
        // the post row is locked while its open report is looked up.
        Post::query()->getConnection()->transaction(fn () => $this->fileLocked($flag, $notify));
    }

    protected function fileLocked(Flag $flag, bool $notify): void
    {
        $post = Post::query()->whereKey($flag->post_id)->lockForUpdate()->first();

        if (! $post) {
            return;
        }

        $report = $this->activeReport($post->id);
        $now = Carbon::now();

        if (! $report) {
            $report = new Report();
            $report->post_id = $post->id;
            $report->discussion_id = $post->discussion_id;
            $report->user_id = $post->user_id;
            $report->status = Report::OPEN;
        }

        $report->entry_count++;
        $report->updated_at = $now;
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
        $entry->save();

        if ($report->entry_count === 1) {
            $this->route($report, $flag, $post, $notify);
        }
    }

    /**
     * Copy in any flag that has no entry yet: the flags already there when
     * the extension was first enabled, and any raised while it was disabled.
     * Run when the report centre is opened; one query when there are none.
     */
    public function backfill(): void
    {
        $store = resolve('cache.store')->getStore();

        if ($store instanceof LockProvider) {
            $store->lock('ernestdefoe-docket.backfill', 60)->get(fn () => $this->copyMissingFlags());
        } else {
            $this->copyMissingFlags();
        }
    }

    /**
     * In batches: a few queries per 200 flags, however many posts they are
     * on. These reports are not run through the rules; they land in the
     * general queue.
     */
    protected function copyMissingFlags(): void
    {
        Flag::query()
            ->whereNotIn('id', Entry::query()->whereNotNull('flag_id')->select('flag_id'))
            ->chunkById(200, function ($flags) {
                $now = Carbon::now();
                $byPost = $flags->groupBy('post_id');
                $posts = Post::query()->whereIn('id', $byPost->keys())->get(['id', 'discussion_id', 'user_id'])->keyBy('id');

                $active = fn () => Report::query()
                    ->whereIn('post_id', $posts->keys())
                    ->whereIn('status', Report::ACTIVE)
                    ->orderBy('id')
                    ->get()
                    ->keyBy('post_id');

                $reports = $active();
                $new = $posts->except($reports->keys()->all());

                if ($new->isNotEmpty()) {
                    Report::query()->insert($new->map(fn (Post $post) => [
                        'post_id' => $post->id,
                        'discussion_id' => $post->discussion_id,
                        'user_id' => $post->user_id,
                        'status' => Report::OPEN,
                        'entry_count' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->values()->all());

                    $reports = $active();
                }

                $entries = [];

                foreach ($byPost as $postId => $group) {
                    if (! $report = $reports->get($postId)) {
                        continue;
                    }

                    foreach ($group as $flag) {
                        $entries[] = [
                            'report_id' => $report->id,
                            'flag_id' => $flag->id,
                            'post_id' => $postId,
                            'user_id' => $flag->user_id,
                            'type' => (string) $flag->type,
                            'reason' => $flag->reason,
                            'reason_detail' => $flag->reason_detail,
                            'created_at' => $flag->created_at ?? $now,
                        ];
                    }
                }

                if ($entries) {
                    Entry::query()->insert($entries);

                    // Every report touched, counted again in one statement.
                    $table = (new Report())->getTable();
                    $entryTable = (new Entry())->getTable();
                    Report::query()
                        ->whereIn('id', array_unique(array_column($entries, 'report_id')))
                        ->update(['entry_count' => Entry::query()->selectRaw('count(*)')->whereColumn("$entryTable.report_id", "$table.id")]);
                }
            });
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

        $facts = new RuleFacts($flag, $post, $this->extensions->isEnabled('flarum-tags'));

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
     * @var array<int, true> posts already checked in this request
     */
    protected array $mayDismiss = [];

    public function assertMayDismiss(int $postId, User $actor): void
    {
        if (isset($this->closing[$postId]) || isset($this->mayDismiss[$postId]) || ! $closed = Queue::closedTo($actor)) {
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

        $this->mayDismiss[$postId] = true;
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
            ->get();

        foreach ($candidates as $user) {
            if ($this->canSee($user, $report)) {
                return $user;
            }
        }

        return null;
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

        $report->updated_at = Carbon::now();
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
        $report->updated_at = Carbon::now();
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
        $report->updated_at = $now;
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

        $report->updated_at = Carbon::now();
        $report->save();

        $report->log('status', $by, ['from' => $from, 'to' => $status]);
    }

    protected function tellReporters(Report $report, string $status, ?string $message, User $by): void
    {
        $entries = $report->entries()
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $by->id)
            ->with('user')
            ->orderBy('id')
            ->get()
            ->unique('user_id');

        foreach ($entries as $entry) {
            if ($entry->user) {
                $this->send(new ReportOutcomeBlueprint($entry, $status, $message), $entry->user);
            }
        }
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
            $acted = ! $post->exists || $report->events()->whereIn('type', self::ACTIONS)->exists();

            $report->status = $acted ? Report::RESOLVED : Report::REJECTED;
            $report->closed_at = $now;
            $report->closed_by_id = $actor?->id;
            $report->updated_at = $now;
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

        $report->updated_at = Carbon::now();
        $report->save();
    }

    /**
     * Something happened to the author of reported posts (suspended, warned
     * without a post): log it on their open reports.
     *
     * @param array<string, mixed>|null $data
     */
    public function userAction(int $userId, string $type, ?User $actor, ?array $data = null): void
    {
        Report::query()
            ->where('user_id', $userId)
            ->whereIn('status', Report::ACTIVE)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->each(fn (Report $report) => $report->log($type, $actor, $data));
    }
}
