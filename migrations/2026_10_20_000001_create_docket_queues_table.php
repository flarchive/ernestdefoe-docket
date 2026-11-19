<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('docket_queues', function (Blueprint $table) {
    $table->increments('id');
    $table->string('name', 100);
    $table->string('description', 255)->nullable();
    $table->string('color', 20)->nullable();
    $table->unsignedInteger('position')->default(0);
    // Kept to groups. Stays set when those groups are deleted, so the queue
    // falls back to admins only rather than opening to every moderator.
    $table->boolean('is_restricted')->default(false);
    $table->dateTime('created_at')->nullable();
});
