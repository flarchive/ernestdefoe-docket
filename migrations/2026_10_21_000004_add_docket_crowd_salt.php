<?php

use Illuminate\Database\Schema\Builder;

/*
 * The key reporters' addresses are hashed with: random per forum, so the
 * hashes can't be matched against a table of every IPv4 address.
 */
return [
    'up' => function (Builder $schema) {
        $settings = $schema->getConnection()->table('settings');

        if (! $settings->where('key', 'ernestdefoe-docket.crowd_salt')->exists()) {
            $settings->insert(['key' => 'ernestdefoe-docket.crowd_salt', 'value' => bin2hex(random_bytes(32))]);
        }
    },
    'down' => function (Builder $schema) {
        $schema->getConnection()->table('settings')->where('key', 'ernestdefoe-docket.crowd_salt')->delete();
    },
];
