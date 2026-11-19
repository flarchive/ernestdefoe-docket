<?php

namespace ErnestDefoe\Docket\Job;

use ErnestDefoe\Docket\Crowd\Crowd;
use Flarum\Queue\AbstractJob;

/**
 * flarum/approval was disabled: hide what the crowd had sent back to
 * approval (Crowd::approvalDisabled), off the admin's request.
 */
class HideInsteadOfApproval extends AbstractJob
{
    public function handle(Crowd $crowd): void
    {
        $crowd->approvalDisabled();
    }
}
