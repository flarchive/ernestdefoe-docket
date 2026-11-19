<?php

namespace ErnestDefoe\Docket\Integration;

use Flarum\Approval\Event\PostWasApproved;
use Flarum\Post\Post;
use Flarum\User\User;

class FlarumApproval implements Approval
{
    public function enabled(): bool
    {
        return true;
    }

    public function approvedEvent(Post $post, User $actor): ?object
    {
        return new PostWasApproved($post, $actor);
    }
}
