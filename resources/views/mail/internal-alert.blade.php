{{--
    S91 — internal alert. Team-facing, so the words are the internal ones.

    A headline, a sentence, and one link. There is deliberately no banner
    above the headline: the first version put the same string in both, which
    on the shortest email in the product reads as a rendering bug rather than
    as emphasis.
--}}
@extends('mail.layout')

@section('preheader'){{ $detail }}@endsection

@section('headline'){{ $headline }}@endsection

@section('content')
    <p style="margin:0 0 20px;word-break:break-word;">{{ $detail }}</p>

    {{--
        S88's *"several dates"* state (#109), and the shape that kept this one
        mailable rather than growing a second internal front door.

        A list rather than more sentences: #109 asks for **one** email covering
        several deadlines, and one email whose four dates are run together in a
        paragraph is the one that gets filtered. Empty for every other caller,
        so nothing renders.

        `{{ }}` escapes, which matters here more than in most of this
        directory: the lines carry key date names and deal names a team typed.

        The border class is conditional for a reason §12 records: a dark-mode
        block that does not name the rule it overrides does nothing, and
        `gf-rule` would repaint an emphasised border back to the ordinary one
        in dark mode — losing the emphasis in exactly the theme most people
        read a 6am email in. `gf-warn` carries the dark warning border.
    --}}
    @if (! empty($lines ?? []))
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;margin:0 0 20px;">
            @foreach ($lines as $line)
                <tr>
                    <td class="gf-panel {{ ($emphasis ?? false) ? 'gf-warn' : 'gf-rule' }}" style="background-color:{{ \App\Support\Mail\EmailPalette::PANEL }};padding:10px 14px;border-left:{{ ($emphasis ?? false) ? '4px' : '1px' }} solid {{ ($emphasis ?? false) ? \App\Support\Mail\EmailPalette::WARNING : \App\Support\Mail\EmailPalette::BORDER }};">
                        <p class="gf-text" style="margin:0;font-size:14px;line-height:21px;color:{{ \App\Support\Mail\EmailPalette::TEXT }};">{{ $line }}</p>
                    </td>
                </tr>
                @if (! $loop->last)
                    <tr><td style="height:6px;line-height:6px;font-size:0;">&nbsp;</td></tr>
                @endif
            @endforeach
        </table>
    @endif
@endsection

@section('cta')
    @include('mail.partials.button', [
        'url' => $actionUrl,
        'label' => $actionLabel,
        'brand' => $brand,
    ])
@endsection
