<?php

namespace ErnestDefoe\Docket\Search;

use Carbon\Carbon;
use ErnestDefoe\Docket\Entry;
use Flarum\Search\Database\AbstractSearcher;
use Flarum\Search\Filter\FilterManager;
use Flarum\Search\SearchCriteria;
use Flarum\Search\SearchResults;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The flags in reports, listed. Unfiltered, they are "My reports": only your
 * own, and only as far back as the forum keeps them there. With the reporter
 * filter (staff only, see ReporterFilter) they are the reports a member made,
 * as far as the viewer may see them.
 */
class EntrySearcher extends AbstractSearcher
{
    /** How far back "My reports" goes, in days: 0 for forever. */
    public const DAYS_SETTING = 'ernestdefoe-docket.my_reports_days';

    /** Whether this search lists a member's reports for staff. */
    protected bool $staff = false;

    public function __construct(
        FilterManager $filters,
        array $mutators,
        protected SettingsRepositoryInterface $settings
    ) {
        parent::__construct($filters, $mutators);
    }

    public function search(SearchCriteria $criteria): SearchResults
    {
        $this->staff = array_key_exists('reporter', $criteria->filters);

        return parent::search($criteria);
    }

    public function getQuery(User $actor): Builder
    {
        $query = Entry::whereVisibleTo($actor)->select('docket_entries.*');

        if (! $this->staff) {
            $query->where('docket_entries.user_id', $actor->id);

            if ($days = self::days($this->settings)) {
                $query->where('docket_entries.created_at', '>=', Carbon::now()->subDays($days));
            }
        }

        return $query;
    }

    public static function days(SettingsRepositoryInterface $settings): int
    {
        return max(0, (int) $settings->get(self::DAYS_SETTING));
    }
}
