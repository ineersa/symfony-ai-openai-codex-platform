<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Auth;

/**
 * Minimal local TCP HTTP server for the OAuth PKCE callback.
 *
 * Binds to 127.0.0.1:<port> and waits for a GET callback
 * request with query parameters ?code=...&state=...
 * (default path /auth/callback; providers may pass a different path).
 *
 * On success it returns the authorization code; on timeout or error
 * it returns null so the caller can fall back to manual paste input.
 *
 * Implementation uses raw PHP stream_socket_server to avoid any
 * HTTP server dependency. The server is single-request: accept one
 * connection, process it, and shut down.
 */
class LocalCallbackServer
{
    private const string DEFAULT_CALLBACK_PATH = '/auth/callback';

    /**
     * Wait for the OAuth callback on a local TCP socket.
     *
     * The server binds and listens BEFORE invoking the optional $afterListen
     * callback, so callers can safely launch a browser inside that callback
     * knowing a fast auth redirect will not hit a closed port.
     *
     * @param string        $expectedState  The OAuth state parameter to validate against
     * @param float         $timeoutSeconds Seconds to wait before returning null (pass 0 for no timeout)
     * @param int           $port           Local TCP port
     * @param callable|null $afterListen    Invoked after bind succeeds, before accept blocks
     * @param string        $callbackPath   Path the IdP redirects to (Codex: /auth/callback, xAI Grok: /callback)
     *
     * @return array{code: string}|null The authorization code, or null on timeout/error
     */
    public function waitForCallback(
        string $expectedState,
        float $timeoutSeconds = 300.0,
        int $port = CodexOAuthConfig::DEFAULT_PORT,
        ?callable $afterListen = null,
        string $callbackPath = self::DEFAULT_CALLBACK_PATH,
    ): ?array {
        $errno = 0;
        $errstr = '';

        $server = @stream_socket_server(
            'tcp://127.0.0.1:'.$port,
            $errno,
            $errstr,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );

        if (false === $server) {
            // Bind failure (e.g. port in use). Return null so caller falls
            // back to manual paste; the URL was already printed before
            // waitForCallback was called.
            return null;
        }

        try {
            // Bind precedes browser launch, and callback failures still close the socket.
            if (null !== $afterListen) {
                $afterListen();
            }

            $conn = @stream_socket_accept($server, $timeoutSeconds);
            if (false === $conn) {
                return null; // timeout
            }

            // 8 KB is intentionally generous: a local OAuth GET callback with
            // headers + query string rarely exceeds 2 KB, so this buffer size
            // safely captures the full request without streaming complexity.
            $request = @fread($conn, 8192);
            if (false === $request || '' === $request) {
                return null;
            }

            // Parse the first line: GET /auth/callback?code=...&state=... HTTP/1.1
            $firstLine = strstr($request, "\r\n", true);
            if (false === $firstLine || !str_starts_with($firstLine, 'GET ')) {
                $this->sendResponse($conn, 400, self::errorHtml('Invalid request method.'));

                return null;
            }

            // Extract the path + query part
            $pathAndQuery = substr($firstLine, 4, -9); // strip "GET " and " HTTP/1.1"
            $queryStart = strpos($pathAndQuery, '?');
            if (false === $queryStart) {
                $this->sendResponse($conn, 400, self::errorHtml('Missing query parameters.'));

                return null;
            }

            $path = substr($pathAndQuery, 0, $queryStart);
            $query = substr($pathAndQuery, $queryStart + 1);

            if ($callbackPath !== $path) {
                $this->sendResponse($conn, 404, self::errorHtml('Not found.'));

                return null;
            }

            parse_str($query, $params);

            $code = $params['code'] ?? null;
            $state = $params['state'] ?? null;

            // State must be present and match exactly (SECURITY: prevent CSRF)
            if (!\is_string($state) || $state !== $expectedState) {
                $this->sendResponse($conn, 400, self::errorHtml('State mismatch.'));

                return null;
            }

            if (!\is_string($code) || '' === $code) {
                $this->sendResponse($conn, 400, self::errorHtml('Missing authorization code.'));

                return null;
            }

            $this->sendResponse($conn, 200, self::successHtml());

            return ['code' => $code];
        } finally {
            if (isset($conn) && \is_resource($conn)) {
                @fclose($conn);
            }
            fclose($server);
        }
    }

    /**
     * @param resource $conn
     */
    private function sendResponse($conn, int $statusCode, string $body): void
    {
        $statusText = match ($statusCode) {
            200 => 'OK',
            400 => 'Bad Request',
            404 => 'Not Found',
            500 => 'Internal Server Error',
            default => 'Unknown',
        };

        $response = \sprintf(
            "HTTP/1.1 %d %s\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
            $statusCode,
            $statusText,
            \strlen($body),
            $body,
        );

        @fwrite($conn, $response);
        @fflush($conn);
    }

    private static function successHtml(): string
    {
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Authentication Successful</title><style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;background:#f0fdf4;color:#166534}div{text-align:center}h1{font-size:1.8rem}code{font-size:1.2rem;color:#15803d}</style></head><body><div><h1>✅ Authentication Successful</h1><p>You can close this browser window and return to the terminal.</p></div></body></html>';
    }

    private static function errorHtml(string $message): string
    {
        $escaped = htmlspecialchars($message, \ENT_QUOTES, 'UTF-8');

        return \sprintf('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Authentication Error</title><style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;background:#fef2f2;color:#991b1b}div{text-align:center}h1{font-size:1.8rem}p{font-size:1rem;color:#dc2626}</style></head><body><div><h1>❌ Authentication Error</h1><p>%s</p><p>Return to the terminal and try again.</p></div></body></html>', $escaped);
    }
}
