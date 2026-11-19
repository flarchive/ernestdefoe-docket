<?php

namespace ErnestDefoe\Docket\Listener;

use ErnestDefoe\Docket\Docket;
use Flarum\Discussion\Event\Restored as DiscussionRestored;
use Flarum\Flags\Event\Cleared;
use Flarum\Flags\Event\Deleting as FlagDeleting;
use Flarum\Flags\Flag;
use Flarum\Post\Event\Deleted;
use Flarum\Post\Event\Hidden;
use Flarum\Post\Event\Restored;
use Flarum\User\Event\Deleting as UserDeleting;

/**
 * Files every new flag, however it was raised (the flag form, flarum/approval,
 * an anti-spam or AI extension), and logs what happens to reported posts.
 *
 * Registered in extend.php by class and method, so it is built (with Docket)
 * when one of these events comes, not on every request.
 */
class RecordActivity
{
    public function __construct(
        protected Docket $docket
    ) {
    }

    /**
     * The model event, not flags' Created: that one is only raised by the
     * flag form, and flarum/approval and others save flags directly.
     */
    public function flagCreated(Flag $flag): void
    {
        $this->docket->file($flag);
    }

    public function cleared(Cleared $event): void
    {
        $this->docket->cleared($event->post, $event->actor);
    }

    public function hidden(Hidden $event): void
    {
        $this->docket->postAction($event->post->id, 'post_hidden', $event->actor);
    }

    public function restored(Restored $event): void
    {
        $this->docket->postAction($event->post->id, 'post_restored', $event->actor);
    }

    /** Crowd moderation hides a whole discussion when its first post is reported. */
    public function discussionRestored(DiscussionRestored $event): void
    {
        if ($event->discussion->first_post_id) {
            $this->docket->postAction((int) $event->discussion->first_post_id, 'post_restored', $event->actor);
        }
    }

    public function deleted(Deleted $event): void
    {
        $this->docket->postAction($event->post->id, 'post_deleted', $event->actor);
    }

    /**
     * Dismissing a post's flags while its report sits in a queue kept from
     * you: refused, as the report and the flags are hidden from you.
     */
    public function flagDeleting(FlagDeleting $event): void
    {
        $this->docket->assertMayDismiss($event->flag->post_id, $event->actor);
    }

    /**
     * Deleting, not Deleted: by then the foreign key has already emptied
     * assignee_id on MySQL and PostgreSQL, and the reports can't be found.
     */
    public function userDeleting(UserDeleting $event): void
    {
        $this->docket->userDeleted($event->user->id);
    }
}
