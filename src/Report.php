<?php

namespace ErnestDefoe\Docket;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Database\ScopeVisibilityTrait;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $post_id
 * @property int|null $discussion_id
 * @property int|null $active_post_id
 * @property int|null $user_id
 * @property int|null $queue_id
 * @property int|null $assignee_id
 * @property string $status
 * @property int $entry_count
 * @property string|null $outcome
 * @property bool $reporters_notified
 * @property int|null $closed_by_id
 * @property Carbon|null $closed_at
 * @property string|null $crowd_state hidden, approval, restored or held: see Crowd
 * @property Carbon|null $crowd_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Post|null $post
 * @property-read Discussion|null $discussion
 * @property-read User|null $user
 * @property-read Queue|null $queue
 * @property-read User|null $assignee
 * @property-read User|null $closedBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Entry> $entries
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ReportEvent> $events
 */
class Report extends AbstractModel
{
    use ScopeVisibilityTrait;

    public const OPEN = 'open';
    public const IN_PROGRESS = 'in_progress';
    public const RESOLVED = 'resolved';
    public const REJECTED = 'rejected';

    /** The statuses of a report still being worked on. */
    public const ACTIVE = [self::OPEN, self::IN_PROGRESS];

    public const STATUSES = [self::OPEN, self::IN_PROGRESS, self::RESOLVED, self::REJECTED];

    /**
     * The post's id while the report is open or in progress, under a unique
     * index: the database itself keeps a post to one open report.
     */
    protected static function booted(): void
    {
        static::saving(function (self $report) {
            $report->active_post_id = $report->isActive() ? $report->post_id : null;
        });
    }

    /**
     * How a closed report counts for its reporters, as SQL over the reports
     * table: one rule for the reporter records and the crowd's trust
     * weighting. A report whose post staff restored after the crowd hid it
     * counts as rejected, even if it was resolved later. The values are this
     * extension's own fixed strings, so they are written in.
     *
     * @return array{resolved: string, rejected: string, open: string}
     */
    public static function outcomeSql(\Illuminate\Database\Query\Grammars\Grammar $grammar): array
    {
        $table = (new self())->getTable();
        $status = $grammar->wrap("$table.status");
        $state = $grammar->wrap("$table.crowd_state");
        $restored = "'".Crowd\Crowd::RESTORED."'";
        $notRestored = "($state is null or $state <> $restored)";

        return [
            'resolved' => "$status = '".self::RESOLVED."' and $notRestored",
            'rejected' => "($status = '".self::REJECTED."' or $state = $restored)",
            'open' => "$status in ('".self::OPEN."', '".self::IN_PROGRESS."') and $notRestored",
        ];
    }

    protected $table = 'docket_reports';

    public $timestamps = true;

    protected $casts = [
        'entry_count' => 'integer',
        'reporters_notified' => 'boolean',
        'closed_at' => 'datetime',
        'crowd_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * The post's discussion when the report was filed, kept after the post
     * is gone. Read by other extensions (Member Desk names it beside a
     * member's reports when the post was deleted).
     */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(Queue::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    /** @return HasMany<Entry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    /** @return HasMany<ReportEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ReportEvent::class);
    }

    /**
     * Add a line to the timeline.
     *
     * @param array<string, mixed>|null $data
     */
    public function log(string $type, ?User $actor, ?array $data = null, ?string $body = null): ReportEvent
    {
        $event = new ReportEvent();
        $event->report_id = $this->id;
        $event->user_id = $actor?->id;
        $event->type = $type;
        $event->data = $data;
        $event->body = $body;
        $event->created_at = Carbon::now();
        $event->save();

        return $event;
    }
}
