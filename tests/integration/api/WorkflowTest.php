<?php

namespace ErnestDefoe\Docket\Tests\integration\api;

use PHPUnit\Framework\Attributes\Test;

/**
 * Claim, assign, close, reopen and comment, and who may do each.
 * Only Seniors (MOD2) may assign reports to other people; Moderators'
 * default grant of it is taken away here.
 */
class WorkflowTest extends DocketTestCase
{
    protected function seed(array $rows): array
    {
        $rows['group_permission'][] = ['permission' => 'docket.assignOthers', 'group_id' => self::SENIOR_GROUP];

        return $rows;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Moderators get it by default; here only Seniors have it.
        $this->database()->table('group_permission')->where('permission', 'docket.assignOthers')->where('group_id', 4)->delete();
    }

    protected function assignee(int $id, ?int $user): array
    {
        return ['assignee' => ['data' => $user ? ['type' => 'users', 'id' => (string) $user] : null]];
    }

    protected function row(int $id): object
    {
        return $this->database()->table('docket_reports')->where('id', $id)->first();
    }

    protected function eventTypes(int $id): array
    {
        return $this->database()->table('docket_events')->where('report_id', $id)->orderBy('id')->pluck('type')->all();
    }

    #[Test]
    public function claiming_puts_it_in_progress_and_unclaiming_reopens_it(): void
    {
        $this->flag(1, self::REPORTER);
        $id = $this->reportId(1);

        $this->assertSame(200, $this->updateReport($id, self::MOD, [], $this->assignee($id, self::MOD))->getStatusCode());
        $this->assertSame('in_progress', $this->row($id)->status);
        $this->assertSame(self::MOD, (int) $this->row($id)->assignee_id);

        $this->assertSame(200, $this->updateReport($id, self::MOD, [], $this->assignee($id, null))->getStatusCode());
        $this->assertSame('open', $this->row($id)->status);
        $this->assertNull($this->row($id)->assignee_id);

        $this->assertSame(['claimed', 'unassigned'], $this->eventTypes($id));
    }

    #[Test]
    public function setting_in_progress_claims_it_and_open_releases_it(): void
    {
        $this->flag(1, self::REPORTER);
        $id = $this->reportId(1);

        $this->updateReport($id, self::MOD, ['status' => 'in_progress']);
        $this->assertSame(self::MOD, (int) $this->row($id)->assignee_id);

        $this->updateReport($id, self::MOD, ['status' => 'open']);
        $this->assertNull($this->row($id)->assignee_id);
    }

    #[Test]
    public function only_those_allowed_may_hand_a_report_to_someone_else(): void
    {
        $this->flag(1, self::REPORTER);
        $id = $this->reportId(1);

        // MOD may not give it to MOD2.
        $this->assertSame(422, $this->updateReport($id, self::MOD, [], $this->assignee($id, self::MOD2))->getStatusCode());

        // MOD2 may give it to MOD, and MOD then holds it.
        $this->assertSame(200, $this->updateReport($id, self::MOD2, [], $this->assignee($id, self::MOD))->getStatusCode());
        $this->assertSame(self::MOD, (int) $this->row($id)->assignee_id);

        // MOD2 holds one; MOD may not take it, release it, or bounce it to open.
        $this->updateReport($id, self::MOD2, [], $this->assignee($id, self::MOD2));
        $this->assertSame(422, $this->updateReport($id, self::MOD, [], $this->assignee($id, self::MOD))->getStatusCode());
        $this->assertSame(422, $this->updateReport($id, self::MOD, [], $this->assignee($id, null))->getStatusCode());
        $this->assertSame(422, $this->updateReport($id, self::MOD, ['status' => 'open'])->getStatusCode());
        $this->assertSame(self::MOD2, (int) $this->row($id)->assignee_id);

        // But anyone who can see it may close it.
        $this->assertSame(200, $this->updateReport($id, self::MOD, ['status' => 'rejected'])->getStatusCode());
    }

