<?php

namespace ErnestDefoe\Docket\Crowd;

use Carbon\Carbon;
use ErnestDefoe\Docket\Entry;
use ErnestDefoe\Docket\Report;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Who among a post's reporters counts, and for how much: members long
 * enough, with enough posts, in a counting group; one person per address or
 * linked account; with trust on, weighted by how their past reports went.
 */
class Reporters
{
    /** The most one person can count for, with trust on. */
    public const MAX_WEIGHT = 1.5;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected LinkedAccounts $links
    ) {
    }

    /**
     * What the report's recent reports weigh, for the timeline, when they
     * reach the threshold: null when they don't.
     *
     * @return array{people: int, weight: float, threshold: int, reason: string}|null
     */
    public function weigh(Report $report, Post $post, int $threshold, int $windowHours): ?array
    {
        $since = Carbon::now()->subHours($windowHours);

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
            return null;
        }

        $reporters = $this->eligible($userIds);

        if (count($reporters) < 2) {
            return null;
        }

        $weights = $this->weights(array_keys($reporters), $report->id);
        [$people, $weight] = $this->tally($entries->whereIn('user_id', array_keys($reporters)), $weights);

        if ($people < 2 || $weight < $threshold) {
            return null;
        }

        return [
            'people' => $people,
            'weight' => round($weight, 2),
            'threshold' => $threshold,
            'reason' => $this->topReason($entries->whereIn('user_id', array_keys($reporters))),
        ];
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
        $outcome = Report::outcomeSql($grammar);

        $rows = Entry::query()
            ->join($reports, "$reports.id", '=', "$entries.report_id")
            ->whereIn("$entries.user_id", $userIds)
            ->where("$entries.type", 'user')
            ->when($exceptReport, fn ($q) => $q->where("$entries.report_id", '!=', $exceptReport))
            ->groupBy("$entries.user_id")
            ->select("$entries.user_id")
            ->selectRaw("sum(case when {$outcome['resolved']} then 1 else 0 end) as upheld")
            ->selectRaw("sum(case when {$outcome['rejected']} then 1 else 0 end) as wrong")
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
    public function ids(string $key): array
    {
        $ids = json_decode((string) $this->setting($key), true);

        return is_array($ids) ? array_values(array_diff(array_map('intval', array_filter($ids, 'is_numeric')), [Group::GUEST_ID])) : [];
    }

    protected function setting(string $key): mixed
    {
        return $this->settings->get('ernestdefoe-docket.crowd_'.$key);
    }
}
