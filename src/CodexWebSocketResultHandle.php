<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

/**
 * Marker object for RawResultInterface::getObject() on WebSocket Codex results.
 *
 * HTTP status handling in ResultConverter applies only to RawHttpResult.
 */
final class CodexWebSocketResultHandle
{
}
