{{--
    Design System §12's *"bulletproof table-cell buttons, never a styled `<a>`
    alone"*.

    Outlook's word renderer does not draw `padding` on an inline element and
    does not draw `border-radius` at all, so a styled anchor arrives as blue
    underlined text with no box around it — legible, and no longer the thing
    the eye is meant to land on. The colour therefore lives on a table cell,
    which Outlook does draw, and the anchor inside it is only the hit area.

    `$url` is expected to have been through `App\Support\Links\SafeUrl` or to
    be one this application built. It is never a value typed into a template
    body: those land in the body's own markup, where `RenderMessage` escaped
    them.
--}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 4px;">
    <tr>
        <td align="center" style="background-color:{{ $brand->accent }};border-radius:6px;">
            <a href="{{ $url }}"
               style="display:inline-block;padding:13px 24px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:15px;line-height:20px;font-weight:600;color:{{ $brand->accentForeground }};text-decoration:none;border-radius:6px;">{{ $label }}</a>
        </td>
    </tr>
</table>
