<?php

declare(strict_types=1);

namespace App\Support\Mail;

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
