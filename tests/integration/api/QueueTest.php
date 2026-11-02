<?php

namespace ErnestDefoe\Docket\Tests\integration\api;

use PHPUnit\Framework\Attributes\Test;

/**
 * Queues: admins manage them; a queue kept to a group keeps its reports, and
 * the flags on their posts, from moderators outside that group.
 */
class QueueTest extends DocketTestCase
{
    protected function createQueue(int $as, array $attributes, array $groups = []): \Psr\Http\Message\ResponseInterface
    {
        $data = ['type' => 'docket-queues', 'attributes' => $attributes];
        if ($groups) {
            $data['relationships'] = ['groups' => ['data' => array_map(fn ($id) => ['type' => 'groups', 'id' => (string) $id], $groups)]];
        }

        return $this->post('/api/docket-queues', $as, ['data' => $data]);
    }

    protected function seniorQueue(): int
    {
        $response = $this->createQueue(self::ADMIN, ['name' => 'Seniors only'], [self::SENIOR_GROUP]);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (int) $this->json($response)['data']['id'];
    }

    protected function moveTo(int $report, ?int $queue, int $as): \Psr\Http\Message\ResponseInterface
    {
        return $this->updateReport($report, $as, [], ['queue' => ['data' => $queue ? ['type' => 'docket-queues', 'id' => (string) $queue] : null]]);
    }

    /** @return int[] */
    protected function flaggedPosts(int $as): array
    {
        return array_map(fn ($f) => (int) $f['relationships']['post']['data']['id'], $this->json($this->get('/api/flags', $as))['data']);
    }

    #[Test]
    public function only_admins_manage_queues(): void
    {
        $this->assertSame(403, $this->createQueue(self::MOD, ['name' => 'Mine'])->getStatusCode());
        $this->assertSame(201, $this->createQueue(self::ADMIN, ['name' => 'Spam', 'color' => '#e74c3c', 'description' => 'Spam and scams'])->getStatusCode());
        $this->assertSame(422, $this->createQueue(self::ADMIN, ['name' => 'Bad', 'color' => 'red;}body{display:none'])->getStatusCode());
        $this->assertSame(422, $this->createQueue(self::ADMIN, ['name' => ''])->getStatusCode());

        $id = $this->database()->table('docket_queues')->value('id');
        $this->assertSame(403, $this->patch("/api/docket-queues/$id", self::MOD, ['data' => ['type' => 'docket-queues', 'id' => (string) $id, 'attributes' => ['name' => 'Hijacked']]])->getStatusCode());
        $this->assertSame(403, $this->send($this->request('DELETE', "/api/docket-queues/$id", ['authenticatedAs' => self::MOD]))->getStatusCode());
    }

    #[Test]
    public function only_admins_see_who_a_queue_is_kept_to(): void
    {
        $queue = $this->seniorQueue();

        $asAdmin = $this->json($this->get('/api/docket-queues', self::ADMIN, ['include' => 'groups']));
        $this->assertArrayHasKey('groups', $asAdmin['data'][0]['relationships']);

        $asSenior = $this->json($this->get('/api/docket-queues', self::MOD2, ['include' => 'groups']));
        $this->assertSame((string) $queue, $asSenior['data'][0]['id']);
        $this->assertArrayNotHasKey('groups', $asSenior['data'][0]['relationships'] ?? []);

        // Kept from MOD, so not even listed.
        $this->assertSame([], $this->json($this->get('/api/docket-queues', self::MOD))['data']);
    }

    #[Test]
    public function a_kept_queue_hides_the_report_and_its_flags_from_everyone_else(): void
    {
        $queue = $this->seniorQueue();
        $this->flag(1, self::REPORTER);
        $id = $this->reportId(1);

        $this->assertSame(200, $this->moveTo($id, $queue, self::MOD2)->getStatusCode());

        // MOD is a moderator, but not a Senior.
        $this->assertSame(404, $this->get("/api/docket-reports/$id", self::MOD)->getStatusCode());
        $this->assertNotContains(1, $this->flaggedPosts(self::MOD));
        $post = $this->json($this->get('/api/posts/1', self::MOD, ['include' => 'flags']));
        $this->assertSame([], $post['data']['relationships']['flags']['data'] ?? []);
        $forum = $this->json($this->get('/api', self::MOD));
        $this->assertSame(0, $forum['data']['attributes']['flagCount']);

        // Seniors and admins still see both.
        foreach ([self::MOD2, self::ADMIN] as $actor) {
            $this->assertSame(200, $this->get("/api/docket-reports/$id", $actor)->getStatusCode());
            $this->assertContains(1, $this->flaggedPosts($actor));
        }

        // Back in the general queue, MOD sees it again.
        $this->moveTo($id, null, self::MOD2);
        $this->assertSame(200, $this->get("/api/docket-reports/$id", self::MOD)->getStatusCode());
        $this->assertContains(1, $this->flaggedPosts(self::MOD));
    }

