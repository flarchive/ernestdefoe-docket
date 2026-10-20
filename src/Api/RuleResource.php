<?php

namespace ErnestDefoe\Docket\Api;

use Carbon\Carbon;
use ErnestDefoe\Docket\Rule;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Tobyz\JsonApiServer\Context;

/**
 * Auto-assignment rules, for admins only.
 *
 * @extends AbstractDatabaseResource<Rule>
 */
class RuleResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'docket-rules';
    }

    public function model(): string
    {
        return Rule::class;
    }

    public function newModel(Context $context): object
    {
        $rule = new Rule();

        if ($context->creating(self::class)) {
            $rule->created_at = Carbon::now();
            $rule->is_enabled = true;
            $rule->position = (int) Rule::query()->max('position') + 1;
        }

        return $rule;
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->admin()
                ->defaultInclude(['queue', 'assignee', 'assigneeGroup'])
                ->defaultSort('position'),
            Endpoint\Create::make()
                ->admin(),
            Endpoint\Update::make()
                ->admin(),
            Endpoint\Delete::make()
                ->admin(),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('name')
                ->writable()
                ->requiredOnCreate()
                ->minLength(1)
                ->maxLength(100),
            Schema\Integer::make('position'),
            Schema\Boolean::make('isEnabled')
                ->writable(),
            Schema\Arr::make('conditions')
                ->writable()
                ->nullable()
                ->set(fn (Rule $rule, ?array $value) => $rule->conditions = self::cleanConditions($value ?? [])),

            Schema\Relationship\ToOne::make('queue')
                ->type('docket-queues')
                ->writable()
                ->nullable()
                ->includable(),
            Schema\Relationship\ToOne::make('assignee')
                ->type('users')
                ->writable()
                ->nullable()
                ->includable(),
            Schema\Relationship\ToOne::make('assigneeGroup')
                ->type('groups')
                ->writable()
                ->nullable()
                ->includable(),
        ];
    }

    public function sorts(): array
    {
        return [
            \Flarum\Api\Sort\SortColumn::make('position'),
        ];
    }

    /**
     * Keep only the known conditions, as lists of ids or short names.
     *
     * @param array<mixed> $value
     * @return array{tags: int[], reasons: string[], types: string[], groups: int[]}
     */
    public static function cleanConditions(array $value): array
    {
        $ids = fn ($list) => array_values(array_unique(array_filter(array_map('intval', is_array($list) ? array_slice($list, 0, 100) : []))));
        $names = fn ($list) => array_values(array_unique(array_filter(array_map(
            fn ($v) => is_scalar($v) ? mb_substr(trim((string) $v), 0, 100) : '',
            is_array($list) ? array_slice($list, 0, 100) : []
        ))));

        return [
            'tags' => $ids($value['tags'] ?? []),
            'reasons' => $names($value['reasons'] ?? []),
            'types' => $names($value['types'] ?? []),
            'groups' => $ids($value['groups'] ?? []),
        ];
    }
}
