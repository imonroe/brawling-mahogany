{{--
    S89 — the magic link (#110).

    Written for somebody who has never used this product and will use it about
    four times. IA §9: no jargon, no instructions, no alarming words — and no
    mention of a token, a session, or authentication.
--}}
@extends('mail.layout')

@section('preheader')Here is the link to see where things stand.@endsection

@section('headline'){{ $what }}@endsection

@section('content')
    <p style="margin:0 0 20px;word-break:break-word;">Hello {{ $clientName }},</p>

    <p style="margin:0 0 20px;word-break:break-word;">
        {{ $team->name }} has a page that shows where things stand. It opens
        with the link below — there is nothing to sign in to.
    </p>

    {{--
        The expiry note. Stated as a fact about the link rather than as an
        instruction to hurry: "act now" is the register of a marketing email,
        and this one is about somebody's house.
    --}}
    <p class="gf-muted" style="margin:0 0 20px;word-break:break-word;">
        The link works for the next {{ $minutes }} minutes. After that, open the
        page again from the last email you received, or ask
        {{ $team->name }} for a new one.
    </p>
@endsection

@section('cta')
    @include('mail.partials.button', [
        'url' => $url,
        'label' => 'See where things stand',
        'brand' => $brand,
    ])
@endsection

{{--
    Screen Inventory's third state, and the one a stranger needs most.
    `footnote` rather than a section of my own: the layout draws it under a
    rule in muted type, which is exactly the weight this belongs at.
--}}
@section('footnote')
    If you were not expecting this, nothing has happened and you can ignore it.
    The link only shows the progress of one transaction.
@endsection
