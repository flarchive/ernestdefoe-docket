<?php

namespace ErnestDefoe\Docket\Notification;

use ErnestDefoe\Docket\Report;
use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/**
 * A report was assigned to you, by someone else or by a rule.
 */
class ReportAssignedBlueprint implements BlueprintInterface, AlertableInterface, MailableInterface
{
    public function __construct(
        public Report $report,
        public ?User $by = null
    ) {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->report;
    }

    public function getFromUser(): ?User
    {
        return $this->by;
    }

    public function getData(): mixed
    {
        return null;
    }

    public static function getType(): string
    {
        return 'docketAssigned';
    }

    public static function getSubjectModel(): string
    {
        return Report::class;
    }

    public function getEmailViews(): array
    {
        return ['text' => 'ernestdefoe-docket::emails.plain.assigned', 'html' => 'ernestdefoe-docket::emails.html.assigned'];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-docket.email.assigned.subject', ['id' => $this->report->id]);
    }
}
