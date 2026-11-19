<?php

namespace ErnestDefoe\Docket;

use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Which queues are kept from whom. The map of every queue's groups lives in
 * the cache, so asking costs no query; it is forgotten whenever a queue or a
 * group changes.
 */
class QueueAccess
{
    public const CACHE_KEY = 'ernestdefoe-docket.queue-access';

    public function __construct(
        protected Cache $cache
    ) {
    }

    /**
     * The ids of the queues kept from this user: kept to groups, none of
     * which the user is in (a queue whose groups were all deleted is kept
     * from everyone). Empty for admins.
     *
     * @return int[]
     */
    public function closedTo(User $actor): array
    {
        if ($actor->isAdmin()) {
            return [];
        }

        $groups = array_map('intval', $actor->permissionGroupIds());
        $closed = [];

        foreach ($this->map() as $queueId => $queueGroups) {
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
    public function map(): array
    {
        return $this->cache->rememberForever(self::CACHE_KEY, function () {
            $map = Queue::query()->pluck('is_restricted', 'id')->mapWithKeys(fn ($restricted, $id) => [(int) $id => $restricted ? [] : null])->all();

            foreach (Queue::query()->getConnection()->table('docket_queue_group')->get() as $row) {
                if (array_key_exists((int) $row->queue_id, $map) && $map[(int) $row->queue_id] !== null) {
                    $map[(int) $row->queue_id][] = (int) $row->group_id;
                }
            }

            return $map;
        });
    }

    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}
