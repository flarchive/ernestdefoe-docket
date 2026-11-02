<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// A report's timeline: staff notes, and every change made to it or its post.
return Migration::createTable('docket_events', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('report_id');
    $table->unsignedInteger('user_id')->nullable();
    $table->string('type', 30);
    $table->text('data')->nullable();
    $table->text('body')->nullable();
    $table->dateTime('created_at')->nullable();

    $table->index(['report_id', 'id']);

    $table->foreign('report_id')->references('id')->on('docket_reports')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
});
