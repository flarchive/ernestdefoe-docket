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
    }
}