    #[Test]
    public function a_report_cannot_go_to_someone_who_cannot_see_it(): void
    {
        $this->flag(1, self::REPORTER);
        $id = $this->reportId(1);

        // TAGMOD sees flags in Games only; REPORTER sees no flags at all.
        foreach ([self::TAGMOD, self::REPORTER] as $user) {
            $response = $this->updateReport($id, self::MOD2, [], $this->assignee($id, $user));
            $this->assertSame(422, $response->getStatusCode());
        }

        $this->assertNull($this->row($id)->assignee_id);
    }

    #[Test]
    public function assigning_someone_else_notifies_them_and_claiming_does_not(): void
    {
        $this->flag(1, self::REPORTER);
        $id = $this->reportId(1);

        $this->updateReport($id, self::MOD2, [], $this->assignee($id, self::MOD2));
        $this->assertSame(0, $this->database()->table('notifications')->where('type', 'docketAssigned')->count());

        $this->updateReport($id, self::MOD2, [], $this->assignee($id, self::MOD));
        $notification = $this->database()->table('notifications')->where('type', 'docketAssigned')->first();
        $this->assertSame(self::MOD, (int) $notification->user_id);

        // It renders in their notification list.
        $list = $this->json($this->get('/api/notifications', self::MOD));
        $this->assertSame('docketAssigned', $list['data'][0]['attributes']['contentType']);
        $this->assertSame((string) $id, $list['data'][0]['relationships']['subject']['data']['id']);
    }

    #[Test]
    public function closing_with_a_message_tells_each_reporter_once_and_only_them(): void
    {
        $this->flag(1, self::REPORTER);
        $this->flag(1, self::OTHER);
        $this->flag(1, self::MOD2);
        $id = $this->reportId(1);

        $this->post('/api/docket-events', self::MOD, ['data' => ['type' => 'docket-events', 'attributes' => ['body' => 'Staff only: the author is a repeat offender'], 'relationships' => ['report' => ['data' => ['type' => 'docket-reports', 'id' => (string) $id]]]]]);

        $response = $this->updateReport($id, self::MOD2, ['status' => 'resolved', 'notifyReporters' => true, 'outcome' => 'We removed it. Thanks!']);
        $this->assertSame(200, $response->getStatusCode());

        $told = $this->database()->table('notifications')->where('type', 'docketOutcome')->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $this->assertSame([self::REPORTER, self::OTHER], $told, 'not the moderator who closed it');

        // What the reporter sees: their own entry, the status, the message.
        $mine = $this->json($this->get('/api/docket-entries', self::REPORTER));
        $this->assertCount(1, $mine['data']);
        $this->assertSame('resolved', $mine['data'][0]['attributes']['reportStatus']);
        $this->assertSame('We removed it. Thanks!', $mine['data'][0]['attributes']['outcome']);

        // ...and nothing staff-only.
        $raw = (string) $this->get('/api/docket-entries', self::REPORTER)->getBody();
        $this->assertStringNotContainsString('repeat offender', $raw);
        $this->assertStringNotContainsString('moddy', $raw);
        $this->assertStringNotContainsString('senior', $raw);

        $notifications = (string) $this->get('/api/notifications', self::REPORTER)->getBody();
        $this->assertStringNotContainsString('repeat offender', $notifications);
        $this->assertStringNotContainsString('senior', $notifications);
    }

    #[Test]
    public function closing_without_telling_shows_reporters_only_that_it_closed(): void
    {
        $this->flag(1, self::REPORTER);
        $id = $this->reportId(1);

        $this->updateReport($id, self::MOD, ['status' => 'rejected', 'outcome' => 'Not sent']);

        $this->assertSame(0, $this->database()->table('notifications')->where('type', 'docketOutcome')->count());
        $mine = $this->json($this->get('/api/docket-entries', self::REPORTER))['data'][0]['attributes'];
        $this->assertSame('closed', $mine['reportStatus']);
        $this->assertNull($mine['outcome']);
    }

