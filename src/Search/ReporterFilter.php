<?php

namespace ErnestDefoe\Docket\Search;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;
use Flarum\User\Exception\PermissionDeniedException;

/**
 * The reports a member made, for staff who see flags: refused to anyone
 * else, so nobody learns who reported them.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class ReporterFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'reporter';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        if (! $state->getActor()->hasPermissionLike('discussion.viewFlags')) {
            throw new PermissionDeniedException();
        }

        /** @var DatabaseSearchState $state */
        $state->getQuery()
            ->where('docket_entries.user_id', $negate ? '!=' : '=', $this->asInt($value))
            ->where('docket_entries.type', 'user');
    }
}
