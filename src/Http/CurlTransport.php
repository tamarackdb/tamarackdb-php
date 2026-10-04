<?php

declare(strict_types=1);

namespace TamarackDB\Http;

use TamarackDB\Exception\InvalidArgumentException;
use TamarackDB\Exception\ServerException;
use TamarackDB\Exception\TimeoutException;
use TamarackDB\Exception\TransportException;

/**
 * Transport built on ext-curl, over TCP or a unix socket. Connections are
 * kept open and reused between requests.
 */
final class CurlTransport implements Transport
{
    private readonly string $baseUrl;

    private readonly \CurlShareHandle $share;

    /**
     * @param string $baseUrl such as "http://127.0.0.1:8085"; with a unix
     *                        socket, only its path part is used
     * @param string|null $unixSocket path of the server's unix socket
     * @param string|null $token Bearer token, when the server has
     *                           enableAuth on
     * @param float $timeout seconds to wait for a response. For a
     *                       streamed read, how long the response may go
     *                       without receiving any byte.
     * @param float $connectTimeout seconds to wait for the connection
     */
    public function __construct(
        string $baseUrl = 'http://127.0.0.1:8085',
        private readonly ?string $unixSocket = null,
        private readonly ?string $token = null,
        private readonly float $timeout = 60.0,
        private readonly float $connectTimeout = 5.0,
    ) {
        if ($unixSocket === '') {
            throw new InvalidArgumentException('the unix socket path must not be empty');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->share = curl_share_init();
        curl_share_setopt($this->share, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
        curl_share_setopt($this->share, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
    }

    public function send(
        string $method,
        string $path,
        array $headers = [],
        ?string $body = null,
    ): Response {
        $responseHeaders = [];
        $ch = $this->handle($method, $path, $headers, $body, $responseHeaders);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, (int) ceil($this->timeout * 1000));

        $result = curl_exec($ch);
        if (!\is_string($result)) {
            throw $this->curlError(curl_errno($ch), curl_error($ch), $method, $path);
        }

        return new Response(curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $responseHeaders, $result);
    }

    public function stream(
        string $method,
        string $path,
        array $headers = [],
        ?string $body = null,
        bool $drainOnAbort = false,
        ?\Closure $onHeaders = null,
    ): \Generator {
        $responseHeaders = [];
        $ch = $this->handle($method, $path, $headers, $body, $responseHeaders);
        $buffer = '';
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function (\CurlHandle $ch, string $chunk) use (&$buffer): int {
            $buffer .= $chunk;

            return \strlen($chunk);
        });
        // No limit on the whole transfer, which grows with the page size:
        // only a response that stalls is given up on.
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1);
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, max(1, (int) ceil($this->timeout)));

        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);
        $finished = false;
        try {
            do {
                $running = $this->step($mh);
                if ($buffer !== '' && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200) {
                    // The body has started, so every header has arrived.
                    if ($onHeaders !== null) {
                        $onHeaders($responseHeaders);
                        $onHeaders = null;
                    }
                    $lines = explode("\n", $buffer);
                    $buffer = array_pop($lines);
                    foreach ($lines as $line) {
                        $line = rtrim($line, "\r");
                        if ($line !== '') {
                            yield $line;
                        }
                    }
                }
            } while ($running);
            $finished = true;

            $info = curl_multi_info_read($mh);
            $errno = \is_array($info) && \is_int($info['result'] ?? null) ? $info['result'] : CURLE_OK;
            if ($errno !== CURLE_OK) {
                throw $this->curlError($errno, curl_strerror($errno) ?? '', $method, $path);
            }
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($status !== 200) {
                throw ServerException::fromResponse(new Response($status, $responseHeaders, $buffer));
            }
            if ($onHeaders !== null) {
                $onHeaders($responseHeaders);
            }
            $line = rtrim($buffer, "\r");
            if ($line !== '') {
                yield $line;
            }
        } finally {
            if (!$finished && $drainOnAbort) {
                try {
                    do {
                        $buffer = '';
                        $running = $this->step($mh);
                    } while ($running);
                } catch (TransportException) {
                    // The connection is gone: nothing left to drain.
                }
            }
            curl_multi_remove_handle($mh, $ch);
        }
    }

    /**
     * Runs the transfer as far as it can go without blocking for long, and
     * returns whether it's still running.
     */
    private function step(\CurlMultiHandle $mh): bool
    {
        $status = curl_multi_exec($mh, $running);
        if ($status !== CURLM_OK) {
            throw new TransportException('curl: ' . curl_multi_strerror($status));
        }
        if ($running > 0 && curl_multi_select($mh, 1.0) === -1) {
            usleep(1000);
        }

        return $running > 0;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $responseHeaders filled in as the
     *                                               response headers arrive
     */
    private function handle(string $method, string $path, array $headers, ?string $body, array &$responseHeaders): \CurlHandle
    {
        $url = $this->unixSocket === null
            ? $this->baseUrl . $path
            : 'http://localhost' . (parse_url($this->baseUrl, PHP_URL_PATH) ?? '') . $path;

        if ($method === '') {
            throw new InvalidArgumentException('the HTTP method must not be empty');
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SHARE, $this->share);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, (int) ceil($this->connectTimeout * 1000));
        if ($this->unixSocket !== null && $this->unixSocket !== '') {
            curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, $this->unixSocket);
        }
        if ($body !== null || $method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '');
        }

        // An empty Expect header saves a round trip on large bodies.
        $lines = ['Expect:'];
        if ($this->token !== null) {
            $lines[] = 'Authorization: Bearer ' . $this->token;
        }
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $lines);

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function (\CurlHandle $ch, string $line) use (&$responseHeaders): int {
            if (str_starts_with($line, 'HTTP/')) {
                $responseHeaders = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }

            return \strlen($line);
        });

        return $ch;
    }

    private function curlError(int $errno, string $error, string $method, string $path): TransportException
    {
        $message = \sprintf('%s %s failed: %s', $method, $path, $error !== '' ? $error : 'curl error ' . $errno);

        return $errno === CURLE_OPERATION_TIMEDOUT
            ? new TimeoutException($message, $errno)
            : new TransportException($message, $errno);
    }
}
