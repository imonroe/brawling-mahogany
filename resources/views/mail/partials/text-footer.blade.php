{{--
    The plain-text signoff, shared so the three text alternatives agree with
    each other and with the HTML footer.

    **Nothing here is escaped**, and the reason is the one every `-text` view
    in this directory carries: Blade's `{{ }}` is `e()` whatever the content
    type is, so a team called O'Brien & Co arrives on the wire as
    `O&#039;Brien &amp; Co` — in the one half a screen reader and a watch
    actually read. `{!! !!}` is safe here in a way it would not be in HTML,
    because `text/plain` has no markup for a value to escape into.
--}}

--
Sent by {!! $brand->teamName !!}.
