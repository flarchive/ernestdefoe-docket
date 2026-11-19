<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * A report's entries, by type and time: crowd moderation and the reporter
 * records read them this way. The foreign key alone indexes report_id only on
 * MySQL. The index is named by Laravel, prefix included.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('docket_entries', function (Blueprint $table) {
            $table->index(['report_id', 'type', 'created_at']);
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('docket_entries', function (Blueprint $table) {
            $table->dropIndex(['report_id', 'type', 'created_at']);
        });
    },
];