    #[Test]
    public function you_cannot_file_a_report_in_a_queue_kept_from_you(): void
    {
        $queue = $this->seniorQueue();
        $this->flag(1, self::REPORTER);
        $id = $this->reportId(1);

        $this->assertContains($this->moveTo($id, $queue, self::MOD)->getStatusCode(), [404, 422]);
        $this->assertNull($this->database()->table('docket_reports')->where('id', $id)->value('queue_id'));
    }

    #[Test]
    public function deleting_a_queue_returns_its_reports_to_the_general_queue(): void
    {
        $queue = $this->seniorQueue();
        $this->flag(1, self::REPORTER);
        $id = $this->reportId(1);
        $this->moveTo($id, $queue, self::MOD2);

        $this->assertSame(204, $this->send($this->request('DELETE', "/api/docket-queues/$queue", ['authenticatedAs' => self::ADMIN]))->getStatusCode());

        $this->assertNull($this->database()->table('docket_reports')->where('id', $id)->value('queue_id'));
        $this->assertSame(200, $this->get("/api/docket-reports/$id", self::MOD)->getStatusCode());
    }

    #[Test]
    public function a_kept_queues_flags_cannot_be_dismissed_from_outside_it(): void
    {
        $queue = $this->seniorQueue();
        $this->flag(1, self::REPORTER);
        $this->moveTo($this->reportId(1), $queue, self::MOD2);

        $dismiss = fn (int $as) => $this->send($this->request('DELETE', '/api/posts/1/flags', ['authenticatedAs' => $as]))->getStatusCode();

        $this->assertSame(403, $dismiss(self::MOD));
        $this->assertSame(1, $this->database()->table('flags')->where('post_id', 1)->count());
        $this->assertSame(204, $dismiss(self::MOD2));
    }

    #[Test]
    public function deleting_the_only_group_a_queue_is_kept_to_keeps_it_to_admins(): void
    {
        $queue = $this->seniorQueue();
        $this->flag(1, self::REPORTER);
        $id = $this->reportId(1);
        $this->moveTo($id, $queue, self::MOD2);

        $response = $this->send($this->request('DELETE', '/api/groups/'.self::SENIOR_GROUP, ['authenticatedAs' => self::ADMIN]));
        $this->assertSame(204, $response->getStatusCode());

        // Not opened to every moderator: MOD2 lost the group, MOD never had it.
        $this->assertSame(404, $this->get("/api/docket-reports/$id", self::MOD)->getStatusCode());
        $this->assertSame(404, $this->get("/api/docket-reports/$id", self::MOD2)->getStatusCode());
        $this->assertSame(200, $this->get("/api/docket-reports/$id", self::ADMIN)->getStatusCode());
    }

    #[Test]
    public function a_deleted_holders_reports_go_back_to_open(): void
    {
        $this->flag(1, self::REPORTER);
        $id = $this->reportId(1);
        $this->updateReport($id, self::MOD2, [], ['assignee' => ['data' => ['type' => 'users', 'id' => (string) self::MOD2]]]);

        $this->assertSame(204, $this->send($this->request('DELETE', '/api/users/'.self::MOD2, ['authenticatedAs' => self::ADMIN]))->getStatusCode());

        $row = $this->database()->table('docket_reports')->where('id', $id)->first();
        $this->assertSame('open', $row->status);
        $this->assertNull($row->assignee_id);
    }

    #[Test]
    public function only_admins_reorder(): void
    {
        $a = (int) $this->json($this->createQueue(self::ADMIN, ['name' => 'A']))['data']['id'];
        $b = (int) $this->json($this->createQueue(self::ADMIN, ['name' => 'B']))['data']['id'];

        $order = fn (int $as) => $this->send($this->request('POST', '/api/docket-order', ['authenticatedAs' => $as, 'json' => ['order' => [$b, $a]]])->withQueryParams(['type' => 'queues']));

        $this->assertSame(403, $order(self::MOD)->getStatusCode());
        $this->assertSame(204, $order(self::ADMIN)->getStatusCode());
        $this->assertSame([$b, $a], $this->database()->table('docket_queues')->orderBy('position')->pluck('id')->map(fn ($id) => (int) $id)->all());
    }
}
