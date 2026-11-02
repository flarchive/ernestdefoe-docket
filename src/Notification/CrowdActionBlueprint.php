<?php

namespace ErnestDefoe\Docket\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\Post\Post;
use Flarum\User\User;

/**
 * Tells an author the community's reports hid their post (or sent it back
 * to approval) until staff review it, and why; and again when staff restore
 * it. Never says who reported it.
 */
class CrowdActionBlueprint implements BlueprintInterface, AlertableInterface, MailableInterface
{
    public function __construct(
        public Post $post,
        public string $state,
        public ?string $reason = null
    ) {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->post;
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getData(): mixed
    {
        return ['state' => $this->state, 'reason' => $this->reason];
    }

    public static function getType(): string
    {
        return 'docketCrowd';
    }

    public static function getSubjectModel(): string
    {
        return Post::class;
    }

    /** The reason as the flag form words it. */
    public function reasonLabel(TranslatorInterface $translator): string
    {
        $key = "flarum-flags.forum.flag_post.reason_{$this->reason}_label";
        $label = $this->reason && $this->reason !== 'other' ? $translator->trans($key) : $key;

        return $label === $key ? $translator->trans('ernestdefoe-docket.forum.reason.other') : $label;
    }

    public function getEmailViews(): array
    {
        return ['text' => 'ernestdefoe-docket::emails.plain.crowd', 'html' => 'ernestdefoe-docket::emails.html.crowd'];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans("ernestdefoe-docket.email.crowd.subject_{$this->state}");
    }
}
