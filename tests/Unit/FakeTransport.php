<?php

declare(strict_types=1);

namespace TamarackDB\Tests\Unit;

use TamarackDB\Exception\ServerException;
use TamarackDB\Exception\TransportException;
use TamarackDB\Http\Response;
use TamarackDB\Http\StreamedResponse;
use TamarackDB\Http\Transport;

/**
 * Answers requests from a queue of canned responses, and records them.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, path: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @var list<Response|\Throwable|CutStream> */
    private array $responses = [];

    public function push(Response|\Throwable|CutStream ...$responses): self
    {
        array_push($this->responses, ...$responses);

        return $this;
    }

    public function send(string $method, string $path, array $headers = [], ?string $body = null): Response
    {
        $this->requests[] = ['method' => $method, 'path' => $path, 'headers' => $headers, 'body' => $body];
        $response = $this->next();
        if (!$response instanceof Response) {
            throw new \LogicException('send() got a canned stream');
        }

        return $response;
    }

    public function stream(string $method, string $path, array $headers = [], ?string $body = null): StreamedResponse
    {
        $this->requests[] = ['method' => $method, 'path' => $path, 'headers' => $headers, 'body' => $body];
        $response = $this->next();
        if ($response instanceof CutStream) {
            return new StreamedResponse($response->headers, self::cut($response->lines));
        }
        if ($response->statusCode !== 200) {
            throw ServerException::fromResponse($response);
        }

        return new StreamedResponse($response->headers, self::lines($response->body));
    }

    /**
     * The JSON body of the request at $index, decoded.
     *
     * @return array<string, mixed>
     */
    public function body(int $index): array
    {
        $body = json_decode($this->requests[$index]['body'] ?? 'null', true, 64, JSON_THROW_ON_ERROR);
        if (!\is_array($body)) {
            throw new \LogicException('request has no JSON object body');
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * @param list<string> $lines
     *
     * @return \Generator<int, string>
     */
    private static function cut(array $lines): \Generator
    {
        yield from $lines;

        throw new TransportException('connection dropped');
    }

    /**
     * @return \Generator<int, string>
     */
    private static function lines(string $body): \Generator
    {
        foreach (explode("\n", $body) as $line) {
            if ($line !== '') {
                yield $line;
            }
        }
    }

    private function next(): Response|CutStream
    {
        $response = array_shift($this->responses);
        if ($response === null) {
            throw new \LogicException('no canned response left');
        }
        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }
}
