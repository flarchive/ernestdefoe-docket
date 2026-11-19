<?php

namespace ErnestDefoe\Docket\Job;

use ErnestDefoe\Docket\Notification\ReportOutcomeBlueprint;
use ErnestDefoe\Docket\Report;
use Flarum\Notification\NotificationSyncer;
use Flarum\Queue\AbstractJob;

/**
 * Tell the people who reported a post how their report went, off the
 * moderator's request: one notification each, however many reported it.
 */
class TellReporters extends AbstractJob
{
    public function __construct(
        public Report $report,
        public string $status,
        public ?string $message,
        public int $byId
    ) {
    }

    public function handle(NotificationSyncer $notifications): void
    {
        $entries = $this->report->entries()
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $this->byId)
            ->with('user')
            ->orderBy('id')
            ->get()
            ->unique('user_id');

        foreach ($entries as $entry) {
            if ($entry->user) {
                $notifications->sync(new ReportOutcomeBlueprint($entry, $this->status, $this->message), [$entry->user]);
            }
        }
    }
}
