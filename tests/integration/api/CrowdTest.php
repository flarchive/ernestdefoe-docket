<?php

namespace ErnestDefoe\Docket\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Docket\Crowd\Crowd;
use ErnestDefoe\Docket\Crowd\LinkedAccounts;
use ErnestDefoe\Docket\Report;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * Crowd moderation: enough trusted members reporting a post hides it until
 * staff review it, and nothing cheaper than that does.
 *
 * Seasoned members (11–16) joined two months ago with 20 posts each; NEWBIE
 * (21) joined yesterday; QUIET (22) has one post. Post 6 is a reply in
 * General by AUTHOR; post 8 a reply by MOD.
 */
class CrowdTest extends DocketTestCase
{
    protected const SEASONED = [11, 12, 13, 14, 15, 16];
    protected const NEWBIE = 21;
    protected const QUIET = 22;

    protected array $enable = ['flarum-flags', 'flarum-tags', 'flarum-approval', 'ernestdefoe-docket'];

    protected function seed(array $rows): array
    {
        $now = Carbon::now();
        $password = $this->normalUser()['password'];
        $user = fn (int $id, Carbon $joined, int $posts) => ['id' => $id, 'username' => "member$id", 'email' => "member$id@machine.local", 'password' => $password, 'is_email_confirmed' => 1, 'joined_at' => $joined, 'comment_count' => $posts];

        foreach (self::SEASONED as $id) {
            $rows['users'][] = $user($id, $now->copy()->subDays(60), 20);
        }

        $rows['users'][] = $user(self::NEWBIE, $now->copy()->subDay(), 20);
        $rows['users'][] = $user(self::QUIET, $now->copy()->subDays(60), 1);

        $rows['posts'][] = ['id' => 6, 'discussion_id' => 1, 'user_id' => self::AUTHOR, 'type' => 'comment', 'content' => '<t><p>Post 6</p></t>', 'number' => 3, 'created_at' => $now];
        $rows['posts'][] = ['id' => 8, 'discussion_id' => 1, 'user_id' => self::MOD, 'type' => 'comment', 'content' => '<t><p>Post 8</p></t>', 'number' => 4, 'created_at' => $now];

        return $rows;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting('ernestdefoe-docket.crowd_enabled', '1');
        $this->setting('ernestdefoe-docket.crowd_cooldown', '24');
    }

    protected function flagFrom(int $postId, int $as, ?string $ip = null, string $reason = 'spam', ?string $forwardedFor = null): ResponseInterface
    {
        $request = new ServerRequest(['REMOTE_ADDR' => $ip ?? "198.51.100.$as"], [], '/api/flags', 'POST', 'php://input', $forwardedFor ? ['X-Forwarded-For' => $forwardedFor] : []);
        $request = $this->requestWithJsonBody($request, ['data' => ['type' => 'flags', 'attributes' => ['reason' => $reason], 'relationships' => ['post' => ['data' => ['type' => 'posts', 'id' => (string) $postId]]]]]);

        $response = $this->send($this->requestAsUser($request, $as));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $response;
    }

    /** @param int[] $users */
    protected function crowd(int $postId, array $users): void
    {
        foreach ($users as $user) {
            $this->flagFrom($postId, $user);
        }
    }

    protected function hidden(int $postId): bool
    {
        return $this->database()->table('posts')->where('id', $postId)->value('hidden_at') !== null;
    }

    protected function report(int $postId): object
    {
        return $this->database()->table('docket_reports')->where('post_id', $postId)->orderByDesc('id')->first();
    }

    /** @return array<int, array{type: string, data: array|null, user: int|null}> */
    protected function timeline(int $reportId): array
    {
        return $this->database()->table('docket_events')->where('report_id', $reportId)->orderBy('id')->get()
            ->map(fn ($e) => ['type' => $e->type, 'data' => json_decode((string) $e->data, true), 'user' => $e->user_id ? (int) $e->user_id : null])
            ->filter(fn ($e) => str_starts_with($e['type'], 'crowd_'))
            ->values()
            ->all();
    }

    protected function changeSetting(string $key, string $value): void
    {
        $this->app()->getContainer()->make(SettingsRepositoryInterface::class)->set("ernestdefoe-docket.$key", $value);
    }

