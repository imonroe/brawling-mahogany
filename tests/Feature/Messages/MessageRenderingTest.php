<?php

declare(strict_types=1);

use App\Enums\ParticipantRole;
use App\Models\Deal;
use App\Models\ExternalLink;
use App\Models\MessageTemplate;
use App\Models\Property;
use App\Models\Stage;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\Workflow;
use App\Support\Deals\DealRoster;
use App\Support\Messages\MergeContext;
use App\Support\Messages\MergeField;
use App\Support\Messages\MergeFields;
use App\Support\Messages\RenderMessage;
use App\Support\Properties\PropertyDeals;
use App\Support\Tenancy\TeamContext;

/**
 * F5.6 — what a merge field resolves to, and how it is escaped (issue #90).
 *
 * The registry and the resolver are two halves of one table, and the first
 * test is what stops them drifting: a field registered and never resolved
 * renders as an empty string in a client email, and a value resolved under no
 * registered token cannot be typed into a body.
 *
 * In `Feature` rather than `Unit` because every one of these needs a real
 * deal — which is also the point issue #90 makes about the preview: *"not
 * lorem ipsum."*
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);
});

/**
 * A deal with a main contact and, unless told otherwise, a subject property.
 */
function mergeDeal(Team $team, string $surname = 'Bosart', bool $withProperty = true): Deal
{
    $deal = Deal::factory()->create(['team_id' => $team->getKey()]);

    $membership = TeamMembership::factory()->create([
        'team_id' => $team->getKey(),
        'first_name' => 'Emily',
        'last_name' => $surname,
        'email' => 'client@example.test',
    ]);

    app(TeamContext::class)->runFor($team, function () use ($deal, $membership, $team, $withProperty): void {
        app(DealRoster::class)->add($deal, $membership, ParticipantRole::Seller, isPrimary: true);

        if ($withProperty) {
            app(PropertyDeals::class)->link(
                Property::factory()->create([
                    'team_id' => $team->getKey(),
                    'street' => '123 Main St',
                    'city' => 'Indianapolis',
                    'state_code' => 'IN',
                    'postal_code' => '46220',
                ]),
                $deal,
            );
        }
    });

    return $deal->fresh();
}

it('resolves every registered field, and registers every resolved one', function (): void {
    $deal = mergeDeal($this->team);

    $resolved = array_keys(MergeFields::resolve(MergeContext::for($deal, $this->team)));
    $registered = array_map(
        static fn (MergeField $field): string => $field->token,
        MergeFields::available(),
    );

    sort($resolved);
    sort($registered);

    expect($resolved)->toBe($registered);
});

it('escapes a merged value into HTML and leaves the plain text alone', function (): void {
    $deal = mergeDeal($this->team, surname: 'O<b>Brien</b> & Sons');

    $template = MessageTemplate::factory()->create([
        'team_id' => $this->team->getKey(),
        'subject' => 'About {{ client_name }}',
        'body_html' => '<p>Hi {{ client_name }}</p>',
        'body_text' => 'Hi {{ client_name }}',
    ]);

    $rendered = app(RenderMessage::class)->render($template, MergeContext::for($deal, $this->team));

    // Escaped into markup — a `<` in a client's name is stored XSS in the
    // approval preview a colleague opens.
    expect($rendered->bodyHtml)->toContain('&lt;b&gt;')
        ->and($rendered->bodyHtml)->not->toContain('<b>Brien</b>')
        // …and untouched in the plain-text alternative, or every message to
        // the O'Brien household says `&amp;`.
        ->and($rendered->bodyText)->toContain('O<b>Brien</b> & Sons');
});

it('strips CR and LF out of a subject line', function (): void {
    /*
     * A subject is a mail **header**. A newline in a merged value is header
     * injection, and the value comes from a name somebody typed into the
     * people directory.
     */
    $deal = mergeDeal($this->team, surname: "Smith\r\nBcc: someone@example.test");

    $template = MessageTemplate::factory()->create([
        'team_id' => $this->team->getKey(),
        'subject' => 'About {{ client_name }}',
        'body_html' => null,
        'body_text' => 'x',
    ]);

    $rendered = app(RenderMessage::class)->render($template, MergeContext::for($deal, $this->team));

    expect($rendered->subject)->not->toContain("\n")
        ->and($rendered->subject)->not->toContain("\r")
        // Still says what it said — the newline is what was dangerous, not the
        // words, and quietly dropping half a client's name would be its own bug.
        ->and($rendered->subject)->toContain('Bcc: someone@example.test');
});

it('reports a field with nothing behind it rather than leaving the braces in', function (): void {
    // No property linked, so there is no MLS link to resolve.
    $deal = mergeDeal($this->team, withProperty: false);

    $template = MessageTemplate::factory()->create([
        'team_id' => $this->team->getKey(),
        'subject' => 'x',
        'body_html' => null,
        'body_text' => 'See the listing at {{ mls_link }}. Also {{ nonsense }}.',
    ]);

    $rendered = app(RenderMessage::class)->render($template, MergeContext::for($deal, $this->team));

    expect($rendered->unresolved)->toContain('mls_link')
        ->and($rendered->unknown)->toContain('nonsense')
        ->and($rendered->isComplete())->toBeFalse()
        // The braces go either way: a template's internals arriving in
        // somebody's inbox is worse than a gap the caller was told about.
        ->and($rendered->bodyText)->not->toContain('{{');
});

it('renders the MLS link and never anything from the other end of it', function (): void {
    $deal = mergeDeal($this->team);

    /** @var Property $property */
    $property = Property::query()->sole();

    ExternalLink::factory()->attachedTo($property)->create([
        'label' => 'MLS listing',
        'url' => 'https://example.test/listing/1',
    ]);

    $values = MergeFields::resolve(MergeContext::for($deal->fresh(), $this->team));

    // PRD §10: links only, never ingested listing content. The column a price
    // would live in does not exist, which is the actual guarantee.
    expect($values['mls_link'])->toBe('https://example.test/listing/1')
        ->and($values['property_address'])->toBe('123 Main St, Indianapolis, IN 46220');
});

it('gives the client the milestone label and never the internal stage name', function (): void {
    // IA §9. Internal stage names say things like "Chase lender".
    $deal = mergeDeal($this->team);

    $workflow = Workflow::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $deal->getKey(),
    ]);

    $stage = Stage::factory()->create([
        'team_id' => $this->team->getKey(),
        'workflow_id' => $workflow->getKey(),
        'name' => 'Chase lender',
        'is_milestone' => true,
        'milestone_label' => 'Your financing is being arranged',
    ]);

    $values = MergeFields::resolve(MergeContext::for($deal, $this->team, $stage));

    expect($values['stage'])->toBe('Your financing is being arranged')
        // The internal name is not offered at all — a field that exists is a
        // field somebody will use.
        ->and(MergeFields::find('internal_stage'))->toBeNull();
});
