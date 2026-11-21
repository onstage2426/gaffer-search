<?php

declare(strict_types=1);

namespace GafferSearch\Services;

/**
 * Thrown when an index is already being rebuilt by another request or process.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class IndexBusyException extends \RuntimeException
{
    /** @param bool $elsewhere Another web server is building it (its result arrives as a snapshot). */
    public function __construct(string $message, public readonly bool $elsewhere = false)
    {
        parent::__construct($message);
    }
}
