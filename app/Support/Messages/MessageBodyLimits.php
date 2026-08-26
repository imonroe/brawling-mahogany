<?php

declare(strict_types=1);

namespace App\Support\Messages;

/**
 * How long a message's parts may be, in one place (issues #90, #93).
 *
 * Two screens now write these fields — S45's template editor and S48's
 * approval preview, where a reviewer edits the words of one queued instance
 * before releasing it — and they are validated by two different form requests.
 * Numbers repeated across two validators drift, and the direction they drift
 * is the one that matters here: an approver allowed 200 KB into a field the
 * template editor caps at 100 KB is an approver who can build a message the
 * product's own merge-field scan was measured against a smaller body.
 *
 * `SUBJECT` is 200 rather than the RFC 5322 line length because it is a
 * product limit, not a protocol one — a subject a mail client truncates at 78
 * characters has no business being three hundred.
 */
final class MessageBodyLimits
{
    public const SUBJECT = 200;

    /**
     * 100 KB, and it is a performance bound as much as a product one.
     *
     * Taking `<style>` blocks out of a markup body is quadratic on an unclosed
     * one — measured at 483ms for this much text against 65ms for the same
     * size of ordinary email, which is why the preview route is throttled.
     */
    public const HTML = 100000;

    public const TEXT = 100000;
}
