<?php

namespace ErnestDefoe\Docket\Access;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Your own reports, and the reports in any case you can see.
 */
class ScopeEntryVisibility
{
    public function __invoke(User $actor, Builder $query): void
    {
        if ($actor->isGuest()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $query) use ($actor) {
            $query->where('docket_entries.user_id', $actor->id);

            if ($actor->hasPermissionLike('discussion.viewFlags')) {
                $query->orWhereHas('report', fn (Builder $report) => $report->whereVisibleTo($actor));
            }
        });
    }
}
