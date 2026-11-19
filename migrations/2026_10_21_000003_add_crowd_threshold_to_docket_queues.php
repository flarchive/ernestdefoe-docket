<?php

use Flarum\Database\Migration;

/*
 * The weight of reports that hides a post in this queue: null for the forum's
 * own setting, 0 for never.
 */
return Migration::addColumns('docket_queues', [
    'crowd_threshold' => ['smallInteger', 'unsigned' => true, 'nullable' => true],
]);
