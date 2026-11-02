<?php

namespace ErnestDefoe\Docket\Api;

use Carbon\Carbon;
use ErnestDefoe\Docket\Report;
use ErnestDefoe\Docket\ReportEvent;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context;

/**
 * A report's timeline. Staff add notes to it; everything else in it is
 * written by the extension.
 *
 * @extends AbstractDatabaseResource<ReportEvent>
 */
class EventResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'docket-events';
    }

    public function model(): string
    {
        return ReportEvent::class;
    }

    public function scope(Builder $query, Context $context): void
    {
        $query->whereHas('report', fn (Builder $report) => $report->whereVisibleTo($context->getActor()));
    }

    public function newModel(Context $context): object
    {
        $event = new ReportEvent();

        if ($context->creating(self::class)) {
            $event->type = ReportEvent::COMMENT;
            $event->user_id = $context->getActor()->id;
            $event->created_at = Carbon::now();
        }

        return $event;
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Create::make()
                ->authenticated()
                ->defaultInclude(['user']),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('type'),
            Schema\Arr::make('data')->nullable(),
            Schema\Str::make('body')
                ->nullable()
                ->writableOnCreate()
                ->requiredOnCreate()
                ->minLength(1)
                ->maxLength(5000)
                ->set(fn (ReportEvent $event, string $value) => $event->body = trim($value)),
            Schema\DateTime::make('createdAt'),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable()
                ->nullable(),
            // Written once, on a new note; the report is looked up as the
            // actor sees reports, so a note can't be put on one they can't see.
            Schema\Relationship\ToOne::make('report')
                ->type('docket-reports')
                ->writableOnCreate()
                ->requiredOnCreate()
                ->set(fn (ReportEvent $event, Report $report) => $event->report_id = $report->id),
        ];
    }

    public function created(object $model, Context $context): ?object
    {
        Report::query()->whereKey($model->report_id)->update(['updated_at' => Carbon::now()]);

        return parent::created($model, $context);
    }
}
