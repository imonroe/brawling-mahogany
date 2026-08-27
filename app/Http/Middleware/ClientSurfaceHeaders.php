<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two headers the client status page cannot do without (#110, #111).
 *
 * ## `Referrer-Policy: no-referrer`
 *
 * The session token is in the path — a deliberate trade, argued in
 * `StatusPageController` — which makes the URL itself the credential. A client
 * who follows any outbound link from this page would otherwise hand that URL
 * to whatever they land on, in a header they never see. The page has few
 * outbound links today; the point is that it is one edit away from having one.
 *
 * ## `X-Robots-Tag: noindex, nofollow`
 *
 * A search engine that found a status page would publish somebody's
 * transaction. Unlikely, and cheap to make impossible: this URL travels by
 * email and by forwarding, both of which are places a crawler can end up.
 *
 * ## Why middleware on one group rather than a global header
 *
 * A rule seventy screens inherit without needing it is a rule somebody relaxes
 * later without being able to see why it is there. These four routes need it
 * and nothing else does.
 */
class ClientSurfaceHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::apply($next($request));
    }

    /**
     * The headers, in one place, because two things need them.
     *
     * An exception is converted into a response **outside** the route
     * middleware — Laravel catches in the kernel, above every pipeline — so
     * this middleware never sees a 404, a 429 or a 500 on its own group. Every
     * error on the client surface went out bare, which is the case the
     * referrer header exists for most: a client who has just been refused is
     * the one most likely to click away to something else.
     *
     * So `bootstrap/app.php`'s `respond()` calls this too. A static rather
     * than three header names copied into a second file, because a rule with
     * two writers is a rule that is about to have two versions.
     */
    public static function apply(Response $response): Response
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        /*
         * And never a shared cache. A status page is one client's transaction,
         * served from a URL a proxy has no way to tell apart from any other —
         * `private` is the difference between a cache hit and a stranger
         * reading somebody's sale.
         */
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }
}
