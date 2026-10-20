<?php

namespace ErnestDefoe\Docket\Api;

use Carbon\Carbon;
use ErnestDefoe\Docket\Queue;
use ErnestDefoe\Docket\Report;
use ErnestDefoe\Docket\Rule;
use Flarum\Api\Context as FlarumContext;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context;

/**
 * Report queues. Staff see the ones open to them; only admins change them,
 * or see which groups they are kept to.
 *
 * @extends AbstractDatabaseResource<Queue>
 */
class QueueResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'docket-queues';
    }

    public function model(): string
    {
        return Queue::class;
    }

    public function scope(Builder $query, Context $context): void
    {
        $actor = $context->getActor();

        if (! $actor->hasPermissionLike('discussion.viewFlags') && ! $actor->isAdmin()) {
            $query->whereRaw('1 = 0');
        } elseif ($closed = Queue::closedTo($actor)) {
            $query->whereNotIn('docket_queues.id', $closed);
        }
    }

    public function newModel(Context $context): object
    {
        $queue = new Queue();

        if ($context->creating(self::class)) {
            $queue->created_at = Carbon::now();
            $queue->position = (int) Queue::query()->max('position') + 1;
        }

        return $queue;
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->authenticated()
                ->defaultSort('position'),
            Endpoint\Create::make()
                ->admin()
                ->after($this->settle(...)),
            Endpoint\Update::make()
                ->admin()
                ->after($this->settle(...)),
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
            Schema\Str::make('description')
                ->writable()
                ->nullable()
                ->maxLength(255),
            Schema\Str::make('color')
                ->writable()
                ->nullable()
                ->regex('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/'),
            Schema\Integer::make('position'),
            Schema\Boolean::make('isRestricted')
                ->property('is_restricted')
                ->visible(fn (Queue $queue, FlarumContext $context) => $context->getActor()->isAdmin()),

            Schema\Relationship\ToMany::make('groups')
                ->writable(fn (Queue $queue, FlarumContext $context) => $context->getActor()->isAdmin())
                ->visible(fn (Queue $queue, FlarumContext $context) => $context->getActor()->isAdmin())
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
     * After the groups are saved, which is after the model's own save hooks:
     * restricted is set by the groups an admin keeps the queue to, and is
     * not cleared when those groups are later deleted.
     */
    protected function settle(FlarumContext $context, Queue $queue): Queue
    {
        $restricted = $queue->groups()->exists();

        if ($queue->is_restricted !== $restricted) {
            $queue->is_restricted = $restricted;
            $queue->save();
        }

        Queue::forgetAccessMap();

        return $queue;
    }

    /**
     * Its reports go back to the general queue and its rules lose their
     * queue. The foreign keys do this too, but not on every database.
     */
    public function deleting(object $model, Context $context): void
    {
        Report::query()->where('queue_id', $model->id)->update(['queue_id' => null]);
        Rule::query()->where('queue_id', $model->id)->update(['queue_id' => null]);
        $model->groups()->detach();

        parent::deleting($model, $context);
    }

    public function deleted(object $model, Context $context): void
    {
        Queue::forgetAccessMap();

        parent::deleted($model, $context);
    }
}
