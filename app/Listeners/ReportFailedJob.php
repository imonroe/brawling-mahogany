<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;

use function Sentry\captureException;
use function Sentry\configureScope;

use Sentry\State\Scope;

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

        if (config('sentry.dsn') === null) {
            return;
        }

        configureScope(function (Scope $scope) use ($context): void {
            // Tags, not the payload: these are what an alert rule groups on.
            foreach ($context as $key => $value) {
                $scope->setTag('job.'.$key, $value);
            }
        });

        captureException($event->exception);
    }
}
