<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// The groups a queue is kept to. A queue with none is open to every moderator
// who can see the flags on a post.
return Migration::createTable('docket_queue_group', function (Blueprint $table) {
    $table->unsignedInteger('queue_id');
    $table->unsignedInteger('group_id');

    $table->primary(['queue_id', 'group_id']);
    $table->foreign('queue_id')->references('id')->on('docket_queues')->cascadeOnDelete();
    $table->foreign('group_id')->references('id')->on('groups')->cascadeOnDelete();
});
