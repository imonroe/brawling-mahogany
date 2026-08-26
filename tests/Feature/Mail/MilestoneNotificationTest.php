<?php

declare(strict_types=1);

use App\Enums\AutomationTrigger;
use App\Mail\AutomatedMessageMail;
use App\Models\ActionInstance;
use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\ExternalLink;
use App\Models\Property;
use App\Models\Stage;
use App\Models\Workflow;
use App\Support\Mail\MilestoneAnnouncement;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

/**
 * S87 — the milestone notification (issue #97 · PRD §5.4, §5.7 · IA §9).
 *
 * The key states the issue names: with and without the MLS link, with and
 * without the status link, and a long address. Plus the one it does not, which
 * is the state every other automated message is in — **not** a milestone, and
 * getting a plain branded frame with no headline invented for it.
 */
beforeEach(function (): void {
    Mail::clearResolvedInstances();
    app()->forgetInstance('mail.manager');
    app()->forgetInstance('mailer');

    [$this->team, $this->owner] = $this->teamWithOwner();
    /*
     * Team context, because everything below reads team-scoped rows the way
     * the worker does. `ExecuteAction` runs inside `withinTeam()`, and the
     * mailable renders inside that — which is what lets the frame resolve the
     * deal's property at all.
     */
    $this->actingAsPerson($this->owner, $this->team);

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
    $this->workflow = Workflow::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);
});

function milestoneStage(string $label = 'Your home is on the market'): Stage
{
    return Stage::factory()->milestone($label)->create([
        'team_id' => test()->team->getKey(),
        'workflow_id' => test()->workflow->getKey(),
    ]);
}

function subjectPropertyWith(?string $mlsUrl, string $street = '12 Oak Lane'): Property
{
    $property = Property::factory()->create([
        'team_id' => test()->team->getKey(),
        'street' => $street,
        'city' => 'Golden',
        'state_code' => 'CO',
        'postal_code' => '80401',
    ]);

    DealProperty::factory()->create([
        'team_id' => test()->team->getKey(),
        'deal_id' => test()->deal->getKey(),
        'property_id' => $property->getKey(),
        'is_subject' => true,
    ]);

    if ($mlsUrl !== null) {
        ExternalLink::factory()->attachedTo($property)->create([
            'label' => 'MLS listing',
            'url' => $mlsUrl,
        ]);
    }

    return $property;
}

function renderAnnouncement(ActionInstance $instance): Email
{
    Mail::to('client@example.test')->send(new AutomatedMessageMail(
        instance: $instance,
        rendered: $instance->rendered(),
        team: test()->team,
    ));

    return Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();
}

function milestoneMessage(array $attributes = []): ActionInstance
{
    return ActionInstance::factory()->create([
        'team_id' => test()->team->getKey(),
        'deal_id' => test()->deal->getKey(),
        ...$attributes,
    ]);
}

it('opens with what the team told the client the milestone is called', function (): void {
    /*
     * IA §9 is absolute: the client sees the `milestone_label`, never the
     * internal stage name. Internal names say things like "Chase lender".
     */
    $stage = milestoneStage();
    $stage->forceFill(['name' => 'Chase lender for the listing paperwork'])->save();

    $message = renderAnnouncement(milestoneMessage(['stage_id' => $stage->getKey()]));

    expect((string) $message->getHtmlBody())->toContain('Your home is on the market')
        ->and((string) $message->getHtmlBody())->not->toContain('Chase lender')
        ->and((string) $message->getTextBody())->toContain('Your home is on the market')
        ->and((string) $message->getTextBody())->not->toContain('Chase lender');
});

it('says nothing about a milestone when the stage is not one', function (): void {
    $stage = Stage::factory()->create([
        'team_id' => $this->team->getKey(),
        'workflow_id' => $this->workflow->getKey(),
        'name' => 'Order the sign',
    ]);

    $message = renderAnnouncement(milestoneMessage(['stage_id' => $stage->getKey()]));

    expect((string) $message->getHtmlBody())->not->toContain('Order the sign')
        ->and((string) $message->getHtmlBody())->toContain('The inspection is booked for Friday.');
});

it('announces a completion and never a start', function (): void {
    /*
     * A milestone is *the notable completion of a stage* (IA §2). A
     * `stage_start` email on a milestone stage would open with "Your home is
     * on the market" on the morning the photographer was booked.
     */
    $stage = milestoneStage();

    $started = milestoneMessage([
        'stage_id' => $stage->getKey(),
        'trigger' => AutomationTrigger::StageStart,
    ]);

    expect(MilestoneAnnouncement::for($started, $this->team, $started->rendered()))->toBeNull();
});

