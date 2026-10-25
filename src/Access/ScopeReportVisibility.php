<?php

namespace ErnestDefoe\Docket\Access;

use ErnestDefoe\Docket\Queue;
use Flarum\Extension\ExtensionManager;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * A report is seen by whoever may see the flags on its post: the post is
 * visible to them (private discussions, hidden and unapproved posts, restricted
 * tags all follow from that), and they hold "View flags" for its tags, exactly
 * as flarum/flags decides it. A queue kept to groups narrows that further.
 *
 * A report whose post is gone is seen only by those who hold "View flags"
 * forum-wide, since there are no longer any tags to check it against.
 */
class ScopeReportVisibility
{
    public function __construct(
        protected ExtensionManager $extensions
    ) {
    }

    public function __invoke(User $actor, Builder $query): void
    {
        if (! $actor->hasPermissionLike('discussion.viewFlags')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $global = $actor->hasPermission('discussion.viewFlags');
        $tags = $this->extensions->isEnabled('flarum-tags');

        $query->where(function (Builder $query) use ($actor, $global, $tags) {
            $query->where(function (Builder $query) use ($actor, $global, $tags) {
                $query->whereHas('post', fn (Builder $post) => $post->whereVisibleTo($actor));

                if ($tags) {
                    $query->where(function (Builder $query) use ($actor, $global) {
                        $query->whereHas('post.discussion.tags', function (Builder $tag) use ($actor) {
                            $tag->whereHasPermission($actor, 'discussion.viewFlags');
                        });

                        if ($global) {
                            $query->orWhereDoesntHave('post.discussion.tags');
                        }
                    });
                } elseif (! $global) {
                    $query->whereRaw('1 = 0');
                }
            });

            if ($global) {
                $query->orWhereDoesntHave('post');
            }
        });

        if ($closed = Queue::closedTo($actor)) {
            $query->where(fn (Builder $query) => $query->whereNull('docket_reports.queue_id')->orWhereNotIn('docket_reports.queue_id', $closed));
        }
    }
}
