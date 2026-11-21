<?php

declare(strict_types=1);

namespace GafferSearch\Support;

/**
 * An answer of the search endpoint: status, headers and the JSON body (null: a 304).
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final readonly class SearchEndpointResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public array $headers,
        public ?string $body,
    ) {}

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        if ($this->body !== null) {
            echo $this->body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON, sent as application/json
        }
    }
}
