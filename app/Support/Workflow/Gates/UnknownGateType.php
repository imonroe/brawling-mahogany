<?php

declare(strict_types=1);

namespace App\Support\Workflow\Gates;

use RuntimeException;

/**
 * A gate carries a type no evaluator answers to.
 *
 * Thrown, never defaulted. Issue #67 is unusually blunt about the reason:
 *
 * > A test asserts that an unknown gate type never evaluates as met. **Failing
 * > open on a gate is the worst available bug in this product.**
 *
 * A gate exists to stop a deal moving before something is true. One that
 * silently passes because nothing recognised its type has not failed to work —
 * it has actively asserted something nobody checked, which is worse than being
 * absent. So an unrecognised type is loud, and the advance it was guarding
 * does not happen.
 */
final class UnknownGateType extends RuntimeException
{
    public static function for(string $type): self
    {
        return new self(
            "No evaluator is registered for gate type [{$type}]. "
            .'A gate whose type nobody recognises cannot be treated as met.',
        );
    }
}
