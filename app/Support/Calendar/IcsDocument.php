<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Models\CalendarFeed;
use App\Models\Event;
use Carbon\CarbonImmutable;

/**
 * An `.ics` document, written by hand (RFC 5545 · PRD §4.8 F8.3 · issue #108).
 *
 * ## By hand rather than by a library, and the reason is the payload
 *
 * What this emits is a fixed, small subset: `VEVENT` with a summary, a start,
 * an end and sometimes a location, plus an `RRULE` copied from a rule this
 * product already owns. Every iCal library in PHP is built to express the rest
 * of RFC 5545 — attendees, organisers, alarms, attachments, free/busy — and
 * every one of those is something #108 explicitly does **not** want in a
 * document that syncs to a third party.
 *
 * A dependency whose whole value is the fields we are refusing to send is a
 * dependency that makes the refusal harder to see. This file is a hundred
 * lines and the allowlist is the code.
 *
 * ## What a feed carries, and nothing else
 *
 * #108: *"serving **no PII beyond what the calendar needs** — a client's phone
 * number does not belong in an event description that syncs to a third-party
 * calendar."* So:
 *
 *  - **No attendees.** `events.attendees` is a list of membership ids, and a
 *    membership is a name and an email address. An `ATTENDEE` line would put
 *    a client's address in Google's copy of this calendar, and it would do it
 *    for every event on the deal.
 *  - **No description.** Nothing on an event is safe by construction —
 *    `description` is free text somebody typed, and *"lockbox code 4412"* is
 *    exactly the kind of thing that ends up in one.
 *  - **The location, which is the exception, and it is a deliberate one.** A
 *    calendar entry with no location is a calendar entry somebody has to open
 *    the app to use, which is the whole point of subscribing. It is a street
 *    address most of the time, and the property it names is already in the
 *    title.
 *
 * ## Deadlines are all-day events, clearly labelled
 *
 * #108's own words. iCal has no *deadline*: a `VEVENT` is the only shape a
 * calendar client will draw, so a key date becomes an all-day one and the
 * summary says which — `Deadline: Inspection objection`. Without the prefix,
 * a client's phone shows a legally significant date looking exactly like a
 * showing.
 */
final class IcsDocument
{
    /**
     * The product identifier RFC 5545 requires.
     *
     * `config('app.product_name')`, never `APP_NAME` — that one is slugged
     * into the session cookie and three cache prefixes, which is why the
     * rename note leaves it at the codename. This string is read by a person
     * debugging a calendar subscription.
     */
    private static function productId(): string
    {
        return '-//'.config('app.product_name').'//Calendar Feed//EN';
    }

