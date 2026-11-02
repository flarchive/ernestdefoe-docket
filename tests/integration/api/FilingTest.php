<?php

namespace ErnestDefoe\Docket\Tests\integration\api;

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

        $entries = $this->database()->table('docket_entries')->orderBy('id')->get();
        $this->assertSame(['spam', 'other'], $entries->pluck('reason')->all());
        $this->assertSame('Rude', $entries[1]->reason_detail);
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

        $this->assertSame(200, $this->get('/api/docket-counts', self::MOD)->getStatusCode());
        $this->assertSame(1, $this->database()->table('docket_reports')->where('post_id', 2)->count());

        // Only once.
        $this->get('/api/docket-counts', self::MOD);
        $this->assertSame(1, $this->database()->table('docket_entries')->count());
    }
}
