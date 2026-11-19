<x-mail::plain.notification :title="$translator->trans('ernestdefoe-docket.email.assigned.title', ['id' => $blueprint->report->id])">
<x-slot:body>
{!! $translator->trans('ernestdefoe-docket.email.assigned.body', ['id' => $blueprint->report->id]) !!}

{!! $url->to('forum')->route('docket.report', ['id' => $blueprint->report->id]) !!}
</x-slot:body>
</x-mail::plain.notification>
