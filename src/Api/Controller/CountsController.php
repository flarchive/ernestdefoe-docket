<?php

namespace ErnestDefoe\Docket\Api\Controller;

use ErnestDefoe\Docket\Docket;
use ErnestDefoe\Docket\Report;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The numbers in the report centre's side bar: open and in-progress reports
 * by queue, and how many you hold. Two grouped queries.
 */
class CountsController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertPermission($actor->hasPermissionLike('discussion.viewFlags'));

        resolve(Docket::class)->backfill();

        $rows = Report::whereVisibleTo($actor)
            ->whereIn('docket_reports.status', Report::ACTIVE)
            ->select(['docket_reports.queue_id', 'docket_reports.status'])
            ->selectRaw('count(*) as aggregate')
            ->groupBy('docket_reports.queue_id', 'docket_reports.status')
            ->toBase()
            ->get();

        $counts = ['open' => 0, 'in_progress' => 0, 'queues' => []];

        foreach ($rows as $row) {
            $counts[$row->status] += (int) $row->aggregate;
            $queue = (string) ($row->queue_id ?? 0);
            $counts['queues'][$queue] = ($counts['queues'][$queue] ?? 0) + (int) $row->aggregate;
        }

        $counts['mine'] = Report::whereVisibleTo($actor)
            ->whereIn('docket_reports.status', Report::ACTIVE)
            ->where('docket_reports.assignee_id', $actor->id)
            ->count();

        return new JsonResponse($counts);
    }
}
