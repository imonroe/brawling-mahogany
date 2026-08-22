<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Teams\RevokeMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        /*
         * All of it, or none of it.
         *
         * Without the transaction the address and the memberships could
         * disagree by half — and unrecoverably, because the propagation below
         * finds memberships by the **old** address. A second attempt would
         * find none still carrying it, skip the propagation entirely, and
         * leave the stale rows permanent.
         */
        DB::transaction(fn () => $this->applyProfile($request, $teams));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    private function applyProfile(ProfileUpdateRequest $request, TeamContext $teams): void
    {
        $validated = $request->validated();
        $person = $request->user();

        $person->fill(['email' => $validated['email'] ?? null]);

        $previousAddress = $person->getRawOriginal('email');
        $addressChanged = $person->isDirty('email');

        if ($addressChanged) {
            $person->email_verified_at = null;
        }

        $person->save();

        /*
         * The sign-in address and the address a team holds are two columns
         * (#140), and changing one has to move the other or the members list
         * shows an address that stopped working.
         *
         * Only the memberships that were carrying the **old login address**.
         * A membership whose address a team typed for itself — a contact who
         * later got a login through some other route — is that team's record
         * of how to reach them, and a person editing their own profile is not
         * entitled to rewrite it. Matching on the old address is what tells
         * the two apart without needing a flag to remember.
         */
        if ($addressChanged && is_string($previousAddress) && $previousAddress !== '') {
            $carryingOldAddress = TeamMembership::withoutTeamScope()
                ->where('person_id', $person->getKey())
                ->whereNull('revoked_at')
                ->whereRaw('lower(email) = ?', [mb_strtolower($previousAddress)])
                ->with('team')
                ->get();

            foreach ($carryingOldAddress as $held) {
                /*
                 * **The read was lifted out of the scope; the write has to go
                 * back into it.**
                 *
                 * `withoutTeamScope()` only lifts the global *select* scope.
                 * `BelongsToTeam`'s `updating` hook still refuses a row whose
                 * `team_id` is not the resolved one, so saving another team's
                 * membership from inside this request threw
                 * `CrossTenantException` — a 500 for anybody who belongs to
                 * two teams, which is the ordinary case this whole column move
                 * exists to serve.
                 *
                 * `runFor` resolves each team for the length of its own write,
                 * which is the same thing `AcceptInvitation` does and the same
                 * thing `RevokeMembership`'s docblock records learning a
                 * review round earlier.
                 */
                $teams->runFor($held->team, function () use ($held, $person): void {
                    /*
                     * One team cannot hold one address twice, and the new one
                     * may already be in this team's directory as somebody
                     * else — a contact a colleague added last week, say. The
                     * index would refuse the write, and a 500 on the profile
                     * screen is a worse answer than a stale address on the
                     * members list.
                     *
                     * So the team keeps what it had, and the login address
                     * changes regardless: the two columns exist precisely
                     * because they are allowed to disagree.
                     */
                    $taken = TeamMembership::query()
                        ->whereKeyNot($held->getKey())
                        ->whereNull('revoked_at')
                        ->whereRaw('lower(email) = ?', [mb_strtolower((string) $person->email)])
                        ->exists();

                    if (! $taken) {
                        $held->forceFill(['email' => $person->email])->save();
                    }
                });
            }
        }

        $team = $teams->get();
        $membership = $team === null ? null : $person->membershipIn($team);

        // A person with no team has nowhere to keep a name yet. Their account
        // is still theirs to edit; the name arrives with the first membership.
        $membership?->forceFill([
            'first_name' => $validated['first_name'] ?? $membership->first_name,
            'last_name' => $validated['last_name'] ?? null,
        ])->save();
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
