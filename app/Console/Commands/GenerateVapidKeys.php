<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;
use Throwable;

/**
 * The one-off that makes push possible in an environment (#103).
 *
 * ## Printed, never written
 *
 * The private key is a secret and this command does not touch `.env` — the
 * same decision `invitation:link` and `auth:reset-link` make about their
 * output. Writing it would mean the command needs to parse and rewrite a file
 * that may be templated, encrypted, or managed by something else entirely;
 * printing it means an operator pastes it wherever their environment actually
 * keeps secrets. See `docs/Environment and secrets.md`.
 *
 * ## And generated once, not rotated
 *
 * Worth saying in the output, because it is the opposite of the advice people
 * carry about key material. The **public** half is baked into every
 * subscription a browser has already created, so replacing the pair does not
 * re-key anything — it invalidates every existing subscription silently, and
 * every device has to be re-subscribed by hand. Nobody finds out until they
 * notice they have stopped being notified.
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'push:vapid-keys';

    protected $description = 'Generate a VAPID key pair for web push';

    public function handle(): int
    {
        try {
            $keys = VAPID::createVapidKeys();
        } catch (Throwable $failure) {
            /*
             * The class and nothing else. Key generation failing is an
             * openssl problem — a missing extension, a locked-down build —
             * and its message can carry paths.
             */
            $this->components->error('Could not generate a key pair: '.$failure::class);

            return self::FAILURE;
        }

        $this->components->info('Add these to this environment and do not rotate them.');

        $this->line('');
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('');

        $this->components->warn(
            'The public key is baked into every subscription a browser creates. '
            .'Replacing this pair does not re-key them, it silently invalidates '
            .'them all — every device then has to be re-subscribed by hand.',
        );

        $this->components->info(
            'VAPID_SUBJECT must also be set, to a mailto: or https: URL a push '
            .'service can use to reach somebody about this application.',
        );

        return self::SUCCESS;
    }
}
