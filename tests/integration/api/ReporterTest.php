<?php

namespace ErnestDefoe\Docket\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Docket\Crowd\Crowd;
use PHPUnit\Framework\Attributes\Test;

/**
 * The reports a member made, and their record, for staff; and how far back
 * a member's own "My reports" goes.
 *
 * REPORTER's reports, by age (report id: status):
 *
 *   10 days   101 resolved, 102 rejected, 103 open, 104 resolved but the
 *             crowd-hidden post was restored (counts as rejected)
 *   60 days   105 resolved, 106 in progress (both in Games)
 *   200 days  107 rejected
 *   500 days  108 resolved, 109 resolved
 *   400 days  110: a post awaiting approval, raised "as" REPORTER: not a
 *             report they made with the flag form, so not counted
 *
 * Every report is on post 1 (General) but 105 and 106, on post 2 (Games):
 * TAGMOD sees only those two. OTHER made 111.
 */
class ReporterTest extends DocketTestCase
{
    protected function seed(array $rows): array
    {
        $now = Carbon::now();
        $reports = [
            101 => [10, 1, 'resolved', null], 102 => [10, 1, 'rejected', null], 103 => [10, 1, 'open', null],
            104 => [10, 1, 'resolved', Crowd::RESTORED], 105 => [60, 2, 'resolved', null], 106 => [60, 2, 'in_progress', null],
            107 => [200, 1, 'rejected', null], 108 => [500, 1, 'resolved', null], 109 => [500, 1, 'resolved', null],
            110 => [400, 1, 'resolved', null], 111 => [5, 1, 'rejected', null],
        ];

        foreach ($reports as $id => [$days, $post, $status, $state]) {
            $at = $now->copy()->subDays($days);
            $rows['docket_reports'][] = ['id' => $id, 'post_id' => $post, 'discussion_id' => $post, 'user_id' => self::AUTHOR, 'status' => $status, 'crowd_state' => $state, 'entry_count' => 1, 'created_at' => $at, 'updated_at' => $at];
            $rows['docket_entries'][] = [
                'id' => $id, 'report_id' => $id, 'post_id' => $post,
                'user_id' => $id === 111 ? self::OTHER : self::REPORTER,
                'type' => $id === 110 ? 'approval' : 'user', 'reason' => 'spam', 'created_at' => $at,
            ];
        }

        return $rows;
    }

    protected function reportsBy(int $member, ?int $as): \Psr\Http\Message\ResponseInterface
    {
        return $this->get('/api/docket-entries', $as, ['filter' => ['reporter' => (string) $member]]);
    }

    #[Test]
    public function staff_list_the_reports_a_member_made_newest_first(): void
    {
        $response = $this->reportsBy(self::REPORTER, self::MOD);
        $this->assertSame(200, $response->getStatusCode());
        $body = $this->json($response);

        // Newest first; reports made at the same moment, the later one first.
        $ids = array_map(fn ($e) => (int) $e['attributes']['reportId'], $body['data']);
        $this->assertSame([104, 103, 102, 101, 106, 105, 107, 109, 108], $ids, '110 was not made with the flag form, 111 by someone else');

        $byId = array_column(array_map(fn ($e) => $e['attributes'], $body['data']), null, 'reportId');
        $this->assertSame('restored', $byId[104]['verdict']);
        $this->assertSame('in_progress', $byId[106]['verdict']);
        $this->assertSame('rejected', $byId[102]['verdict']);
        $this->assertSame('General chat', $byId[101]['post']['title']);
        $this->assertArrayNotHasKey('reportsDays', $body['meta']);
    }

    #[Test]
    public function members_and_guests_cannot_list_anyones_reports(): void
    {
        $this->assertSame(403, $this->reportsBy(self::REPORTER, self::OTHER)->getStatusCode());
        $this->assertSame(403, $this->reportsBy(self::OTHER, self::REPORTER)->getStatusCode());
        $this->assertSame(403, $this->reportsBy(self::REPORTER, self::REPORTER)->getStatusCode(), 'not even their own, staff view');
        $this->assertSame(401, $this->reportsBy(self::REPORTER, null)->getStatusCode());
        $this->assertSame(403, $this->get('/api/docket-entries', self::OTHER, ['filter' => ['-reporter' => (string) self::OTHER]])->getStatusCode());

        // Their own list says nothing staff-only.
        $own = $this->json($this->get('/api/docket-entries', self::REPORTER));
        $this->assertCount(10, $own['data']);
        $this->assertArrayNotHasKey('reporterStats', $own['meta']);
        foreach ($own['data'] as $entry) {
            $this->assertArrayNotHasKey('reportId', $entry['attributes']);
            $this->assertArrayNotHasKey('verdict', $entry['attributes']);
        }
    }

