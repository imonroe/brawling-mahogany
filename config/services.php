<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),

        /*
         * The SNS topic that may post bounce and complaint notifications
         * (#95). **Not optional in production**, and the webhook refuses
         * everything while it is empty rather than accepting everything.
         *
         * A valid Amazon signature only proves *some* SNS topic sent the
         * message: anybody with an AWS account can create one, point it here,
         * and have its notifications signed exactly as genuinely as ours. This
         * is what makes the signature check mean *our* topic — see
         * `App\Support\Delivery\SnsMessage`.
         */
        'topic_arn' => env('SES_SNS_TOPIC_ARN'),
    ],

    /*
     * The n8n form behind the top bar's Report a bug button (issue #176).
     *
     * A URL rather than a credential: n8n hosts the form, takes the
     * submission, and opens the GitHub issue. Nothing here authenticates to
     * it, and this application never sees what somebody types into it.
     *
     * `enabled` is separate from `url` on purpose — see
     * `App\Support\Feedback\BugReportForm`.
     */
    'bug_report' => [
        'enabled' => env('BUG_REPORT_ENABLED', false),
        'url' => env('BUG_REPORT_URL'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
