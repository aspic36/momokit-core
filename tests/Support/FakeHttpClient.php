<?php

declare(strict_types=1);

namespace MomoKit\Core\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class FakeHttpClient implements ClientInterface
{
    /** @var array<int, RequestInterface> */
    public array $requests = [];

    /** @var array<int, ResponseInterface> */
    private array $responses = [];

    public function queueResponse(ResponseInterface $response): void
    {
        $this->responses[] = $response;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $response = array_shift($this->responses);

        if ($response === null) {
            throw new RuntimeException('No queued HTTP response left for '.$request->getMethod().' '.$request->getUri());
        }

        return $response;
    }
}
