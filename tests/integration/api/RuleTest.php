<?php

namespace ErnestDefoe\Docket\Tests\integration\api;

use PHPUnit\Framework\Attributes\Test;

/**
 * Auto-assignment rules: the first enabled rule a new report matches files it
 * and/or assigns it.
 */
class RuleTest extends DocketTestCase
{
    protected function queue(string $name): int
    {
        return (int) $this->json($this->post('/api/docket-queues', self::ADMIN, ['data' => ['type' => 'docket-queues', 'attributes' => ['name' => $name]]]))['data']['id'];
    }

    protected function rule(array $conditions, ?int $queue = null, ?int $assignee = null, ?int $group = null, bool $enabled = true): int
    {
        $relationships = [];
        if ($queue) {
            $relationships['queue'] = ['data' => ['type' => 'docket-queues', 'id' => (string) $queue]];
        }
        if ($assignee) {
            $relationships['assignee'] = ['data' => ['type' => 'users', 'id' => (string) $assignee]];
        }
        if ($group) {
            $relationships['assigneeGroup'] = ['data' => ['type' => 'groups', 'id' => (string) $group]];
        }

        $response = $this->post('/api/docket-rules', self::ADMIN, ['data' => ['type' => 'docket-rules', 'attributes' => ['name' => 'Rule', 'conditions' => $conditions, 'isEnabled' => $enabled], 'relationships' => $relationships]]);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (int) $this->json($response)['data']['id'];
    }

    protected function report(int $post): object
    {
        return $this->database()->table('docket_reports')->where('post_id', $post)->orderByDesc('id')->first();
    }

    #[Test]
    public function a_matching_rule_files_and_assigns_and_notifies(): void
    {
        $games = $this->queue('Games');
        $this->rule(['tags' => [2]], $games, self::MOD);

        $this->flag(2, self::REPORTER);
        $report = $this->report(2);

        $this->assertSame($games, (int) $report->queue_id);
        $this->assertSame(self::MOD, (int) $report->assignee_id);
        $this->assertSame('in_progress', $report->status);
        $this->assertSame(1, $this->database()->table('notifications')->where('type', 'docketAssigned')->where('user_id', self::MOD)->count());

        // Post 1 is in General: no match, general queue, unassigned.
        $this->flag(1, self::REPORTER);
        $this->assertNull($this->report(1)->queue_id);
        $this->assertNull($this->report(1)->assignee_id);
    }

    #[Test]
    public function the_first_matching_rule_wins_and_disabled_ones_are_skipped(): void
    {
        $a = $this->queue('A');
        $b = $this->queue('B');
        $c = $this->queue('C');
        $this->rule(['reasons' => ['spam']], $a, enabled: false);
        $this->rule(['reasons' => ['spam']], $b);
        $this->rule([], $c);

        $this->flag(1, self::REPORTER, 'spam');
        $this->flag(2, self::REPORTER, 'inappropriate');

        $this->assertSame($b, (int) $this->report(1)->queue_id);
        $this->assertSame($c, (int) $this->report(2)->queue_id);
    }

    #[Test]
    public function a_written_reason_alone_counts_as_other(): void
    {
        $other = $this->queue('Other');
        $this->rule(['reasons' => ['other']], $other);

        $this->flag(1, self::REPORTER, '', 'They were rude to me');

        $this->assertSame($other, (int) $this->report(1)->queue_id);
    }

    #[Test]
    public function the_authors_groups_can_route_a_report(): void
    {
        $staff = $this->queue('About staff');
        $this->rule(['groups' => [4]], $staff);

        // AUTHOR is a plain member: no match. Make them a moderator: match.
        $this->flag(1, self::REPORTER);
        $this->assertNull($this->report(1)->queue_id);

        $this->database()->table('group_user')->insert(['user_id' => self::AUTHOR, 'group_id' => 4]);
        $this->flag(2, self::REPORTER);
        $this->assertSame($staff, (int) $this->report(2)->queue_id);
    }

    #[Test]
    public function a_rule_never_assigns_someone_who_cannot_see_the_report(): void
    {
        // TAGMOD sees flags in Games only; post 1 is in General.
        $this->rule([], null, self::TAGMOD);
        $this->flag(1, self::REPORTER);

        $this->assertNull($this->report(1)->assignee_id);
        $this->assertSame('open', $this->report(1)->status);
    }

    #[Test]
    public function a_group_rule_picks_the_least_busy_member_who_can_see_it(): void
    {
        $this->rule([], null, null, 4);

        // MOD (3) and MOD2 (7) are Moderators; each new report goes to
        // whoever holds fewer.
        $this->flag(1, self::REPORTER);
        $this->flag(2, self::REPORTER);
        $this->flag(4, self::REPORTER);

        $this->assertSame(self::MOD, (int) $this->report(1)->assignee_id);
        $this->assertSame(self::MOD2, (int) $this->report(2)->assignee_id);
        $this->assertSame(self::MOD, (int) $this->report(4)->assignee_id);
    }

    #[Test]
    public function only_admins_see_or_change_rules_and_conditions_are_cleaned(): void
    {
        $this->assertSame(403, $this->post('/api/docket-rules', self::MOD, ['data' => ['type' => 'docket-rules', 'attributes' => ['name' => 'Mine']]])->getStatusCode());
        $this->assertSame(403, $this->get('/api/docket-rules', self::MOD)->getStatusCode());

        $id = $this->rule(['tags' => ['2', 'x', 2, -0], 'reasons' => ['spam', ['nested'], str_repeat('a', 300)], 'evil' => ['drop table']]);
        $stored = json_decode($this->database()->table('docket_rules')->where('id', $id)->value('conditions'), true);

        $this->assertSame([2], $stored['tags']);
        $this->assertSame(['spam', str_repeat('a', 100)], $stored['reasons']);
        $this->assertArrayNotHasKey('evil', $stored);
    }
}
