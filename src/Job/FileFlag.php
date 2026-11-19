<?php

namespace ErnestDefoe\Docket\Job;

use ErnestDefoe\Docket\Docket;
use Flarum\Flags\Flag;
use Flarum\Queue\AbstractJob;

/**
 * A flag that arrived while another flag on the same post was being filed:
 * filed here, off the request, once that one is done.
 */
class FileFlag extends AbstractJob
{
    public function __construct(
        public Flag $flag,
        public bool $notify,
        public ?string $ipHash
    ) {
    }

    public function handle(Docket $docket): void
    {
        $docket->file($this->flag, $this->notify, wait: true, ipHash: $this->ipHash);
    }
}
