<?php

namespace ErnestDefoe\Docket\Integration;

class NoTags implements Tags
{
    public function enabled(): bool
    {
        return false;
    }

    public function tagIds(?int $discussionId): array
    {
        return [];
    }
}
