<?php

declare(strict_types=1);

namespace App\Actions\People;

use App\Enums\ActivitySource;
use App\Enums\PersonLifecycleState;
use App\Models\Person;
use App\Models\TeamMembership;
use App\Support\Activity\RecordActivity;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * Create or update a person, as this team knows them (S32).
 *
 * Two records move together and they belong to different owners: the shared
 * `people` row carries the facts about the human (name, email, phone), and the
 * `team_memberships` row carries what this team thinks (lifecycle, notes,
 * vendor assessment). Splitting the write here is what keeps Team A's private
 * notes out of Team B's view.
 */
final class SavePerson
{
    public function __construct(
        private readonly TeamContext $teams,
        private readonly RecordActivity $activity,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): TeamMembership
    {
        return DB::transaction(function () use ($attributes): TeamMembership {
            $person = $this->personFor($attributes);

            $membership = TeamMembership::query()->firstOrCreate(
                ['team_id' => $this->teams->requireId(TeamMembership::class), 'person_id' => $person->getKey()],
                ['status' => PersonLifecycleState::Lead, 'joined_at' => now()],
            );

            $this->applyTeamAttributes($membership, $attributes);

            $this->activity->record(
                subject: $person,
                eventType: 'person.added',
                summary: 'Added to the team directory',
                source: ActivitySource::System,
            );

            return $membership;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(TeamMembership $membership, array $attributes): TeamMembership
    {
        return DB::transaction(function () use ($membership, $attributes): TeamMembership {
            $person = $membership->person;
            $team = $membership->team()->sole();

            /*
             * The shared record's identity is not always this team's to
             * rewrite — see Person::identityIsEditableBy(). Silently dropping
             * the fields rather than failing is deliberate: the screen shows
             * them read-only, so a submission carrying them is a stale form
             * or somebody poking at the endpoint, and neither deserves a
             * 500.
             */
            if ($person->identityIsEditableBy($team)) {
                // Only the keys the form actually sent: a partial update must
                // not blank a column the screen did not show.
                $person->fill(array_intersect_key(
                    $attributes,
                    array_flip(['first_name', 'last_name', 'email', 'phone']),
                ))->save();
            }

            $previousStatus = $membership->status;

            $this->applyTeamAttributes($membership, $attributes);

            if ($membership->status !== $previousStatus) {
                // PRD §7.3: Past Client is a first-class state that drives
                // Keep in Touch (Slice 6), so the transition is worth a
                // timeline entry rather than a silent column change.
                $this->activity->record(
                    subject: $person,
                    eventType: 'person.status_changed',
                    summary: $previousStatus->label().' → '.$membership->status->label(),
                    source: ActivitySource::System,
                    payload: ['from' => $previousStatus->value, 'to' => $membership->status->value],
                );
            }

            return $membership;
        });
    }

    /**
     * Find the shared person, or make one.
     *
     * `firstOrCreate` on the address is the mechanism behind issue #45's rule
     * that an invitation for a known address attaches a membership rather than
     * creating a second human — and behind the import's merge behaviour.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function personFor(array $attributes): Person
    {
        $email = $attributes['email'] ?? null;

        $person = is_string($email) && $email !== ''
            ? Person::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first()
            : null;

        if ($person instanceof Person) {
            // Never overwrite a shared record's name with what one team typed:
            // the other team knows them by the name already there. Only fill
            // what is genuinely missing, and only when this record is not
            // somebody's account — an account holder's details are theirs.
            if (! $person->hasCredentials()) {
                $person->fill(array_filter([
                    'phone' => $person->phone ?? ($attributes['phone'] ?? null),
                    'last_name' => $person->last_name ?? ($attributes['last_name'] ?? null),
                ]))->save();
            }

            return $person;
        }

        return Person::query()->create([
            'first_name' => $attributes['first_name'],
            'last_name' => $attributes['last_name'] ?? null,
            'email' => $email,
            'phone' => $attributes['phone'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyTeamAttributes(TeamMembership $membership, array $attributes): void
    {
        $fields = [
            'status',
            'is_vendor',
            'notes',
            'vendor_specialties',
            'vendor_typical_cost',
            'vendor_service_area',
            'vendor_rating',
            'vendor_notes',
        ];

        foreach ($fields as $field) {
            if (array_key_exists($field, $attributes)) {
                $membership->setAttribute($field, $attributes[$field]);
            }
        }

        if (! $membership->isDirty()) {
            return;
        }

        $membership->save();

        // PRD §9 audits permission and access changes; a lifecycle move is
        // neither, but a vendor rating and a private note are the two things a
        // team argues about later, so the change is recorded with its values
        // redacted.
        $this->audit->recordChange('person.updated', $membership);
    }
}
