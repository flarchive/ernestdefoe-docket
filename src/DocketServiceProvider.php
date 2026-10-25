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

        // An alt-account extension may bind its own, before or after this.
        if (! $this->container->bound(Crowd\LinkedAccounts::class)) {
            $this->container->bind(Crowd\LinkedAccounts::class, Crowd\SeparateAccounts::class);
        }
    }
}
