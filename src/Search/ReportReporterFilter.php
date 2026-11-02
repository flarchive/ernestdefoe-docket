<?php

namespace ErnestDefoe\Docket\Search;

use Flarum\Search\Database\DatabaseSearchState;

/**
 * Reports this member raised with the report form. The report list is staff-only
 * already (reports are invisible to anyone who can't see flags).
 */
class ReportReporterFilter extends ReportFilter
{
    public function getFilterKey(): string
    {
        return 'reporter';
    }

    protected function apply(DatabaseSearchState $state, string $value, bool $negate): void
    {
        $state->getQuery()->{$negate ? 'whereNotExists' : 'whereExists'}(function ($query) use ($value) {
            $query->selectRaw('1')
                ->from('docket_entries')
                ->whereColumn('docket_entries.report_id', 'docket_reports.id')
                ->where('docket_entries.user_id', (int) $value)
                ->where('docket_entries.type', 'user');
        });
    }
}
