<?php

declare(strict_types=1);

use App\Models\Person;
use App\Support\Mail\EmailIndependence;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Tests\Support\Sources;

/**
 * ADR 0003: **no user flow depends on email alone.**
 *
 * Held here rather than by memory, for the reason every rule in this suite is:
 * the decision is cheap on the day a mailable is written and expensive a year
 * later. Slice 1 shipped one email-only flow — the one every customer starts
 * with — and nobody noticed until an environment with no mail transport made
 * the product unusable from its own first screen.
 *
 * So this reads `app/Mail`, reads the Fortify features that are switched on,
 * and fails when a sender is not listed in `EmailIndependence::FLOWS` with an
 * alternative that resolves against the real route table and the real artisan
 * registry. An entry is not a promise; the thing it names has to exist.
 */

/** @return list<class-string<Mailable>> */
function mailableClasses(): array
{
    return array_values(array_filter(array_map(
        static fn (string $path): string => 'App\\Mail\\'.str_replace(['/', '.php'], ['\\', ''], $path),
        Sources::files(['app/Mail'], ['php']),
    ), static fn (string $class): bool => class_exists($class) && is_subclass_of($class, Mailable::class)));
}

it('lists every mailable in the catalogue', function (): void {
    // A test that examines nothing passes for the wrong reason.
    expect(mailableClasses())->not->toBeEmpty();

    $uncatalogued = array_values(array_diff(mailableClasses(), EmailIndependence::coveredSenders()));

    expect($uncatalogued)->toBe(
        [],
        'A mailable exists with no non-email alternative recorded. ADR 0003: every flow the '
        .'product initiates by email must have a second way to be started or answered. Add it '
        .'to App\\Support\\Mail\\EmailIndependence::FLOWS along with the route or command that '
        .'is the second door — and if there is no second door yet, that is the thing to build, '
        .'not the thing to exempt.',
    );
});

it('covers password resets while Fortify is sending them', function (): void {
    if (! Features::enabled(Features::resetPasswords())) {
        $this->markTestSkipped('Password resets are disabled, so nothing is sent.');
    }

    expect(EmailIndependence::coveredSenders())->toContain(EmailIndependence::FORTIFY_PASSWORD_RESET);
});

it('covers email verification the moment it starts sending', function (): void {
    /*
     * The feature is switched on in config, but `Person` is not a
     * `MustVerifyEmail`, so nothing is ever sent and there is no flow to give
     * a second door to. The day somebody changes that, this fails — which is
     * the day the decision costs nothing.
     */
    if (! Features::enabled(Features::emailVerification()) || ! is_subclass_of(Person::class, MustVerifyEmail::class)) {
        $this->markTestSkipped('Email verification sends nothing while Person is not a MustVerifyEmail.');
    }

    expect(EmailIndependence::coveredSenders())->toContain(EmailIndependence::FORTIFY_EMAIL_VERIFICATION);
});

it('gives every flow at least one alternative', function (string $key, array $flow): void {
    expect($flow['alternatives'])->not->toBe(
        [],
        "[{$key}] is catalogued with no alternative at all, which is the state ADR 0003 forbids.",
    );
})->with(array_map(
    static fn (string $key): array => [$key, EmailIndependence::FLOWS[$key]],
    array_keys(EmailIndependence::FLOWS),
));

it('resolves every alternative it names', function (string $key, array $flow): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->map(static fn ($route): ?string => $route->getName())
        ->filter()
        ->all();

    $commands = array_keys(Artisan::all());

    foreach ($flow['alternatives'] as $alternative) {
        [$kind, $name] = explode(':', $alternative, 2);

        $exists = match ($kind) {
            'route' => in_array($name, $routes, true),
            'command' => in_array($name, $commands, true),
            default => false,
        };

        expect($exists)->toBeTrue(
            "[{$key}] names [{$alternative}] as its non-email alternative and it does not exist. ".
            'A catalogue of doors that are not there is worse than no catalogue: it reads as '.
            'coverage on every review after this one.',
        );
    }
})->with(array_map(
    static fn (string $key): array => [$key, EmailIndependence::FLOWS[$key]],
    array_keys(EmailIndependence::FLOWS),
));

it('keeps the catalogue free of senders that no longer exist', function (): void {
    foreach (EmailIndependence::coveredSenders() as $sender) {
        if (str_starts_with($sender, 'fortify:')) {
            continue;
        }

        expect(class_exists($sender))->toBeTrue(
            "[{$sender}] is catalogued and no longer exists. A stale entry reads as a decision ".
            'long after it stopped being one.',
        );
    }
});
