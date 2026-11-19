<?php

namespace ErnestDefoe\Docket\Job;

use Carbon\Carbon;
use ErnestDefoe\Docket\Entry;
use ErnestDefoe\Docket\Report;
use Flarum\Flags\Flag;
use Flarum\Post\Post;
use Flarum\Queue\AbstractJob;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Collection;

/**
 * Copy in every flag that has no entry yet: the flags already there when
 * Docket is first enabled, and any raised while it was disabled. Queued when
 * Docket is enabled (Extend\BackfillOnEnable).
 *
 * In batches, a few queries per 200 flags however many posts they are on.
 * These reports are not run through the rules; they land in the general queue.
 */
class BackfillFlags extends AbstractJob
{
    public function handle(Cache $cache): void
    {
        // Every Laravel store (file, array, database, redis, memcached) is a
        // LockProvider; the fallback is for a custom one. A second run while
        // one is going has nothing to add, so it stops.
        $store = $cache->getStore();

        if ($store instanceof LockProvider) {
            $store->lock('ernestdefoe-docket.backfill', 600)->get(fn () => $this->copyMissingFlags());
        } else {
            $this->copyMissingFlags();
        }
    }

    /**
     * One anti-join over flags, which hold only open reports (closing a
     * report deletes its flags), against an indexed flag_id.
     */
    protected function copyMissingFlags(): void
    {
        Flag::query()
            ->whereNotIn('id', Entry::query()->whereNotNull('flag_id')->select('flag_id'))
            ->chunkById(200, fn (Collection $flags) => $this->file($flags));
    }

    /**
     * @param Collection<int, Flag> $flags
     */
    protected function file(Collection $flags): void
    {
        $now = Carbon::now();
        $byPost = $flags->groupBy('post_id');
        $posts = Post::query()->whereIn('id', $byPost->keys())->get(['id', 'discussion_id', 'user_id'])->keyBy('id');
        $reports = $this->reportsFor($posts, $now);
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
    }

    /**
     * Each post's open report, opening one in a single insert for the posts
     * that have none.
     *
     * @param \Illuminate\Support\Collection<int, Post> $posts
     * @return \Illuminate\Support\Collection<int, Report>
     */
    protected function reportsFor(\Illuminate\Support\Collection $posts, Carbon $now): \Illuminate\Support\Collection
    {
        $active = fn () => Report::query()
            ->whereIn('post_id', $posts->keys())
            ->whereIn('status', Report::ACTIVE)
            ->orderBy('id')
            ->get()
            ->keyBy('post_id');

        $reports = $active();
        $new = $posts->except($reports->keys()->all());

        if ($new->isEmpty()) {
            return $reports;
        }

        Report::query()->insert($new->map(fn (Post $post) => [
            'post_id' => $post->id,
            'discussion_id' => $post->discussion_id,
            'user_id' => $post->user_id,
            'status' => Report::OPEN,
            'active_post_id' => $post->id,
            'entry_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all());

        return $active();
    }
}
