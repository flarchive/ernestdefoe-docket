<?php

namespace ErnestDefoe\Docket\Integration;

use Flarum\Post\Post;
use Flarum\User\User;

/**
 * flarum/approval, as crowd moderation uses it. Without it, NoApproval; with
 * it, FlarumApproval, bound in extend.php's Extend\Conditional.
 */
interface Approval
{
    public function enabled(): bool;

    /** The event that tells flarum/approval a post was approved, if it is there. */
    public function approvedEvent(Post $post, User $actor): ?object;
}
