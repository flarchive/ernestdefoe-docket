<?php

namespace ErnestDefoe\Docket;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line in a report's timeline: a staff note, or a change to the report or
 * its post.
 *
 * @property int $id
 * @property int $report_id
 * @property int|null $user_id
 * @property string $type
 * @property array<string, mixed>|null $data
 * @property string|null $body
 * @property Carbon|null $created_at
 * @property-read Report $report
 * @property-read User|null $user
 */
class ReportEvent extends AbstractModel
{
    public const COMMENT = 'comment';

    protected $table = 'docket_events';

    protected $casts = ['data' => 'array', 'created_at' => 'datetime'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
