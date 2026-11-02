<?php

namespace ErnestDefoe\Docket\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * A forum with three tags and these people:
 *
 *   ADMIN (1)     admin
 *   AUTHOR (2)    wrote every post
 *   MOD (3)       Moderators group: "View flags" everywhere
 *   TAGMOD (4)    "Tag mods" group (5): "View flags" in the Games tag only
 *   REPORTER (5)  a member
 *   OTHER (6)     a member
 *   MOD2 (7)      Moderators and "Senior" (6)
 *
 * Tags: 1 General (open), 2 Games (restricted, but open to every member;
 * Moderators and Tag mods see its flags), 3 Staff (restricted: Moderators
 * may view it, Tag mods may not).
 * Discussions: 1 in General, 2 in Games, 3 in Staff, 4 untagged.
 * Posts: 1 (d1), 2 (d2), 3 (d3), 4 (d4), 5 (d1, hidden).
 */
abstract class DocketTestCase extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected const ADMIN = 1;
    protected const AUTHOR = 2;
    protected const MOD = 3;
    protected const TAGMOD = 4;
    protected const REPORTER = 5;
    protected const OTHER = 6;
    protected const MOD2 = 7;

    protected const TAGMOD_GROUP = 5;
    protected const SENIOR_GROUP = 6;

    protected array $enable = ['flarum-flags', 'flarum-tags', 'ernestdefoe-docket'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension(...$this->enable);

        $password = $this->normalUser()['password'];
        $users = [];
        foreach ([self::AUTHOR => 'author', self::MOD => 'moddy', self::TAGMOD => 'tagmod', self::REPORTER => 'reporter', self::OTHER => 'other', self::MOD2 => 'senior'] as $id => $name) {
            $users[] = ['id' => $id, 'username' => $name, 'email' => "$name@machine.local", 'password' => $password, 'is_email_confirmed' => 1];
        }

        $now = Carbon::now();
        $discussion = fn (int $id, string $title) => ['id' => $id, 'title' => $title, 'slug' => "d$id", 'user_id' => self::AUTHOR, 'created_at' => $now, 'last_posted_at' => $now, 'comment_count' => 1, 'participant_count' => 1, 'first_post_id' => $id, 'last_post_id' => $id, 'last_post_number' => 1];
        $post = fn (int $id, int $discussion, array $extra = []) => $extra + ['id' => $id, 'discussion_id' => $discussion, 'user_id' => self::AUTHOR, 'type' => 'comment', 'content' => "<t><p>Post $id</p></t>", 'number' => $id === 5 ? 2 : 1, 'created_at' => $now];

        $this->prepareDatabase($this->seed([
            'users' => $users,
            'groups' => [
                ['id' => self::TAGMOD_GROUP, 'name_singular' => 'Tag mod', 'name_plural' => 'Tag mods', 'is_hidden' => 0],
                ['id' => self::SENIOR_GROUP, 'name_singular' => 'Senior', 'name_plural' => 'Seniors', 'is_hidden' => 0],
            ],
            'group_user' => [
                ['user_id' => self::MOD, 'group_id' => 4],
                ['user_id' => self::TAGMOD, 'group_id' => self::TAGMOD_GROUP],
                ['user_id' => self::MOD2, 'group_id' => 4],
                ['user_id' => self::MOD2, 'group_id' => self::SENIOR_GROUP],
            ],
            'group_permission' => [
                ['permission' => 'discussion.flagPosts', 'group_id' => 3],
                ['permission' => 'discussion.viewFlags', 'group_id' => 4],
                ['permission' => 'discussion.hidePosts', 'group_id' => 4],
                ['permission' => 'discussion.editPosts', 'group_id' => 4],
                ['permission' => 'tag2.discussion.viewFlags', 'group_id' => self::TAGMOD_GROUP],
                // Tag permissions only bite on restricted tags: Games is open to
                // every member, with its own flag moderators.
                ['permission' => 'tag2.viewForum', 'group_id' => 3],
                ['permission' => 'tag2.discussion.flagPosts', 'group_id' => 3],
                ['permission' => 'tag2.discussion.viewFlags', 'group_id' => 4],
                ['permission' => 'tag3.viewForum', 'group_id' => 4],
                ['permission' => 'tag3.discussion.viewFlags', 'group_id' => 4],
            ],
            'tags' => [
                ['id' => 1, 'name' => 'General', 'slug' => 'general', 'position' => 0],
                ['id' => 2, 'name' => 'Games', 'slug' => 'games', 'position' => 1, 'is_restricted' => 1],
                ['id' => 3, 'name' => 'Staff', 'slug' => 'staff', 'position' => 2, 'is_restricted' => 1],
            ],
            'discussions' => [$discussion(1, 'General chat'), $discussion(2, 'Games chat'), $discussion(3, 'Staff room'), $discussion(4, 'No tags')],
            'discussion_tag' => [
                ['discussion_id' => 1, 'tag_id' => 1],
                ['discussion_id' => 2, 'tag_id' => 2],
                ['discussion_id' => 3, 'tag_id' => 3],
            ],
            'posts' => [
                $post(1, 1), $post(2, 2), $post(3, 3), $post(4, 4),
                $post(5, 1, ['hidden_at' => $now, 'hidden_user_id' => self::MOD]),
            ],
        ]));
    }

    /**
     * Each test's own rows on top of the shared ones.
     *
     * @param array<string, array<int, array<string, mixed>>> $rows
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function seed(array $rows): array
    {
        return $rows;
    }

    protected function flag(int $postId, int $as, string $reason = 'spam', ?string $detail = null): ResponseInterface
    {
        return $this->send($this->request('POST', '/api/flags', [
            'authenticatedAs' => $as,
            'json' => ['data' => ['type' => 'flags', 'attributes' => ['reason' => $reason, 'reasonDetail' => $detail], 'relationships' => ['post' => ['data' => ['type' => 'posts', 'id' => (string) $postId]]]]],
        ]));
    }

    protected function get(string $path, ?int $as = null, array $query = []): ResponseInterface
    {
        $options = $as ? ['authenticatedAs' => $as] : [];

        return $this->send($this->request('GET', $path, $options)->withQueryParams($query));
    }

    protected function patch(string $path, int $as, array $json): ResponseInterface
    {
        return $this->send($this->request('PATCH', $path, ['authenticatedAs' => $as, 'json' => $json]));
    }

    protected function post(string $path, int $as, array $json): ResponseInterface
    {
        return $this->send($this->request('POST', $path, ['authenticatedAs' => $as, 'json' => $json]));
    }

    protected function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /**
     * The ids of the reports the user is listed.
     *
     * @return int[]
     */
    protected function reportIds(?int $as, array $filter = []): array
    {
        $response = $this->get('/api/docket-reports', $as, $filter ? ['filter' => $filter] : []);

        if ($response->getStatusCode() !== 200) {
            return [];
        }

        $ids = array_map(fn ($r) => (int) $r['id'], $this->json($response)['data']);
        sort($ids);

        return $ids;
    }

    protected function reportId(int $postId): int
    {
        return (int) $this->database()->table('docket_reports')->where('post_id', $postId)->orderByDesc('id')->value('id');
    }

    protected function updateReport(int $id, int $as, array $attributes = [], array $relationships = []): ResponseInterface
    {
        $data = ['type' => 'docket-reports', 'id' => (string) $id, 'attributes' => $attributes];
        if ($relationships) {
            $data['relationships'] = $relationships;
        }

        return $this->patch("/api/docket-reports/$id", $as, ['data' => $data]);
    }
}
