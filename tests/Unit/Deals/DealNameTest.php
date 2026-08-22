<?php

declare(strict_types=1);

use App\Enums\DealSide;
use App\Support\Deals\DealNameFacts;
use App\Support\Deals\GenerateDealName;

/**
 * Deal naming (F3.2 · IA §10 · issue #59).
 *
 * > | Deal names | Subject property street address, falling back to client
 * > surname | `123 Main St · Bosart Purchase` |
 */
it('builds the canonical name from an address and a client', function (): void {
    $name = (new GenerateDealName)->from(new DealNameFacts(
        streetAddress: '123 Main St',
        clientSurname: 'Bosart',
        side: DealSide::Buy,
    ));

    expect($name)->toBe('123 Main St · Bosart Purchase');
});

it('falls back to the client surname when there is no property yet', function (): void {
    // IA §13.4: the ordinary case for a buyer-side deal, not an edge case.
    // A buyer is represented before there is anything to buy.
    $name = (new GenerateDealName)->from(new DealNameFacts(
        clientSurname: 'Bosart',
        side: DealSide::Buy,
    ));

    expect($name)->toBe('Bosart Purchase');
});

it('uses the address alone when nobody is attached yet', function (): void {
    $name = (new GenerateDealName)->from(new DealNameFacts(
        streetAddress: '123 Main St',
        side: DealSide::Sell,
    ));

    expect($name)->toBe('123 Main St');
});

it('returns nothing when there is nothing to build a name from', function (): void {
    expect((new GenerateDealName)->from(new DealNameFacts))->toBeNull();
});

it('names the side with the word for it', function (DealSide $side, string $expected): void {
    $name = (new GenerateDealName)->from(new DealNameFacts(clientSurname: 'Neal', side: $side));

    expect($name)->toBe($expected);
})->with([
    'buy' => [DealSide::Buy, 'Neal Purchase'],
    'sell' => [DealSide::Sell, 'Neal Sale'],
    'rent' => [DealSide::Rent, 'Neal Rental'],
]);

it('appends nothing rather than a word that might be wrong', function (): void {
    // A purchase labelled "Sale" is a worse name than one labelled nothing.
    expect((new GenerateDealName)->from(new DealNameFacts(clientSurname: 'Neal', side: DealSide::Other)))
        ->toBe('Neal')
        ->and((new GenerateDealName)->from(new DealNameFacts(clientSurname: 'Neal')))
        ->toBe('Neal');
});

it('ignores whitespace-only facts', function (): void {
    $facts = new DealNameFacts(streetAddress: '  ', clientSurname: "\t", side: DealSide::Buy);

    expect($facts->areEmpty())->toBeTrue()
        ->and((new GenerateDealName)->from($facts))->toBeNull();
});

it('trims what it is given', function (): void {
    $name = (new GenerateDealName)->from(new DealNameFacts(
        streetAddress: '  123 Main St  ',
        clientSurname: ' Bosart ',
        side: DealSide::Sell,
    ));

    expect($name)->toBe('123 Main St · Bosart Sale');
});
