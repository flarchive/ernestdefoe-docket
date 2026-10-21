<?php

namespace ErnestDefoe\Docket\Notification;

use ErnestDefoe\Docket\Entry;
use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/**
 * Tells someone who reported a post how their report was closed. The subject
 * is their own entry, the only part of the report they may see; the message
 * is the one staff wrote for them.
 */
class ReportOutcomeBlueprint implements BlueprintInterface, AlertableInterface, MailableInterface
{
    public function __construct(
        public Entry $entry,
        public string $status,
        public ?string $message = null
    ) {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->entry;
    }

    public function getFromUser(): ?User
    {
        // Never who closed it: moderators stay anonymous to reporters.
        return null;
    }

    public function getData(): mixed
    {
        return ['status' => $this->status];
    }

    public static function getType(): string
    {
        return 'docketOutcome';
    }

    public static function getSubjectModel(): string
    {
        return Entry::class;
    }

    public function getEmailViews(): array
    {
        return ['text' => 'ernestdefoe-docket::emails.plain.outcome', 'html' => 'ernestdefoe-docket::emails.html.outcome'];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-docket.email.outcome.subject');
    }
}
