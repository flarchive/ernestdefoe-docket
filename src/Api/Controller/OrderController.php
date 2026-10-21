<?php

namespace ErnestDefoe\Docket\Api\Controller;

use ErnestDefoe\Docket\Queue;
use ErnestDefoe\Docket\Rule;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Saves a drag-and-drop order of queues or rules: the ids, first to last.
 */
class OrderController implements RequestHandlerInterface
{
    public function __construct(
        protected ConnectionInterface $db
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $model = match (Arr::get($request->getQueryParams(), 'type')) {
            'queues' => Queue::class,
            'rules' => Rule::class,
            default => null,
        };

        $ids = Arr::get($request->getParsedBody(), 'order');

        if (! $model || ! is_array($ids)) {
            return new EmptyResponse(422);
        }

        $this->db->transaction(function () use ($model, $ids) {
            foreach (array_values(array_slice($ids, 0, 500)) as $position => $id) {
                $model::query()->whereKey((int) $id)->update(['position' => $position]);
            }
        });

        return new EmptyResponse(204);
    }
}
