<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Audit\AuditLogger;
use App\Support\Branding\AccentContrast;
use App\Support\Branding\TeamLogo;
use App\Support\Documents\UnsupportedDocument;
use App\Support\Tenancy\TeamContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Team profile and branding (Screen Inventory S72 · Design System §2.7).
 *
 * The branding set here is what a client sees on the status page (Slice 4) and
 * in every automated email (Slice 3). Emily *"will not read documentation"*
 * (PRD §3.1) and will not imagine what a hex value looks like on a phone,
 * which is why the screen carries a live preview.
 *
 * On the open question in Design System §15.6 — warn about a low-contrast
 * accent, or silently adjust it — this **warns**. The client status page is
 * held to WCAG 2.1 AA (PRD §9), and a silently altered colour is a support
 * ticket that arrives later and angrier.
 */
class TeamController extends Controller
{
    public function edit(TeamContext $teams, TeamLogo $logos): Response
    {
        $team = $teams->get();

        $this->authorize('update', $team);

        return Inertia::render('Settings/Team', [
            'team' => [
                'name' => $team->name,
                'slug' => $team->slug,
                'timezone' => $team->timezone,
                /*
                 * Whether there is one, never where it is. The path is a key
                 * on a private disk and the screen has no use for it — it
                 * renders the logo through `team.logo.show`, which authorizes.
                 */
                'hasLogo' => $logos->exists($team),
                'brandAccentColor' => $team->brand_accent_color,
                'sendingIdentityName' => $team->sending_identity_name,
                'sendingIdentityEmail' => $team->sending_identity_email,
                'signatureBlock' => $team->signature_block,
            ],
            'timezones' => timezone_identifiers_list(),
            'accentWarning' => AccentContrast::warningFor($team->brand_accent_color),
        ]);
    }

    public function update(Request $request, TeamContext $teams, AuditLogger $audit): RedirectResponse
    {
        $team = $teams->get();

        $this->authorize('update', $team);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'brand_accent_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sending_identity_name' => ['nullable', 'string', 'max:255'],
            'sending_identity_email' => ['nullable', 'string', 'email', 'max:255'],
            'signature_block' => ['nullable', 'string', 'max:2000'],
        ]);

        $team->fill($validated)->save();

        $audit->recordChange('team.updated', $team);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team updated.')]);

        return to_route('team.edit');
    }

    /**
     * Replace the team's logo (S86's *"per-team logo"*, issue #55's other half).
     *
     * The `max` rule and `TeamLogo::MAX_BYTES` say the same thing twice on
     * purpose: the validator gives a person a message on the form, and the
     * storage class refuses bytes that reached it another way. Neither is
     * redundant — the second is the one that holds for a caller with no form
     * in front of it.
     */
    public function storeLogo(Request $request, TeamContext $teams, TeamLogo $logos, AuditLogger $audit): RedirectResponse
    {
        $team = $teams->get();

        $this->authorize('update', $team);

        $request->validate([
            'logo' => ['required', 'file', 'max:'.(int) (TeamLogo::MAX_BYTES / 1024)],
        ]);

        try {
            $logos->store($team, $request->file('logo'));
        } catch (UnsupportedDocument $refusal) {
            /*
             * A refusal a person can act on rather than a 500, and one that
             * never names the file: PRD §9 keeps PII out of logs, and a
             * filename is often the most descriptive thing about an upload.
             */
            return back()->withErrors(['logo' => $refusal->getMessage()]);
        }

        $audit->recordChange('team.logo.updated', $team);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Logo updated.')]);

        return to_route('team.edit');
    }

    public function destroyLogo(TeamContext $teams, TeamLogo $logos, AuditLogger $audit): RedirectResponse
    {
        $team = $teams->get();

        $this->authorize('update', $team);

        $logos->delete($team);

        $audit->recordChange('team.logo.removed', $team);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Logo removed.')]);

        return to_route('team.edit');
    }

    /**
     * Stream the logo back for the settings screen.
     *
     * `view` rather than `update`: everybody in the team sees their own
     * branding, and only an owner changes it. Not audited, unlike a document
     * download — PRD §9 makes *document* access an audited event because a
     * document is a client's paperwork, and an entry per render of a settings
     * page would bury the entries that matter under a team's own letterhead.
     */
    public function showLogo(TeamContext $teams, TeamLogo $logos): HttpResponse
    {
        $team = $teams->get();

        $this->authorize('view', $team);

        $contents = $logos->contents($team);
        $mime = $logos->mimeType($team);

        abort_if($contents === null || $mime === null, 404);

        return response($contents, 200, [
            /*
             * The type this application chose from its own allowlist, never
             * the browser's claim, and `nosniff` so a client cannot decide
             * otherwise — the same treatment S38's photographs get.
             */
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
