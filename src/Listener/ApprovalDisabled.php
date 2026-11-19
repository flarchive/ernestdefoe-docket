<?php

namespace ErnestDefoe\Docket\Listener;

use ErnestDefoe\Docket\Job\HideInsteadOfApproval;
use Flarum\Extension\Event\Disabled;
use Illuminate\Contracts\Queue\Queue;

/**
 * flarum/approval was disabled: queue hiding what the crowd had sent back to
 * approval, so the admin's request doesn't wait for it.
 */
class ApprovalDisabled
{
    public function __construct(
        protected Queue $queue
    ) {
    }

    public function handle(Disabled $event): void
    {
        if ($event->extension->getId() === 'flarum-approval') {
            $this->queue->push(new HideInsteadOfApproval());
        }
    }
}
