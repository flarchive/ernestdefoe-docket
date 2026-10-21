<?php

namespace ErnestDefoe\Docket\Crowd;

/**
 * The default: no alternate-account detection, every account its own person.
 * Shared addresses are caught by the reports' address hashes either way.
 */
class SeparateAccounts implements LinkedAccounts
{
    public function people(array $userIds): array
    {
        return [];
    }
}
