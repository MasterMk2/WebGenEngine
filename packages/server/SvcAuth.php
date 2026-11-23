<?php
// Requests signed by a trusted front (contract "svc-auth/v1"), for running the editor behind an app that
// signs its users in and decides what each may do (front.php, docs/design/server.md §6). The front calls
// this server with an HMAC-SHA256 over
//   svc-auth/v1 \n client \n METHOD \n path with query \n time (ms) \n nonce \n actor \n SHA-256 of the body (hex)
// in the headers x-svc-client, x-svc-timestamp, x-svc-nonce, x-svc-actor (absent when empty) and
// x-svc-signature (hex). The time may be off by 30 s at most, and a nonce is accepted once.
// fixtures/svc-auth.json holds values made by such a front's own code, so the two stay the same.

declare(strict_types=1);

namespace WebGenEngine\Server;

use PDO;

final class SvcAuth
{
    public const VERSION = 'svc-auth/v1';
    public const MAX_SKEW_MS = 30_000;
    public const MIN_KEY_BYTES = 32;

    /** @var \Closure(): int */
    private \Closure $now;

    public function __construct(
        private readonly string $key,
        private readonly string $client,
        private readonly PDO $db,
        ?\Closure $now = null,
    ) {
        if (strlen($key) < self::MIN_KEY_BYTES || $client === '') {
            throw new \InvalidArgumentException('svc-auth needs a key of 32 bytes or more and a client ID');
        }
        $this->now = $now ?? static fn (): int => (int) floor(microtime(true) * 1000);
        $db->exec('CREATE TABLE IF NOT EXISTS svc_nonces (nonce TEXT PRIMARY KEY, at INTEGER NOT NULL)');
    }

    // Key file content: hex, or base64 / base64url, of 32 random bytes or more (the same rule as the front's).
    public static function parseKey(string $text): ?string
    {
        $value = trim($text);
        if (preg_match('/^[0-9a-fA-F]+$/D', $value) === 1 && strlen($value) % 2 === 0) {
            $key = (string) hex2bin($value);
        } elseif (preg_match('/^[A-Za-z0-9+\/_-]+={0,2}$/D', $value) === 1) {
            $key = base64_decode(strtr($value, '-_', '+/'), true);
            if ($key === false) {
                return null;
            }
        } else {
            return null;
        }
        if (strlen($key) < self::MIN_KEY_BYTES || count(array_unique(str_split($key))) === 1) {
            return null;
        }
        return $key;
    }

    public static function canonical(string $client, string $method, string $path, string $timestamp, string $nonce, string $actor, string $body): string
    {
        return implode("\n", [self::VERSION, $client, strtoupper($method), $path, $timestamp, $nonce, $actor, hash('sha256', $body)]);
    }

    public static function sign(string $key, string $client, string $method, string $path, string $timestamp, string $nonce, string $actor, string $body): string
    {
        return hash_hmac('sha256', self::canonical($client, $method, $path, $timestamp, $nonce, $actor, $body), $key);
    }

    /**
     * Checks one request. $path is the request target as received (path and query, not decoded).
     *
     * @param array<string, string> $headers lower-case names
     * @return array{ok: true, actor: string}|array{ok: false, reason: string}
     */
    public function verify(string $method, string $path, string $body, array $headers): array
    {
        $client = $headers['x-svc-client'] ?? '';
        $timestamp = $headers['x-svc-timestamp'] ?? '';
        $nonce = $headers['x-svc-nonce'] ?? '';
        $actor = $headers['x-svc-actor'] ?? '';
        $signature = $headers['x-svc-signature'] ?? '';
        if (!hash_equals($this->client, $client)) {
            return ['ok' => false, 'reason' => 'client'];
        }
        if (preg_match('/^\d{1,15}$/D', $timestamp) !== 1) {
            return ['ok' => false, 'reason' => 'timestamp'];
        }
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $nonce) !== 1) {
            return ['ok' => false, 'reason' => 'nonce'];
        }
        if ($actor !== '' && preg_match('/^[\x21-\x7e]{1,128}$/D', $actor) !== 1) {
            return ['ok' => false, 'reason' => 'actor'];
        }
        if (preg_match('/^[0-9a-f]{64}$/D', $signature) !== 1) {
            return ['ok' => false, 'reason' => 'signature'];
        }
        $now = ($this->now)();
        if (abs($now - (int) $timestamp) > self::MAX_SKEW_MS) {
            return ['ok' => false, 'reason' => 'timestamp'];
        }
        if (!hash_equals(self::sign($this->key, $client, $method, $path, $timestamp, $nonce, $actor, $body), $signature)) {
            return ['ok' => false, 'reason' => 'signature'];
        }
        // Checked last, so a request with a bad signature cannot use up someone's nonce. Kept for twice the
        // allowed skew: an older request is refused by its time anyway.
        $this->db->prepare('DELETE FROM svc_nonces WHERE at < ?')->execute([$now - 2 * self::MAX_SKEW_MS]);
        $insert = $this->db->prepare('INSERT OR IGNORE INTO svc_nonces (nonce, at) VALUES (?, ?)');
        $insert->execute([$nonce, $now]);
        if ($insert->rowCount() !== 1) {
            return ['ok' => false, 'reason' => 'replay'];
        }
        return ['ok' => true, 'actor' => $actor];
    }
}
