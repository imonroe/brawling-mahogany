{{--
    Nothing here is HTML-escaped, for the reason every `-text` view in this
    directory carries: Blade's `{{ }}` is `e()` whatever the content type is,
    and a deal named after the O'Brien household would arrive as
    `O&#039;Brien`. `text/plain` has no markup for a value to escape into.
--}}
{!! $headline !!}

{!! $detail !!}

{!! $actionLabel !!}:
{!! $actionUrl !!}
@if ($footnote)

{!! $footnote !!}
@endif
@include('mail.partials.text-footer', ['brand' => $brand])
