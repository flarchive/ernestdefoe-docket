<?php

namespace ErnestDefoe\Docket\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * What the forum payload carries for Docket: for staff who see reports,
 * whether closing one tells its reporters by default.
 */
class ForumFields
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    /**
     * @return array<Schema\Boolean>
     */
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('docketNotifyByDefault')
                ->visible(fn (object $forum, Context $context) => $context->getActor()->hasPermissionLike('discussion.viewFlags'))
                ->get(fn () => (bool) $this->settings->get('ernestdefoe-docket.notify_by_default')),
        ];
    }
}