    #[Test]
    public function reporters_see_only_their_own_entries_and_only_posts_they_still_can(): void
    {
        $this->flag(1, self::REPORTER);
        $this->flag(1, self::OTHER);
        $this->flag(2, self::OTHER);

        $this->assertCount(1, $this->json($this->get('/api/docket-entries', self::REPORTER))['data']);
        $this->assertCount(2, $this->json($this->get('/api/docket-entries', self::OTHER))['data']);

        // Post 1 is hidden afterwards: the reporter keeps the entry, not the post.
        $hide = $this->patch('/api/posts/1', self::MOD, ['data' => ['type' => 'posts', 'id' => '1', 'attributes' => ['isHidden' => true]]]);
        $this->assertSame(200, $hide->getStatusCode(), (string) $hide->getBody());
        $this->assertNotNull($this->database()->table('posts')->where('id', 1)->value('hidden_at'));
        $this->assertSame(404, $this->get('/api/posts/1', self::REPORTER)->getStatusCode(), 'the reporter can no longer see the post');
        $entries = $this->json($this->get('/api/docket-entries', self::REPORTER))['data'];
        $this->assertNull($entries[0]['attributes']['post']);

        // Post 2 they can still see: a link and its discussion's title.
        $other = $this->json($this->get('/api/docket-entries', self::OTHER))['data'];
        $places = array_column(array_column($other, 'attributes'), 'post');
        $this->assertContains(['slug' => '2-d2', 'title' => 'Games chat', 'number' => 1], $places);
    }

    #[Test]
    public function reopening_clears_the_verdict(): void
    {
        $this->flag(1, self::REPORTER);
        $id = $this->reportId(1);

        $this->updateReport($id, self::MOD, ['status' => 'resolved', 'notifyReporters' => true, 'outcome' => 'Done']);
        $this->assertSame(200, $this->updateReport($id, self::MOD, ['status' => 'in_progress'])->getStatusCode());

        $row = $this->row($id);
        $this->assertSame('in_progress', $row->status);
        $this->assertNull($row->closed_at);
        $this->assertNull($row->outcome);
        $this->assertSame(self::MOD, (int) $row->assignee_id);
        $this->assertSame('in_progress', $this->json($this->get('/api/docket-entries', self::REPORTER))['data'][0]['attributes']['reportStatus']);
    }

    #[Test]
    public function a_closed_report_cannot_be_assigned_or_moved(): void
    {
        $this->flag(1, self::REPORTER);
        $id = $this->reportId(1);
        $this->updateReport($id, self::MOD, ['status' => 'resolved']);

        $this->assertSame(422, $this->updateReport($id, self::MOD, [], $this->assignee($id, self::MOD))->getStatusCode());
    }

    #[Test]
    public function staff_notes_need_a_body_and_land_on_the_timeline(): void
    {
        $this->flag(1, self::REPORTER);
        $id = $this->reportId(1);
        $note = fn (string $body) => $this->post('/api/docket-events', self::MOD, ['data' => ['type' => 'docket-events', 'attributes' => ['body' => $body], 'relationships' => ['report' => ['data' => ['type' => 'docket-reports', 'id' => (string) $id]]]]]);

        $this->assertSame(422, $note('')->getStatusCode());
        $this->assertSame(422, $note(str_repeat('a', 5001))->getStatusCode());
        $this->assertSame(201, $note('<script>alert(1)</script> checked the history')->getStatusCode());

        $show = $this->json($this->get("/api/docket-reports/$id", self::MOD));
        $bodies = array_column(array_column(array_filter($show['included'], fn ($i) => $i['type'] === 'docket-events'), 'attributes'), 'body');
        $this->assertSame(['<script>alert(1)</script> checked the history'], $bodies, 'stored as text; the frontend escapes it');
    }

    #[Test]
    public function an_unknown_status_is_refused(): void
    {
        $this->flag(1, self::REPORTER);
        $id = $this->reportId(1);

        $this->assertSame(422, $this->updateReport($id, self::MOD, ['status' => 'deleted'])->getStatusCode());
    }

    #[Test]
    public function members_can_neither_list_nor_write_reports(): void
    {
        $this->flag(1, self::REPORTER);
        $id = $this->reportId(1);

        $this->assertSame([], $this->reportIds(self::REPORTER));
        $this->assertSame(404, $this->updateReport($id, self::REPORTER, ['status' => 'rejected'])->getStatusCode());
    }
}
