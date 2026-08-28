<?php

declare(strict_types=1);

namespace App\Support\Extraction;

use App\Support\Extraction\Contracts\ExtractionProvider;
use App\Support\Extraction\Providers\AnthropicProvider;
use App\Support\Extraction\Providers\NullProvider;
use Illuminate\Contracts\Container\Container;

/**
 * The one place a driver name becomes a class (F10.6 · issue #113).
 *
 * *"Swapping providers is a config change."* The registry below is what makes
 * that literally true — adding a provider is a class plus a line here, and
 * nothing else in the application changes, because nothing else in the
 * application names a provider.
 *
 * Modelled on `App\Support\Workflow\Gates\GateRegistry`, including resolving
 * through the container rather than `new`-ing: a provider that needs an HTTP
 * client, a logger or a clock should be able to take one.
 *
 * An unknown driver falls back to {@see NullProvider} rather than throwing,
 * and the direction of that choice is deliberate. A typo in
 * `EXTRACTION_DRIVER` on a running installation should stop documents leaving,
 * not take the application down — and it should be visible, which is why the
 * S65 dialog and the admin health screen both read `isConfigured()` rather
 * than assuming.
 *
 * @see config/extraction.php
 */
final class ProviderManager
{
    /** @var array<string, class-string<ExtractionProvider>> */
    private const PROVIDERS = [
        'anthropic' => AnthropicProvider::class,
        'null' => NullProvider::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function provider(): ExtractionProvider
    {
        $driver = (string) config('extraction.driver', 'null');

        /** @var class-string<ExtractionProvider> $class */
        $class = self::PROVIDERS[$driver] ?? NullProvider::class;

        return $this->container->make($class);
    }

    /**
     * Is extraction available at all on this installation?
     *
     * Asked before a row is written, so somebody is told *"this is not switched
     * on"* at the moment they press the button rather than one worker later.
     */
    public function isAvailable(): bool
    {
        return $this->provider()->isConfigured();
    }
}
