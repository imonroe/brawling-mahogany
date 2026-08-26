{{--
    A banner above the card: a sandbox redirect, a test send, a merge-field
    problem. Three views had three slightly different versions of this, in
    three slightly different yellows.

    `$tone` is `warning` or `danger`, and nothing else — a caller asking for a
    tone this does not know gets the warning rather than an unstyled block,
    which is `lib/states.ts`'s rule pointed at a surface that cannot throw
    usefully. Colours come from §12.1 and never from the team: a warning that
    wore a tenant's accent would be a warning a tenant could make invisible.
--}}
@php
    $danger = ($tone ?? 'warning') === 'danger';
    $ink = $danger ? \App\Support\Mail\EmailPalette::DANGER : \App\Support\Mail\EmailPalette::WARNING;
    $toneClass = $danger ? 'gf-danger' : 'gf-warn';
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;">
    <tr>
        <td class="gf-panel gf-pad {{ $toneClass }}" style="background-color:{{ \App\Support\Mail\EmailPalette::PANEL }};padding:14px 32px;border-left:4px solid {{ $ink }};">
            <p class="{{ $toneClass }}" style="margin:0;font-size:14px;line-height:21px;color:{{ $ink }};">{{ $body }}</p>
        </td>
    </tr>
</table>