    protected function reject(int $postId, int $as): ResponseInterface
    {
        return $this->updateReport((int) $this->report($postId)->id, $as, ['status' => 'rejected']);
    }

    #[Test]
    public function three_seasoned_members_hide_the_post_once_and_the_author_is_told_why(): void
    {
        $this->crowd(6, [11, 12]);
        $this->assertFalse($this->hidden(6), 'two is not three');

        $this->flagFrom(6, 13, reason: 'inappropriate');
        $this->assertTrue($this->hidden(6));
        $this->assertNull($this->database()->table('posts')->where('id', 6)->value('hidden_user_id'));

        // Members can no longer see it to report it; a flag that still
        // arrives (here from staff, who can) doesn't act again.
        $this->assertSame(404, $this->flag(6, 14)->getStatusCode());
        $this->flagFrom(6, self::MOD);

        $report = $this->report(6);
        $this->assertSame('hidden', $report->crowd_state);
        $this->assertSame('open', $report->status, 'it waits for staff');
        $this->assertSame([['type' => 'crowd_hidden', 'data' => ['people' => 3, 'weight' => 3, 'threshold' => 3, 'reason' => 'spam', 'discussion' => false], 'user' => null]], $this->timeline((int) $report->id));
        $this->assertNotContains('post_hidden', $this->database()->table('docket_events')->where('report_id', $report->id)->pluck('type')->all(), 'logged once, in its own words');

        $notification = $this->database()->table('notifications')->where('type', 'docketCrowd')->first();
        $this->assertSame(self::AUTHOR, (int) $notification->user_id);
        $this->assertSame(['state' => 'hidden', 'reason' => 'spam'], json_decode($notification->data, true));
        $this->assertSame(6, (int) $notification->subject_id);
    }

    #[Test]
    public function nothing_happens_while_it_is_off(): void
    {
        $this->changeSetting('crowd_enabled', '0');

        $this->crowd(6, [11, 12, 13, 14]);

        $this->assertFalse($this->hidden(6));
        $this->assertNull($this->report(6)->crowd_state);
    }

    #[Test]
    public function new_accounts_and_members_with_few_posts_do_not_count(): void
    {
        $this->crowd(6, [11, 12, self::NEWBIE, self::QUIET]);
        $this->assertFalse($this->hidden(6));

        $this->flagFrom(6, 13);
        $this->assertTrue($this->hidden(6));
        $this->assertSame(3, $this->timeline((int) $this->report(6)->id)[0]['data']['people']);
    }

    #[Test]
    public function reports_from_one_address_count_once(): void
    {
        $this->flagFrom(6, 11, '203.0.113.7');
        $this->flagFrom(6, 12, '203.0.113.7');
        $this->flagFrom(6, 13, '::ffff:203.0.113.7');
        $this->assertFalse($this->hidden(6), 'one connection, three accounts');

        // One IPv6 /64 is one connection too.
        $this->flagFrom(6, 14, '2001:db8:1:2::10');
        $this->flagFrom(6, 15, '2001:db8:1:2:ffff::1');
        $this->assertFalse($this->hidden(6), 'two people so far');

        $this->flagFrom(6, 16, '2001:db8:1:3::1');
        $this->assertTrue($this->hidden(6));
        $this->assertSame(3, $this->timeline((int) $this->report(6)->id)[0]['data']['people']);

        $hashes = $this->database()->table('docket_entries')->where('post_id', 6)->pluck('ip_hash')->all();
        $this->assertCount(3, array_unique($hashes));
        $this->assertSame(64, strlen($hashes[0]));
        $this->assertStringNotContainsString('203.0.113.7', implode(',', $hashes));
    }

