<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Symfony\Component\Uid\UuidV7;

/**
 * Resolved Codex correlation identifiers for one request invocation.
 */
final readonly class CodexCorrelationResolution
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $id,
        public array $options,
        public CodexCorrelationProvenance $provenance,
    ) {
    }

    /**
     * Correlation ID for a bounded 401 retry handshake/header.
     *
     * Generated IDs rotate to a fresh UUIDv7 so retry headers and body stay aligned.
     * Explicit caller IDs are preserved unchanged across retry.
     */
    public function idFor401Retry(): string
    {
        if (CodexCorrelationProvenance::Generated === $this->provenance) {
            return UuidV7::v7()->toRfc4122();
        }

        return $this->id;
    }
}
