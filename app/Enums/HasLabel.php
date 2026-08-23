<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A backed enum that knows its own internal label.
 *
 * Code values are snake_case and match Information Architecture §8 and PRD
 * §6.3 exactly. Labels are Title Case, for the internal UI. Client-facing
 * wording is a separate layer and never comes from here (IA §4).
 */
interface HasLabel
{
    public function label(): string;
}
