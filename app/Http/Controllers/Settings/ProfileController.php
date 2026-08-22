<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Teams\RevokeMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Support\Tenancy\TeamContext;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request, TeamContext $teams): Response
    {
        $membership = $teams->get() === null ? null : $request->user()->membershipIn($teams->get());

        return Inertia::render('Settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            // The name is this team's record of them (#140), so a person in
            // two teams edits it once per team — which is correct, and is why
            // the screen says which team it is changing.
            'teamName' => $teams->get()?->name,
            'firstName' => $membership?->first_name,
            'lastName' => $membership?->last_name,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    /**
     * Two records, and the split is the point (#140).
     *
     * The sign-in address is the account and lives on `people`. The name is
     * what a team calls them and lives on that team's membership. Changing an
     * address re-verifies it; changing a name does not, and never touches
     * another team's row.
     */
    public function update(ProfileUpdateRequest $request, TeamContext $teams): RedirectResponse
    {
        $validated = $request->validated();
        $person = $request->user();

        $person->fill(['email' => $validated['email'] ?? null]);

        if ($person->isDirty('email')) {
            $person->email_verified_at = null;
        }

        $person->save();

        $team = $teams->get();
        $membership = $team === null ? null : $person->membershipIn($team);

        // A person with no team has nowhere to keep a name yet. Their account
        // is still theirs to edit; the name arrives with the first membership.
        $membership?->forceFill([
            'first_name' => $validated['first_name'] ?? $membership->first_name,
            'last_name' => $validated['last_name'] ?? null,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete this person's own account.
     *
     * The last-owner rule applies here as much as on the members screen
     * (issue #45). Revoking the last owner there is refused; deleting the
     * account was the way round the back, and it left the team unadministrable
     * with no route to repair it.
     */
    public function destroy(ProfileDeleteRequest $request, RevokeMembership $revoke): RedirectResponse
    {
        $user = $request->user();

        $revoke->guardLastOwnerAnywhere($user);

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