    #[Test]
    public function behind_a_local_proxy_the_proxys_view_of_the_address_counts(): void
    {
        // Through a Docker or local proxy everyone arrives from 172.x: what
        // the proxy appended to X-Forwarded-For is the address. Whatever the
        // client put before it is ignored, so faking it doesn't help.
        $this->flagFrom(6, 11, '172.21.0.1', forwardedFor: '1.1.1.1, 203.0.113.9');
        $this->flagFrom(6, 12, '172.21.0.1', forwardedFor: '8.8.8.8, 203.0.113.9');
        $this->flagFrom(6, 13, '172.21.0.1', forwardedFor: '203.0.113.9, 10.0.0.5');
        $this->assertFalse($this->hidden(6), 'one address behind the proxy');

        // A public connection's header is not trusted at all.
        $this->flagFrom(6, 14, '198.51.100.1', forwardedFor: '203.0.113.10');
        $this->flagFrom(6, 15, '198.51.100.1', forwardedFor: '203.0.113.11');
        $this->assertFalse($this->hidden(6), 'two people');

        $this->flagFrom(6, 16, '172.21.0.1', forwardedFor: '203.0.113.12');
        $this->assertTrue($this->hidden(6));
        $this->assertSame(3, $this->timeline((int) $this->report(6)->id)[0]['data']['people']);
    }

    #[Test]
    public function linked_accounts_count_as_one_person(): void
    {
        $this->app()->getContainer()->instance(LinkedAccounts::class, new class() implements LinkedAccounts {
            public function people(array $userIds): array
            {
                return [11 => 'alice', 12 => 'alice', 13 => 'alice'];
            }
        });

        $this->crowd(6, [11, 12, 13]);
        $this->assertFalse($this->hidden(6));

        $this->crowd(6, [14, 15]);
        $this->assertTrue($this->hidden(6));
        $this->assertSame(3, $this->timeline((int) $this->report(6)->id)[0]['data']['people']);
    }

    #[Test]
    public function only_the_chosen_groups_count(): void
    {
        $this->changeSetting('crowd_groups', json_encode([self::SENIOR_GROUP]));
        $this->database()->table('group_user')->insert([['user_id' => 11, 'group_id' => self::SENIOR_GROUP], ['user_id' => 12, 'group_id' => self::SENIOR_GROUP]]);

        $this->crowd(6, [11, 12, 13, 14]);
        $this->assertFalse($this->hidden(6), 'two Seniors');

        $this->database()->table('group_user')->insert(['user_id' => 15, 'group_id' => self::SENIOR_GROUP]);
        $this->flagFrom(6, 15);
        $this->assertTrue($this->hidden(6));
    }

    #[Test]
    public function staff_and_exempt_groups_are_left_to_staff(): void
    {
        $this->crowd(8, [11, 12, 13, 14]);

        $this->assertFalse($this->hidden(8));
        $report = $this->report(8);
        $this->assertSame('held', $report->crowd_state);
        $timeline = $this->timeline((int) $report->id);
        $this->assertCount(1, $timeline);
        $this->assertSame('crowd_held', $timeline[0]['type']);
        $this->assertSame('staff', $timeline[0]['data']['why']);

        $this->changeSetting('crowd_exempt_groups', json_encode([self::SENIOR_GROUP]));
        $this->database()->table('group_user')->insert(['user_id' => self::AUTHOR, 'group_id' => self::SENIOR_GROUP]);

        $this->crowd(6, [11, 12, 13]);
        $this->assertFalse($this->hidden(6));
        $this->assertSame('exempt', $this->timeline((int) $this->report(6)->id)[0]['data']['why']);
    }

    #[Test]
    public function a_tag_can_raise_the_threshold_or_switch_it_off(): void
    {
        $this->changeSetting('crowd_tags', json_encode(['1' => 4, '2' => 2]));

        $this->crowd(6, [11, 12, 13]);
        $this->assertFalse($this->hidden(6), 'General needs four');
        $this->flagFrom(6, 14);
        $this->assertTrue($this->hidden(6));
        $this->assertSame(4, $this->timeline((int) $this->report(6)->id)[0]['data']['threshold']);

        $this->changeSetting('crowd_tags', json_encode(['1' => 0]));
        $this->database()->table('posts')->insert(['id' => 9, 'discussion_id' => 1, 'user_id' => self::AUTHOR, 'type' => 'comment', 'content' => '<t><p>Post 9</p></t>', 'number' => 5, 'created_at' => Carbon::now()]);
        $this->crowd(9, self::SEASONED);
        $this->assertFalse($this->hidden(9), 'never in General');
        $this->assertNull($this->report(9)->crowd_state);
    }

