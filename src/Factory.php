<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\CodexContract;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Factory
{
    /**
     * @param non-empty-string $name
     * @param list<string>     $internalOptions Host-only invocation options consumed before transmission
     */
    public static function createProvider(
        string $baseUrl = 'https://chatgpt.com/backend-api',
        #[\SensitiveParameter] string $accessToken = '',
        string $accountId = '',
        ?HttpClientInterface $httpClient = null,
        ModelCatalogInterface $modelCatalog = new CodexModelCatalog(),
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        string $responsesPath = '/codex/responses',
        string $originator = 'symfony-ai-openai-codex',
        string $name = 'openai-codex',
        ?LoggerInterface $logger = null,
        ?\Closure $accessTokenRefresher = null,
        CodexTransportEnum $transport = CodexTransportEnum::Websocket,
        ?CodexWebSocketConnectorInterface $websocketConnector = null,
        ?CodexWebSocketConnectionCache $websocketConnectionCache = null,
        CodexWebSocketCacheSettings $websocketCacheSettings = new CodexWebSocketCacheSettings(),
        string $userAgent = 'symfony-ai-openai-codex',
        array $internalOptions = [],
    ): ProviderInterface {
        $httpClient ??= HttpClient::create();
        $requestBodyFactory = new CodexRequestBodyFactory($internalOptions);

        $modelClient = self::createModelClient(
            $transport,
            $httpClient,
            $baseUrl,
            $accessToken,
            $accountId,
            $responsesPath,
            $originator,
            $logger,
            $accessTokenRefresher,
            $requestBodyFactory,
            $websocketConnector,
            $websocketConnectionCache,
            $websocketCacheSettings,
            $name,
            $userAgent,
        );

        return new Provider(
            $name,
            [$modelClient],
            [new ResultConverter()],
            $modelCatalog,
            $contract ?? CodexContract::create(),
            $eventDispatcher,
        );
    }

    private static function createModelClient(
        CodexTransportEnum $transport,
        HttpClientInterface $httpClient,
        string $baseUrl,
        string $accessToken,
        string $accountId,
        string $responsesPath,
        string $originator,
        ?LoggerInterface $logger,
        ?\Closure $accessTokenRefresher,
        CodexRequestBodyFactory $requestBodyFactory,
        ?CodexWebSocketConnectorInterface $websocketConnector,
        ?CodexWebSocketConnectionCache $websocketConnectionCache,
        CodexWebSocketCacheSettings $websocketCacheSettings,
        string $providerId,
        string $userAgent,
    ): ModelClientInterface {
        return match ($transport) {
            CodexTransportEnum::Sse => new CodexModelClient(
                $httpClient,
                $baseUrl,
                $accessToken,
                $accountId,
                $responsesPath,
                $originator,
                $logger,
                $accessTokenRefresher,
                $requestBodyFactory,
                $userAgent,
            ),
            CodexTransportEnum::Websocket, CodexTransportEnum::WebsocketCached => new CodexWebSocketModelClient(
                $websocketConnector ?? new AmpCodexWebSocketConnector(),
                new CodexWebSocketUrlResolver(),
                new CodexWebSocketHandshakeHeadersFactory(),
                $requestBodyFactory,
                $baseUrl,
                $accessToken,
                $accountId,
                $responsesPath,
                $originator,
                $providerId,
                $logger,
                $accessTokenRefresher,
                transport: $transport,
                connectionCache: CodexTransportEnum::WebsocketCached === $transport ? $websocketConnectionCache : null,
                cacheSettings: $websocketCacheSettings,
                userAgent: $userAgent,
            ),
        };
    }
}
