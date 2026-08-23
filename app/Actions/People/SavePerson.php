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
 * Create or update a person, as this team knows them (S32 · #140).
 *
 * Much shorter than it was, and the deletions are the interesting part.
 *
 * This used to write two records with two owners — a shared `people` row for
 * the human and a `team_memberships` row for what this team thought — and
 * that split was the disclosure in #140: finding the shared row by address
 * meant a team could see what another team had typed into it. It also needed
 * `identityIsEditableBy()` to decide, per field, whether this team was allowed
 * to write, a rule that took three review rounds to hold.
 *
 * Now everything a team knows lives on the membership, so there is one write,
 * no lookup across teams, and no rule to enforce. The `people` row is created
 * blank: a directory entry is not a login (#140), and one appears only when
 * somebody is invited.
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
            /*
             * A credential-less row, per team.
             *
             * Deliberately *not* looked up by address. Sharing a row across
             * teams bought one thing — a stager known to two teams having one
             * phone number — and that stopped existing when the phone number
             * moved onto the membership. What the lookup still cost was the
             * disclosure in #140, and a way to probe whether an address is
             * known to the platform. An account is created by an invitation,
             * which is a deliberate act by somebody who already knows the
             * address.
             */
            $person = Person::query()->create([]);

            // One insert: `first_name` is not nullable, so a membership
            // cannot exist for a moment without a name.
            $membership = TeamMembership::query()->create([
                'team_id' => $this->teams->requireId(TeamMembership::class),
                'person_id' => $person->getKey(),
                'status' => PersonLifecycleState::Lead,
                'joined_at' => now(),
                ...$this->identityFrom($attributes),
            ]);

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

            // No permission question left to ask: this row is this team's view
            // and nobody else reads it.
            $this->applyIdentity($membership, $attributes);

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
     * The name, address, and number this team holds.
     *
     * Only the keys the form actually sent: a partial update must not blank a
     * column the screen did not show.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function applyIdentity(TeamMembership $membership, array $attributes): void
    {
        $membership->fill($this->identityFrom($attributes))->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function identityFrom(array $attributes): array
    {
        return array_intersect_key(
            $attributes,
            array_flip(['first_name', 'last_name', 'email', 'phone']),
        );
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
