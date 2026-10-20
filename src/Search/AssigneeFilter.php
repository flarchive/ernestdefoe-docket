<?php

namespace ErnestDefoe\Docket\Search;

use Flarum\Search\Database\DatabaseSearchState;

class AssigneeFilter extends ReportFilter
{
    public function getFilterKey(): string
    {
        return 'assignee';
    }

    protected function apply(DatabaseSearchState $state, string $value, bool $negate): void
    {
        $this->idOrNone($state, 'docket_reports.assignee_id', $value === 'me' ? (string) $state->getActor()->id : $value, $negate);
    }
}
