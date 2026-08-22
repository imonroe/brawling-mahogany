<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * PRD §9 Authorization: *"Deny by default. Every controller action gated by a
 * policy."*
 *
 * `AuthorizesRequests` is here rather than on individual controllers so that
 * `$this->authorize()` is always available — a controller that has to remember
 * to import a trait before it can check a permission is a controller that will
 * one day not bother. `tests/Feature/AuthorizationCoverageTest.php` enumerates
 * the routes and fails on any action that never asks.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
