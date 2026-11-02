<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('docket_rules', function (Blueprint $table) {
    $table->increments('id');
    $table->string('name', 100);
    $table->unsignedInteger('position')->default(0);
    $table->boolean('is_enabled')->default(true);
    // {tags: int[], reasons: string[], types: string[], groups: int[]}; an
    // empty or missing list matches anything.
    $table->text('conditions')->nullable();
    $table->unsignedInteger('queue_id')->nullable();
    $table->unsignedInteger('assignee_id')->nullable();
    // Assign to whichever member of this group has the fewest reports in hand.
    $table->unsignedInteger('assignee_group_id')->nullable();
    $table->dateTime('created_at')->nullable();

    $table->foreign('queue_id')->references('id')->on('docket_queues')->nullOnDelete();
    $table->foreign('assignee_id')->references('id')->on('users')->nullOnDelete();
    $table->foreign('assignee_group_id')->references('id')->on('groups')->nullOnDelete();
});
