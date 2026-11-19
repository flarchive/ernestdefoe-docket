<?php

namespace ErnestDefoe\Docket\Api;

use Flarum\Discussion\Discussion;
use Flarum\Http\SlugManager;
use Flarum\Post\Post;

/**
 * Where a post is, for a link to it: its discussion's slug (as the forum's
 * slug driver writes it) and title, and the post's number.
 */
class PostPlace
{
    public function __construct(
        protected SlugManager $slugs
    ) {
    }

    /**
     * @return array{slug: string, title: string, number: int}|null
     */
    public function of(?Post $post): ?array
    {
        $discussion = $post?->discussion;

        if (! $discussion) {
            return null;
        }

        return [
            'slug' => $this->slugs->forResource(Discussion::class)->toSlug($discussion),
            'title' => (string) $discussion->title,
            'number' => (int) $post->number,
        ];
    }
}
