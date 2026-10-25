<?php

namespace ErnestDefoe\Docket\Search;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;

/**
 * The report list's filters, each a fixed key with a checked value:
 *
 *   status    open, in_progress, resolved, rejected, or several, comma-separated
 *   queue     a queue id, or "none" for the general queue
 *   assignee  a user id, "me", or "none"
 *   user      reports about this user's posts
 *   post      reports about this post
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
abstract class ReportFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        /** @var DatabaseSearchState $state */
        $this->apply($state, $this->asString($value), $negate);
    }

    abstract protected function apply(DatabaseSearchState $state, string $value, bool $negate): void;

    /**
     * Narrow to `$column` equal to the id, or null for "none".
     */
    protected function idOrNone(DatabaseSearchState $state, string $column, string $value, bool $negate): void
    {
        $query = $state->getQuery();

        if ($value === 'none') {
            $negate ? $query->whereNotNull($column) : $query->whereNull($column);
        } else {
            $query->where($column, $negate ? '!=' : '=', (int) $value);
        }
    }
}
