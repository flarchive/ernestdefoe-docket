<?php

namespace ErnestDefoe\Docket\Extend;

use ErnestDefoe\Docket\Job\BackfillFlags;
use Flarum\Extend\ExtenderInterface;
use Flarum\Extend\LifecycleInterface;
use Flarum\Extension\Extension;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Queue;

/**
 * Whenever Docket is enabled, file the flags it has not seen: those from
 * before it was installed, and any raised while it was disabled.
 */
class BackfillOnEnable implements ExtenderInterface, LifecycleInterface
{
    public function extend(Container $container, ?Extension $extension = null): void
    {
        // Nothing at boot: this extender acts only when Docket is enabled.
    }

    public function onEnable(Container $container, Extension $extension): void
    {
        $container->call(fn (Queue $queue) => $queue->push(new BackfillFlags()));
    }

    public function onDisable(Container $container, Extension $extension): void
    {
        // Disabling leaves the reports as they are.
    }
}
