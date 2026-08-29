<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            /*
             * Above the longest job timeout in the application, which is
             * `RunDocumentExtraction`'s — the provider's own 180 seconds plus
             * its margin (#115).
             *
             * Laravel's rule is that `retry_after` must exceed every job's
             * timeout, or Redis makes the message visible again while the
             * worker is still holding it and a second worker picks it up. At
             * the previous **90** an extraction was handed out again while the
             * first read was still in flight. `PerformExtraction::claim()`
             * happens to catch that one — a conditional `UPDATE … WHERE state
             * = 'queued'` — so it cost a wasted worker rather than a second
             * charge, which is the layer working, not the setting being right.
             *
             * `tests/Unit/Extraction/ExtractionTimeoutsTest.php` holds the
             * ordering, because this file and the job are edited by different
             * people for different reasons.
             */
            'retry_after' => (int) env(
                'REDIS_QUEUE_RETRY_AFTER',
                /*
                 * **Derived, not a literal**, for the reason the job's own
                 * timeout is derived: the invariant has to hold at every value
                 * of `EXTRACTION_TIMEOUT`, not only at the default.
                 *
                 * A flat `300` held against the shipped 180 and inverted at any
                 * `EXTRACTION_TIMEOUT >= 240` — well inside the range the
                 * `.env.example` comment calls reasonable for *"several pages
                 * of contract through a vision model"*. The place that value is
                 * raised is a `.env` on the droplet, which is the one place no
                 * test run and no review can observe (`MAIL_REDIRECT_TO`'s
                 * lesson, #196), so the number has to follow it by itself.
                 *
                 * `+ 120` over the job's own `+ 60`: the margin is for the gap
                 * between a worker being killed and Redis making the message
                 * visible again, and it only has to be big enough that a live
                 * worker is never overtaken.
                 */
                (int) env('EXTRACTION_TIMEOUT', 180) + 120,
            ),
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
