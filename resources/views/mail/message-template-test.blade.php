{{--
    S46 — a test send of a message template.

    Deliberately framed rather than sent bare. A rendered template that looked
    exactly like the real thing would be forwarded to a colleague and read as
    one, so the banner and the `[Test]` subject travel with it.

    `{!! $bodyHtml !!}` is unescaped on purpose and is the *only* place in this
    application that renders a template body as markup. The body is written by
    somebody holding `templates.manage`, it is their own outbound email, and
    this message can only reach the person who asked for it. The in-app preview
    is the case that is **not** safe — a colleague opening S46 — and it renders
    in a sandboxed iframe rather than through `v-html`.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $templateName }}</title>
</head>
<body style="margin:0;padding:24px;background:#f6f6f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2328;line-height:1.5;">
<div style="max-width:600px;margin:0 auto;">
    <p style="margin:0 0 12px;padding:12px 16px;background:#fff4d6;border-radius:6px;font-size:14px;color:#5b4300;">
        This is a test of “{{ $templateName }}” from {{ $teamName }}. No client received it.
    </p>

    @if (count($malformed) > 0 || count($unknown) > 0 || count($unresolved) > 0)
        <p style="margin:0 0 12px;padding:12px 16px;background:#fdecec;border-radius:6px;font-size:14px;color:#7a1b1a;">
            @if (count($malformed) > 0)
                A merge field is missing a brace, so it went out as written —
                look for “{{ implode('” and “', $malformed) }}”.
            @endif
            @if (count($unknown) > 0)
                No merge field is called {{ implode(', ', $unknown) }}.
            @endif
            @if (count($unresolved) > 0)
                These had nothing behind them on the deal you chose:
                {{ implode(', ', $unresolved) }}.
            @endif
        </p>
    @endif

    <div style="background:#ffffff;border-radius:8px;padding:32px;">
        @if ($bodyHtml)
            {!! $bodyHtml !!}
        @else
            <pre style="margin:0;font-family:inherit;white-space:pre-wrap;">{{ $bodyText }}</pre>
        @endif
    </div>
</div>
</body>
</html>
