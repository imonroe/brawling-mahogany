<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Web push (VAPID) — issue #103, PRD §4.12 F12.2
    |--------------------------------------------------------------------------
    |
    | VAPID is how a push service knows which application a message is from.
    | The key pair is generated once per environment with
    | `php artisan push:vapid-keys` and then left alone: the **public** half is
    | baked into every subscription a browser creates, so rotating it does not
    | re-key existing subscriptions — it silently invalidates every one of
    | them, and every device has to be re-subscribed by hand. Treat it like a
    | domain, not like a password.
    |
    | Absent keys are not an error. Push is one channel of several and the
    | panel is the record (ADR 0003), so an environment with no keys simply
    | never offers push — `enabled()` below is what every caller asks.
    |
    */

    'vapid' => [
        /*
         * Who to contact about this application's pushes. A push service that
         * sees something wrong — a flood, a broken endpoint pattern — uses
         * this, and RFC 8292 requires it to be a `mailto:` or an `https:` URL.
         * A malformed one is rejected by some services at send time rather
         * than at configuration time, which is a bad place to find out.
         */
        'subject' => env('VAPID_SUBJECT'),

        'public_key' => env('VAPID_PUBLIC_KEY'),

        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    /*
     * How long the library may wait on one push service.
     *
     * Short, because `SendPush` runs inside the notification delivery job and
     * a person with four dead devices should not hold a worker for two
     * minutes. A timeout is not a failure worth reporting: the panel already
     * has the notification.
     */
    'timeout' => (int) env('PUSH_TIMEOUT', 10),

];
