{{--
    S90 — team invitation.

    Plain, correct, and accessible: a table-free single column, real text, and
    a link that is a link even when the styling does not load. The branded
    layout arrives in Slice 3 (S86) and replaces this file rather than being
    approximated here.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>You’ve been invited to {{ $teamName }}</title>
</head>
<body style="margin:0;padding:24px;background:#f6f6f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2328;line-height:1.5;">
<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:32px;">
    <h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;">You’ve been invited to {{ $teamName }}</h1>

    <p style="margin:0 0 16px;">
        @if ($inviterName)
            {{ $inviterName }} has invited you to join {{ $teamName }} on {{ config('app.name') }}.
        @else
            You’ve been invited to join {{ $teamName }} on {{ config('app.name') }}.
        @endif
    </p>

    <p style="margin:0 0 24px;">
        <a href="{{ $acceptUrl }}" style="display:inline-block;padding:12px 20px;background:#1f2328;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;">
            Accept the invitation
        </a>
    </p>

    <p style="margin:0 0 16px;color:#5b6169;font-size:14px;">
        This link works once and expires on {{ $expiresAt->toFormattedDayDateString() }}.
    </p>

    <p style="margin:0;color:#5b6169;font-size:14px;">
        If the button doesn’t work, copy this address into your browser:<br>
        <span style="word-break:break-all;">{{ $acceptUrl }}</span>
    </p>
</div>
</body>
</html>
