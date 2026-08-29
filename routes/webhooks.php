<?php

declare(strict_types=1);

use App\Http\Controllers\Webhooks\SesNotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhooks
|--------------------------------------------------------------------------
|
| Endpoints that a third party posts to. Registered outside the `web` group
| deliberately: there is no session, no CSRF token, no resolved team and no
| authenticated person on any of them, and pretending otherwise by hanging
| them off the group that provides those things is how a webhook ends up
| depending on one of them.
|
| Each one authenticates itself. `SesNotificationController` does it with
| Amazon's signature over the canonical SNS string plus a configured topic
| ARN — see that class, and PRD §8.5.
|
*/

/*
 * Not `messages/...`, which is the team-facing queue, and not under `api/`,
 * which this product does not otherwise use. The path names who is calling.
 */
Route::post('webhooks/ses', SesNotificationController::class)
    ->name('webhooks.ses');
