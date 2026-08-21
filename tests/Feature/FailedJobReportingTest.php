<?php

declare(strict_types=1);

use App\Listeners\ReportFailedJob;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;

/**
 * PRD §9 asks for an alert within 15 minutes of a queue failure. The rule
 * lives in Sentry; this is the report it fires on, and what it must not carry.
 */
function failedJobEvent(Throwable $exception): JobFailed
{
    $job = Mockery::mock(Job::class);
    $job->allows('resolveName')->andReturns('App\\Jobs\\SendMilestoneEmail');
    $job->allows('getQueue')->andReturns('automation');
    $job->allows('attempts')->andReturns(3);

    return new JobFailed('redis', $job, $exception);
}

it('logs the job, the queue, and the attempt count', function (): void {
    Log::spy();

    (new ReportFailedJob)->handle(failedJobEvent(new RuntimeException('SES rejected the message')));

    Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
        return $message === 'Queued job failed.'
            && $context['job'] === 'App\\Jobs\\SendMilestoneEmail'
            && $context['queue'] === 'automation'
            && $context['attempts'] === '3';
    })->once();
});

it('never carries the job payload', function (): void {
    // A payload on this product holds a client's address; the alert needs the
    // job's identity, not its contents.
    Log::spy();

    (new ReportFailedJob)->handle(failedJobEvent(new RuntimeException('failed')));

    Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
        return array_keys($context) === ['job', 'connection', 'queue', 'attempts'];
    })->once();
});
