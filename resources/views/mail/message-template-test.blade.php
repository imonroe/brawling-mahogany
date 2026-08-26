{{--
    S46 — a test send of a message template, in S86's frame.

    Deliberately framed rather than sent bare. A rendered template that looked
    exactly like the real thing would be forwarded to a colleague and read as
    one, so the banner and the `[Test]` subject travel with it.

    Now that the frame is the real one, the banner does more work than it did:
    this message is no longer distinguishable from a client's by its styling,
    because looking like a client's is the point of a test send.

    `{!! $bodyHtml !!}` is unescaped on purpose and is one of two places in
    this application that render a template body as markup. The body is written
    by somebody holding `templates.manage`, it is their own outbound email, and
    this message can only reach the person who asked for it. The in-app preview
    is the case that is **not** safe — a colleague opening S46 — and it renders
    in a sandboxed iframe rather than through `v-html`.
--}}
@extends('mail.layout')

@section('preheader')A test of “{{ $templateName }}”. No client received it.@endsection

@section('banner')
    @include('mail.partials.notice', [
        'tone' => 'warning',
        'body' => 'This is a test of “'.$templateName.'” from '.$teamName.'. No client received it.',
    ])

    @if (count($malformed) > 0 || count($unknown) > 0 || count($unresolved) > 0)
        @include('mail.partials.notice', [
            'tone' => 'danger',
            /*
             * The three lists separately, never flattened into one. They have
             * three different fixes, and one label for all of them said the
             * wrong thing about two — the note in `MessageTemplateTestMail`
             * has the worked example.
             */
            'body' => trim(implode(' ', array_filter([
                count($malformed) > 0
                    ? 'A merge field is missing a brace, so it went out as written — look for “'.implode('” and “', $malformed).'”.'
                    : null,
                count($unknown) > 0
                    ? 'No merge field is called '.implode(', ', $unknown).'.'
                    : null,
                count($unresolved) > 0
                    ? 'These had nothing behind them on the deal you chose: '.implode(', ', $unresolved).'.'
                    : null,
            ]))),
        ])
    @endif
@endsection

@section('content')
    @if ($bodyHtml)
        {!! $bodyHtml !!}
    @else
        <pre style="margin:0;font-family:inherit;white-space:pre-wrap;word-break:break-word;">{{ $bodyText }}</pre>
    @endif
@endsection
