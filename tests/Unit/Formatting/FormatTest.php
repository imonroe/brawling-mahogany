<?php

declare(strict_types=1);

use App\Models\Property;
use App\Support\Formatting\Format;
use Illuminate\Support\Carbon;

/**
 * The server-side mirror of `lib/formatters.ts` (IA §10 · issue #90).
 *
 * An email is the first surface this product renders without a browser, so the
 * rules it needs exist twice. The **worked examples are copied verbatim** from
 * `tests/js/formatters.test.ts` — the same address, the same instants, the same
 * expected strings — and that is the whole mechanism keeping the pair honest: a
 * rule that changes on one side and not the other fails here, in the pull
 * request that changed it.
 *
 * The cases with no counterpart there are the ones a *message* raises and a
 * screen does not: a unit line, a property that is absent entirely, and the
 * timezone case, which on a screen is answered by `setTeamTimeZone()` at boot
 * and here has to be passed in by a queue worker running in UTC.
 *
 * `Property` is built unsaved rather than through the factory — none of this
 * touches the database, and a formatter that needed one would be a formatter
 * in the wrong place.
 */
function addressed(array $attributes): Property
{
    return (new Property)->forceFill($attributes);
}

it('puts the street on line one and the locality on line two', function (): void {
    // `formatters.test.ts`'s own fixture, verbatim.
    expect(Format::addressLines(addressed([
        'street' => '123 Main St',
        'city' => 'Denver',
        'state_code' => 'CO',
        'postal_code' => '80202',
    ])))->toBe(['line1' => '123 Main St', 'line2' => 'Denver, CO 80202']);
});

it('puts a unit on the street line', function (): void {
    // No counterpart in the browser's tests: an address block in an email is
    // the first place the two lines are rendered as two lines.
    expect(Format::addressLines(addressed([
        'street' => '123 Main St',
        'unit' => 'Apt 4',
        'city' => 'Denver',
        'state_code' => 'CO',
    ])))->toBe(['line1' => '123 Main St Apt 4', 'line2' => 'Denver, CO']);
});

it('drops the parts that are not known rather than leaving separators behind', function (): void {
    // A property with a street and nothing else is an ordinary state of a
    // property somebody typed in this morning, and ", ," is what a naive join
    // renders it as.
    expect(Format::addressOneLine(addressed(['street' => '123 Main St'])))
        ->toBe('123 Main St');

    expect(Format::addressOneLine(addressed(['city' => 'Denver', 'state_code' => 'CO'])))
        ->toBe('Denver, CO');
});

it('has nothing to say about a property that is not there', function (): void {
    // A deal with no subject property is ordinary, and `{{ property_address }}`
    // on one resolves to an empty string the preview then reports.
    expect(Format::addressOneLine(null))->toBe('')
        ->and(Format::addressLines(null))->toBe(['line1' => '', 'line2' => '']);
});

it('gives a client the year only when it differs from theirs', function (): void {
    /*
     * IA §10's client date. "Thursday, August 20" reads as this year, so one
     * about next January has to say which.
     */
    // `formatters.test.ts`'s own instants, verbatim.
    $now = Carbon::parse('2026-01-04T00:00:00Z');

    expect(Format::dateForClient(Carbon::parse('2026-08-20T18:00:00Z'), 'America/Denver', $now))
        ->toBe('Thursday, August 20')
        ->and(Format::dateForClient(Carbon::parse('2027-08-20T18:00:00Z'), 'America/Denver', $now))
        ->toBe('Friday, August 20, 2027');
});

it('renders in the team’s timezone rather than the worker’s', function (): void {
    /*
     * A message is composed by a queue worker running in UTC, and a stamp at
     * 01:00 UTC is the previous evening in Denver. PRD §9: storage is UTC and
     * display is the team's zone — and this is the case where "display" happens
     * somewhere with no browser to ask.
     */
    $instant = Carbon::parse('2026-08-21 01:00', 'UTC');
    $now = Carbon::parse('2026-08-20 15:00', 'UTC');

    expect(Format::dateForClient($instant, 'America/Denver', $now))->toBe('Thursday, August 20')
        ->and(Format::dateForClient($instant, 'UTC', $now))->toBe('Friday, August 21');
});
