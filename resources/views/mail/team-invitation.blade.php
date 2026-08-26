{{--
    S90 — team invitation, now wearing S86's frame.

    The file it replaces said it would: *"the branded layout arrives in Slice 3
    (S86) and replaces this file rather than being approximated here."*

    The branding is the **inviting team's**, which is a small decision worth
    naming. An invitation is the one message that goes to somebody who is not
    yet in the team, so there is an argument for the product's own frame — but
    the recipient was invited by a person at an agency whose name they know,
    and a message wearing that agency's mark is the one they will not delete
    as spam.
--}}
@extends('mail.layout')

@section('preheader'){{ $inviterName ? $inviterName.' has invited you to join '.$teamName.'.' : 'You’ve been invited to join '.$teamName.'.' }}@endsection

@section('headline')You’ve been invited to {{ $teamName }}@endsection

@section('content')
    <p style="margin:0 0 20px;">
        @if ($inviterName)
            {{ $inviterName }} has invited you to join {{ $teamName }} on {{ config('app.product_name') }}.
        @else
            You’ve been invited to join {{ $teamName }} on {{ config('app.product_name') }}.
        @endif
    </p>
@endsection

@section('cta')
    @include('mail.partials.button', [
        'url' => $acceptUrl,
        'label' => 'Accept the invitation',
        'brand' => $brand,
    ])
@endsection

@section('footnote')
    <p style="margin:0 0 12px;">This link works once and expires on {{ $expiresAt->toFormattedDayDateString() }}.</p>
    <p style="margin:0;">
        If the button doesn’t work, copy this address into your browser:<br>
        <span style="word-break:break-all;">{{ $acceptUrl }}</span>
    </p>
@endsection
