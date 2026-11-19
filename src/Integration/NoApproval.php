<?php

namespace ErnestDefoe\Docket\Integration;

use Flarum\Post\Post;
use Flarum\User\User;

class NoApproval implements Approval
{
    public function enabled(): bool
    {
        return false;
    }

    public function approvedEvent(Post $post, User $actor): ?object
    {
        return null;
    }
}
