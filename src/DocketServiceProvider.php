<?php

namespace ErnestDefoe\Docket;

use Flarum\Foundation\AbstractServiceProvider;

class DocketServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        // One per request: it remembers which posts' flags it is clearing
        // itself, for the Cleared event that clearing raises.
        $this->container->singleton(Docket::class);
        $this->container->singleton(Crowd\Crowd::class);
        // Holds the address of the request filing a flag until its entry takes it.
        $this->container->singleton(Crowd\Address::class);

        // Without flarum/tags or flarum/approval: extend.php binds the real
        // ones while those are enabled, whichever registers first.
        $this->container->bindIf(Integration\Tags::class, Integration\NoTags::class);
        $this->container->bindIf(Integration\Approval::class, Integration\NoApproval::class);

        // An alt-account extension may bind its own, before or after this.
        if (! $this->container->bound(Crowd\LinkedAccounts::class)) {
            $this->container->bind(Crowd\LinkedAccounts::class, Crowd\SeparateAccounts::class);
        }
    }
}
