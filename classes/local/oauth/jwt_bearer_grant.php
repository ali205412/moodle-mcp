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

namespace webservice_mcp\local\oauth;

use Closure;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use stdClass;

/**
 * Verify RFC 7523 identity assertions (Identity Assertion JWT Authorization Grant) from trusted enterprise IdPs.
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class jwt_bearer_grant {
    /** JOSE typ of an Identity Assertion JWT Authorization Grant. */
    private const ID_JAG_TYP = 'oauth-id-jag+jwt';

    /** Clock skew tolerance in seconds. */
    private const LEEWAY = 60;

    /** Asymmetric algorithms accepted for assertions. */
    private const ALGORITHMS = ['RS256', 'RS384', 'RS512', 'ES256', 'ES384'];

    /** Longest assertion lifetime accepted, so replay tracking can outlive every accepted assertion. */
    private const MAX_LIFETIME = 86400;

    /** Maximum size of fetched discovery and JWKS documents. */
    private const MAX_BYTES = 262144;

    /** @var Closure|null HTTP seam: fn(string $url): string body. */
    private ?Closure $fetcher;

    /**
     * Constructor.
     *
     * @param Closure|null $fetcher Optional HTTP fetcher for tests.
     */
    public function __construct(?Closure $fetcher = null) {
        $this->fetcher = $fetcher;
    }

    /**
     * Verify an assertion and return the Moodle user it identifies.
     *
     * @param string $assertion Compact JWS.
     * @param string $clientid Authenticated OAuth client id, which must match the client_id claim.
     * @param string[] $audiences Accepted audiences (issuer and token endpoint).
     * @return stdClass User record.
     */
    public function resolve_user(string $assertion, string $clientid, array $audiences): stdClass {
        if ($assertion === '') {
            throw new exception('invalid_request', 400, 'assertion is required.');
        }

        $parts = explode('.', $assertion);
        $header = count($parts) === 3 ? json_decode(JWT::urlsafeB64Decode($parts[0]), true) : null;
        $unverified = count($parts) === 3 ? json_decode(JWT::urlsafeB64Decode($parts[1]), true) : null;
        if (!is_array($header) || !is_array($unverified)) {
            throw new exception('invalid_grant', 400, 'The assertion is not a valid JWT.');
        }
        // Plain JWT or an ID-JAG; anything else (e.g. at+jwt access tokens) is a different token type.
        if (isset($header['typ']) && !in_array(strtolower((string)$header['typ']), [self::ID_JAG_TYP, 'jwt'], true)) {
            throw new exception('invalid_grant', 400, 'The assertion type is not supported.');
        }
        $alg = (string)($header['alg'] ?? '');
        if (!in_array($alg, self::ALGORITHMS, true)) {
            throw new exception('invalid_grant', 400, 'The assertion algorithm is not supported.');
        }

        $issuer = (string)($unverified['iss'] ?? '');
        if (!in_array(rtrim($issuer, '/'), self::trusted_issuers(), true)) {
            throw new exception('invalid_grant', 400, 'The assertion issuer is not trusted.');
        }

        $previousleeway = JWT::$leeway;
        JWT::$leeway = self::LEEWAY;
        try {
            $claims = (array)JWT::decode($assertion, $this->load_keys($issuer, $alg));
        } catch (exception $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new exception('invalid_grant', 400, 'The assertion signature or validity period is invalid.');
        } finally {
            JWT::$leeway = $previousleeway;
        }

        if (($claims['iss'] ?? null) !== $issuer || !isset($claims['exp'])) {
            throw new exception('invalid_grant', 400, 'The assertion is missing required claims.');
        }
        $aud = (array)($claims['aud'] ?? []);
        if (!array_intersect($aud, $audiences)) {
            throw new exception('invalid_grant', 400, 'The assertion audience is not this server.');
        }
        if (($claims['client_id'] ?? null) !== $clientid) {
            throw new exception('invalid_grant', 400, 'The assertion was not issued to this client.');
        }
        if ((int)$claims['exp'] > time() + self::MAX_LIFETIME) {
            throw new exception('invalid_grant', 400, 'The assertion lifetime is too long.');
        }

        $jti = $claims['jti'] ?? null;
        if (is_string($jti) && $jti !== '') {
            $this->consume_jti($issuer, $jti, (int)$claims['exp']);
        } else if (
            strtolower((string)($header['typ'] ?? '')) === self::ID_JAG_TYP ||
                get_config('webservice_mcp', 'emarequirejti') !== '0'
        ) {
            throw new exception('invalid_grant', 400, 'The assertion has no jti.');
        }

        return $this->match_user($claims)
            ?? throw new exception('invalid_grant', 400, 'The assertion subject does not match a Moodle user.');
    }

    /**
     * Accept an assertion id once per issuer until the assertion has expired (durable: webservice_mcp_jti).
     *
     * @param string $issuer Issuer.
     * @param string $jti Assertion id.
     * @param int $exp Assertion expiry.
     * @return void
     */
    private function consume_jti(string $issuer, string $jti, int $exp): void {
        global $DB;

        $key = hash('sha256', $issuer . "\n" . $jti);
        $lock = \core\lock\lock_config::get_lock_factory('webservice_mcp')->get_lock('jti_' . substr($key, 0, 40), 5);
        if (!$lock) {
            throw new exception('invalid_grant', 400, 'A concurrent request is using this assertion.');
        }
        try {
            $seen = $DB->get_record('webservice_mcp_jti', ['keyhash' => $key]);
            if ($seen && (int)$seen->expiresat + self::LEEWAY >= time()) {
                throw new exception('invalid_grant', 400, 'The assertion was already used.');
            }
            if ($seen) {
                $DB->set_field('webservice_mcp_jti', 'expiresat', $exp, ['id' => $seen->id]);
            } else {
                try {
                    $DB->insert_record('webservice_mcp_jti', (object)['keyhash' => $key, 'expiresat' => $exp]);
                } catch (\dml_write_exception $exception) {
                    // A concurrent redemption on another lock backend won the unique index.
                    throw new exception('invalid_grant', 400, 'The assertion was already used.');
                }
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Return the configured trusted issuers.
     *
     * @return string[]
     */
    public static function trusted_issuers(): array {
        $lines = preg_split('/\R/', (string)get_config('webservice_mcp', 'ematrustedissuers')) ?: [];
        return array_values(array_filter(array_map(static fn(string $line): string => rtrim(trim($line), '/'), $lines)));
    }

    /**
     * Load the issuer's signing keys via OIDC discovery, cached for an hour.
     *
     * @param string $issuer Trusted issuer URL.
     * @param string $alg Algorithm applied to keys that do not declare one.
     * @return array Firebase\JWT\Key objects keyed by kid.
     */
    private function load_keys(string $issuer, string $alg): array {
        $cache = \cache::make('webservice_mcp', 'ema_jwks');
        $cachekey = sha1($issuer);
        $jwks = json_decode((string)$cache->get($cachekey), true);

        if (!is_array($jwks)) {
            $discovery = json_decode($this->fetch(rtrim($issuer, '/') . '/.well-known/openid-configuration'), true);
            $jwksuri = is_array($discovery) ? (string)($discovery['jwks_uri'] ?? '') : '';
            if (!str_starts_with($jwksuri, 'https://')) {
                throw new exception('invalid_grant', 400, 'The issuer does not publish an HTTPS jwks_uri.');
            }
            $document = json_decode($this->fetch($jwksuri), true);
            $keys = array_values(array_filter(
                is_array($document) ? (array)($document['keys'] ?? []) : [],
                static fn($key): bool => is_array($key) && in_array($key['kty'] ?? '', ['RSA', 'EC'], true)
            ));
            if (!$keys) {
                throw new exception('invalid_grant', 400, 'The issuer publishes no usable signing keys.');
            }
            $jwks = ['keys' => $keys];
            $cache->set($cachekey, json_encode($jwks));
        }

        return JWK::parseKeySet($jwks, $alg);
    }

    /**
     * Fetch an HTTPS document with Moodle's SSRF-protected curl.
     *
     * @param string $url URL.
     * @return string
     */
    private function fetch(string $url): string {
        if ($this->fetcher !== null) {
            return ($this->fetcher)($url);
        }

        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        if (!str_starts_with($url, 'https://')) {
            throw new exception('invalid_grant', 400, 'Issuer metadata must be served over HTTPS.');
        }
        $curl = new \curl();
        $body = $curl->get($url, [], [
            'CURLOPT_TIMEOUT' => 5,
            'CURLOPT_CONNECTTIMEOUT' => 3,
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_MAXFILESIZE' => self::MAX_BYTES,
        ]);
        $info = $curl->get_info();
        if ($curl->get_errno() || (int)($info['http_code'] ?? 0) !== 200 || !is_string($body) || strlen($body) > self::MAX_BYTES) {
            throw new exception('invalid_grant', 400, 'Issuer metadata could not be retrieved.');
        }

        return $body;
    }

    /**
     * Map assertion claims to exactly one active Moodle user.
     *
     * @param array $claims Verified claims.
     * @return stdClass|null
     */
    private function match_user(array $claims): ?stdClass {
        global $CFG, $DB;

        // Immutable identifiers first: the auth_oidc (Microsoft) token table maps the IdP's oid/sub to a user.
        if ($DB->get_manager()->table_exists('auth_oidc_token')) {
            foreach ([$claims['oid'] ?? null, $claims['sub'] ?? null] as $uniqid) {
                if (!is_string($uniqid) || $uniqid === '') {
                    continue;
                }
                $username = $DB->get_field('auth_oidc_token', 'username', ['oidcuniqid' => $uniqid], IGNORE_MULTIPLE);
                $user = $username ? $DB->get_record('user', [
                    'username' => $username,
                    'deleted' => 0,
                    'mnethostid' => $CFG->mnet_localhost_id,
                ]) : null;
                if ($user) {
                    return $user;
                }
            }
        }

        $field = (string)get_config('webservice_mcp', 'emausermatchfield') ?: 'email';
        // Mail attributes can be edited or belong to guests: match by email only when the IdP verified it.
        $emailverified = ($claims['email_verified'] ?? null) === true || ($claims['email_verified'] ?? null) === 'true';
        $candidates = match ($field) {
            'username' => [$claims['preferred_username'] ?? null, $claims['upn'] ?? null],
            'idnumber' => [$claims['sub'] ?? null, $claims['oid'] ?? null],
            default => $emailverified ? [$claims['email'] ?? null] : [],
        };

        foreach ($candidates as $value) {
            if (!is_string($value) || trim($value) === '' || ($field === 'email' && !str_contains($value, '@'))) {
                continue;
            }
            $value = trim($value);
            $select = match ($field) {
                'email' => $DB->sql_equal('email', ':value', false),
                'username' => 'username = :value',
                default => 'idnumber = :value',
            };
            $users = $DB->get_records_select('user', $select . ' AND deleted = 0 AND mnethostid = :mnethostid', [
                'value' => $field === 'username' ? \core_text::strtolower($value) : $value,
                'mnethostid' => $CFG->mnet_localhost_id,
            ], '', '*', 0, 2);
            if (count($users) === 1) {
                return reset($users);
            }
        }

        return null;
    }
}
