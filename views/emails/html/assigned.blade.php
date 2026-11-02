<x-mail::html.notification :title="$translator->trans('ernestdefoe-docket.email.assigned.title', ['id' => $blueprint->report->id])">
    <x-slot:body>
        <p>{{ $translator->trans('ernestdefoe-docket.email.assigned.body', ['id' => $blueprint->report->id]) }}</p>
        <p><a href="{{ $url->to('forum')->route('docket.report', ['id' => $blueprint->report->id]) }}">{{ $translator->trans('ernestdefoe-docket.email.assigned.link') }}</a></p>
    </x-slot:body>
</x-mail::html.notification>