    #[Test]
    public function a_queue_threshold_beats_the_tags_and_is_never_below_two(): void
    {
        $this->changeSetting('crowd_tags', json_encode(['1' => 0]));
        $this->database()->table('docket_queues')->insert(['id' => 1, 'name' => 'Spam', 'position' => 0, 'crowd_threshold' => 1]);
        $this->database()->table('docket_rules')->insert(['id' => 1, 'name' => 'Spam', 'position' => 0, 'is_enabled' => 1, 'conditions' => json_encode(['reasons' => ['spam']]), 'queue_id' => 1]);

        $this->flagFrom(6, 11);
        $this->assertFalse($this->hidden(6), 'one person is not a crowd');
        $this->flagFrom(6, 12);
        $this->assertTrue($this->hidden(6));
        $this->assertSame(2, $this->timeline((int) $this->report(6)->id)[0]['data']['threshold']);
    }

    #[Test]
    public function only_reports_within_the_window_count(): void
    {
        $this->changeSetting('crowd_window', '6');

        $this->crowd(6, [11, 12]);
        $this->database()->table('docket_entries')->where('post_id', 6)->update(['created_at' => Carbon::now()->subHours(7)]);

        $this->flagFrom(6, 13);
        $this->assertFalse($this->hidden(6));
        $this->crowd(6, [14, 15]);
        $this->assertTrue($this->hidden(6));
    }

    #[Test]
    public function flags_raised_without_an_address_never_count(): void
    {
        // A flag saved from code (an anti-spam extension, a queued job) has
        // no request to take an address from.
        $this->app();

        foreach ([11, 12, 13] as $user) {
            $flag = new \Flarum\Flags\Flag();
            $flag->post_id = 6;
            $flag->user_id = $user;
            $flag->type = 'user';
            $flag->reason = 'spam';
            $flag->created_at = Carbon::now();
            $flag->save();
        }

        $this->assertSame(3, $this->database()->table('docket_entries')->where('post_id', 6)->whereNull('ip_hash')->count());

        $this->assertFalse($this->hidden(6));
    }

    #[Test]
    public function rejecting_restores_it_in_one_step_and_only_staff_may(): void
    {
        $this->crowd(6, [11, 12, 13]);
        $id = (int) $this->report(6)->id;

        $this->assertContains($this->reject(6, self::REPORTER)->getStatusCode(), [403, 404]);
        $this->assertContains($this->reject(6, self::TAGMOD)->getStatusCode(), [403, 404]);
        $this->assertTrue($this->hidden(6));

        $response = $this->reject(6, self::MOD);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('restored', $this->json($response)['data']['attributes']['crowdState']);

        $this->assertFalse($this->hidden(6));
        $report = $this->report(6);
        $this->assertSame('rejected', $report->status);
        $this->assertSame('restored', $report->crowd_state);
        $this->assertSame(['crowd_hidden', 'crowd_restored'], array_column($this->timeline($id), 'type'));
        $this->assertSame(self::MOD, $this->timeline($id)[1]['user']);
        $this->assertSame(0, $this->database()->table('flags')->where('post_id', 6)->count());
        $this->assertSame(['state' => 'restored', 'reason' => null], json_decode($this->database()->table('notifications')->where('type', 'docketCrowd')->orderByDesc('id')->value('data'), true));
    }

    #[Test]
    public function a_moderator_who_may_not_restore_posts_may_not_reject_it(): void
    {
        $this->crowd(6, [11, 12, 13]);
        $this->database()->table('group_permission')->where('permission', 'discussion.hidePosts')->delete();

        // Without the permission the hidden post, and so its report, is
        // hidden from them; the check behind that refuses it as well.
        $response = $this->reject(6, self::MOD);

        $this->assertContains($response->getStatusCode(), [404, 422]);
        $this->assertFalse($this->app()->getContainer()->make(Crowd::class)->mayRestore(Report::query()->find($this->report(6)->id), User::query()->find(self::MOD)));
        $this->assertTrue($this->app()->getContainer()->make(Crowd::class)->mayRestore(Report::query()->find($this->report(6)->id), User::query()->find(self::ADMIN)));
        $this->assertTrue($this->hidden(6));
        $this->assertSame('open', $this->report(6)->status);
    }

