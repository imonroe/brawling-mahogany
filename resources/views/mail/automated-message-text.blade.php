{{--
    The plain-text alternative, and **nothing here is HTML-escaped**.

    Same rule, and the same reason, as `mail.message-template-test-text`:
    Blade's `{{ }}` is `e()` whatever the view's content type is, so a client
    called O'Brien arrives as `O&#039;Brien` on the wire. `RenderMessage`
    deliberately hands the text half over raw; escaping it here would undo that
    one layer later, on the one half a watch and a screen reader actually read.

    `{!! !!}` is safe here in a way it would not be in the HTML sibling: this
    is `text/plain`, so there is no markup for a value to escape into.

    S87's announcement is repeated here rather than left to the HTML half.
    Design System §12 asks for *"a real plain-text alternative, not a stripped-tag
    afterthought"*, and a milestone email whose headline and listing link exist
    only in the markup is exactly the afterthought it means.
--}}
@if ($redirected)
Sandbox mode is on for {!! $teamName !!}, so this came to you instead of the people it names. Nobody outside the team received it.

----------------------------------------------------------------

@endif
@if ($milestone)
{!! $milestone->headline !!}
@if ($milestone->propertyAddress)
{!! $milestone->propertyAddress !!}
@endif

@endif
{!! $bodyText !!}
@if ($milestone && $milestone->callToAction())

{!! $milestone->callToAction()['label'] !!}: {!! $milestone->callToAction()['url'] !!}
@endif
@include('mail.partials.text-footer', ['brand' => $brand])
