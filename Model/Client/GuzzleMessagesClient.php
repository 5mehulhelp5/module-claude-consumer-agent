<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Client;

use GuzzleHttp\Exception\ConnectException;
use MageOS\ClaudeConsumerAgent\Api\Client\MessagesClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class GuzzleMessagesClient implements MessagesClientInterface
{
    public const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    public const API_VERSION = '2023-06-01';
    public const MAX_RETRIES = 2;
    private const READ_CHUNK_BYTES = 256;
    private const TIMEOUT_MARGIN_SECONDS = 5;
    private const HEARTBEAT_SLICE_SECONDS = 1;

    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 529];
    private const MAX_RETRY_AFTER_SECONDS = 30.0;

    public function __construct(
        private readonly \GuzzleHttp\ClientInterface $http,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig $config,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Client\Sleeper $sleeper
    ) {
    }

    public function stream(array $request, ?callable $onWaiting = null, ?int $storeId = null): \Generator
    {
        $storeId ??= 0;
        $apiKey = $this->config->apiKey($storeId);
        if ($apiKey === '') {
            throw new Exception\Unauthorized('No API key configured for store ' . $storeId);
        }
        if (preg_match('/^[\x21-\x7e]+$/', $apiKey) !== 1) {
            throw new Exception\Unauthorized('The configured API key is not a valid header value.');
        }
        $agentConfig = $this->config->agent($storeId);
        $body = $request;
        $body['stream'] = true;
        $encodedBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $encodedBody = $encodedBody !== false ? $encodedBody : '{}';
        if ($agentConfig->debugLog) {
            $this->logger->debug('aiagent request body', ['body' => $encodedBody]);
        }

        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new Exception\Transport(
                'The model call streams over the PHP stream handler, which needs allow_url_fopen enabled in php.ini.'
            );
        }
        $response = $this->send($encodedBody, $agentConfig->connectTimeout, $agentConfig->requestTimeout, $apiKey);

        $lineReader = new SseLineReader();
        $lastEventType = null;
        foreach ($lineReader->read($this->readChunks($response->getBody(), $onWaiting)) as $rawEvent) {
            if ($agentConfig->debugLog) {
                $this->logger->debug('aiagent frame', ['type' => $rawEvent->type, 'data' => $rawEvent->data]);
            }
            $lastEventType = $rawEvent->type;
            yield $rawEvent;
        }
        if ($lastEventType !== 'message_stop') {
            throw new Exception\Transport('The model stream ended before message_stop.');
        }
    }

    private function send(string $encodedBody, int $connectTimeout, int $requestTimeout, string $apiKey): ResponseInterface
    {
        $attempt = 0;
        while (true) {
            try {
                $response = $this->http->request('POST', self::ENDPOINT, [
                    'headers' => [
                        'x-api-key' => $apiKey,
                        'anthropic-version' => self::API_VERSION,
                        'content-type' => 'application/json',
                        'accept' => 'text/event-stream',
                    ],
                    'body' => $encodedBody,
                    'stream' => true,
                    'connect_timeout' => $connectTimeout,
                    'read_timeout' => $requestTimeout,
                    'timeout' => $requestTimeout + self::TIMEOUT_MARGIN_SECONDS,
                    'http_errors' => false,
                ]);
            } catch (ConnectException $exception) {
                if ($attempt < self::MAX_RETRIES) {
                    $this->sleeper->sleep($this->backoff($attempt));
                    $attempt++;
                    continue;
                }
                throw new Exception\Transport($exception->getMessage(), null, null, $exception);
            }

            $status = $response->getStatusCode();
            if ($status === 200) {
                return $response;
            }

            $errorBody = json_decode($response->getBody()->getContents(), true);
            $errorBody = is_array($errorBody) ? $errorBody : [];

            if (in_array($status, self::RETRYABLE_STATUSES, true) && $attempt < self::MAX_RETRIES) {
                $wait = $this->retryAfterFromHeader($response) ?? $this->backoff($attempt);
                $this->sleeper->sleep(min($wait, self::MAX_RETRY_AFTER_SECONDS));
                $attempt++;
                continue;
            }

            throw $this->mapStatus($status, $errorBody, $response);
        }
    }

    private function readChunks(StreamInterface $stream, ?callable $onWaiting): \Generator
    {
        $resource = $stream->detach();
        if (!is_resource($resource)) {
            while (!$stream->eof()) {
                if ($onWaiting !== null) {
                    $onWaiting();
                }
                $chunk = $stream->read(self::READ_CHUNK_BYTES);
                if ($chunk !== '') {
                    yield $chunk;
                }
            }
            return;
        }

        try {
            yield from $this->readChunksFromResource($resource, $onWaiting);
        } finally {
            fclose($resource);
        }
    }

    private function readChunksFromResource($resource, ?callable $onWaiting): \Generator
    {
        while (!feof($resource)) {
            if ($onWaiting !== null) {
                $onWaiting();
            }
            $read = [$resource];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, self::HEARTBEAT_SLICE_SECONDS);
            if ($ready === false || $ready === 0) {
                continue;
            }
            $chunk = fread($resource, self::READ_CHUNK_BYTES);
            if ($chunk === false) {
                return;
            }
            if ($chunk !== '') {
                yield $chunk;
            }
        }
    }

    private function retryAfterFromHeader(ResponseInterface $response): ?float
    {
        $values = $response->getHeader('retry-after');
        if ($values === [] || !is_numeric($values[0])) {
            return null;
        }
        return (float)$values[0];
    }

    private function backoff(int $attempt): float
    {
        return 0.5 * (2 ** $attempt);
    }

    private function mapStatus(int $status, array $errorBody, ResponseInterface $response): Exception\ApiException
    {
        $message = $errorBody['error']['message'] ?? ('HTTP ' . $status);
        $apiType = $errorBody['error']['type'] ?? null;
        if ($status === 400) {
            return new Exception\BadRequest($message, $status, $apiType);
        }
        if ($status === 401) {
            return new Exception\Unauthorized($message, $status, $apiType);
        }
        if ($status === 404) {
            return new Exception\NotFound($message, $status, $apiType);
        }
        if ($status === 429) {
            $retryAfter = $this->retryAfterFromHeader($response);
            return new Exception\RateLimited($message, $retryAfter !== null ? (int)$retryAfter : 0, $status, $apiType);
        }
        if ($status >= 500) {
            return new Exception\ServerError($message, $status, $apiType);
        }
        return new Exception\ApiException($message, $status, $apiType);
    }
}