    #[Test]
    public function after_a_restore_the_post_cools_down_and_the_reporters_count_for_less(): void
    {
        $this->crowd(6, [11, 12, 13]);
        $this->reject(6, self::MOD);

        // Within the cool-down: held, even by people with a clean record.
        $this->crowd(6, [14, 15, 16]);
        $this->assertFalse($this->hidden(6));
        $this->assertSame('cooldown', $this->timeline((int) $this->report(6)->id)[0]['data']['why']);

        // After it, the three who were wrong weigh 2.5 together.
        $this->changeSetting('crowd_cooldown', '0');
        $this->database()->table('posts')->insert(['id' => 9, 'discussion_id' => 1, 'user_id' => self::AUTHOR, 'type' => 'comment', 'content' => '<t><p>Post 9</p></t>', 'number' => 5, 'created_at' => Carbon::now()]);
        $this->crowd(9, [11, 12, 13]);
        $this->assertFalse($this->hidden(9));

        // Without trust, everyone counts as one.
        $this->changeSetting('crowd_trust', '0');
        $this->database()->table('posts')->insert(['id' => 10, 'discussion_id' => 1, 'user_id' => self::AUTHOR, 'type' => 'comment', 'content' => '<t><p>Post 10</p></t>', 'number' => 6, 'created_at' => Carbon::now()]);
        $this->crowd(10, [11, 12, 13]);
        $this->assertTrue($this->hidden(10));
    }

    #[Test]
    public function a_record_of_upheld_reports_counts_for_more(): void
    {
        // 11 and 12 reported three posts that were resolved each.
        foreach ([101, 102, 103] as $i => $reportId) {
            $this->database()->table('docket_reports')->insert(['id' => $reportId, 'post_id' => 1, 'status' => 'resolved', 'entry_count' => 2]);
            $this->database()->table('docket_entries')->insert([
                ['report_id' => $reportId, 'post_id' => 1, 'user_id' => 11, 'type' => 'user'],
                ['report_id' => $reportId, 'post_id' => 1, 'user_id' => 12, 'type' => 'user'],
            ]);
        }

        // (3+1)/(3+2) = 0.8, so 1.3 each: two of them make 2.6, short of
        // three, and with a third person it acts.
        $this->crowd(6, [11, 12]);
        $this->assertFalse($this->hidden(6));
        $this->flagFrom(6, 13);
        $this->assertTrue($this->hidden(6));
        $this->assertSame(3.6, (float) $this->timeline((int) $this->report(6)->id)[0]['data']['weight']);
    }

    #[Test]
    public function a_first_post_hides_its_discussion(): void
    {
        $this->crowd(1, [11, 12, 13]);

        $this->assertNotNull($this->database()->table('discussions')->where('id', 1)->value('hidden_at'));
        $this->assertTrue($this->timeline((int) $this->report(1)->id)[0]['data']['discussion']);

        $this->assertSame(200, $this->reject(1, self::MOD)->getStatusCode());
        $this->assertNull($this->database()->table('discussions')->where('id', 1)->value('hidden_at'));
        $this->assertSame('restored', $this->report(1)->crowd_state);
    }

    #[Test]
    public function restoring_by_hand_counts_as_a_restore_and_dismissing_confirms_a_hide(): void
    {
        $this->crowd(6, [11, 12, 13]);
        $this->patch('/api/posts/6', self::MOD, ['data' => ['type' => 'posts', 'id' => '6', 'attributes' => ['isHidden' => false]]]);

        $this->assertFalse($this->hidden(6));
        $this->assertSame('restored', $this->report(6)->crowd_state);
        $this->send($this->request('DELETE', '/api/posts/6/flags', ['authenticatedAs' => self::MOD]));
        $this->assertSame('rejected', $this->report(6)->status);

        // A hide the staff leave in place and dismiss the flags of: upheld.
        $this->database()->table('posts')->insert(['id' => 9, 'discussion_id' => 1, 'user_id' => self::AUTHOR, 'type' => 'comment', 'content' => '<t><p>Post 9</p></t>', 'number' => 5, 'created_at' => Carbon::now()]);
        $this->crowd(9, [14, 15, 16]);
        $this->send($this->request('DELETE', '/api/posts/9/flags', ['authenticatedAs' => self::MOD]));
        $this->assertTrue($this->hidden(9));
        $this->assertSame('resolved', $this->report(9)->status);
    }

