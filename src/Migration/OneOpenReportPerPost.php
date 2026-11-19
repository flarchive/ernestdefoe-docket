<?php

namespace ErnestDefoe\Docket\Migration;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;

/**
 * Before a post is held to one open report (2026_11_19_000002), a forum may
 * already have several open on one post. The newest stays open and takes
 * the others' entries; each older one is closed with a timeline line saying
 * where its entries went. Then every open report claims its post.
 *
 * Plain query builder only, so it runs the same in a migration and a test.
 */
class OneOpenReportPerPost
{
    public const ACTIVE = ['open', 'in_progress'];

    public static function run(ConnectionInterface $db): void
    {
        $reports = fn () => $db->table('docket_reports');

        $duplicated = $reports()
            ->whereNotNull('post_id')
            ->whereIn('status', self::ACTIVE)
            ->groupBy('post_id')
            ->havingRaw('count(*) > 1')
            ->pluck('post_id');

        foreach ($duplicated as $postId) {
            $ids = $reports()->where('post_id', $postId)->whereIn('status', self::ACTIVE)->orderByDesc('id')->pluck('id')->all();
            self::merge($db, (int) array_shift($ids), array_map('intval', $ids));
        }

        $newest = $reports()->whereNotNull('post_id')->whereIn('status', self::ACTIVE)->groupBy('post_id')->selectRaw('max(id) as id')->pluck('id')->all();

        foreach (array_chunk($newest, 500) as $ids) {
            $reports()->whereIn('id', $ids)->update(['active_post_id' => $db->raw($db->getQueryGrammar()->wrap('post_id'))]);
        }
    }

    /**
     * @param int[] $older
     */
    protected static function merge(ConnectionInterface $db, int $keep, array $older): void
    {
        $now = Carbon::now();

        $db->transaction(function () use ($db, $keep, $older, $now) {
            $db->table('docket_entries')->whereIn('report_id', $older)->update(['report_id' => $keep]);
            $db->table('docket_reports')->where('id', $keep)->update([
                'entry_count' => $db->table('docket_entries')->where('report_id', $keep)->count(),
                'updated_at' => $now,
            ]);
            $db->table('docket_reports')->whereIn('id', $older)->update([
                'status' => 'resolved',
                'entry_count' => 0,
                'closed_at' => $now,
                'updated_at' => $now,
            ]);
            $db->table('docket_events')->insert(array_map(fn (int $id) => [
                'report_id' => $id,
                'type' => 'merged',
                'data' => json_encode(['into' => $keep]),
                'created_at' => $now,
            ], $older));
        });
    }
}
