<?php

namespace ErnestDefoe\Docket;

use Carbon\Carbon;
use ErnestDefoe\Docket\Crowd\Crowd;
use Flarum\User\User;

/**
 * How a member's reports went, for staff: resolved, rejected and still open,
 * over the last 30 and 90 days, the last 12 months and all time.
 *
 * Counted as crowd moderation's trust weighting counts them: reports made
 * with the flag form, a report whose post staff restored after the crowd hid
 * it counts as rejected even if it was resolved later, and every flag in a
 * report is one report by its reporter. Unlike the weighting, only reports
 * the viewer may see are counted, so nobody learns of a report from a number.
 *
 * One grouped query, however many members and frames.
 */
class ReporterStats
{
    /** The frames, by key, in days; null for all time. */
    public const FRAMES = ['30d' => 30, '90d' => 90, '12m' => 365, 'all' => null];

    public const BUCKETS = ['resolved', 'rejected', 'open'];

    /**
     * @param int[] $userIds
     * @return array<int, array<string, array{total: int, resolved: int, rejected: int, open: int}>>
     */
    public function forUsers(array $userIds, User $actor): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        if (! $userIds) {
            return [];
        }

        $entries = (new Entry())->getTable();
        $reports = (new Report())->getTable();
        $grammar = Entry::query()->getQuery()->getGrammar();
        $status = $grammar->wrap("$reports.status");
        $state = $grammar->wrap("$reports.crowd_state");
        $created = $grammar->wrap("$entries.created_at");

        // Fixed strings of this extension's own, so written in; the cut-offs
        // are bound.
        $restored = "'".Crowd::RESTORED."'";
        $buckets = [
            'resolved' => "$status = '".Report::RESOLVED."' and ($state is null or $state <> $restored)",
            'rejected' => "($status = '".Report::REJECTED."' or $state = $restored)",
            'open' => "$status in ('".Report::OPEN."', '".Report::IN_PROGRESS."') and ($state is null or $state <> $restored)",
        ];

        $query = Entry::whereVisibleTo($actor)
            ->join($reports, "$reports.id", '=', "$entries.report_id")
            ->whereIn("$entries.user_id", $userIds)
            ->where("$entries.type", 'user')
            ->groupBy("$entries.user_id")
            ->select("$entries.user_id");

        $now = Carbon::now();

        foreach (self::FRAMES as $frame => $days) {
            foreach ($buckets as $bucket => $condition) {
                if ($days === null) {
                    $query->selectRaw("sum(case when $condition then 1 else 0 end) as {$bucket}_$frame");
                } else {
                    $query->selectRaw("sum(case when $created >= ? and $condition then 1 else 0 end) as {$bucket}_$frame", [$now->copy()->subDays($days)]);
                }
            }
        }

        $empty = array_fill_keys(array_keys(self::FRAMES), ['total' => 0, 'resolved' => 0, 'rejected' => 0, 'open' => 0]);
        $stats = array_fill_keys($userIds, $empty);

        foreach ($query->toBase()->get() as $row) {
            $row = (array) $row;

            foreach (array_keys(self::FRAMES) as $frame) {
                $counts = [];

                foreach (self::BUCKETS as $bucket) {
                    $counts[$bucket] = (int) $row["{$bucket}_$frame"];
                }

                $stats[(int) $row['user_id']][$frame] = ['total' => array_sum($counts)] + $counts;
            }
        }

        return $stats;
    }
}
