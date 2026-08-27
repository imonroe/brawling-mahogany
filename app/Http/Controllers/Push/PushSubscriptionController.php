<?php

declare(strict_types=1);

namespace App\Http\Controllers\Push;

use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Support\Push\PushSubscriptionRegistry;
use App\Support\Push\SendPush;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * S55 — registering and forgetting a device (#103 · F12.2).
 *
 * ## Authorised by the predicate, like S08 and S78
 *
 * Every write here is keyed on `$request->user()`, so a subscription can only
 * ever be attached to the person asking. There is no permission to hold —
 * everybody chooses whether their own phone buzzes — and a policy would be a
 * second thing to keep in step with the predicate that already decides.
 * `AuthorizationCoverageTest` records the exemption with that reason.
 *
 * ## Not team-scoped, and neither is the route
 *
 * A subscription belongs to a browser, and a browser belongs to a person who
 * may be in two teams (see the migration). So this deliberately does not care
 * which team is resolved, and switching teams neither registers nor forgets
 * anything.
 */
class PushSubscriptionController extends Controller
{
    public function store(Request $request, PushSubscriptionRegistry $registry): Response
    {
        $person = $request->user();

        abort_unless($person instanceof Person, 403);

        /*
         * **422 rather than a stored row that can never be pushed to.** Every
         * one of these comes from `PushManager.subscribe()` and is opaque to
         * us, so the only checks worth making are structural — and the one
         * that matters is `url` on the endpoint: a non-URL there is a row
         * `SendPush` would hand to an HTTP client on every notification,
         * forever, for a device that does not exist.
         */
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'url:http,https', 'max:2000'],
            'public_key' => ['required', 'string', 'max:255'],
            'auth_token' => ['required', 'string', 'max:255'],
            'user_agent' => ['nullable', 'string'],
        ]);

        /*
         * Refused rather than stored when the environment cannot push. A row
         * written now would sit there un-pushable until somebody configured
         * VAPID keys, and the browser would have granted a permission that
         * does nothing — which is the state S55's *"blocked"* copy exists to
         * make legible, told from the wrong side.
         */
        abort_unless(SendPush::configured(), 503);

        $registry->store(
            person: $person,
            endpoint: $validated['endpoint'],
            publicKey: $validated['public_key'],
            authToken: $validated['auth_token'],
            userAgent: $request->userAgent(),
        );

        /*
         * **204 for the background re-post, a redirect for the form.**
         *
         * `resources/js/lib/pwa.ts` re-posts the browser's subscription on
         * every navigation with a plain `fetch`, and `fetch` follows a 302 —
         * so `back()` alone meant every navigation quietly fetched and
         * discarded a whole rendered page. Round 2 of review measured it.
         *
         * `wantsJson()` rather than sniffing for Inertia: the S55 button goes
         * through `router.post` and needs the redirect that re-renders the
         * device list, and the background call asks for nothing back.
         */
        if ($request->wantsJson()) {
            return response()->noContent();
        }

        return back();
    }

    /**
     * Forget one device, or all of them.
     *
     * One endpoint when the browser has just unsubscribed and knows which,
     * which is what S55's control does — it calls `unsubscribe()` first, so
     * the browser stops holding one and `reRegisterPush()` has nothing to
     * hand back.
     *
     * The no-endpoint branch forgets every row for the person, and it is
     * reached by the sign-out hook rather than by anything on a screen. Worth
     * being exact about what it can promise, because round 2 of review asked:
     * it removes the **server's** ability to push, and it cannot reach into a
     * browser somewhere else that still holds a subscription. A device that
     * still holds one, whose owner signs in on it again, re-registers — which
     * is correct, because that browser never stopped being subscribed. What
     * the sign-out wipe actually buys is the case it was written for: a phone
     * handed back and never signed into again stops buzzing.
     */
    public function destroy(Request $request, PushSubscriptionRegistry $registry): RedirectResponse
    {
        $person = $request->user();

        abort_unless($person instanceof Person, 403);

        $validated = $request->validate([
            'endpoint' => ['sometimes', 'string', Rule::exists('push_subscriptions', 'endpoint')
                ->where('person_id', $person->getKey())],
        ]);

        $endpoint = $validated['endpoint'] ?? null;

        if (is_string($endpoint) && $endpoint !== '') {
            $registry->forget($endpoint);
        } else {
            $registry->forgetFor($person);
        }

        return back();
    }
}
