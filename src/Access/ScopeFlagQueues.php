<?php

namespace ErnestDefoe\Docket\Access;

use ErnestDefoe\Docket\Queue;
use ErnestDefoe\Docket\Report;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A post whose open report sits in a queue kept from you keeps its flags from
 * you too, in the post stream, the flags dropdown and its count; otherwise a
 * restricted queue would only hide the report, not what was reported.
 *
 * Costs nothing for anyone who cannot see flags, or when no queue is kept
 * from them: the queue map comes from the cache.
 */
class ScopeFlagQueues
{
    public function __invoke(User $actor, Builder $query): void
    {
        if (! $actor->hasPermissionLike('discussion.viewFlags')) {
            return;
        }

        if (! $closed = Queue::closedTo($actor)) {
            return;
        }

        $reports = (new Report())->getTable();

        $query->whereNotExists(function (QueryBuilder $sub) use ($closed, $reports) {
            $sub->selectRaw('1')
                ->from($reports)
                ->whereColumn("$reports.post_id", 'flags.post_id')
                ->whereIn("$reports.status", Report::ACTIVE)
                ->whereIn("$reports.queue_id", $closed);
        });
    }
}
