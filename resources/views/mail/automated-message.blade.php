{{--
    The HTML half of a team's own message to a client (#92).

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
    product already did.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $teamName }}</title>
</head>
<body style="margin:0;padding:24px;background:#f6f6f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2328;line-height:1.5;">
<div style="max-width:600px;margin:0 auto;">
    @if ($redirected)
        <p style="margin:0 0 12px;padding:12px 16px;background:#fff4d6;border-radius:6px;font-size:14px;color:#5b4300;">
            Sandbox mode is on for {{ $teamName }}, so this came to you instead of
            the people it names. Nobody outside the team received it. Turn sandbox
            mode off in team settings when you are ready to send for real.
        </p>
    @endif

    <div style="background:#ffffff;border-radius:8px;padding:32px;">
        @if ($bodyHtml)
            {!! $bodyHtml !!}
        @else
            <pre style="margin:0;font-family:inherit;white-space:pre-wrap;">{{ $bodyText }}</pre>
        @endif
    </div>

    <p style="margin:16px 0 0;font-size:12px;color:#6b7280;">
        Sent by {{ $teamName }}.
    </p>
</div>
</body>
</html>