    #[Test]
    public function the_record_is_counted_for_each_time_frame(): void
    {
        $stats = $this->json($this->reportsBy(self::REPORTER, self::MOD))['meta']['reporterStats'];

        $this->assertSame([
            '30d' => ['total' => 4, 'resolved' => 1, 'rejected' => 2, 'open' => 1],
            '90d' => ['total' => 6, 'resolved' => 2, 'rejected' => 2, 'open' => 2],
            '12m' => ['total' => 7, 'resolved' => 2, 'rejected' => 3, 'open' => 2],
            'all' => ['total' => 9, 'resolved' => 4, 'rejected' => 3, 'open' => 2],
        ], $stats);
    }

    #[Test]
    public function the_record_agrees_with_the_trust_weighting(): void
    {
        $stats = $this->json($this->reportsBy(self::REPORTER, self::ADMIN))['meta']['reporterStats']['all'];

        // Trust: 0.5 + (upheld + 1) / (upheld + wrong + 2).
        $weight = $this->app()->getContainer()->make(Crowd::class)->weights([self::REPORTER])[self::REPORTER];
        $this->assertEqualsWithDelta(0.5 + ($stats['resolved'] + 1) / ($stats['resolved'] + $stats['rejected'] + 2), $weight, 0.0001);
    }

    #[Test]
    public function a_tag_moderator_sees_only_the_reports_they_may_see(): void
    {
        $body = $this->json($this->reportsBy(self::REPORTER, self::TAGMOD));

        $this->assertEqualsCanonicalizing([105, 106], array_map(fn ($e) => (int) $e['attributes']['reportId'], $body['data']));
        $this->assertSame(['total' => 2, 'resolved' => 1, 'rejected' => 0, 'open' => 1], $body['meta']['reporterStats']['all']);
        $this->assertSame(['total' => 0, 'resolved' => 0, 'rejected' => 0, 'open' => 0], $body['meta']['reporterStats']['30d']);
    }

    #[Test]
    public function a_report_shows_each_reporters_record(): void
    {
        $body = $this->json($this->get('/api/docket-reports/101', self::MOD));

        $this->assertSame(['total' => 4, 'resolved' => 1, 'rejected' => 2, 'open' => 1], $body['meta']['reporterStats'][(string) self::REPORTER]['30d']);
        $this->assertCount(1, $body['meta']['reporterStats']);

        $this->assertSame(404, $this->get('/api/docket-reports/101', self::OTHER)->getStatusCode());
    }

    #[Test]
    public function my_reports_goes_back_forever_by_default(): void
    {
        $body = $this->json($this->get('/api/docket-entries', self::REPORTER));

        $this->assertCount(10, $body['data']);
        $this->assertSame(0, $body['meta']['reportsDays']);
    }

    #[Test]
    public function my_reports_goes_back_only_as_far_as_the_setting_on_the_server(): void
    {
        $this->setting('ernestdefoe-docket.my_reports_days', '90');

        $body = $this->json($this->get('/api/docket-entries', self::REPORTER, ['page' => ['limit' => 50]]));
        $this->assertCount(6, $body['data'], 'older reports are not sent at all');
        $this->assertSame(90, $body['meta']['reportsDays']);

        // Asking for a later page doesn't reach past it either.
        $this->assertCount(0, $this->json($this->get('/api/docket-entries', self::REPORTER, ['page' => ['offset' => 6]]))['data']);

        // Staff still see the whole record.
        $this->assertCount(9, $this->json($this->reportsBy(self::REPORTER, self::MOD))['data']);
    }

    #[Test]
    public function staff_list_the_reports_a_member_raised(): void
    {
        $ids = fn (?int $as, string $key = 'reporter') => array_map('intval', array_column(
            $this->json($this->get('/api/docket-reports', $as, ['filter' => [$key => (string) self::REPORTER, 'status' => 'open,in_progress,resolved,rejected'], 'page' => ['limit' => 50]]))['data'],
            'id'
        ));

        $this->assertEqualsCanonicalizing([101, 102, 103, 104, 105, 106, 107, 108, 109], $ids(self::MOD), '110 was not raised with the form, 111 by someone else');
        $this->assertEqualsCanonicalizing([105, 106], $ids(self::TAGMOD), 'only reports they may see');
        $this->assertEqualsCanonicalizing([110, 111], $ids(self::MOD, '-reporter'));
        $this->assertSame([], $this->json($this->get('/api/docket-reports', self::OTHER, ['filter' => ['reporter' => (string) self::REPORTER]]))['data'] ?? [], 'members see no reports');
    }
}
