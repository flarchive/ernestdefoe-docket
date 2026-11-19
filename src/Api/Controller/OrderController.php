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
 *
 * A handler, not a JSON:API endpoint: it changes many records at once in one
 * statement, which a resource's update of one record at a time would not.
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

        $ids = array_values(array_unique(array_map('intval', array_filter(array_slice($ids, 0, 500), 'is_numeric'))));

        if ($ids) {
            // One statement: every id and position is an integer, so they
            // are written in.
            $column = $this->db->getQueryGrammar()->wrap('id');
            $cases = implode(' ', array_map(fn (int $id, int $position) => "when $id then $position", $ids, array_keys($ids)));

            $model::query()->whereIn('id', $ids)->update(['position' => $this->db->raw("case $column $cases end")]);
        }

        return new EmptyResponse(204);
    }
}
