<?php

declare(strict_types=1);

namespace App\Support\Push;

use App\Models\DealProperty;
use App\Models\Notification;
use App\Models\Property;

/**
 * What a push may say (#103 · PRD §9).
 *
 * ## An allowlist, because a lock screen is not a session
 *
 * Issue #103 states the rule and the reason:
 *
 * > A push notification body sits on a **third-party push service** and on a
 * > **lock screen**. *"123 Main St has an overdue task"* is fine. A client's
 * > name, phone number, or financial figure is not.
 *
 * So this composes a push out of two things and refuses everything else: a
 * **fixed sentence chosen by the notification's type**, and the subject
 * property's **street**. Both are decided here; neither is copied from
 * anything a person typed.
 *
 * That is the opposite of how the panel works, and deliberately.
 * `notifications.summary` is free text — *"You were assigned 'Call Dana about
 * the appraisal'"* — composed from a task title, a gate label, or an
 * automation's subject line. Every one of those is a field somebody typed
 * into, so every one of them can contain a client's name. The panel is behind
 * a session and may show it. A lock screen is behind nothing.
 *
 * ## Why not the deal's name, which is right there
 *
 * Because `Deal::displayName()` falls back to `generated_name`, and
 * `NameDeal` derives that from the subject property's street **or, failing
 * that, the client's surname** — its own docblock says so. So the obvious
 * one-liner, `$deal->displayName()`, pushes a client's surname to a lock
 * screen for every deal with no property attached, which is every buy-side
 * deal before an offer. The street is read directly for that reason, and its
 * absence produces no deal reference at all rather than a fallback.
 *
 * A street is an address, and an address is not nothing — but it is what
 * #103 explicitly permits, and a notification that cannot say which
 * transaction it is about is one somebody has to open the app to triage,
 * which defeats the point of sending it.
 */
final class PushPayload
{
    /**
     * @return array{title: string, body: string, url: string, tag: string}
     */
    public static function for(Notification $notification): array
    {
        $street = self::subjectStreet($notification);

        /*
         * The type's own words, which are a constant in an enum rather than
         * anything a tenant can influence. `NotificationType::label()` is the
         * heading S78 shows for the same kind of event, so what arrives on a
         * phone matches what the person switched on.
         */
        $body = $notification->type->description();

        if ($street !== null) {
            $body = $street.' — '.$body;
        }

        return [
            'title' => $notification->type->label(),
            'body' => $body,
            /*
             * A path with ULIDs in it. Opaque, and the same URL the panel
             * uses, so a tap lands where a click would.
             */
            'url' => $notification->url() ?? '/notifications',
            /*
             * Collapses repeats about one subject instead of stacking six
             * lock-screen entries for one deal — the push half of the panel's
             * grouping rule. Ids rather than words, so the tag itself carries
             * nothing either.
             */
            'tag' => $notification->type->value.':'.($notification->deal_id ?? 'none'),
        ];
    }

    /**
     * The subject property's street, or nothing.
     *
     * Read straight off `deal_properties` rather than through the deal's
     * name, for the reason in the class docblock. `is_subject` is the same
     * predicate `NameDeal` uses, so the street in a push is the street on the
     * deal header rather than whichever property happened to be linked first.
     */
    private static function subjectStreet(Notification $notification): ?string
    {
        if ($notification->deal_id === null) {
            return null;
        }

        $link = DealProperty::query()
            ->where('deal_id', $notification->deal_id)
            ->where('is_subject', true)
            ->with('property')
            ->first();

        if (! $link instanceof DealProperty || ! $link->property instanceof Property) {
            return null;
        }

        return $link->property->street;
    }
}
