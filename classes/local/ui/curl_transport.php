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

namespace webservice_mcp\local\ui;

use webservice_mcp\local\files\transfer_exception;

/**
 * Loopback transport: raw curl pinned to the configured loopback IP, TLS verified, no proxy, no redirects.
 *
 * Moodle's \curl class is deliberately not used: its security helper blocks loopback addresses, and the request
 * only ever targets this site's own origin (checked by session_bridge before every hop).
 *
 * @package     webservice_mcp
 * @copyright   2026 Moodle MCP contributors
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class curl_transport implements http_transport {
    /** Request timeout in seconds. */
    private const TIMEOUT = 60;

    /** @var string IP address the site's host name is pinned to. */
    private string $loopbackip;

    /**
     * Constructor.
     *
     * @param string $loopbackip IP the site host resolves to for these requests.
     */
    public function __construct(string $loopbackip) {
        $this->loopbackip = $loopbackip;
    }

    /**
     * Send a request without following redirects.
     *
     * @param string $method HTTP method.
     * @param string $url Absolute URL (already policy-checked).
     * @param array $headers Request header lines.
     * @param string|array|null $body Request body.
     * @param int $maxbytes Largest body to accept.
     * @return array
     */
    public function request(string $method, string $url, array $headers, string|array|null $body, int $maxbytes): array {
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ip = str_contains($this->loopbackip, ':') ? '[' . trim($this->loopbackip, '[]') . ']' : $this->loopbackip;

        $responseheaders = [];
        $responsebody = '';
        $toolarge = false;
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RESOLVE => [$parts['host'] . ':' . $port . ':' . $ip],
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseheaders): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseheaders[strtolower(trim($name))][] = trim($value);
                } else if (preg_match('#^HTTP/#', $line)) {
                    // A new status line (e.g. after 100 Continue) starts a new header block.
                    $responseheaders = [];
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responsebody, &$toolarge, $maxbytes): int {
                if (strlen($responsebody) + strlen($chunk) > $maxbytes) {
                    $toolarge = true;
                    return 0;
                }
                $responsebody .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($method === 'POST' || $body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body ?? '');
        }

        $ok = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        if ($toolarge) {
            throw new transfer_exception(413, 'uibridgetoolarge', 'The page is larger than the ' . display_size($maxbytes)
                . ' limit for browsing.');
        }
        if ($ok === false) {
            throw new transfer_exception(502, 'uibridgehttp', 'Could not reach this Moodle site over loopback: ' . $error);
        }

        return ['status' => $status, 'headers' => $responseheaders, 'body' => $responsebody];
    }
}
