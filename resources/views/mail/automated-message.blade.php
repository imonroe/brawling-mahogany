{{--
    The HTML half of a team's own message to a client (#92), in S86's frame,
    carrying S87's milestone announcement when there is one.

    `{!! $bodyHtml !!}` is unescaped, and this is the second of the two places
    in this application that renders a template body as markup — S46's test
    send is the other, and its view argues the case at length. The short
    version: the body was written by somebody holding `templates.manage`, it is
    that team's own outbound email, and the merged values were escaped on the
    way in by `RenderMessage::asHtml()`. What a client's browser must never
    receive unescaped is the *client's* data, and that is already handled one
    layer up.

    The sandbox banner is not decoration. F5.9's sandbox rewrites the recipient
    to the team owner, and an owner holding a message that looks exactly like
    the real one is an owner who forwards it to the client believing the
    product already did. That was true when the frame was plain and it is more
    true now that the frame is the real one.
--}}
@extends('mail.layout')

@if ($milestone)
    @section('preheader'){{ $milestone->headline }}@endsection
    @section('headline'){{ $milestone->headline }}@endsection
    @if ($milestone->propertyAddress)
        @section('subhead'){{ $milestone->propertyAddress }}@endsection
    @endif
@endif

@if ($redirected)
    @section('banner')
        @include('mail.partials.notice', [
            'tone' => 'warning',
            'body' => 'Sandbox mode is on for '.$teamName.', so this came to you instead of the people it names. '
                .'Nobody outside the team received it. Turn sandbox mode off in team settings when you are ready to send for real.',
        ])
    @endsection
@endif

@section('content')
    @if ($bodyHtml)
        {!! $bodyHtml !!}
    @else
        <pre style="margin:0;font-family:inherit;white-space:pre-wrap;word-break:break-word;">{{ $bodyText }}</pre>
    @endif
@endsection

@if ($milestone && $milestone->callToAction())
    @section('cta')
        @include('mail.partials.button', [
            'url' => $milestone->callToAction()['url'],
            'label' => $milestone->callToAction()['label'],
            'brand' => $brand,
        ])
    @endsection
@endif
