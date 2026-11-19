<?php

namespace ErnestDefoe\Docket\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Docket\Crowd\Crowd;
use ErnestDefoe\Docket\Report;
use Flarum\Extension\ExtensionManager;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * The crowd sent post 6 (a reply in General) back to approval, and then
 * flarum/approval was disabled.
 */
class ApprovalGoneTest extends DocketTestCase
{
    protected function seed(array $rows): array
    {
        $now = Carbon::now();

        $rows['posts'][] = ['id' => 6, 'discussion_id' => 1, 'user_id' => self::AUTHOR, 'type' => 'comment', 'content' => '<t><p>Post 6</p></t>', 'number' => 3, 'created_at' => $now, 'is_approved' => 0, 'is_private' => 1];
        $rows['docket_reports'][] = ['id' => 1, 'post_id' => 6, 'user_id' => self::AUTHOR, 'status' => 'open', 'entry_count' => 3, 'crowd_state' => 'approval', 'crowd_at' => $now, 'created_at' => $now, 'updated_at' => $now];

        return $rows;
    }

    protected function post6(): object
    {
        return $this->database()->table('posts')->where('id', 6)->first();
    }

    #[Test]
    public function disabling_approval_hides_it_instead_so_staff_can_still_restore_it(): void
    {
        $this->extension('flarum-approval');
        $this->app();

        $this->app()->getContainer()->make(ExtensionManager::class)->disable('flarum-approval');

        $post = $this->post6();
        $this->assertSame(1, (int) $post->is_approved);
        $this->assertSame(0, (int) $post->is_private);
        $this->assertNotNull($post->hidden_at);
        $this->assertSame('hidden', $this->database()->table('docket_reports')->where('id', 1)->value('crowd_state'));
        $this->assertSame(['crowd_moved'], $this->database()->table('docket_events')->where('report_id', 1)->pluck('type')->all());
        $this->assertSame(404, $this->get('/api/posts/6', self::OTHER)->getStatusCode(), 'still out of view');

        $response = $this->updateReport(1, self::MOD, ['status' => 'rejected']);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $this->assertNull($this->post6()->hidden_at);
        $this->assertSame('restored', $this->database()->table('docket_reports')->where('id', 1)->value('crowd_state'));
        $this->assertSame(200, $this->get('/api/posts/6', self::OTHER)->getStatusCode());
    }

    #[Test]
    public function restoring_without_approval_approves_it_without_its_event_class(): void
    {
        $this->app();

        $this->app()->getContainer()->make(Crowd::class)->restore(Report::query()->findOrFail(1), User::query()->findOrFail(self::ADMIN));

        $post = $this->post6();
        $this->assertSame(1, (int) $post->is_approved);
        $this->assertSame(0, (int) $post->is_private);
        $this->assertSame('restored', $this->database()->table('docket_reports')->where('id', 1)->value('crowd_state'));
        $this->assertSame(200, $this->get('/api/posts/6', self::OTHER)->getStatusCode());
        $this->assertSame(2, (int) $this->database()->table('discussions')->where('id', 1)->value('comment_count'), 'post 1 and post 6');
    }
}
