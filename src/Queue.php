<?php

namespace ErnestDefoe\Docket;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Group\Group;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string|null $color
 * @property int $position
 * @property bool $is_restricted
 * @property int|null $crowd_threshold
 * @property Carbon|null $created_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Group> $groups
 */
class Queue extends AbstractModel
{
    protected $table = 'docket_queues';

    protected $casts = ['position' => 'integer', 'is_restricted' => 'boolean', 'crowd_threshold' => 'integer', 'created_at' => 'datetime'];

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'docket_queue_group');
    }
}
