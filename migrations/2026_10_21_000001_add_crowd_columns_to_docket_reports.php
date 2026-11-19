<?php

use Flarum\Database\Migration;

/*
 * Crowd moderation: what the community's reports did to the post (hidden or
 * sent to approval, then restored by staff, or held back because the author is
 * exempt) and when.
 */
return Migration::addColumns('docket_reports', [
    'crowd_state' => ['string', 'length' => 20, 'nullable' => true],
    'crowd_at' => ['dateTime', 'nullable' => true],
]);
