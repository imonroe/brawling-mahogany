{{--
    The plain-text alternative, and **nothing here is HTML-escaped**.

    `MergeFields::resolve()` states the rule this view has to honour: escaping
    a merged value on its way into the plain-text half *"would put `&amp;` into
    the plain text alternative of every message sent to the O'Brien
    household."* The renderer is careful about it and hands `bodyText` over
    raw — and Blade's `{{ }}` is `e()` whatever the view's content type is, so
    this file undid it one layer later. A client called O'Brien arrived as
    `O&#039;Brien` on the wire while the in-app preview, which is Vue text
    interpolation rather than Blade, showed the name correctly. The two things
    this screen offers for checking a template disagreed, and the one that
    disagreed was the one on a transport.

    `{!! !!}` is safe here in a way it would not be in the HTML sibling: this
    is `text/plain`, so there is no markup for a value to escape into. Design
    System §12 makes this half *"what a watch, a screen reader, and an inbox
    with images turned off will show"* — it has to be the words, exactly.
--}}
This is a test of "{!! $templateName !!}" from {!! $teamName !!}. No client received it.
@if (count($malformed) > 0)

A merge field is missing a brace, so it went out as written — look for "{!! implode('" and "', $malformed) !!}".
@endif
@if (count($unknown) > 0)

No merge field is called {!! implode(', ', $unknown) !!}.
@endif
@if (count($unresolved) > 0)

These had nothing behind them on the deal you chose: {!! implode(', ', $unresolved) !!}.
@endif

----------------------------------------------------------------

{!! $bodyText !!}
@include('mail.partials.text-footer', ['brand' => $brand])
