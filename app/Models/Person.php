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
 * One record per human (PRD §4.2 F2.1 · IA §2).
 *
 * **Credentials are optional.** Most people in this product — clients,
 * vendors, opposing agents, the other side's title officer — never log in, and
 * `password` is null for all of them. IA §11 is precise about why the word
 * matters: *User* means somebody with a login, so a client in the people
 * directory is a Person and calling them a user would imply an account they do
 * not have.
 *
 * The class extends Laravel's `Authenticatable` because a Person *may* hold
 * credentials, not because they all do. `NullPasswordsCannotAuthenticate`
 * (App\Providers\FortifyServiceProvider) is what makes the difference real.
 *
 * Deliberately **not** team-scoped. Issue #18 settled it the way PRD §6.2
 * proposed: one row per human shared across teams, with everything a team
 * knows privately about them on `team_memberships`.
 *
 * @property string $id
 * @property string $first_name
 * @property string|null $last_name
 * @property string $email
 * @property string|null $phone
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
#[Fillable(['first_name', 'last_name', 'email', 'phone', 'password'])]
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
     * An address is stored folded to lower case.
     *
     * The unique index is over `lower(email)`, and a person is one person
     * whatever their mail client capitalised. Normalising on the way in means
     * every lookup — this model's, the invitation's, the import's — is asking
     * the same question the index answers.
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

    /** Display form, IA §10: First Last. */
    public function fullName(): string
    {
        return trim($this->first_name.' '.($this->last_name ?? ''));
    }

    /** A person who can sign in at all. Most people in the directory cannot. */
    public function hasCredentials(): bool
    {
        return is_string($this->password) && $this->password !== '';
    }

    /**
     * May this team rewrite the shared record's name, address, and number?
     *
     * The `people` row is shared across teams (PRD decision log, 2026-08-22),
     * which is the whole point — a stager working for two teams is one record
     * with one phone number. It is also the sharp edge: without this check,
     * any team could POST a stranger's address to attach a membership to
     * their row, then PATCH that row and rewrite the address on somebody
     * else's *account*. The password would be untouched and the reset link
     * would arrive at the new address.
     *
     * So identity belongs to the person when they have one, and to the team
     * only while the team is the only one who knows them:
     *
     *  - **Has credentials** — never. It is their account; they edit it at
     *    `/settings/profile`, and nobody else does.
     *  - **Known to another team** — never. Their view of this human is not
     *    ours to rewrite.
     *  - **Ours alone, no login** — yes. This is the ordinary case: a client
     *    or vendor somebody typed in, whose details only we hold.
     *
     * What a team may always edit is its own membership: the lifecycle
     * status, the private notes, the vendor assessment. Those are team-scoped
     * by construction.
     */
    public function identityIsEditableBy(Team $team): bool
    {
        if ($this->hasCredentials()) {
            return false;
        }

        return ! TeamMembership::withoutTeamScope()
            ->where('person_id', $this->getKey())
            ->whereKeyNot(optional($this->membershipIn($team))->getKey() ?? '')
            ->whereNull('revoked_at')
            ->exists();
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
     * Every team this person can currently **act in**, for the switcher (S09).
     *
     * Being in a team's directory is not being on the team. A client, a
     * vendor, and an opposing agent all hold a `team_memberships` row — that
     * is what the table is for — and none of them may open the agent's
     * dashboard. Counting any membership here handed the tenant to anybody a
     * team merely knew who happened to have a password.
     *
     * The test is holding a role that carries at least one permission. That
     * covers the shipped roles (Team Owner and Team Member do, Status Viewer
     * and Contact hold nothing at all) and covers a team's own composed roles
     * (PRD F2.3) without needing a list of their keys.
     *
     * @return \Illuminate\Support\Collection<int, Team>
     */
    public function activeTeams(): \Illuminate\Support\Collection
    {
        return Team::query()
            ->whereIn('id', TeamMembership::withoutTeamScope()
                ->where('person_id', $this->getKey())
                ->whereNull('revoked_at')
                ->whereHas('roles', fn (Builder $roles) => $roles->whereHas('permissions'))
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
