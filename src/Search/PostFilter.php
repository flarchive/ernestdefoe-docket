<?php

namespace ErnestDefoe\Docket\Search;

use Flarum\Search\Database\DatabaseSearchState;

class PostFilter extends ReportFilter
{
    public function getFilterKey(): string
    {
        return 'post';
    }

    protected function apply(DatabaseSearchState $state, string $value, bool $negate): void
    {
        $state->getQuery()->where('docket_reports.post_id', $negate ? '!=' : '=', (int) $value);
    }
}
