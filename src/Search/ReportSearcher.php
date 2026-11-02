<?php

namespace ErnestDefoe\Docket\Search;

use ErnestDefoe\Docket\Report;
use Flarum\Search\Database\AbstractSearcher;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

class ReportSearcher extends AbstractSearcher
{
    public function getQuery(User $actor): Builder
    {
        return Report::whereVisibleTo($actor)->select('docket_reports.*');
    }
}
