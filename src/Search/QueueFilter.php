<?php

namespace ErnestDefoe\Docket\Search;

use Flarum\Search\Database\DatabaseSearchState;

class QueueFilter extends ReportFilter
{
    public function getFilterKey(): string
    {
        return 'queue';
    }

    protected function apply(DatabaseSearchState $state, string $value, bool $negate): void
    {
        $this->idOrNone($state, 'docket_reports.queue_id', $value, $negate);
    }
}
