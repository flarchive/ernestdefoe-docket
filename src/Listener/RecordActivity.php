<?php

namespace ErnestDefoe\Docket\Listener;

use ErnestDefoe\Docket\Docket;
use ErnestDefoe\Docket\Queue;
use Flarum\Flags\Event\Cleared;
use Flarum\Flags\Event\Deleting as FlagDeleting;
use Flarum\Flags\Flag;
use Flarum\Group\Event\Deleted as GroupDeleted;
use Flarum\Post\Event\Deleted;
use Flarum\Post\Event\Hidden;
use Flarum\Post\Event\Restored;
use Flarum\User\Event\Deleting as UserDeleting;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Files every new flag, however it was raised (the flag form, flarum/approval,
 * an anti-spam or AI extension), and logs what happens to reported posts.
 */
class RecordActivity
{
    /**
     * Resolved when an event comes, not when the listener is registered on
     * every request.
     */
    protected function docket(): Docket
    {
        return resolve(Docket::class);
    }

    public function subscribe(Dispatcher $events): void
    {
        // The model event, not flags' Created: that one is only raised by the
        // flag form, and flarum/approval and others save flags directly.
        $events->listen('eloquent.created: '.Flag::class, fn (Flag $flag) => $this->docket()->file($flag));

        $events->listen(Cleared::class, fn (Cleared $event) => $this->docket()->cleared($event->post, $event->actor));

        $events->listen(Hidden::class, fn (Hidden $event) => $this->docket()->postAction($event->post->id, 'post_hidden', $event->actor));
        $events->listen(Restored::class, fn (Restored $event) => $this->docket()->postAction($event->post->id, 'post_restored', $event->actor));
        $events->listen(Deleted::class, fn (Deleted $event) => $this->docket()->postAction($event->post->id, 'post_deleted', $event->actor));

        // Dismissing a post's flags while its report sits in a queue kept
        // from you: refused, as the report and the flags are hidden from you.
        $events->listen(FlagDeleting::class, fn (FlagDeleting $event) => $this->docket()->assertMayDismiss($event->flag->post_id, $event->actor));

        // Deleting, not Deleted: by then the foreign key has already emptied
        // assignee_id on MySQL and PostgreSQL, and the reports can't be found.
        $events->listen(UserDeleting::class, fn (UserDeleting $event) => $this->docket()->userDeleted($event->user->id));
        $events->listen(GroupDeleted::class, fn () => Queue::forgetAccessMap());
    }
}