it('says nothing when a milestone stage carries no client label', function (): void {
    /*
     * `Stage::clientAnnouncement()` returns null rather than falling back to
     * the internal name, and this is the frame honouring that: a configuration
     * mistake produces a plain message, never a leak.
     */
    $stage = Stage::factory()->create([
        'team_id' => $this->team->getKey(),
        'workflow_id' => $this->workflow->getKey(),
        'name' => 'Nudge the other agent',
        'is_milestone' => true,
        'milestone_label' => null,
    ]);

    $instance = milestoneMessage(['stage_id' => $stage->getKey()]);

    expect(MilestoneAnnouncement::for($instance, $this->team, $instance->rendered()))->toBeNull();
});

it('carries the address, and does not break the layout on a long one', function (): void {
    subjectPropertyWith(null, '4827 North Wildflower Ridge Terrace Southeast, Building C');

    $message = renderAnnouncement(milestoneMessage(['stage_id' => milestoneStage()->getKey()]));

    $html = (string) $message->getHtmlBody();

    expect($html)->toContain('4827 North Wildflower Ridge Terrace Southeast, Building C')
        // The one state the issue names that is a rendering decision rather
        // than a data one.
        ->and($html)->toContain('word-break:break-word');
});

it('offers the listing when there is one', function (): void {
    subjectPropertyWith('https://mls.example.test/listing/8891');

    $message = renderAnnouncement(milestoneMessage(['stage_id' => milestoneStage()->getKey()]));

    expect((string) $message->getHtmlBody())->toContain('https://mls.example.test/listing/8891')
        ->and((string) $message->getHtmlBody())->toContain('View the listing')
        ->and((string) $message->getTextBody())->toContain('https://mls.example.test/listing/8891');
});

it('offers nothing when the property has no listing linked', function (): void {
    subjectPropertyWith(null);

    $message = renderAnnouncement(milestoneMessage(['stage_id' => milestoneStage()->getKey()]));

    expect((string) $message->getHtmlBody())->not->toContain('View the listing');
});

it('does not put the listing link in twice when the team already wrote it', function (): void {
    /*
     * `{{ mls_link }}` is a merge field a template may already carry, and
     * PRD §5.4's worked example is exactly that email. A frame that added its
     * own button regardless would send half the teams in the product a message
     * with the same URL in it twice — once as they wrote it and once as we
     * decided.
     */
    subjectPropertyWith($url = 'https://mls.example.test/listing/8891');

    $instance = milestoneMessage([
        'stage_id' => milestoneStage()->getKey(),
        'payload' => [
            ...ActionInstance::factory()->definition()['payload'],
            'bodyHtml' => '<p>See the listing: <a href="'.$url.'">here</a>.</p>',
            'bodyText' => 'See the listing: '.$url,
        ],
    ]);

    $html = (string) renderAnnouncement($instance)->getHtmlBody();

    expect(substr_count($html, $url))->toBe(1)
        ->and($html)->not->toContain('View the listing');
});

it('finds the team’s own copy of the link even when only the escaped one is in the body', function (): void {
    /*
     * `RenderMessage` escapes a merged value into `body_html` and leaves it
     * alone in `body_text`, so a listing URL with a query string is
     * `&amp;` in one half and `&` in the other. A single-spelling search finds
     * it in one body and misses it in the other — and a template with no
     * plain-text half of its own leaves only the body where the miss happens.
     */
    subjectPropertyWith($url = 'https://mls.example.test/l?id=8891&src=agent');

    $instance = milestoneMessage([
        'stage_id' => milestoneStage()->getKey(),
        'payload' => [
            ...ActionInstance::factory()->definition()['payload'],
            'bodyHtml' => '<p>See it at '.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'.</p>',
            'bodyText' => 'See it at the listing.',
        ],
    ]);

    expect((string) renderAnnouncement($instance)->getHtmlBody())->not->toContain('View the listing');
});

it('has a slot for the status page and does not invent a link for it', function (): void {
    /*
     * PRD §5.7 step 1 makes the status page what a milestone email exists to
     * lead to, and #110 is where the page arrives. The slot takes precedence
     * over the listing when it is filled; until then the honest answer is that
     * there is no link, not a URL that 404s.
     */
    subjectPropertyWith('https://mls.example.test/listing/8891');

    $instance = milestoneMessage(['stage_id' => milestoneStage()->getKey()]);
    $announcement = MilestoneAnnouncement::for($instance, $this->team, $instance->rendered());

    expect($announcement)->not->toBeNull()
        ->and($announcement->statusPageLink)->toBeNull()
        ->and($announcement->callToAction()['label'])->toBe('View the listing');
});
