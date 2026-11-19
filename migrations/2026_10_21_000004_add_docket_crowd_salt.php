<?php

use Flarum\Database\Migration;

/*
 * The key reporters' addresses are hashed with: random per forum, so the
 * hashes can't be matched against a table of every IPv4 address. A
 * migration runs once per forum, so the key is never replaced.
 */
return Migration::addSettings([
    'ernestdefoe-docket.crowd_salt' => bin2hex(random_bytes(32)),
]);
