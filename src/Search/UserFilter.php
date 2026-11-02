<?php

namespace ErnestDefoe\Docket\Search;

use Flarum\Search\Database\DatabaseSearchState;

class UserFilter extends ReportFilter
{
    public function getFilterKey(): string
    {
        return 'user';
    }

    protected function apply(DatabaseSearchState $state, string $value, bool $negate): void
    {
        $state->getQuery()->where('docket_reports.user_id', $negate ? '!=' : '=', (int) $value);
    }
}
