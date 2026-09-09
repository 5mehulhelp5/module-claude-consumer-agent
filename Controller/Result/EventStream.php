<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Controller\Result;

use Magento\Framework\App\Response\HttpInterface as HttpResponseInterface;
use Magento\Framework\Controller\AbstractResult;
use MageOS\ClaudeConsumerAgent\Model\Agent\Event;
use MageOS\ClaudeConsumerAgent\Model\Agent\Event\SseFrame;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Limits\SlotHandle;
use MageOS\ClaudeConsumerAgent\Model\Session\Binding;

/**
 * Stays non-final so a unit test can override write() and drainOutputBuffers(), the two
 * points that touch the real output stream, and observe frames without fighting PHPUnit's
 * own output buffer. Per-request state arrives through setTurn()/setBusy() rather than the
 * constructor, because Magento's compiled factory drops null constructor arguments.
 */
class EventStream extends AbstractResult
{
    private ?Binding $binding = null;

    private ?string $message = null;

    private ?SessionContext $context = null;

    private ?SlotHandle $slot = null;

    private ?Event $busyEvent = null;

    public function __construct(
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Turn\Orchestrator $orchestrator,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig $storeConfig,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
    }

    public function setTurn(Binding $binding, string $message, SessionContext $context, ?SlotHandle $slot): self
    {
        $this->binding = $binding;
        $this->message = $message;
        $this->context = $context;
        $this->slot = $slot;
        return $this;
    }

    public function setBusy(Event $busyEvent): self
    {
        $this->busyEvent = $busyEvent;
        return $this;
    }

    protected function render(HttpResponseInterface $response)
    {
        if ($this->context === null && $this->busyEvent === null) {
            throw new \LogicException('EventStream requires setTurn() or setBusy() to be called before render().');
        }
        if (!headers_sent()) {
            ini_set('zlib.output_compression', '0');
        }
        if ($this->compressionIsOn()) {
            return $this->renderJsonFallback($response);
        }
        $this->sendStreamHeaders($response);
        $this->drainOutputBuffers();
        $this->write(": open\n\n");
        if ($this->busyEvent !== null) {
            $this->write(SseFrame::encode($this->busyEvent));
            $this->slot?->release();
            $response->setBody('');
            return $this;
        }
        $this->runTurn($response);
        return $this;
    }

    private function runTurn(HttpResponseInterface $response): void
    {
        $agentConfig = $this->storeConfig->agent($this->context->storeId);
        $lastBeat = time();
        $onWaiting = function () use (&$lastBeat, $agentConfig): void {
            $now = time();
            if ($now - $lastBeat < $agentConfig->heartbeatSeconds) {
                return;
            }
            $this->write(": ping\n\n");
            $lastBeat = $now;
        };
        try {
            foreach ($this->orchestrator->streamTurn($this->binding, $this->message, $this->context, $onWaiting) as $event) {
                $this->write(SseFrame::encode($event));
                if (!connection_aborted()) {
                    continue;
                }
                $this->orchestrator->interrupt($this->binding);
                break;
            }
        } catch (\Throwable $exception) {
            $this->logError($exception);
            $this->write(SseFrame::encode(Event::error('Something went wrong. Please try again.')));
        } finally {
            $this->slot?->release();
        }
        $response->setBody('');
    }

    private function renderJsonFallback(HttpResponseInterface $response): self
    {
        $events = $this->busyEvent !== null ? [$this->busyEvent] : $this->collectEvents();
        $response->setHeader('X-AiAgent-Stream', 'unavailable', true);
        $response->setNoCacheHeaders();
        $response->setMetadata('NotCacheable', true);
        $body = json_encode(
            ['events' => array_map(static fn (Event $event): array => $event->toArray(), $events)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $response->representJson($body !== false ? $body : '{"events":[]}');
        return $this;
    }

    private function collectEvents(): array
    {
        $events = [];
        try {
            foreach ($this->orchestrator->streamTurn($this->binding, $this->message, $this->context) as $event) {
                $events[] = $event;
            }
        } catch (\Throwable $exception) {
            $this->logError($exception);
            $events[] = Event::error('Something went wrong. Please try again.');
        } finally {
            $this->slot?->release();
        }
        return $events;
    }

    private function logError(\Throwable $exception): void
    {
        $masked = preg_replace('/sk-ant-[A-Za-z0-9_-]+/', 'sk-ant-***', $exception->getMessage());
        $message = $masked !== null ? $masked : $exception->getMessage();
        $this->logger->error(get_class($exception) . ': ' . $message, ['exception' => $exception]);
    }

    private function sendStreamHeaders(HttpResponseInterface $response): void
    {
        $response->setHeader('Content-Type', 'text/event-stream; charset=utf-8', true);
        $response->setHeader('X-Accel-Buffering', 'no', true);
        $response->setHeader('Connection', 'keep-alive', true);
        $response->setNoCacheHeaders();
        $response->setMetadata('NotCacheable', true);
        $response->sendHeaders();
    }

    protected function compressionIsOn(): bool
    {
        return filter_var(ini_get('zlib.output_compression'), FILTER_VALIDATE_BOOLEAN);
    }

    protected function drainOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
            if (!ob_end_flush()) {
                break;
            }
        }
    }

    protected function write(string $frame): void
    {
        echo $frame;
        flush();
    }
}
