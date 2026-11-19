<?php

namespace ErnestDefoe\Docket;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An auto-assignment rule: when a new report matches its conditions, it is
 * filed in the rule's queue and/or assigned.
 *
 * @property int $id
 * @property string $name
 * @property int $position
 * @property bool $is_enabled
 * @property array{tags?: int[], reasons?: string[], types?: string[], groups?: int[]}|null $conditions
 * @property int|null $queue_id
 * @property int|null $assignee_id
 * @property int|null $assignee_group_id
 * @property Carbon|null $created_at
 * @property-read Queue|null $queue
 * @property-read User|null $assignee
 * @property-read Group|null $assigneeGroup
 */
class Rule extends AbstractModel
{
    protected $table = 'docket_rules';

    protected $casts = [
        'position' => 'integer',
        'is_enabled' => 'boolean',
        'conditions' => 'array',
        'created_at' => 'datetime',
    ];

    public function queue(): BelongsTo
    {
        return $this->belongsTo(Queue::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function assigneeGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'assignee_group_id');
    }
}
