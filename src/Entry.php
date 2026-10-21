<?php

namespace ErnestDefoe\Docket;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Database\ScopeVisibilityTrait;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One flag in a report, kept after the flag itself is dismissed.
 *
 * @property int $id
 * @property int $report_id
 * @property int|null $flag_id
 * @property int|null $post_id
 * @property int|null $user_id
 * @property string $type
 * @property string|null $reason
 * @property string|null $reason_detail
 * @property string|null $ip_hash never serialized
 * @property Carbon|null $created_at
 * @property-read Report $report
 * @property-read \Flarum\Post\Post|null $post
 * @property-read User|null $user
 */
class Entry extends AbstractModel
{
    use ScopeVisibilityTrait;

    protected $table = 'docket_entries';

    protected $casts = ['created_at' => 'datetime'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(\Flarum\Post\Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
