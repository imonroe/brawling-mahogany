{{--
    S86 — the base branded email layout (issue #97 · Design System §12).

    Every `Mailable` in `app/Mail` is drawn here. Three of them had their own
    frame before this one and had already drifted apart — two card widths, two
    greys, one with a footer and two without — which is Design System §12's
    whole argument for having a layout at all, arriving on schedule.

    **One email in the product is not drawn here, and the wording above is
    narrow on purpose.** Fortify's password reset is a framework notification
    rendering Laravel's own markdown mail, not a `Mailable` of ours. It goes to
    a colleague rather than to a client, it is the one flow ADR 0003 makes
    deliberately console-only in its second door, and bringing it into this
    frame means replacing Fortify's notification — a separate change with its
    own `EmailIndependence` consequences. `BrandedEmailLayoutTest` enumerates
    `app/Mail` and fails when a class is added without a render, which is the
    boundary this comment describes.

    ## What §12 asks for, and where each answer is

    | Rule | Here |
    |---|---|
    | Tables, no flex or grid | Every structural element below is a `<table>` |
    | Inline styles | All of them. The `<style>` block adds only dark mode |
    | 600px, single column | `.gf-shell`, with `width="100%"` under it |
    | Web-safe fonts | The stack below. Inter will not load |
    | Literal hex | `EmailPalette`, duplicated from §12.1 and held by a test |
    | Images with alt text | The logo, always, and assume it is blocked |
    | Bulletproof buttons | `mail.partials.button`, never a styled `<a>` alone |
    | `prefers-color-scheme` | The block below, degrading to the light design |
    | Plain text | A sibling `-text` view on every mailable, not a stripped tag |

    ## Why the class names carry a prefix

    An email lands inside somebody else's document — a webmail client's own
    page — and a class called `card` is a class that client may already have
    opinions about. The `gf-` prefix is the cheapest way to be sure the dark
    block below is talking about this message and nothing around it.

    ## The dark block is `!important` on purpose

    Every rule it overrides is an inline style, which wins on specificity
    against anything in a `<style>` block. A dark rule without `!important` is
    a rule that parses cleanly and does nothing, in the exact clients that
    support it — which is worse than not writing it, because it looks handled.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" style="margin:0;padding:0;">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{--
        Both, and they are not duplicates. `color-scheme` tells the client this
        message has a dark design, so it should stop inverting it itself;
        `supported-color-schemes` is Apple Mail's older spelling of the same
        claim. Omitting them leaves iOS Mail free to invert the light design
        *and* apply the dark block, which is how a message ends up with black
        text on a dark card.
    --}}
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $title ?? $brand->teamName }}</title>
    <style>
        @media (prefers-color-scheme: dark) {
            .gf-canvas { background-color: {{ \App\Support\Mail\EmailPalette::DARK_CANVAS }} !important; }
            .gf-card { background-color: {{ \App\Support\Mail\EmailPalette::DARK_BACKGROUND }} !important; }
            .gf-text { color: {{ \App\Support\Mail\EmailPalette::DARK_TEXT }} !important; }
            .gf-muted { color: {{ \App\Support\Mail\EmailPalette::DARK_MUTED_TEXT }} !important; }
            .gf-rule { border-color: {{ \App\Support\Mail\EmailPalette::DARK_BORDER }} !important; }
            .gf-panel { background-color: {{ \App\Support\Mail\EmailPalette::DARK_PANEL }} !important; }
            /*
             * Links keep being links. These rules used to be folded into the
             * two above as `.gf-text a`, which repainted every anchor a team
             * wrote into their own template to exactly the colour of the
             * paragraph around it — with `!important`, so the author's own
             * inline colour lost — and removed link affordance from the
             * client-facing half of the product in the one mode where the
             * team never sees it.
             */
            .gf-text a, .gf-muted a { color: {{ \App\Support\Mail\EmailPalette::DARK_PRIMARY }} !important; }
            .gf-warn { color: {{ \App\Support\Mail\EmailPalette::DARK_WARNING }} !important; border-color: {{ \App\Support\Mail\EmailPalette::DARK_WARNING }} !important; }
            .gf-danger { color: {{ \App\Support\Mail\EmailPalette::DARK_DANGER }} !important; border-color: {{ \App\Support\Mail\EmailPalette::DARK_DANGER }} !important; }
        }

        @media only screen and (max-width: 620px) {
            .gf-shell { width: 100% !important; }
            .gf-pad { padding-left: 20px !important; padding-right: 20px !important; }
        }
    </style>
</head>
<body class="gf-canvas" style="margin:0;padding:0;width:100%;background-color:{{ \App\Support\Mail\EmailPalette::CANVAS }};font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Helvetica,Arial,sans-serif;">

@hasSection('preheader')
    {{--
        The line a phone shows under the subject. Left out, a client picks the
        first words in the document, which here is the team's own name — so
        every message in an inbox would preview identically.

        The trailing joiners stop the client reaching past this span and
        pulling the greeting in after it.
    --}}
    <div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:{{ \App\Support\Mail\EmailPalette::CANVAS }};opacity:0;">
        @yield('preheader')
        {!! str_repeat('&#8204;&nbsp;', 60) !!}
    </div>
