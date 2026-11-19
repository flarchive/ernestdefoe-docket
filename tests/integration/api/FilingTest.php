<?php

namespace ErnestDefoe\Docket\Tests\integration\api;

use ErnestDefoe\Docket\Job\FileFlag;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Testing\Fakes\BusFake;
use PHPUnit\Framework\Attributes\Test;

class FilingTest extends DocketTestCase
{
    #[Test]
    public function a_flag_opens_a_report_and_later_flags_join_it(): void
    {
        $this->assertSame(201, $this->flag(1, self::REPORTER)->getStatusCode());
        $this->assertSame(201, $this->flag(1, self::OTHER, 'other', 'Rude')->getStatusCode());

        $reports = $this->database()->table('docket_reports')->get();
        $this->assertCount(1, $reports);
        $this->assertSame('open', $reports[0]->status);
        $this->assertSame(2, (int) $reports[0]->entry_count);
        $this->assertSame(self::AUTHOR, (int) $reports[0]->user_id);
        // Kept for other extensions (Member Desk), for when the post is gone.
        $this->assertSame(1, (int) $reports[0]->discussion_id);
        $this->assertSame('General chat', \ErnestDefoe\Docket\Report::query()->with('discussion')->findOrFail($reports[0]->id)->discussion?->title);

        $entries = $this->database()->table('docket_entries')->orderBy('id')->get();
        $this->assertSame(['spam', 'other'], $entries->pluck('reason')->all());
        $this->assertSame('Rude', $entries[1]->reason_detail);
    }

    #[Test]
    public function a_flag_that_finds_its_post_being_filed_waits_in_the_queue_not_the_request(): void
    {
        $container = $this->app()->getContainer();
        $bus = new BusFake($container->make(Dispatcher::class), [FileFlag::class]);
        $container->instance(Dispatcher::class, $bus);

        $this->flag(1, self::REPORTER);

        // Another request is filing a flag on post 1 right now.
        $held = $container->make(Cache::class)->getStore()->lock('ernestdefoe-docket.file.1', 30);
        $this->assertTrue($held->get());

        $this->assertSame(201, $this->flag(1, self::OTHER)->getStatusCode());
        $this->assertSame(1, $this->database()->table('docket_entries')->count(), 'not filed in the request');

        $jobs = $bus->dispatched(FileFlag::class);
        $this->assertCount(1, $jobs);
        $this->assertNotNull($jobs->first()->ipHash, 'the address is taken while it is still known');

        $held->release();
        $jobs->first()->handle($container->make(\ErnestDefoe\Docket\Docket::class));

        $this->assertSame(1, $this->database()->table('docket_reports')->count());
        $this->assertSame(2, (int) $this->database()->table('docket_reports')->value('entry_count'));
        $this->assertNotNull($this->database()->table('docket_entries')->orderByDesc('id')->value('ip_hash'));
    }

    #[Test]
    public function a_queued_flag_still_files_if_the_lock_is_never_released(): void
    {
        $container = $this->app()->getContainer();
        $bus = new BusFake($container->make(Dispatcher::class), [FileFlag::class]);
        $container->instance(Dispatcher::class, $bus);

        $this->flag(1, self::REPORTER);
        $flag = \Flarum\Flags\Flag::query()->firstOrFail();
        $this->database()->table('docket_entries')->delete();
        $this->database()->table('docket_reports')->delete();

        // Held by a request that died: the job gives up waiting after 5 s
        // and files under the row lock alone.
        $held = $container->make(Cache::class)->getStore()->lock('ernestdefoe-docket.file.1', 30);
        $this->assertTrue($held->get());

        (new FileFlag($flag, false, null))->handle($container->make(\ErnestDefoe\Docket\Docket::class));

        $this->assertSame(1, $this->database()->table('docket_entries')->count());
        $held->release();
    }

    #[Test]
    public function the_upgrade_merges_open_reports_a_post_already_had_into_the_newest(): void
    {
        $now = \Carbon\Carbon::now();
        $report = fn (int $id, string $status) => ['id' => $id, 'post_id' => 1, 'discussion_id' => 1, 'user_id' => self::AUTHOR, 'status' => $status, 'entry_count' => 1, 'created_at' => $now, 'updated_at' => $now];
        // As a forum has them before 2026_11_19_000002: no active_post_id.
        $this->database()->table('docket_reports')->insert([$report(11, 'resolved'), $report(12, 'open'), $report(13, 'in_progress'), $report(14, 'open')]);
        foreach ([11, 12, 13, 14] as $id) {
            $this->database()->table('docket_entries')->insert(['report_id' => $id, 'post_id' => 1, 'user_id' => self::REPORTER, 'type' => 'user', 'reason' => 'spam', 'created_at' => $now]);
        }

        \ErnestDefoe\Docket\Migration\OneOpenReportPerPost::run($this->database());

        $rows = $this->database()->table('docket_reports')->orderBy('id')->get()->keyBy('id');
        $this->assertSame('resolved', $rows[11]->status, 'closed reports are left alone');
        $this->assertNull($rows[11]->active_post_id);
        $this->assertSame(['resolved', 'resolved'], [$rows[12]->status, $rows[13]->status]);
        $this->assertSame('open', $rows[14]->status);
        $this->assertSame(1, (int) $rows[14]->active_post_id);
        $this->assertSame(3, (int) $rows[14]->entry_count, 'its own entry and the two it took');
        $this->assertSame(1, $this->database()->table('docket_entries')->where('report_id', 11)->count());
        $this->assertSame(
            ['{"into":14}', '{"into":14}'],
            $this->database()->table('docket_events')->whereIn('report_id', [12, 13])->where('type', 'merged')->pluck('data')->all()
        );

        // And the post now takes no second open report.
        $this->assertSame(201, $this->flag(1, self::OTHER)->getStatusCode());
        $this->assertSame(14, $this->reportId(1));
    }

