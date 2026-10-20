<?php

use ErnestDefoe\Docket\Access;
use ErnestDefoe\Docket\Api;
use ErnestDefoe\Docket\Docket;
use ErnestDefoe\Docket\DocketServiceProvider;
use ErnestDefoe\Docket\Entry;
use ErnestDefoe\Docket\Listener\RecordActivity;
use ErnestDefoe\Docket\Notification\ReportAssignedBlueprint;
use ErnestDefoe\Docket\Notification\ReportOutcomeBlueprint;
use ErnestDefoe\Docket\Report;
use ErnestDefoe\Docket\Search;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Flags\Flag;
use Flarum\Forum\Content\AssertRegistered;
use Flarum\Search\Database\DatabaseSearchDriver;
use Flarum\Settings\SettingsRepositoryInterface;

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

    new Extend\ApiResource(Api\ReportResource::class),
    new Extend\ApiResource(Api\EntryResource::class),
    new Extend\ApiResource(Api\EventResource::class),
    new Extend\ApiResource(Api\QueueResource::class),
    new Extend\ApiResource(Api\RuleResource::class),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('docketNotifyByDefault')
                ->visible(fn (object $forum, Context $context) => $context->getActor()->hasPermissionLike('discussion.viewFlags'))
                ->get(fn () => (bool) resolve(SettingsRepositoryInterface::class)->get('ernestdefoe-docket.notify_by_default')),
        ]),

    (new Extend\Routes('api'))
        ->get('/docket-counts', 'docket.counts', Api\Controller\CountsController::class)
        ->post('/docket-order', 'docket.order', Api\Controller\OrderController::class),

    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addSearcher(Report::class, Search\ReportSearcher::class)
        ->addFilter(Search\ReportSearcher::class, Search\StatusFilter::class)
        ->addFilter(Search\ReportSearcher::class, Search\QueueFilter::class)
        ->addFilter(Search\ReportSearcher::class, Search\AssigneeFilter::class)
        ->addFilter(Search\ReportSearcher::class, Search\UserFilter::class)
        ->addFilter(Search\ReportSearcher::class, Search\PostFilter::class),

    (new Extend\ModelVisibility(Report::class))
        ->scope(Access\ScopeReportVisibility::class),

    (new Extend\ModelVisibility(Entry::class))
        ->scope(Access\ScopeEntryVisibility::class),

    (new Extend\ModelVisibility(Flag::class))
        ->scope(Access\ScopeFlagQueues::class),

    (new Extend\Notification())
        ->type(ReportAssignedBlueprint::class, ['alert', 'email'])
        ->type(ReportOutcomeBlueprint::class, ['alert']),

    (new Extend\Event())
        ->subscribe(RecordActivity::class),

    (new Extend\Conditional())
        // Permission checks on each post read its discussion's tags: load
        // them for the whole page, as flarum/tags does for flags.
        ->whenExtensionEnabled('flarum-tags', fn () => [
            (new Extend\ApiResource(Api\ReportResource::class))
                ->endpoint(Endpoint\Index::class, fn (Endpoint\Index $endpoint) => $endpoint->eagerLoad(['post.discussion.tags']))
                ->endpoint(Endpoint\Show::class, fn (Endpoint\Show $endpoint) => $endpoint->eagerLoadWhenIncluded(['post.discussion' => ['post.discussion.tags']])),
        ])
        ->whenExtensionEnabled('flarum-approval', fn () => [
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
