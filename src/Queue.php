<?php

namespace ErnestDefoe\Docket;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string|null $color
 * @property int $position
 * @property bool $is_restricted
 * @property Carbon|null $created_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Group> $groups
 */
class Queue extends AbstractModel
{
    public const CACHE_KEY = 'ernestdefoe-docket.queue-access';

    protected $table = 'docket_queues';

    protected $casts = ['position' => 'integer', 'is_restricted' => 'boolean', 'created_at' => 'datetime'];

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'docket_queue_group');
    }

    /**
     * The ids of the queues kept from this user: kept to groups, none of
     * which the user is in (a queue whose groups were all deleted is kept
     * from everyone). Empty for admins.
     *
     * Reads the queue map from the cache, so it costs no query.
     *
     * @return int[]
     */
    public static function closedTo(User $actor): array
    {
        if ($actor->isAdmin()) {
            return [];
        }

        $groups = array_map('intval', $actor->permissionGroupIds());
        $closed = [];

        foreach (static::accessMap() as $queueId => $queueGroups) {
            if ($queueGroups !== null && ! array_intersect($queueGroups, $groups)) {
                $closed[] = $queueId;
            }
        }

        return $closed;
    }

    /**
     * Every queue's groups, by queue id: null for a queue open to all.
     *
     * @return array<int, int[]|null>
     */
    public static function accessMap(): array
    {
        /** @var \Illuminate\Contracts\Cache\Repository $cache */
        $cache = resolve('cache.store');

        return $cache->rememberForever(self::CACHE_KEY, function () {
            $map = static::query()->pluck('is_restricted', 'id')->mapWithKeys(fn ($restricted, $id) => [(int) $id => $restricted ? [] : null])->all();

            foreach (static::query()->getConnection()->table('docket_queue_group')->get() as $row) {
                if (array_key_exists((int) $row->queue_id, $map) && $map[(int) $row->queue_id] !== null) {
                    $map[(int) $row->queue_id][] = (int) $row->group_id;
                }
            }

            return $map;
        });
    }

    public static function forgetAccessMap(): void
    {
        resolve('cache.store')->forget(self::CACHE_KEY);
    }

    public function isOpenTo(User $actor): bool
    {
        return ! in_array($this->id, static::closedTo($actor), true);
    }
}
