<?php

use ErnestDefoe\Docket\Access;
use ErnestDefoe\Docket\Api;
use ErnestDefoe\Docket\Crowd\Address;
use ErnestDefoe\Docket\Docket;
use ErnestDefoe\Docket\DocketServiceProvider;
use ErnestDefoe\Docket\Entry;
use ErnestDefoe\Docket\Extend\BackfillOnEnable;
use ErnestDefoe\Docket\Integration;
use ErnestDefoe\Docket\Listener\ApprovalDisabled;
use ErnestDefoe\Docket\Listener\RecordActivity;
use ErnestDefoe\Docket\Notification\CrowdActionBlueprint;
use ErnestDefoe\Docket\Notification\ReportAssignedBlueprint;
use ErnestDefoe\Docket\Notification\ReportOutcomeBlueprint;
use ErnestDefoe\Docket\QueueAccess;
use ErnestDefoe\Docket\Report;
use ErnestDefoe\Docket\Search;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\ForumResource;
use Flarum\Discussion\Event\Restored as DiscussionRestored;
use Flarum\Extend;
use Flarum\Extension\Event\Disabled as ExtensionDisabled;
use Flarum\Flags\Api\Resource\FlagResource;
use Flarum\Flags\Event\Cleared as FlagCleared;
use Flarum\Flags\Event\Deleting as FlagDeleting;
use Flarum\Flags\Flag;
use Flarum\Forum\Content\AssertRegistered;
use Flarum\Group\Event\Deleted as GroupDeleted;
use Flarum\Post\Event\Deleted as PostDeleted;
use Flarum\Post\Event\Hidden as PostHidden;
use Flarum\Post\Event\Restored as PostRestored;
use Flarum\Search\Database\DatabaseSearchDriver;
use Flarum\User\Event\Deleting as UserDeleting;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/reports', 'docket', AssertRegistered::class)
        ->route('/reports/{id:\d+}', 'docket.report', AssertRegistered::class)
        ->route('/my-reports', 'docket.mine', AssertRegistered::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\View())
        ->namespace('ernestdefoe-docket', __DIR__.'/views'),

    (new Extend\ServiceProvider())
        ->register(DocketServiceProvider::class),

    new BackfillOnEnable(),

    new Extend\ApiResource(Api\ReportResource::class),
    new Extend\ApiResource(Api\EntryResource::class),
    new Extend\ApiResource(Api\EventResource::class),
    new Extend\ApiResource(Api\QueueResource::class),
    new Extend\ApiResource(Api\RuleResource::class),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(Api\ForumFields::class),

    // Crowd moderation counts a connection once: the address a flag is sent
    // from, read on that endpoint only.
    (new Extend\ApiResource(FlagResource::class))
        ->endpoint(Endpoint\Create::class, fn (Endpoint\Create $endpoint) => $endpoint->before(function (Context $context) {
            resolve(Address::class)->filingFrom(Address::clientAddress($context->request));
        })),

    (new Extend\Settings())
        ->default('ernestdefoe-docket.crowd_enabled', false)
        ->default('ernestdefoe-docket.crowd_threshold', 3)
        ->default('ernestdefoe-docket.crowd_window', 24)
        ->default('ernestdefoe-docket.crowd_action', 'hidden')
        ->default('ernestdefoe-docket.crowd_min_age', 7)
        ->default('ernestdefoe-docket.crowd_min_posts', 5)
        ->default('ernestdefoe-docket.crowd_groups', '[]')
        ->default('ernestdefoe-docket.crowd_exempt_groups', '[]')
        ->default('ernestdefoe-docket.crowd_trust', true)
        ->default('ernestdefoe-docket.crowd_cooldown', 24)
        ->default('ernestdefoe-docket.crowd_tags', '{}')
        ->default('ernestdefoe-docket.notify_by_default', false)
        // EntrySearcher::DAYS_SETTING, by name: extend.php reads no class constants.
        ->default('ernestdefoe-docket.my_reports_days', 0),

    (new Extend\Routes('api'))
        ->get('/docket-counts', 'docket.counts', Api\Controller\CountsController::class)
        ->post('/docket-order', 'docket.order', Api\Controller\OrderController::class),

    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addSearcher(Report::class, Search\ReportSearcher::class)
        ->addFilter(Search\ReportSearcher::class, Search\StatusFilter::class)
        ->addFilter(Search\ReportSearcher::class, Search\QueueFilter::class)
        ->addFilter(Search\ReportSearcher::class, Search\AssigneeFilter::class)
        ->addFilter(Search\ReportSearcher::class, Search\UserFilter::class)
        ->addFilter(Search\ReportSearcher::class, Search\PostFilter::class)
        ->addFilter(Search\ReportSearcher::class, Search\ReportReporterFilter::class)
        ->addSearcher(Entry::class, Search\EntrySearcher::class)
        ->addFilter(Search\EntrySearcher::class, Search\ReporterFilter::class),

    (new Extend\ModelVisibility(Report::class))
        ->scope(Access\ScopeReportVisibility::class),

    (new Extend\ModelVisibility(Entry::class))
        ->scope(Access\ScopeEntryVisibility::class),

    (new Extend\ModelVisibility(Flag::class))
        ->scope(Access\ScopeFlagQueues::class),

    (new Extend\Notification())
        ->type(ReportAssignedBlueprint::class, ['alert', 'email'])
        ->type(ReportOutcomeBlueprint::class, ['alert'])
        ->type(CrowdActionBlueprint::class, ['alert', 'email']),

    (new Extend\Event())
        ->listen('eloquent.created: '.Flag::class, [RecordActivity::class, 'flagCreated'])
        ->listen(FlagCleared::class, [RecordActivity::class, 'cleared'])
        ->listen(PostHidden::class, [RecordActivity::class, 'hidden'])
        ->listen(PostRestored::class, [RecordActivity::class, 'restored'])
        ->listen(DiscussionRestored::class, [RecordActivity::class, 'discussionRestored'])
        ->listen(PostDeleted::class, [RecordActivity::class, 'deleted'])
        ->listen(FlagDeleting::class, [RecordActivity::class, 'flagDeleting'])
        ->listen(UserDeleting::class, [RecordActivity::class, 'userDeleting'])
        ->listen(GroupDeleted::class, fn () => resolve(QueueAccess::class)->forget())
        ->listen(ExtensionDisabled::class, ApprovalDisabled::class),

    (new Extend\Conditional())
        // Permission checks on each post read its discussion's tags: load
        // them for the whole page, as flarum/tags does for flags.
        ->whenExtensionEnabled('flarum-tags', fn () => [
            (new Extend\ServiceProvider())->register(Integration\TagsServiceProvider::class),
            (new Extend\ApiResource(Api\ReportResource::class))
                ->endpoint(Endpoint\Index::class, fn (Endpoint\Index $endpoint) => $endpoint->eagerLoad(['post.discussion.tags']))
                ->endpoint(Endpoint\Show::class, fn (Endpoint\Show $endpoint) => $endpoint->eagerLoadWhenIncluded(['post.discussion' => ['post.discussion.tags']])),
        ])
        ->whenExtensionEnabled('flarum-approval', fn () => [
            (new Extend\ServiceProvider())->register(Integration\ApprovalServiceProvider::class),
            (new Extend\Event())
                ->listen(\Flarum\Approval\Event\PostWasApproved::class, fn ($event) => resolve(Docket::class)->postAction($event->post->id, 'post_approved', $event->actor)),
        ])
        ->whenExtensionEnabled('flarum-suspend', fn () => [
            (new Extend\Event())
                ->listen(\Flarum\Suspend\Event\Suspended::class, fn ($event) => resolve(Docket::class)->userAction($event->user->id, 'suspended', $event->actor)),
        ])
        ->whenExtensionEnabled('fof-moderator-warnings', fn () => [
            (new Extend\Event())
                ->listen(\FoF\ModeratorWarnings\Event\WarningWasCreated::class, function ($event) {
                    $warning = $event->warning;
                    $data = ['strikes' => (int) $warning->strikes];

                    $warning->post_id
                        ? resolve(Docket::class)->postAction((int) $warning->post_id, 'warning', $event->actor, $data)
                        : resolve(Docket::class)->userAction((int) $warning->user_id, 'warning', $event->actor, $data);
                }),
        ]),
];
