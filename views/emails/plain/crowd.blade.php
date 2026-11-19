<x-mail::plain.notification :title="$blueprint->getEmailSubject($translator)">
<x-slot:body>
{!! $translator->trans('ernestdefoe-docket.email.crowd.body_'.$blueprint->state, ['title' => $blueprint->post->discussion?->title, 'reason' => $blueprint->reasonLabel($translator)]) !!}

{!! $url->to('forum')->route('discussion', ['id' => $blueprint->post->discussion_id, 'near' => $blueprint->post->number]) !!}
</x-slot:body>
</x-mail::plain.notification>
