<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;

use function Sentry\captureException;

use Sentry\State\Scope;

use function Sentry\withScope;

/**
 * A failed job is the product's loudest signal, and PRD §9 asks for an alert
 * within 15 minutes of one.
 *
 * The alerting rule itself lives in Sentry (docs/Deployment.md §5); what has
 * to exist here is the report that rule fires on, carrying enough to act —
 * the job class, the queue, the connection — and nothing else. A job payload
 * on this product contains a client's address, so it never reaches either
 * Sentry or the log.
 */
final class ReportFailedJob
{
    public function handle(JobFailed $event): void
    {
        $context = [
            'job' => $event->job->resolveName(),
            'connection' => $event->connectionName,
            'queue' => (string) $event->job->getQueue(),
            'attempts' => (string) $event->job->attempts(),
        ];

        Log::error('Queued job failed.', $context);

        if (blank(config('sentry.dsn'))) {
            return;
        }

        /*
         * withScope, not configureScope: a Horizon worker is long-lived, and
         * configureScope would leave this job's tags on every later event from
         * the same process — including unrelated failures.
         */
        withScope(function (Scope $scope) use ($context, $event): void {
            // Tags, not the payload: these are what an alert rule groups on.
            foreach ($context as $key => $value) {
                $scope->setTag('job.'.$key, $value);
            }

            // The hub's current scope is the one pushed above, so the capture
            // picks up these tags and nothing later does.
            captureException($event->exception);
        });
    }
}
