<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'docket.assignOthers' => Group::MODERATOR_ID,
]);
