<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// A copy of each flag in a report: flags are deleted when they are dismissed,
// and these are the record of who reported what.
return Migration::createTable('docket_entries', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('report_id');
    $table->unsignedInteger('flag_id')->nullable();
    // The reported post, so a reporter's list can show it to them (when they
    // can still see it) without reading the report.
    $table->unsignedInteger('post_id')->nullable();
    $table->unsignedInteger('user_id')->nullable();
    $table->string('type', 50);
    $table->string('reason', 100)->nullable();
    $table->text('reason_detail')->nullable();
    $table->dateTime('created_at')->nullable();

    $table->index('flag_id');
    $table->index(['user_id', 'created_at']);

    $table->foreign('report_id')->references('id')->on('docket_reports')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
});
