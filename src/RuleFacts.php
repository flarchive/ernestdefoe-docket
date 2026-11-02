<?php

namespace ErnestDefoe\Docket;

use Flarum\Flags\Flag;
use Flarum\Group\Group;
use Flarum\Post\Post;

/**
 * What a new report's rules are checked against, each looked up once and
 * only if some rule asks for it.
 */
class RuleFacts
{
    /** @var int[]|null */
    protected ?array $tagIds = null;

    /** @var int[]|null */
    protected ?array $groupIds = null;

    public function __construct(
        protected Flag $flag,
        protected Post $post,
        protected bool $tagsEnabled
    ) {
    }

    public function matches(Rule $rule): bool
    {
        $conditions = $rule->conditions ?? [];

        if (! empty($conditions['types']) && ! in_array($this->flag->type, $conditions['types'], true)) {
            return false;
        }

        if (! empty($conditions['reasons']) && ! in_array($this->reason(), $conditions['reasons'], true)) {
            return false;
        }

        if (! empty($conditions['tags']) && ! array_intersect(array_map('intval', $conditions['tags']), $this->tagIds())) {
            return false;
        }

        if (! empty($conditions['groups']) && ! array_intersect(array_map('intval', $conditions['groups']), $this->groupIds())) {
            return false;
        }

        return true;
    }

    /**
     * The flag's reason; a flag with only a written explanation counts as
     * "other", as the flag form presents it.
     */
    protected function reason(): string
    {
        return $this->flag->reason ?: 'other';
    }

    /** @return int[] */
    protected function tagIds(): array
    {
        if ($this->tagIds === null) {
            $this->tagIds = $this->tagsEnabled && $this->post->discussion_id
                ? $this->post->getConnection()->table('discussion_tag')->where('discussion_id', $this->post->discussion_id)->pluck('tag_id')->map(fn ($id) => (int) $id)->all()
                : [];
        }

        return $this->tagIds;
    }

    /**
     * The groups of the reported post's author, Members included.
     *
     * @return int[]
     */
    protected function groupIds(): array
    {
        if ($this->groupIds === null) {
            $author = $this->post->user;
            $this->groupIds = $author ? array_map('intval', array_diff($author->permissionGroupIds(), [Group::GUEST_ID])) : [];
        }

        return $this->groupIds;
    }
}
