<?php

namespace ErnestDefoe\Docket\Tests\integration\api;

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * The optional extensions: flarum/approval, flarum/suspend,
 * fof/moderator-warnings and fof/byobu.
 */
class IntegrationsTest extends DocketTestCase
{
    protected array $enable = ['flarum-flags', 'flarum-tags', 'flarum-approval', 'flarum-suspend', 'fof-moderator-warnings', 'fof-byobu', 'ernestdefoe-docket'];

    protected function events(int $report): array
    {
        return $this->database()->table('docket_events')->where('report_id', $report)->orderBy('id')->pluck('type')->all();
    }

    #[Test]
    public function a_post_awaiting_approval_gets_a_report_and_approving_resolves_it(): void
    {
        // Members need approval to reply in General.
        $this->database()->table('group_permission')->where('permission', 'like', '%WithoutApproval')->delete();

        $response = $this->post('/api/posts', self::REPORTER, ['data' => ['type' => 'posts', 'attributes' => ['content' => 'Buy cheap watches'], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]]]]);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $postId = (int) $this->json($response)['data']['id'];

        $report = $this->database()->table('docket_reports')->where('post_id', $postId)->first();
        $this->assertNotNull($report, 'the approval flag was filed');
        $this->assertSame('approval', $this->database()->table('docket_entries')->where('report_id', $report->id)->value('type'));

        // Approving from the flag bar sends the approval and the dismissal.
        $this->patch("/api/posts/$postId", self::MOD, ['data' => ['type' => 'posts', 'id' => (string) $postId, 'attributes' => ['isApproved' => true]]]);
        $this->send($this->request('DELETE', "/api/posts/$postId/flags", ['authenticatedAs' => self::MOD]));

        $this->assertContains('post_approved', $this->events($report->id));
        $this->assertSame('resolved', $this->database()->table('docket_reports')->where('id', $report->id)->value('status'));
    }

    #[Test]
    public function a_warning_on_the_reported_post_is_logged_on_its_report(): void
    {
        $this->flag(1, self::REPORTER);
        $id = $this->reportId(1);

        $response = $this->post('/api/warnings', self::MOD, ['data' => ['type' => 'warnings', 'attributes' => ['userId' => self::AUTHOR, 'publicComment' => 'Keep it civil', 'strikes' => 2], 'relationships' => ['post' => ['data' => ['type' => 'posts', 'id' => '1']]]]]);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $event = $this->database()->table('docket_events')->where('report_id', $id)->where('type', 'warning')->first();
        $this->assertNotNull($event);
        $this->assertSame(['strikes' => 2], json_decode($event->data, true));
        $this->assertSame(self::MOD, (int) $event->user_id);
    }

    #[Test]
    public function suspending_the_author_is_logged_on_their_open_reports(): void
    {
        $this->flag(1, self::REPORTER);
        $this->flag(2, self::REPORTER);

        $response = $this->patch('/api/users/'.self::AUTHOR, self::ADMIN, ['data' => ['type' => 'users', 'id' => (string) self::AUTHOR, 'attributes' => ['suspendedUntil' => Carbon::now()->addDay()->toIso8601String()]]]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $this->assertSame(2, $this->database()->table('docket_events')->where('type', 'suspended')->count());
    }

    #[Test]
    public function reports_in_private_discussions_follow_byobu(): void
    {
        $now = Carbon::now();
        // The discussion first without its post, then the post, then the link
        // between them: the foreign keys go both ways.
        $this->database()->table('discussions')->insert(['id' => 6, 'title' => 'Private talk', 'slug' => 'private', 'user_id' => self::AUTHOR, 'created_at' => $now, 'last_posted_at' => $now, 'comment_count' => 1, 'participant_count' => 1, 'last_post_number' => 1, 'is_private' => 1]);
        $this->database()->table('posts')->insert(['id' => 6, 'discussion_id' => 6, 'user_id' => self::AUTHOR, 'type' => 'comment', 'content' => '<t><p>Just us</p></t>', 'number' => 1, 'created_at' => $now]);
        $this->database()->table('discussions')->where('id', 6)->update(['first_post_id' => 6, 'last_post_id' => 6]);
        $this->database()->table('recipients')->insert([
            ['discussion_id' => 6, 'user_id' => self::AUTHOR, 'created_at' => $now, 'updated_at' => $now],
            ['discussion_id' => 6, 'user_id' => self::REPORTER, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $this->assertSame(201, $this->flag(6, self::REPORTER)->getStatusCode());
        $id = $this->reportId(6);

        foreach ([self::ADMIN, self::MOD, self::MOD2, self::TAGMOD, self::OTHER, self::REPORTER] as $actor) {
            $flagged = array_map(fn ($f) => (int) $f['relationships']['post']['data']['id'], $this->json($this->get('/api/flags', $actor))['data'] ?? []);
            $seesFlags = in_array(6, $flagged, true);
            $seesReport = $this->get("/api/docket-reports/$id", $actor)->getStatusCode() === 200;

            $this->assertSame($seesFlags, $seesReport, "actor $actor: the report follows the flags");
        }

        $this->assertSame(200, $this->get("/api/docket-reports/$id", self::ADMIN)->getStatusCode(), 'not vacuous: someone sees it');
        $this->assertSame(404, $this->get("/api/docket-reports/$id", self::OTHER)->getStatusCode());
        $this->assertSame(404, $this->get("/api/docket-reports/$id", self::REPORTER)->getStatusCode());
    }
}
