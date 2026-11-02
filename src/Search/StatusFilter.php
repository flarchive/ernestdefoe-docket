<?php

namespace ErnestDefoe\Docket\Search;

use ErnestDefoe\Docket\Report;
use Flarum\Search\Database\DatabaseSearchState;

class StatusFilter extends ReportFilter
{
    public function getFilterKey(): string
    {
        return 'status';
    }

    protected function apply(DatabaseSearchState $state, string $value, bool $negate): void
    {
        $statuses = array_values(array_intersect(explode(',', $value), Report::STATUSES));

        $state->getQuery()->{$negate ? 'whereNotIn' : 'whereIn'}('docket_reports.status', $statuses ?: ['']);
    }
}
