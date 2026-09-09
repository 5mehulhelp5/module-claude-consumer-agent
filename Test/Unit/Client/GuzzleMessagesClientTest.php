<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Test\Unit\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\ClaudeConsumerAgent\Model\Client\Exception\BadRequest;
use MageOS\ClaudeConsumerAgent\Model\Client\Exception\ServerError;
use MageOS\ClaudeConsumerAgent\Model\Client\Exception\Unauthorized;
use MageOS\ClaudeConsumerAgent\Model\Client\GuzzleMessagesClient;
use MageOS\ClaudeConsumerAgent\Model\Client\Sleeper;
use MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class GuzzleMessagesClientTest extends TestCase
{
    private const SSE_TEXT = "event: message_start\n"
        . "data: {\"type\":\"message_start\",\"message\":{\"usage\":{\"input_tokens\":10,\"output_tokens\":1,"
        . "\"cache_read_input_tokens\":0,\"cache_creation_input_tokens\":0}}}\n\n"
        . "event: content_block_start\n"
        . "data: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n"
        . "event: content_block_delta\n"
        . "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"hi\"}}\n\n"
        . "event: content_block_stop\n"
        . "data: {\"type\":\"content_block_stop\",\"index\":0}\n\n"
        . "event: message_delta\n"
        . "data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":2}}\n\n"
        . "event: message_stop\n"
        . "data: {\"type\":\"message_stop\"}\n\n";

    private function buildStoreConfig(?string $apiKey = 'sk-ant-test'): StoreConfig
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) use ($apiKey) {
                if ($path === 'aiagent/model/api_key') {
                    return $apiKey;
                }
                return null;
            }
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('Test Store');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new StoreConfig($scopeConfig, $storeManager);
    }

    private function buildClientWithHandler(MockHandler $mockHandler, array &$history): Client
    {
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($history));
        return new Client(['handler' => $handlerStack]);
    }

    private function errorBody(string $type, string $message): string
    {
        $json = json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => $message]]);
        return $json !== false ? $json : '{}';
    }

    public function testStreamYieldsEventsFromA200Response(): void
    {
        $history = [];
        $mockHandler = new MockHandler([new Response(200, [], self::SSE_TEXT)]);
        $http = $this->buildClientWithHandler($mockHandler, $history);
        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);
        $sleeper->expects($this->never())->method('sleep');

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig(), $logger, $sleeper);
        $events = iterator_to_array($client->stream(['messages' => []]), false);

        $this->assertCount(1, $history);
        $types = array_map(static fn ($event) => $event->type, $events);
        $this->assertSame(
            [
                'message_start',
                'content_block_start',
                'content_block_delta',
                'content_block_stop',
                'message_delta',
                'message_stop',
            ],
            $types
        );
        $this->assertSame('hi', $events[2]->data['delta']['text']);
    }

    public function testRetryAfterOn429ThenSucceeds(): void
    {
        $history = [];
        $mockHandler = new MockHandler([
            new Response(429, ['retry-after' => '2'], $this->errorBody('rate_limit_error', 'Slow down')),
            new Response(200, [], self::SSE_TEXT),
        ]);
        $http = $this->buildClientWithHandler($mockHandler, $history);
        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);
        $sleeps = [];
        $sleeper->method('sleep')->willReturnCallback(static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        });

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig(), $logger, $sleeper);
        $events = iterator_to_array($client->stream(['messages' => []]), false);

        $this->assertCount(2, $history);
        $this->assertSame([2.0], $sleeps);
        $this->assertSame('message_stop', $events[count($events) - 1]->type);
    }

    public function testRetryAfterAsAnHttpDateFallsBackToBackoff(): void
    {
        $history = [];
        $mockHandler = new MockHandler([
            new Response(
                429,
                ['retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT'],
                $this->errorBody('rate_limit_error', 'Slow down')
            ),
            new Response(200, [], self::SSE_TEXT),
        ]);
        $http = $this->buildClientWithHandler($mockHandler, $history);
        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);
        $sleeps = [];
        $sleeper->method('sleep')->willReturnCallback(static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        });

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig(), $logger, $sleeper);
        $events = iterator_to_array($client->stream(['messages' => []]), false);

        $this->assertCount(2, $history);
        $this->assertSame([0.5], $sleeps);
        $this->assertSame('message_stop', $events[count($events) - 1]->type);
    }

    public function test400MapsToBadRequestWithApiMessage(): void
    {
        $history = [];
        $mockHandler = new MockHandler([
            new Response(400, [], $this->errorBody('invalid_request_error', 'model is required')),
        ]);
        $http = $this->buildClientWithHandler($mockHandler, $history);
        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);
        $sleeper->expects($this->never())->method('sleep');

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig(), $logger, $sleeper);

        try {
            iterator_to_array($client->stream(['messages' => []]), false);
            $this->fail('Expected BadRequest was not thrown');
        } catch (BadRequest $exception) {
            $this->assertSame('model is required', $exception->getMessage());
            $this->assertSame(400, $exception->getStatus());
        }
        $this->assertCount(1, $history);
    }

    public function test500TwiceThenSucceeds(): void
    {
        $history = [];
        $mockHandler = new MockHandler([
            new Response(500, [], $this->errorBody('api_error', 'Server error')),
            new Response(500, [], $this->errorBody('api_error', 'Server error')),
            new Response(200, [], self::SSE_TEXT),
        ]);
        $http = $this->buildClientWithHandler($mockHandler, $history);
        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);
        $sleeps = [];
        $sleeper->method('sleep')->willReturnCallback(static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        });

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig(), $logger, $sleeper);
        $events = iterator_to_array($client->stream(['messages' => []]), false);

        $this->assertCount(3, $history);
        $this->assertSame([0.5, 1.0], $sleeps);
        $this->assertSame('message_stop', $events[count($events) - 1]->type);
    }

    public function test500ThreeTimesThrowsServerError(): void
    {
        $history = [];
        $mockHandler = new MockHandler([
            new Response(500, [], $this->errorBody('api_error', 'Server error')),
            new Response(500, [], $this->errorBody('api_error', 'Server error')),
            new Response(500, [], $this->errorBody('api_error', 'Server error')),
        ]);
        $http = $this->buildClientWithHandler($mockHandler, $history);
        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);
        $sleeps = [];
        $sleeper->method('sleep')->willReturnCallback(static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        });

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig(), $logger, $sleeper);

        $this->expectException(ServerError::class);
        try {
            iterator_to_array($client->stream(['messages' => []]), false);
        } finally {
            $this->assertCount(3, $history);
            $this->assertSame([0.5, 1.0], $sleeps);
        }
    }

    public function testConnectExceptionIsRetried(): void
    {
        $history = [];
        $request = new Request('POST', GuzzleMessagesClient::ENDPOINT);
        $mockHandler = new MockHandler([
            new ConnectException('Connection refused', $request),
            new Response(200, [], self::SSE_TEXT),
        ]);
        $http = $this->buildClientWithHandler($mockHandler, $history);
        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);
        $sleeps = [];
        $sleeper->method('sleep')->willReturnCallback(static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        });

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig(), $logger, $sleeper);
        $events = iterator_to_array($client->stream(['messages' => []]), false);

        $this->assertCount(2, $history);
        $this->assertSame([0.5], $sleeps);
        $this->assertSame('message_stop', $events[count($events) - 1]->type);
    }

    public function testNoRetryAfterFirstBodyByte(): void
    {
        $callCount = 0;
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('eof')->willReturn(false);
        $stream->method('read')->willReturnCallback(static function () use (&$callCount): string {
            $callCount++;
            if ($callCount === 1) {
                return "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"usage\":{}}}\n\n";
            }
            throw new \RuntimeException('connection reset');
        });

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($stream);

        $http = $this->createMock(\GuzzleHttp\ClientInterface::class);
        $http->expects($this->once())->method('request')->willReturn($response);

        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);
        $sleeper->expects($this->never())->method('sleep');

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig(), $logger, $sleeper);

        $events = [];
        try {
            foreach ($client->stream(['messages' => []]) as $event) {
                $events[] = $event;
            }
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $exception) {
            $this->assertSame('connection reset', $exception->getMessage());
        }
        $this->assertCount(1, $events);
        $this->assertSame('message_start', $events[0]->type);
    }

    public function testMissingApiKeyThrowsUnauthorizedWithoutARequest(): void
    {
        $http = $this->createMock(\GuzzleHttp\ClientInterface::class);
        $http->expects($this->never())->method('request');
        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);
        $sleeper->expects($this->never())->method('sleep');

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig(null), $logger, $sleeper);

        $this->expectException(Unauthorized::class);
        iterator_to_array($client->stream(['messages' => []]), false);
    }

    public function testInvalidApiKeyCharactersThrowUnauthorizedWithoutARequestOrTheKeyInTheMessage(): void
    {
        $http = $this->createMock(\GuzzleHttp\ClientInterface::class);
        $http->expects($this->never())->method('request');
        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);
        $sleeper->expects($this->never())->method('sleep');

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig("sk-ant-bad\nkey"), $logger, $sleeper);

        try {
            iterator_to_array($client->stream(['messages' => []]), false);
            $this->fail('Expected Unauthorized was not thrown');
        } catch (Unauthorized $exception) {
            $this->assertSame('The configured API key is not a valid header value.', $exception->getMessage());
        }
    }
}