@endif

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="gf-canvas" style="width:100%;background-color:{{ \App\Support\Mail\EmailPalette::CANVAS }};">
    <tr>
        <td align="center" style="padding:24px 12px;">

            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" class="gf-shell" style="width:600px;max-width:600px;">

                {{-- The brand band. The one place a team's accent is a fill. --}}
                <tr>
                    <td style="background-color:{{ $brand->accent }};border-radius:10px 10px 0 0;padding:20px 32px;" class="gf-pad">
                        @if ($brand->hasLogo() && isset($message))
                            {{--
                                The plate is Design System §2.6's `--logo-plate`,
                                one universe over: a raster mark cannot answer
                                the theme, so it gets a ground that never
                                changes rather than a colour that might.

                                Embedded rather than linked. The bytes live on
                                a private disk, and a client reading this email
                                has no session to fetch them with — a `src`
                                pointing at the app would render as a broken
                                image for the one reader the logo is for.
                            --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="background-color:{{ \App\Support\Mail\EmailPalette::PLATE }};border-radius:6px;padding:8px 12px;">
                                        <img src="{{ $message->embedData($brand->logoBytes, 'logo', $brand->logoMimeType) }}"
                                             alt="{{ $brand->teamName }}"
                                             width="160"
                                             style="display:block;width:auto;max-width:160px;height:auto;max-height:44px;border:0;outline:none;text-decoration:none;">
                                    </td>
                                </tr>
                            </table>
                        @else
                            <span style="display:block;font-size:18px;line-height:24px;font-weight:700;color:{{ $brand->accentForeground }};">{{ $brand->teamName }}</span>
                        @endif
                    </td>
                </tr>

                @hasSection('banner')
                    <tr>
                        <td style="padding:0;">@yield('banner')</td>
                    </tr>
                @endif

                <tr>
                    <td class="gf-card gf-pad" style="background-color:{{ \App\Support\Mail\EmailPalette::BACKGROUND }};padding:32px;border-radius:0 0 10px 10px;">

                        @hasSection('headline')
                            {{--
                                Palette, not accent. The narrowing `BrandedEmail`
                                argues: a heading in a team's own deep blue is
                                unreadable the moment a client's phone inverts
                                the card behind it, and the darker the brand the
                                worse it gets.
                            --}}
                            <h1 class="gf-text" style="margin:0 0 8px;font-size:22px;line-height:30px;font-weight:700;color:{{ \App\Support\Mail\EmailPalette::TEXT }};">@yield('headline')</h1>
                        @endif

                        @hasSection('subhead')
                            {{-- `break-word` because S87's long-address state is a real one. --}}
                            <p class="gf-muted" style="margin:0 0 20px;font-size:15px;line-height:22px;color:{{ \App\Support\Mail\EmailPalette::MUTED_TEXT }};word-break:break-word;">@yield('subhead')</p>
                        @endif

                        <div class="gf-text" style="font-size:16px;line-height:24px;color:{{ \App\Support\Mail\EmailPalette::TEXT }};">
                            @yield('content')
                        </div>

                        @hasSection('cta')
                            <div style="padding-top:8px;">@yield('cta')</div>
                        @endif

                        @hasSection('footnote')
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;margin-top:28px;">
                                <tr>
                                    <td class="gf-rule" style="border-top:1px solid {{ \App\Support\Mail\EmailPalette::BORDER }};padding-top:16px;">
                                        <div class="gf-muted" style="font-size:13px;line-height:20px;color:{{ \App\Support\Mail\EmailPalette::MUTED_TEXT }};">@yield('footnote')</div>
                                    </td>
                                </tr>
                            </table>
                        @endif

                    </td>
                </tr>

                <tr>
                    <td class="gf-pad" style="padding:16px 32px 0;">
                        <p class="gf-muted" style="margin:0;font-size:12px;line-height:18px;color:{{ \App\Support\Mail\EmailPalette::MUTED_TEXT }};">
                            @hasSection('signoff')
                                @yield('signoff')
                            @else
                                Sent by {{ $brand->teamName }}.
                            @endif
                        </p>
                        {{--
                            PRD §8.5 wants one-click unsubscribe on anything not
                            strictly transactional. Everything this product
                            sends today is: a message about a deal somebody is
                            party to, an invitation somebody was named in, a
                            test send to its own author. The section is here so
                            Slice 6's Keep in Touch inherits the layout with the
                            block switched on rather than needing its own frame,
                            and it is empty until something is genuinely
                            marketing.
                        --}}
                        @hasSection('unsubscribe')
                            <p class="gf-muted" style="margin:8px 0 0;font-size:12px;line-height:18px;color:{{ \App\Support\Mail\EmailPalette::MUTED_TEXT }};">@yield('unsubscribe')</p>
                        @endif
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>
</body>
</html>
