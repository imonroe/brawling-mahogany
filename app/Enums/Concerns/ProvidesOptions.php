<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

/**
 * Shared helpers for the lookup and state enums.
 */
trait ProvidesOptions
{
    /**
     * Every value, in the order the PRD lists them.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Value => internal label, for a picker.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Every internal label, in PRD order.
     *
     * @return list<string>
     */
    public static function labels(): array
    {
        return array_map(fn (self $case): string => $case->label(), self::cases());
    }
}
