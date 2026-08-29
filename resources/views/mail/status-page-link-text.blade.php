{{--
    Nothing here is HTML-escaped, for the reason every `-text` view in this
    directory carries: Blade's `{{ }}` is `e()` whatever the content type is,
    and a client named O'Brien would be greeted as `O&#039;Brien`.
--}}
{!! $what !!}

Hello {!! $clientName !!},

{!! $team->name !!} has a page that shows where things stand. It opens with the
link below — there is nothing to sign in to.

{!! $url !!}

The link works for the next {!! $minutes !!} minutes. After that, open the page
again from the last email you received, or ask {!! $team->name !!} for a new one.

If you were not expecting this, nothing has happened and you can ignore it. The
link only shows the progress of one transaction.
@include('mail.partials.text-footer', ['brand' => $brand])
