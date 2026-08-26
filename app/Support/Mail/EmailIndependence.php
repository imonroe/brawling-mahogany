<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Mail\AutomatedMessageMail;
use App\Mail\InternalAlertMail;
use App\Mail\MessageTemplateTestMail;
use App\Mail\TeamInvitationMail;

/**
 * **No user flow depends on email alone** (ADR 0003).
 *
 * ## The rule
 *
 * Email is a channel this product does not control. A message can be dropped
 * by a relay, filed as spam, sent to a shared mailbox nobody reads, or — in
 * every local environment and in staging by design — never sent at all. A
 * flow whose only door is an emailed link is a flow that can become
 * unreachable without anybody being told, and with no screen anywhere that
 * admits it happened. Slice 1 shipped exactly one such flow, and it was the
 * flow every new customer starts with.
 *
 * So: **every flow the product initiates by email must have a second way to
 * be started or answered that does not involve email.** Not necessarily an
 * equal way. The second door may be narrower — another person, a console with
 * shell access — but it must exist, be documented, and be tested.
 *
 * ## Why the catalogue is code
 *
 * Because the rule is only worth writing down if something checks it.
 * `tests/Unit/EmailIndependenceTest.php` reads this, finds every mailable in
 * `app/Mail` and every mail-sending Fortify feature that is switched on, and
 * fails when one of them is not listed here with an alternative that
 * resolves. A new mailable added in Slice 5 fails the build on the day it
 * lands, which is the day the decision is cheap.
 *
 * An entry is not a promise; the route or command it names has to exist.
 */
final class EmailIndependence
{
    /** A Fortify feature rather than a mailable of ours. */
    public const FORTIFY_PASSWORD_RESET = 'fortify:reset-passwords';

    /** Likewise. Inert while `Person` is not a `MustVerifyEmail`. */
    public const FORTIFY_EMAIL_VERIFICATION = 'fortify:email-verification';

    /**
     * Every flow this product initiates by email, and its second door.
     *
     * `sends` is a mailable class or one of the `FORTIFY_*` constants above.
     * Each alternative is `route:<name>` or `command:<signature>`, both of
     * which the test resolves against the real route table and the real
     * artisan registry.
     *
     * @var array<string, array{label: string, sends: string, alternatives: list<string>, note: string}>
     */
    public const FLOWS = [
        'team-invitation' => [
            'label' => 'Accepting an invitation to a team',
            'sends' => TeamInvitationMail::class,
            'alternatives' => [
                // The invitee, signed in as the invited address, accepting
                // from S09 or from the shell banner.
                'route:invitations.claim',
                // The team owner handing over the link from S74.
                'route:members.invitations.link',
                // The platform operator doing the same from S83, which is
                // where the very first owner of a team is invited.
                'route:admin.teams.invitations.link',
                // And the install with no screens yet and nobody to open them.
                'command:invitation:link',
            ],
            'note' => 'The invitee can accept in-app; the inviter and the operator can both '
                .'issue the link directly; and the console can issue one with no session at all.',
        ],

        'message-template-test' => [
            'label' => 'Checking what a message template will actually look like',
            'sends' => MessageTemplateTestMail::class,
            'alternatives' => [
                // S46's preview, which renders the same draft against the same
                // real deal through the same `RenderMessage` — including the
                // list of merge fields with nothing behind them.
                'route:message-templates.preview',
            ],
            'note' => 'The preview is the same render, in the app. A test send only exists to '
                .'show what a mail client does with it, and it can reach nobody but the person '
                .'who asked for it — so an install with no mail transport loses the rendering '
                .'check and none of the content check.',
        ],

        'automated-message' => [
            'label' => 'Telling a client what has happened on their deal',
            'sends' => AutomatedMessageMail::class,
            'alternatives' => [
                // S47 and S49. The exact words, the exact recipients, and —
                // when the transport refused it — the reason, on a screen the
                // team already opens.
                'route:messages.index',
                'route:messages.show',
            ],
            'note' => 'This is the flow that most needs the rule and satisfies it least '
                .'comfortably, so the reasoning is worth stating rather than implying. What the '
                .'second door is **not** is another way to reach the client — email is the '
                .'channel v1 has, and #103 adds push for the team rather than for clients. What '
                .'it is, is a way for the *team* to find out and act: on an install with no mail '
                .'transport, or one whose credentials expired, every message that did not go out '
                .'is on S47 in red with its reason, its recipients and its full text, and a '
                .'person can send it by hand or pick up the phone. ADR 0003\'s failure is a flow '
                .'that becomes unreachable **without anybody being told**; this one cannot go '
                .'quiet, because a message that fails is a row somebody has to clear.',
        ],

        'internal-alert' => [
            'label' => 'Being told that a client email did not go out',
            'sends' => InternalAlertMail::class,
            'alternatives' => [
                // S47, where every failed message is already a red row with
                // its reason on it, and S49 one click further with the words
                // and the recipients.
                'route:messages.index',
                'route:messages.show',
            ],
            'note' => 'The alert is a **push** of something both screens already hold, which is '
                .'the shape ADR 0003 asks for and an unusually clean example of it: the second '
                .'door is not a worse version of the first, it is the record itself. An install '
                .'with no transport loses the interruption and none of the information — and the '
                .'failure this alert is about is very often the transport, so the design assumes '
                .'the email may not arrive. That is also why the sweep that sends it cannot '
                .'throw — `AlertOnFailures::sweep()` runs for every team on the platform, and '
                .'one broken team must not stop it reaching the next.',
        ],

        'password-reset' => [
            'label' => 'Resetting a forgotten password',
            'sends' => self::FORTIFY_PASSWORD_RESET,
            'alternatives' => [
                'command:auth:reset-link',
            ],
            'note' => 'Deliberately console-only. A screen that mints reset links for other '
                .'accounts is an account-takeover button however carefully it is gated, so the '
                .'second door here is shell access — the same bar as reading the database.',
        ],
    ];

    /**
     * @return list<string> Every sender the catalogue covers.
     */
    public static function coveredSenders(): array
    {
        return array_values(array_map(
            static fn (array $flow): string => $flow['sends'],
            self::FLOWS,
        ));
    }
}
