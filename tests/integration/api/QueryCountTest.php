<?php

namespace ErnestDefoe\Docket\Tests\integration\api;

use PHPUnit\Framework\Attributes\Test;

/**
 * Lists stay flat as they grow. flarum/testing fails any request in a test
 * that runs the same query once per record, so these only need to fill a
 * page and ask for it.
 */
class QueryCountTest extends DocketTestCase
{
    /** Seven more discussions, past flarum/testing's threshold of five repeats. */
    protected const POSTS = [10, 11, 12, 13, 14, 15, 16];

    protected function seed(array $rows): array
    {
        $now = \Carbon\Carbon::now();

        foreach (self::POSTS as $id) {
            $rows['discussions'][] = ['id' => $id, 'title' => "Busy $id", 'slug' => "busy-$id", 'user_id' => self::AUTHOR, 'created_at' => $now, 'last_posted_at' => $now, 'comment_count' => 1, 'participant_count' => 1, 'first_post_id' => $id, 'last_post_id' => $id, 'last_post_number' => 1];
            $rows['posts'][] = ['id' => $id, 'discussion_id' => $id, 'user_id' => self::AUTHOR, 'type' => 'comment', 'content' => "<t><p>Post $id</p></t>", 'number' => 1, 'created_at' => $now];
            $rows['discussion_tag'][] = ['discussion_id' => $id, 'tag_id' => $id % 2 ? 1 : 2];
        }

        return $rows;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // A report in each, with two reporters, a note, a holder and an
        // outcome sent to the reporters.
        foreach (self::POSTS as $post) {
            $this->flag($post, self::REPORTER);
            $this->flag($post, self::OTHER);
            $id = $this->reportId($post);
            $this->post('/api/docket-events', self::MOD, ['data' => ['type' => 'docket-events', 'attributes' => ['body' => 'Note'], 'relationships' => ['report' => ['data' => ['type' => 'docket-reports', 'id' => (string) $id]]]]]);
            $this->updateReport($id, self::MOD, [], ['assignee' => ['data' => ['type' => 'users', 'id' => (string) self::MOD2]]]);
            $this->updateReport($id, self::MOD, ['status' => 'resolved', 'notifyReporters' => true, 'outcome' => 'Done']);
        }
    }

    #[Test]
    public function the_report_list_and_a_report_load_flat(): void
    {
        $this->assertSame(200, $this->get('/api/docket-reports', self::MOD, ['filter' => ['status' => 'resolved']])->getStatusCode());
        $this->assertSame(200, $this->get('/api/docket-reports/'.$this->reportId(10), self::MOD)->getStatusCode());
    }

    #[Test]
    public function a_reporters_list_and_notifications_load_flat(): void
    {
        $this->assertCount(7, $this->json($this->get('/api/docket-entries', self::REPORTER))['data']);

        $notifications = $this->json($this->get('/api/notifications', self::REPORTER));
        $this->assertCount(7, $notifications['data']);
        $this->assertSame('resolved', $notifications['included'][0]['attributes']['reportStatus'] ?? null);
    }

    #[Test]
    public function a_members_reports_and_record_load_flat_for_staff(): void
    {
        $body = $this->json($this->get('/api/docket-entries', self::MOD, ['filter' => ['reporter' => (string) self::REPORTER]]));

        $this->assertCount(7, $body['data']);
        $this->assertSame(7, $body['meta']['reporterStats']['all']['resolved']);
        $this->assertNotNull($body['data'][0]['attributes']['post']);
    }

    #[Test]
    public function the_moderators_notifications_load_flat(): void
    {
        $this->assertCount(7, $this->json($this->get('/api/notifications', self::MOD2))['data']);
    }
}
