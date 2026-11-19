<?php

namespace ErnestDefoe\Docket\Integration;

use Flarum\Foundation\AbstractServiceProvider;

/** Registered only while flarum/tags is enabled (extend.php). */
class TagsServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(Tags::class, FlarumTags::class);
    }
}
