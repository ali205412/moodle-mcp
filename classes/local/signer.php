<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

declare(strict_types=1);

namespace webservice_mcp\local;

/**
 * HMAC signer for opaque, short-lived, purpose-bound blobs.
 *
 * Used for MRTR requestState and for file upload/download tickets so neither
 * needs server-side storage. Payloads are integrity protected, not encrypted.
 *
 * @package     webservice_mcp
 * @copyright   2026 Ali Abdelaal
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signer {
    /**
     * Sign a payload for a purpose.
     *
     * @param string $purpose Purpose label; a blob signed for one purpose never verifies for another.
     * @param array $payload Claims.
     * @param int $ttl Lifetime in seconds.
     * @return string
     */
    public static function sign(string $purpose, array $payload, int $ttl): string {
        $payload['p'] = $purpose;
        $payload['exp'] = time() + $ttl;
        $body = self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $body . '.' . self::b64(hash_hmac('sha256', $body, self::secret(), true));
    }

    /**
     * Verify a blob and return its claims, or null when invalid, expired, or for another purpose.
     *
     * @param string $purpose Expected purpose.
     * @param string $blob Signed blob.
     * @return array|null
     */
    public static function verify(string $purpose, string $blob): ?array {
        $parts = explode('.', $blob);
        if (count($parts) !== 2) {
            return null;
        }
        [$body, $sig] = $parts;
        $expected = self::b64(hash_hmac('sha256', $body, self::secret(), true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $payload = json_decode((string)self::unb64($body), true);
        if (!is_array($payload) || ($payload['p'] ?? null) !== $purpose || (int)($payload['exp'] ?? 0) < time()) {
            return null;
        }
        return $payload;
    }

    /**
     * Return the site-specific signing secret, creating it on first use.
     *
     * @return string
     */
    private static function secret(): string {
        $secret = (string)get_config('webservice_mcp', 'signingsecret');
        if (strlen($secret) < 64) {
            $secret = bin2hex(random_bytes(32));
            set_config('signingsecret', $secret, 'webservice_mcp');
        }
        return $secret;
    }

    /**
     * Base64url encode.
     *
     * @param string $data Raw bytes.
     * @return string
     */
    private static function b64(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64url decode.
     *
     * @param string $data Encoded string.
     * @return string|false
     */
    private static function unb64(string $data) {
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
