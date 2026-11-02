<?php

namespace ErnestDefoe\Docket\Crowd;

/**
 * Which reporters are one person. Crowd moderation counts each person once:
 * an extension that detects alternate accounts binds its own version of this
 * in the container, and accounts it puts under one key count as one reporter.
 *
 *     $container->bind(LinkedAccounts::class, MyAltAccounts::class);
 */
interface LinkedAccounts
{
    /**
     * A key for each user id, the same for accounts that belong together.
     * Ids left out are taken as their own person.
     *
     * @param int[] $userIds
     * @return array<int, string>
     */
    public function people(array $userIds): array;
}
