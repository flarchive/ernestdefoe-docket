<?php

namespace ErnestDefoe\Docket\Integration;

use Flarum\Foundation\AbstractServiceProvider;

/** Registered only while flarum/approval is enabled (extend.php). */
class ApprovalServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(Approval::class, FlarumApproval::class);
    }
}
