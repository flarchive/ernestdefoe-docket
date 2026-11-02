<?php

use Flarum\Database\Migration;

/*
 * A keyed hash of the address a flag was sent from (an IPv6 address by its
 * /64), so reports from one connection count once. The address itself is
 * never stored.
 */
return Migration::addColumns('docket_entries', [
    'ip_hash' => ['string', 'length' => 64, 'nullable' => true],
]);
