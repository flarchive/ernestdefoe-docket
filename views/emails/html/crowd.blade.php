<x-mail::html.notification :title="$blueprint->getEmailSubject($translator)">
    <x-slot:body>
        <p>{{ $translator->trans('ernestdefoe-docket.email.crowd.body_'.$blueprint->state, ['title' => $blueprint->post->discussion?->title, 'reason' => $blueprint->reasonLabel($translator)]) }}</p>
        <p><a href="{{ $url->to('forum')->route('discussion', ['id' => $blueprint->post->discussion_id, 'near' => $blueprint->post->number]) }}">{{ $translator->trans('ernestdefoe-docket.email.crowd.link') }}</a></p>
    </x-slot:body>
</x-mail::html.notification>
