<?php

namespace ErnestDefoe\Docket\Tests\integration\api;

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Who sees which report. The oracle is flarum/flags itself: a report is seen
 * by exactly the people who see the flags on its post.
 */
class VisibilityTest extends DocketTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A flag on every post, hidden and restricted ones included, raised
        // straight into the table (members can't flag what they can't see),
        // then filed by opening the report centre.
        foreach ([1, 2, 3, 4, 5] as $post) {
            $this->database()->table('flags')->insert(['post_id' => $post, 'user_id' => self::REPORTER, 'type' => 'user', 'reason' => 'spam', 'created_at' => Carbon::now()]);
        }

        $this->get('/api/docket-counts', self::ADMIN);
    }

    /** @return int[] */
    protected function flaggedPostIds(int $as): array
    {
        $response = $this->get('/api/flags', $as);
        $ids = array_map(fn ($f) => (int) $f['relationships']['post']['data']['id'], $this->json($response)['data'] ?? []);
        sort($ids);

        return array_values(array_unique($ids));
    }

    /** @return int[] */
    protected function reportedPostIds(int $as): array
    {
        $response = $this->get('/api/docket-reports', $as);
        $ids = array_map(fn ($r) => (int) $r['relationships']['post']['data']['id'], $this->json($response)['data'] ?? []);
        sort($ids);

        return $ids;
    }

    public static function actors(): array
    {
        return [
            'admin' => [self::ADMIN, [1, 2, 3, 4, 5]],
            'global moderator' => [self::MOD, [1, 2, 3, 4, 5]],
            'Games-only moderator' => [self::TAGMOD, [2]],
            'member who reported them' => [self::REPORTER, []],
            'other member' => [self::OTHER, []],
        ];
    }

    #[Test]
    #[DataProvider('actors')]
    public function reports_are_seen_by_exactly_who_sees_the_flags(int $actor, array $expected): void
    {
        $this->assertSame($expected, $this->flaggedPostIds($actor), 'flarum/flags agrees with the expectation');
        $this->assertSame($expected, $this->reportedPostIds($actor));
    }

    #[Test]
    public function guests_get_nothing(): void
    {
        $this->assertSame(401, $this->get('/api/docket-reports')->getStatusCode());
        $this->assertSame(401, $this->get('/api/docket-counts')->getStatusCode());
        $this->assertSame(401, $this->get('/api/docket-entries')->getStatusCode());
    }

    #[Test]
    public function a_report_out_of_sight_is_not_found_whatever_is_asked(): void
    {
        $staffReport = $this->reportId(3);

        foreach ([self::TAGMOD, self::REPORTER, self::OTHER] as $actor) {
            $this->assertSame(404, $this->get("/api/docket-reports/$staffReport", $actor)->getStatusCode(), "show as $actor");
            $this->assertSame(404, $this->updateReport($staffReport, $actor, ['status' => 'resolved'])->getStatusCode(), "close as $actor");
            $this->assertSame(404, $this->updateReport($staffReport, $actor, [], ['assignee' => ['data' => ['type' => 'users', 'id' => (string) $actor]]])->getStatusCode(), "claim as $actor");

            $comment = $this->post('/api/docket-events', $actor, ['data' => ['type' => 'docket-events', 'attributes' => ['body' => 'Sneaky'], 'relationships' => ['report' => ['data' => ['type' => 'docket-reports', 'id' => (string) $staffReport]]]]]);
            $this->assertContains($comment->getStatusCode(), [403, 404, 422], "comment as $actor");
        }

        $this->assertSame(0, $this->database()->table('docket_events')->where('type', 'comment')->count());
        $this->assertSame('open', $this->database()->table('docket_reports')->where('id', $staffReport)->value('status'));
    }

    #[Test]
    public function members_cannot_reach_the_report_centre_endpoints(): void
    {
        $this->assertSame(403, $this->get('/api/docket-counts', self::REPORTER)->getStatusCode());
        $this->assertSame([], $this->json($this->get('/api/docket-queues', self::REPORTER))['data']);
        $this->assertSame(403, $this->get('/api/docket-rules', self::MOD)->getStatusCode());
    }

    #[Test]
    public function the_counts_cover_only_what_you_can_see(): void
    {
        $this->assertSame(1, $this->json($this->get('/api/docket-counts', self::TAGMOD))['open']);
        $this->assertSame(5, $this->json($this->get('/api/docket-counts', self::MOD))['open']);
    }

    #[Test]
    public function a_report_on_a_deleted_post_is_kept_to_forum_wide_moderators(): void
    {
        $games = $this->reportId(2);
        $this->database()->table('posts')->where('id', 2)->delete();

        $this->assertSame(200, $this->get("/api/docket-reports/$games", self::MOD)->getStatusCode());
        $this->assertSame(404, $this->get("/api/docket-reports/$games", self::TAGMOD)->getStatusCode());
    }

    #[Test]
    public function the_filters_narrow_and_reject_nonsense(): void
    {
        $this->assertSame([$this->reportId(2)], $this->reportIds(self::MOD, ['post' => '2']));
        $this->assertCount(5, $this->reportIds(self::MOD, ['user' => (string) self::AUTHOR]));
        $this->assertSame([], $this->reportIds(self::MOD, ['status' => 'resolved']));
        $this->assertCount(5, $this->reportIds(self::MOD, ['status' => 'open,in_progress']));
        $this->assertSame([], $this->reportIds(self::MOD, ['status' => "open') OR 1=1 --"]));
        $this->assertCount(5, $this->reportIds(self::MOD, ['assignee' => 'none']));
        $this->assertSame([], $this->reportIds(self::MOD, ['assignee' => 'me']));
        $this->assertCount(5, $this->reportIds(self::MOD, ['queue' => 'none']));
        // An unknown filter is ignored, as on every Flarum list.
        $this->assertCount(5, $this->reportIds(self::MOD, ['nope' => '1']));
    }
}
