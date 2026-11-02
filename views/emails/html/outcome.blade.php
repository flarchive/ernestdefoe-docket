<x-mail::html.notification :title="$translator->trans('ernestdefoe-docket.email.outcome.subject')">
    <x-slot:body>
        <p>{{ $translator->trans('ernestdefoe-docket.email.outcome.body_'.$blueprint->status) }}</p>
        @if ($blueprint->message)
            <blockquote>{!! nl2br(e($blueprint->message)) !!}</blockquote>
        @endif
        <p><a href="{{ $url->to('forum')->route('docket.mine') }}">{{ $translator->trans('ernestdefoe-docket.email.outcome.link') }}</a></p>
    </x-slot:body>
</x-mail::html.notification>