    #[Test]
    public function a_flag_after_a_report_closes_opens_a_new_one(): void
    {
        $this->flag(1, self::REPORTER);
        $id = $this->reportId(1);

        $this->assertSame(200, $this->updateReport($id, self::MOD, ['status' => 'resolved'])->getStatusCode());
        $this->assertSame(0, $this->database()->table('flags')->where('post_id', 1)->count(), 'closing a report dismisses its flags');

        $this->flag(1, self::OTHER);
        $this->assertSame(2, $this->database()->table('docket_reports')->where('post_id', 1)->count());
        $this->assertNotSame($id, $this->reportId(1));
    }

    #[Test]
    public function the_database_keeps_a_post_to_one_open_report(): void
    {
        $this->flag(1, self::REPORTER);
        $id = $this->reportId(1);

        // Another flag found no report and opened one: the database refuses
        // the second, and the flag joins the first.
        $docket = $this->app()->getContainer()->make(\ErnestDefoe\Docket\Docket::class);
        $post = \Flarum\Post\Post::query()->findOrFail(1);
        $open = (new \ReflectionMethod($docket, 'openReport'))->invoke($docket, $post);

        $this->assertSame($id, $open->id);
        $this->assertSame(1, $this->database()->table('docket_reports')->where('post_id', 1)->count());

        // Once it closes, a new one may open; the old one can't be reopened
        // beside it.
        $this->assertSame(200, $this->updateReport($id, self::MOD, ['status' => 'resolved'])->getStatusCode());
        $this->flag(1, self::OTHER);
        $this->assertSame(2, $this->database()->table('docket_reports')->where('post_id', 1)->count());

        $response = $this->updateReport($id, self::MOD, ['status' => 'open']);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('reopen_active', (string) $response->getBody());
    }

    #[Test]
    public function dismissing_flags_the_flarum_way_closes_the_report(): void
    {
        $this->flag(1, self::REPORTER);

        $response = $this->send($this->request('DELETE', '/api/posts/1/flags', ['authenticatedAs' => self::MOD]));
        $this->assertSame(204, $response->getStatusCode());

        $report = $this->database()->table('docket_reports')->first();
        $this->assertSame('rejected', $report->status, 'no action on the post: rejected');
        $this->assertSame(self::MOD, (int) $report->closed_by_id);
    }

    #[Test]
    public function hiding_the_post_then_dismissing_counts_as_resolved(): void
    {
        $this->flag(1, self::REPORTER);
        $this->patch('/api/posts/1', self::MOD, ['data' => ['type' => 'posts', 'id' => '1', 'attributes' => ['isHidden' => true]]]);
        $this->send($this->request('DELETE', '/api/posts/1/flags', ['authenticatedAs' => self::MOD]));

        $this->assertSame('resolved', $this->database()->table('docket_reports')->value('status'));
    }

    #[Test]
    public function dismissing_then_hiding_turns_rejected_into_resolved(): void
    {
        $this->flag(1, self::REPORTER);
        $this->send($this->request('DELETE', '/api/posts/1/flags', ['authenticatedAs' => self::MOD]));
        $this->assertSame('rejected', $this->database()->table('docket_reports')->value('status'));

        $this->patch('/api/posts/1', self::MOD, ['data' => ['type' => 'posts', 'id' => '1', 'attributes' => ['isHidden' => true]]]);
        $this->assertSame('resolved', $this->database()->table('docket_reports')->value('status'));
    }

    #[Test]
    public function the_history_survives_the_post_being_deleted(): void
    {
        $this->flag(1, self::REPORTER);
        $this->send($this->request('DELETE', '/api/posts/1', ['authenticatedAs' => self::ADMIN]));

        $report = $this->database()->table('docket_reports')->first();
        $this->assertSame('resolved', $report->status);
        $this->assertSame(1, $this->database()->table('docket_entries')->count());
        $this->assertContains(self::ADMIN, [(int) $report->closed_by_id]);
    }

    #[Test]
    public function flags_raised_before_the_extension_are_backfilled(): void
    {
        $this->database()->table('flags')->insert(['post_id' => 2, 'user_id' => self::REPORTER, 'type' => 'user', 'reason' => 'spam', 'created_at' => \Carbon\Carbon::now()]);
        $this->assertSame(0, $this->database()->table('docket_reports')->count());

        // Opening the report centre is only a count: it files nothing.
        $this->assertSame(200, $this->get('/api/docket-counts', self::MOD)->getStatusCode());
        $this->assertSame(0, $this->database()->table('docket_reports')->count());

        $this->enableDocket();
        $this->assertSame(1, $this->database()->table('docket_reports')->where('post_id', 2)->count());

        // Only once.
        $this->enableDocket();
        $this->assertSame(1, $this->database()->table('docket_entries')->count());
    }
}
