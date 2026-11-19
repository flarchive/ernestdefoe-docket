<?php

namespace ErnestDefoe\Docket\Integration;

use Flarum\Tags\Tag;

class FlarumTags implements Tags
{
    public function enabled(): bool
    {
        return true;
    }

    public function tagIds(?int $discussionId): array
    {
        if (! $discussionId) {
            return [];
        }

        return Tag::query()->whereHas('discussions', fn ($query) => $query->whereKey($discussionId))->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
