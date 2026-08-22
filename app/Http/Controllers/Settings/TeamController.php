<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Audit\AuditLogger;
use App\Support\Branding\AccentContrast;
use App\Support\Tenancy\TeamContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function edit(TeamContext $teams): Response
    {
        $team = $teams->get();

        $this->authorize('update', $team);

        return Inertia::render('Settings/Team', [
            'team' => [
                'name' => $team->name,
                'slug' => $team->slug,
                'timezone' => $team->timezone,
                'logoPath' => $team->logo_path,
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
}
