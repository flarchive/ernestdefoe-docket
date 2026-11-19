<?php

use ErnestDefoe\Docket\Migration\OneOpenReportPerPost;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * One open report per post, held by the database itself: active_post_id is
 * the post's id while the report is open or in progress, and empty once it
 * closes, under a unique index (empty values never clash). Where a post
 * already has several open, they are merged into the newest first
 * (OneOpenReportPerPost). The index is named by Laravel, prefix included.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('docket_reports', function (Blueprint $table) {
            $table->unsignedInteger('active_post_id')->nullable();
        });

        // Several open on one post already: merged into the newest first.
        OneOpenReportPerPost::run($schema->getConnection());

        $schema->table('docket_reports', function (Blueprint $table) {
            $table->unique('active_post_id');
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('docket_reports', function (Blueprint $table) {
            $table->dropUnique(['active_post_id']);
            $table->dropColumn('active_post_id');
        });
    },
];