    #[Test]
    public function in_approval_mode_it_goes_back_to_approval_and_approving_restores_it(): void
    {
        $this->changeSetting('crowd_action', 'approval');

        $this->crowd(6, [11, 12, 13]);

        $post = $this->database()->table('posts')->where('id', 6)->first();
        $this->assertSame(0, (int) $post->is_approved);
        $this->assertSame(1, (int) $post->is_private, 'out of the counts and the stream');
        $this->assertNull($post->hidden_at);
        $this->assertSame('approval', $this->report(6)->crowd_state);
        $this->assertSame('crowd_approval', $this->timeline((int) $this->report(6)->id)[0]['type']);
        $this->assertSame(404, $this->get('/api/posts/6', self::OTHER)->getStatusCode());
        $this->assertSame(200, $this->get('/api/posts/6', self::AUTHOR)->getStatusCode(), 'its author still sees it');

        // Approving it from the flag bar is a restore.
        $this->patch('/api/posts/6', self::MOD, ['data' => ['type' => 'posts', 'id' => '6', 'attributes' => ['isApproved' => true]]]);
        $this->assertSame(1, (int) $this->database()->table('posts')->where('id', 6)->value('is_approved'));
        $this->assertSame('restored', $this->report(6)->crowd_state);
        $this->assertSame(200, $this->get('/api/posts/6', self::OTHER)->getStatusCode());
    }

    #[Test]
    public function in_approval_mode_rejecting_approves_it(): void
    {
        $this->changeSetting('crowd_action', 'approval');
        $this->crowd(6, [11, 12, 13]);

        $this->assertSame(200, $this->reject(6, self::MOD)->getStatusCode());

        $this->assertSame(1, (int) $this->database()->table('posts')->where('id', 6)->value('is_approved'));
        $this->assertSame(0, (int) $this->database()->table('posts')->where('id', 6)->value('is_private'));
        $this->assertSame(['crowd_approval', 'crowd_restored'], array_column($this->timeline((int) $this->report(6)->id), 'type'));
        $this->assertNotContains('post_approved', $this->database()->table('docket_events')->pluck('type')->all());
    }

    #[Test]
    public function the_authors_notifications_load_flat(): void
    {
        $this->changeSetting('crowd_threshold', '2');

        foreach ([9, 10, 11, 12, 13] as $i => $id) {
            $this->database()->table('posts')->insert(['id' => $id, 'discussion_id' => 1, 'user_id' => self::AUTHOR, 'type' => 'comment', 'content' => "<t><p>Post $id</p></t>", 'number' => 5 + $i, 'created_at' => Carbon::now()]);
            $this->crowd($id, [14, 15]);
        }

        $notifications = $this->json($this->get('/api/notifications', self::AUTHOR));
        $this->assertCount(5, $notifications['data']);
        $this->assertSame('docketCrowd', $notifications['data'][0]['attributes']['contentType']);
    }

    #[Test]
    public function the_settings_and_addresses_stay_with_admins(): void
    {
        $this->changeSetting('crowd_tags', json_encode(['1' => 9]));
        $this->database()->table('docket_queues')->insert(['id' => 1, 'name' => 'Spam', 'position' => 0, 'crowd_threshold' => 7]);
        $this->crowd(6, [11, 12]);

        foreach ([null, self::REPORTER, self::MOD] as $as) {
            $body = (string) $this->get('/api', $as)->getBody();
            $this->assertStringNotContainsString('crowd', $body);
        }

        $queues = $this->json($this->get('/api/docket-queues', self::MOD));
        $this->assertArrayNotHasKey('crowdThreshold', $queues['data'][0]['attributes']);
        $this->assertSame(7, $this->json($this->get('/api/docket-queues', self::ADMIN))['data'][0]['attributes']['crowdThreshold']);

        $report = (string) $this->get('/api/docket-reports/'.$this->report(6)->id, self::ADMIN)->getBody();
        $this->assertStringNotContainsString('ip_hash', $report);
        $this->assertStringNotContainsString('ipHash', $report);
        $this->assertStringNotContainsString((string) $this->database()->table('docket_entries')->value('ip_hash'), $report);

        // Only admins write the settings.
        $response = $this->post('/api/settings', self::MOD, ['ernestdefoe-docket.crowd_threshold' => '2']);
        $this->assertSame(403, $response->getStatusCode());
    }
}
