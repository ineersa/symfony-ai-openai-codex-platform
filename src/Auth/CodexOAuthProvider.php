<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Auth;

use League\OAuth2\Client\Provider\GenericProvider;
use Psr\Http\Message\RequestInterface;

/**
 * OAuth provider for the public Codex PKCE client.
 *
 * Extends league/oauth2-client GenericProvider with two compatibility fixes
 * that are generic to public OAuth clients (no client secret). Both OpenAI's
 * Hydra (Codex) and xAI's auth.x.ai (Grok CLI) reject the same league defaults:
 *
 * 1. The default 'approval_prompt' parameter injected by the league library
 *    is stripped from the authorization URL — neither IdP accepts this
 *    Google/Facebook-style extension for these client registrations.
 *
 * 2. Public OAuth clients have no client secret. The league library sends an
 *    empty 'client_secret' in the token request body by default, which both
 *    IdPs reject. This provider filters it out when empty.
 *
 * Used by {@see CodexOAuthService} and {@see CodexTokenRefresher}.
 * Hosts can reuse these public-client fixes through GenericProvider options.
 *
 * @see https://auth.openai.com/oauth/authorize
 * @see https://auth.openai.com/oauth/token
 * @see https://auth.x.ai/oauth2/authorize
 * @see https://auth.x.ai/oauth2/token
 */
final class CodexOAuthProvider extends GenericProvider
{
    /**
     * Strips the league/oauth2-client default 'approval_prompt' parameter.
     *
     * OpenAI's Hydra instance rejects unrecognized/invalid parameters for
     * the Codex OAuth client. This parameter is a Google/Facebook extension
     * that is not part of the OAuth 2.0 or OpenID Connect specifications
     * and is not expected by Hydra.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    protected function getAuthorizationParameters(array $options)
    {
        $params = parent::getAuthorizationParameters($options);
        unset($params['approval_prompt']);

        return $params;
    }

    /**
     * Filters out the empty client_secret from token request params.
     *
     * OpenAI Codex is a public OAuth 2.0 client — there is no client secret.
     * The league library unconditionally includes the property in the token
     * request body, which OpenAI's Hydra OAuth server rejects as an
     * unrecognised/invalid parameter. We omit the field entirely when the
     * value is empty.
     *
     * @param array<string, mixed> $params
     *
     * @return RequestInterface
     */
    protected function getAccessTokenRequest(array $params)
    {
        if (\array_key_exists('client_secret', $params) && (null === $params['client_secret'] || '' === $params['client_secret'])) {
            unset($params['client_secret']);
        }

        return parent::getAccessTokenRequest($params);
    }
}
