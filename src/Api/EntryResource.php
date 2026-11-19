<?php

namespace ErnestDefoe\Docket\Api;

use ErnestDefoe\Docket\Crowd\Crowd;
use ErnestDefoe\Docket\Entry;
use ErnestDefoe\Docket\Report;
use ErnestDefoe\Docket\ReporterStats;
use ErnestDefoe\Docket\Search\EntrySearcher;
use Flarum\Api\Context as FlarumContext;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Tobyz\JsonApiServer\Context;
use WeakMap;

/**
 * The flags in a report, kept after the flags are gone.
 *
 * Listed, they are "My reports": only your own, with the post (when you can
 * still see it) and how the report went, worded for a reporter: whether it
 * was resolved or rejected only when staff chose to tell you. How far back
 * that list goes is EntrySearcher's.
 *
 * Listed with filter[reporter] (staff only), they are the reports a member
 * made, with how each really went and the member's record in `meta`.
 *
 * @extends AbstractDatabaseResource<Entry>
 */
class EntryResource extends AbstractDatabaseResource
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ReporterStats $stats,
        protected PostPlace $postPlaces
    ) {
        $this->placing = new Deferred();
        $this->reports = new Deferred();
    }

    /** @var Deferred<Entry> */
    protected Deferred $placing;

    /** @var Deferred<Entry> */
    protected Deferred $reports;

    public function type(): string
    {
        return 'docket-entries';
    }

    public function model(): string
    {
        return Entry::class;
    }

    public function scope(Builder $query, Context $context): void
    {
        $query->whereVisibleTo($context->getActor());
    }

    /**
     * The member whose reports are listed, when staff list them. The filter
     * itself refuses anyone else (ReporterFilter).
     */
    protected static function reporter(Context $context): ?int
    {
        $filter = $context->queryParam('filter');
        $id = is_array($filter) ? ($filter['reporter'] ?? null) : null;

        return is_numeric($id) && $context->getActor()->hasPermissionLike('discussion.viewFlags') ? (int) $id : null;
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->authenticated()
                ->eagerLoad(['report'])
                ->defaultSort('-createdAt')
                ->paginate(20, 50)
                ->meta([
                    // How far back "My reports" goes, in days (0: forever).
                    Schema\Integer::make('reportsDays')
                        ->visible(fn (Context $context) => self::reporter($context) === null)
                        ->get(fn () => EntrySearcher::days($this->settings)),
                    // The member's record, by time frame: see ReporterStats.
                    Schema\Arr::make('reporterStats')
                        ->visible(fn (Context $context) => self::reporter($context) !== null)
                        ->get(fn (Context $context) => $this->stats->forUsers([self::reporter($context)], $context->getActor())[self::reporter($context)]),
                ]),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('type'),
            Schema\Str::make('reason')->nullable(),
            Schema\Str::make('reasonDetail')->nullable(),
            Schema\DateTime::make('createdAt'),

            // How it went, as the reporter may know it.
            Schema\Str::make('reportStatus')
                ->get(fn (Entry $entry) => $this->withReport($entry, function (Report $report) {
                    if ($report->isActive()) {
                        return $report->status;
                    }

                    return $report->reporters_notified ? $report->status : 'closed';
                })),
            Schema\Str::make('outcome')
                ->nullable()
                ->get(fn (Entry $entry) => $this->withReport($entry, fn (Report $report) => $report->reporters_notified ? $report->outcome : null)),

            // For staff listing a member's reports: which report, and how it
            // really went (restored: rejected by putting back what the crowd hid).
            Schema\Integer::make('reportId')
                ->property('report_id')
                ->visible(fn (Entry $entry, Context $context) => self::reporter($context) !== null),
            Schema\Str::make('verdict')
                ->visible(fn (Entry $entry, Context $context) => self::reporter($context) !== null)
                ->get(fn (Entry $entry) => $this->withReport($entry, fn (Report $report) => $report->crowd_state === Crowd::RESTORED ? Crowd::RESTORED : $report->status)),

            // The reported post, as a link and a discussion title, while the
            // actor can still see it; null once they can't.
            Schema\Arr::make('post')
                ->nullable()
                ->get(fn (Entry $entry, FlarumContext $context) => $this->place($entry, $context)),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable()
                ->nullable(),
        ];
    }

    /**
     * The posts placed so far, by the actor they were looked up for: a
     * resource can outlive a request, and what one actor sees is not what
     * another does.
     *
     * @var WeakMap<User, array<int, Post|null>>|null
     */
    protected ?WeakMap $places = null;

    /**
     * The post's place, looked up for every entry on the page at once: one
     * visibility query for all of them.
     */
    protected function place(Entry $entry, FlarumContext $context): mixed
    {
        if (! $entry->post_id) {
            return null;
        }

        $actor = $context->getActor();

        return $this->placing->later(
            $entry,
            fn (array $entries) => $this->lookUpPosts($entries, $actor),
            fn () => $this->postPlaces->of($this->places[$actor][$entry->post_id] ?? null)
        );
    }

    /**
     * @param Entry[] $entries
     */
    protected function lookUpPosts(array $entries, User $actor): void
    {
        $this->places ??= new WeakMap();
        $known = $this->places[$actor] ?? [];
        $ids = array_diff(array_unique(array_map(fn (Entry $e) => (int) $e->post_id, $entries)), array_keys($known));
        $found = $ids ? Post::whereVisibleTo($actor)->whereIn('posts.id', $ids)->with('discussion')->get()->keyBy('id')->all() : [];

        foreach ($ids as $id) {
            $known[(int) $id] = $found[$id] ?? null;
        }

        $this->places[$actor] = $known;
    }

    /**
     * Entries serialized without their report loaded (the subjects of
     * notifications) load the reports of all of them at once.
     */
    protected function withReport(Entry $entry, callable $value): mixed
    {
        if ($entry->relationLoaded('report')) {
            return $value($entry->report);
        }

        return $this->reports->later(
            $entry,
            fn (array $entries) => (new Collection($entries))->load('report'),
            fn () => $value($entry->report)
        );
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('createdAt'),
        ];
    }
}
