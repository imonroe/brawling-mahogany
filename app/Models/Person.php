<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasProductDefaults;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * **The login.** One row per human who can sign in (issue #140).
 *
 * That is narrower than it was, and the narrowing is the point. Slice 1 put a
 * human's name, address, and phone number here and shared the row across
 * teams, so adding somebody by an address another team had entered showed you
 * what that team had typed. Slice 2 moved every team-visible field onto
 * `team_memberships`, where it is private by construction rather than by a
 * rule somebody has to remember.
 *
 * What is left is what makes authentication work: the sign-in address, the
 * password, the second factor, the passkeys, the super-admin flag. **Nothing
 * a team types.** So there is nothing here for one team to show another, and
 * the identity-write machinery Slice 1 needed — an `updating` hook,
 * `identityIsEditableBy()` — is gone with the problem it solved.
 *
 * ## Credentials are still optional, and the null now means something precise
 *
 * IA §11 is exact about why the word matters: *User* means somebody with a
 * login, so a client in the directory is a Person and calling them a user
 * would imply an account they do not have. Most people in this product never
 * sign in, and their row here holds a null `email` and a null `password`.
 *
 * A **null `email` means no login**, where before it meant "a contact who
 * gave us no address". The address a team holds for somebody lives on the
 * membership now. `NullPasswordsCannotAuthenticate`
 * (App\Providers\FortifyServiceProvider) is what makes the null password real.
 *
 * ## What a person is called
 *
 * Not here. `TeamMembership::fullName()`, because a name is something a team
 * recorded — and two teams may legitimately have written it differently.
 * `Person::displayNameWithin()` exists for the one case that has no membership
 * to read: a platform administrator acting outside any team.
 *
 * @property string $id
 * @property string|null $email
 * @property bool $is_super_admin
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class Person extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory, HasProductDefaults, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected $table = 'people';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_super_admin' => 'boolean',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<TeamMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TeamMembership::class);
    }

    /**
     * A sign-in address is stored folded to lower case.
     *
     * The unique index is over `lower(email)`, and one human is one account
     * whatever their mail client capitalised. Normalising on the way in means
     * every lookup — this model's, the invitation's, the reset form's — asks
     * the question the index answers.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null || trim($value) === ''
                ? null
                : mb_strtolower(trim($value)),
        );
    }

    /** A person who can sign in at all. Most people in the directory cannot. */
    public function hasCredentials(): bool
    {
        return is_string($this->password) && $this->password !== '';
    }

    protected static function booted(): void
    {
        static::deleting(function (self $person): void {
            $person->revokeEveryMembership();
        });
    }

    /**
     * A deleted account cannot go on being a live member of anything.
     *
     * Without this the membership rows outlive the person and every screen
     * that renders them dereferences a null. `/settings/members` was the worst
     * of them: it 500s for the whole team, and it is the only screen that
     * could have undone the membership.
     */
    public function revokeEveryMembership(): void
    {
        TeamMembership::withoutTeamScope()
            ->where('person_id', $this->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * The membership this person holds in a team, revoked ones excluded.
     */
    public function membershipIn(Team $team): ?TeamMembership
    {
        return TeamMembership::withoutTeamScope()
            ->where('team_id', $team->getKey())
            ->where('person_id', $this->getKey())
            ->whereNull('revoked_at')
            ->first();
    }

    /**
     * What to call this person on a screen inside a team.
     *
     * The membership answers it, because a name is something a team recorded.
     * The fallbacks are for the one case with no membership to read — a
     * platform administrator acting outside any team, whose name appears on an
     * audit entry. Their sign-in address is the only thing anybody knows about
     * them, and it is not a client's address, so showing it is not a
     * disclosure.
     */
    public function displayNameWithin(?Team $team): string
    {
        $membership = $team instanceof Team ? $this->membershipIn($team) : null;

        if ($membership instanceof TeamMembership) {
            return $membership->fullName();
        }

        return $this->email ?? 'Unknown';
    }

    /**
     * Every team this person can currently **act in**, for the switcher (S09).
     *
     * Being in a team's directory is not being on the team. A client, a
     * vendor, and an opposing agent all hold a `team_memberships` row — that
     * is what the table is for — and none of them may open the agent's
     * dashboard. Counting any membership here handed the tenant to anybody a
     * team merely knew who happened to have a password.
     *
     * The test is `TeamMembership::carriesAccess()`, asked in SQL — a role
     * carrying at least one **team-surface** permission (#142). It covers the
     * shipped roles, covers a team's own composed roles (PRD F2.3) without
     * needing a list of their keys, and keeps a Status Viewer out of the
     * switcher once #110 gives that role `status_page.view`: reading a status
     * page through a magic link is not a team you can switch into.
     *
     * @return \Illuminate\Support\Collection<int, Team>
     */
    public function activeTeams(): \Illuminate\Support\Collection
    {
        return Team::query()
            ->whereIn('id', TeamMembership::withoutTeamScope()
                ->where('person_id', $this->getKey())
                ->whereNull('revoked_at')
                ->carryingAccess()
                ->select('team_id'))
            ->whereNull('suspended_at')
            ->orderBy('name')
            ->get();
    }

    /**
     * People who can actually sign in.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithLogin(Builder $query): Builder
    {
        return $query->whereNotNull('password');
    }
}
