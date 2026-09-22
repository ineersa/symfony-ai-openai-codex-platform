<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

enum CodexTransportEnum: string
{
    case Websocket = 'websocket';
    case WebsocketCached = 'websocket-cached';
    case Sse = 'sse';

    public static function fromNullableString(?string $value): self
    {
        if (null === $value || '' === trim($value)) {
            return self::default();
        }

        return self::fromString($value);
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            self::Websocket->value => self::Websocket,
            self::WebsocketCached->value => self::WebsocketCached,
            self::Sse->value => self::Sse,
            default => throw new \InvalidArgumentException(\sprintf('Invalid Codex transport "%s". Allowed values: websocket, websocket-cached, sse.', $value)),
        };
    }

    public static function default(): self
    {
        return self::Websocket;
    }
}
