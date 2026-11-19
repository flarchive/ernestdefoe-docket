<?php

namespace ErnestDefoe\Docket\Integration;

/**
 * flarum/tags, as Docket uses it. Without it, NoTags; with it, FlarumTags,
 * bound in extend.php's Extend\Conditional.
 */
interface Tags
{
    public function enabled(): bool;

    /**
     * A discussion's tag ids.
     *
     * @return int[]
     */
    public function tagIds(?int $discussionId): array;
}