    /**
     * @param  list<array<string, mixed>>  $items  from `CalendarBoard`
     */
    public static function render(CalendarFeed $feed, array $items, string $timezone): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:'.self::productId(),
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            /*
             * Google and Apple both read this as the subscription's name, and
             * neither is standard — `X-WR-CALNAME` is a de-facto extension.
             * Sent anyway: without it a subscribed feed appears as its URL,
             * which is a 43-character token in somebody's sidebar.
             */
            'X-WR-CALNAME:'.self::escape($feed->name),
            'X-WR-TIMEZONE:'.self::escape($timezone),
            /*
             * How often a client should re-poll. Advisory — Google ignores it
             * and polls on its own schedule — and worth sending for the ones
             * that honour it, because the alternative is their default, which
             * is often a day.
             */
            'REFRESH-INTERVAL;VALUE=DURATION:PT4H',
            'X-PUBLISHED-TTL:PT4H',
        ];

        foreach ($items as $item) {
            $lines = [...$lines, ...self::event($item, $feed, $timezone)];
        }

        $lines[] = 'END:VCALENDAR';

        /*
         * CRLF, which RFC 5545 requires and which several clients enforce.
         * Folded to 75 octets afterwards, because a long summary on one line
         * is the other thing they enforce.
         */
        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private static function event(array $item, CalendarFeed $feed, string $timezone): array
    {
        $isDeadline = ($item['kind'] ?? '') === 'deadline';

        $summary = $isDeadline
            // #108: *"deadlines as all-day events, clearly labelled."*
            ? 'Deadline: '.(string) $item['title']
            : (string) $item['title'];

        $deal = is_array($item['deal'] ?? null) ? (string) $item['deal']['label'] : null;

        if ($deal !== null) {
            $summary .= ' — '.$deal;
        }

        $lines = [
            'BEGIN:VEVENT',
            /*
             * Stable across fetches, so a client updates an entry rather than
             * creating a second one — the `key` already distinguishes one
             * occurrence of a repeat from another. Suffixed with the feed's
             * team so two feeds in one calendar client cannot collide.
             */
            'UID:'.self::escape((string) $item['key']).'@'.$feed->team_id.'.goldieflow',
            /*
             * When this *version* of the entry was produced. A client compares
             * it to decide whether anything changed, so `now()` is correct and
             * a fixed value would make edits invisible to a subscriber.
             */
            'DTSTAMP:'.CarbonImmutable::now()->utc()->format('Ymd\THis\Z'),
            'SUMMARY:'.self::escape($summary),
        ];

        if ($isDeadline || ($item['isAllDay'] ?? false) === true) {
            $day = CarbonImmutable::parse((string) $item['day']);

            /*
             * `VALUE=DATE`, and `DTEND` is the **day after** — RFC 5545's end
             * is exclusive for a date value. Getting that wrong is the classic
             * off-by-one that draws a one-day deadline across two squares in
             * every calendar client at once.
             */
            $lines[] = 'DTSTART;VALUE=DATE:'.$day->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$day->addDay()->format('Ymd');
        } else {
            $lines[] = 'DTSTART:'.self::instant($item['startsAt'] ?? null);
            $lines[] = 'DTEND:'.self::instant($item['endsAt'] ?? $item['startsAt'] ?? null);
        }

        $location = $item['location'] ?? null;

        if (is_string($location) && trim($location) !== '') {
            $lines[] = 'LOCATION:'.self::escape($location);
        }

        /*
         * A deadline is drawn as busy time otherwise, which is wrong: nobody
         * attends it, and a week of contingency dates would make somebody look
         * unavailable to every colleague who shares their calendar.
         */
        if ($isDeadline) {
            $lines[] = 'TRANSP:TRANSPARENT';
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    private static function instant(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return CarbonImmutable::now()->utc()->format('Ymd\THis\Z');
        }

        return CarbonImmutable::parse($value)->utc()->format('Ymd\THis\Z');
    }

    /**
     * RFC 5545 §3.3.11, and the reason a feed is not string concatenation.
     *
     * A backslash, a semicolon, a comma or a newline inside a value ends the
     * property early — which is a **content-injection** hole rather than a
     * cosmetic one: a deal named `Smith\nBEGIN:VEVENT` would put a second
     * event into somebody's calendar, and every value here is text a tenant
     * typed. The backslash goes first, or escaping the others would escape the
     * escapes.
     */
    private static function escape(string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n", "\r"],
            ['\\\\', '\;', '\\,', '\\n', '\\n', '\\n'],
            $value,
        );
    }

    /**
     * RFC 5545 §3.1: no line over 75 **octets**, continued with a leading
     * space.
     *
     * Octets rather than characters, and `mb_str_split` on bytes would be the
     * wrong tool — a multi-byte character split across a fold boundary arrives
     * as two broken bytes. So the split walks characters and counts their
     * byte length, which is slower and correct for a team whose deal names are
     * not ASCII.
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $folded = '';
        $current = '';

        foreach (mb_str_split($line) as $character) {
            // 75 for the first line; a continuation starts with a space, so it
            // has 74 of its own.
            $limit = $folded === '' ? 75 : 74;

            if (strlen($current) + strlen($character) > $limit) {
                $folded .= ($folded === '' ? '' : "\r\n ").$current;
                $current = '';
            }

            $current .= $character;
        }

        return $folded.($folded === '' ? '' : "\r\n ").$current;
    }
}
