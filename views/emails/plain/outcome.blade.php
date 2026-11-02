<x-mail::plain.notification :title="$translator->trans('ernestdefoe-docket.email.outcome.subject')">
<x-slot:body>
{!! $translator->trans('ernestdefoe-docket.email.outcome.body_'.$blueprint->status) !!}
@if ($blueprint->message)

{!! $blueprint->message !!}
@endif

{!! $url->to('forum')->route('docket.mine') !!}
</x-slot:body>
</x-mail::plain.notification>
