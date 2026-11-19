<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * One report per flagged post, from its first flag until it is closed. A flag
 * on the post after that opens a new one.
 *
 * post_id and discussion_id have no foreign keys on purpose: a report outlives
 * the post it is about, so its history is still there after the post is
 * deleted.
 */
return Migration::createTable('docket_reports', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('post_id')->nullable();
    $table->unsignedInteger('discussion_id')->nullable();
    // The author of the reported post.
    $table->unsignedInteger('user_id')->nullable();
    $table->unsignedInteger('queue_id')->nullable();
    $table->unsignedInteger('assignee_id')->nullable();
    $table->string('status', 20)->default('open');
    $table->unsignedInteger('entry_count')->default(0);
    // The message for the people who reported it, when they were told.
    $table->text('outcome')->nullable();
    $table->boolean('reporters_notified')->default(false);
    $table->unsignedInteger('closed_by_id')->nullable();
    $table->dateTime('closed_at')->nullable();
    $table->dateTime('created_at')->nullable();
    $table->dateTime('updated_at')->nullable();

    $table->index(['post_id', 'status']);
    $table->index(['status', 'updated_at']);
    $table->index(['assignee_id', 'status']);
    $table->index('user_id');
    $table->index('discussion_id');

    $table->foreign('queue_id')->references('id')->on('docket_queues')->nullOnDelete();
    $table->foreign('assignee_id')->references('id')->on('users')->nullOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
    $table->foreign('closed_by_id')->references('id')->on('users')->nullOnDelete();
});
