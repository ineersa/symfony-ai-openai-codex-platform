<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Auth;

/**
 * Immutable value object holding Codex OAuth credentials.
 *
 * Maps to the per-provider entry in auth.json:
 *   providerKey => { type, access, refresh, expires, accountId }
 */
final readonly class CodexAuthRecord
{
    public string $type;

    public function __construct(
        public string $access,
        public string $refresh,
        public int $expires,
        public string $accountId,
        string $type = 'oauth',
    ) {
        $this->type = $type;
    }

    /**
     * Whether the access token is expired (or within 60s of expiry).
     *
     * The `expires` field is a Unix timestamp in **seconds**, as returned
     * by League\OAuth2\Client\Token\AccessToken::getExpires().
     *
     * @param int $bufferSeconds Grace period before actual expiry
     */
    public function isExpired(int $bufferSeconds = 60): bool
    {
        return (time() + $bufferSeconds) >= $this->expires;
    }

    /**
     * Create a record from the auth.json entry array.
     *
     * @param array{access?: string, refresh?: string, expires?: int, accountId?: string, type?: string} $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['access'], $data['refresh'], $data['accountId'])) {
            throw new \InvalidArgumentException('Codex auth record missing required fields: access, refresh, accountId');
        }

        return new self(
            access: (string) $data['access'],
            refresh: (string) $data['refresh'],
            expires: (int) ($data['expires'] ?? 0),
            accountId: (string) $data['accountId'],
            type: (string) ($data['type'] ?? 'oauth'),
        );
    }

    /**
     * Serialize to an array suitable for auth.json storage.
     *
     * @return array{type: string, access: string, refresh: string, expires: int, accountId: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'access' => $this->access,
            'refresh' => $this->refresh,
            'expires' => $this->expires,
            'accountId' => $this->accountId,
        ];
    }
}
