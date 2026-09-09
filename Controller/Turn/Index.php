<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Controller\Turn;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use MageOS\ClaudeConsumerAgent\Controller\Request\TurnRequest;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Config\Source\Streaming;
use MageOS\ClaudeConsumerAgent\Model\Data\PageContext;
use MageOS\ClaudeConsumerAgent\Model\Limits\Exception\LimitExceeded;
use MageOS\ClaudeConsumerAgent\Model\Session\Binding;

class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const SLOT_RETRY_AFTER = 5;
    private const SESSION_CAP_RETRY_AFTER = 60;

    public function __construct(
        private readonly \MageOS\ClaudeConsumerAgent\Controller\Request\BodyReader $bodyReader,
        private readonly \MageOS\ClaudeConsumerAgent\Controller\Request\FormKeyGuard $formKeyGuard,
        private readonly \Magento\Customer\Model\Session $customerSession,
        private readonly \Magento\Checkout\Model\Session $checkoutSession,
        private readonly \Magento\Quote\Api\CartRepositoryInterface $cartRepository,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Session\Repository $sessionRepository,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Limits\SlotLock $slotLock,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Limits\Counters $counters,
        private readonly \Magento\Framework\Session\SessionManagerInterface $sessionManager,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig $storeConfig,
        private readonly \MageOS\ClaudeConsumerAgent\Controller\Result\EventStreamFactory $eventStreamFactory,
        private readonly \MageOS\ClaudeConsumerAgent\Controller\Result\JsonTurnFactory $jsonTurnFactory,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Limits\BusyEvent $busyEvent,
        private readonly \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress $remoteAddress,
        private readonly \Magento\Framework\App\RequestInterface $request,
        private readonly \Magento\Framework\App\Response\Http $response,
        private readonly \Magento\Framework\Controller\Result\JsonFactory $jsonFactory,
        private readonly \Magento\Framework\Controller\ResultFactory $resultFactory
    ) {
    }

    public function execute(): ResultInterface
    {
        $storeId = (int)$this->storeManager->getStore()->getId();
        if (!$this->storeConfig->isEnabled($storeId)) {
            return $this->noRoute();
        }
        $agentConfig = $this->storeConfig->agent($storeId);
        try {
            $body = $this->bodyReader->read($this->request, $agentConfig);
        } catch (\InvalidArgumentException $exception) {
            return $this->badRequest();
        }
        $customerId = $this->customerSession->getCustomerId();
        $quote = $this->checkoutSession->getQuote();
        if ($quote->getId() === null) {
            $this->cartRepository->save($quote);
            $this->checkoutSession->setQuoteId((int)$quote->getId());
        }
        $quoteId = (int)$quote->getId();
        $page = PageContext::fromArray($body->page);
        $now = new \DateTimeImmutable('now');
        $preContext = new SessionContext((string)($body->sessionId ?? ''), $customerId, $quoteId, $storeId, $page, $now);
        $binding = $this->sessionRepository->bind($body->sessionId, $preContext);
        $context = new SessionContext($binding->sessionId, $customerId, $quoteId, $storeId, $page, $now);
        $ip = (string)($this->remoteAddress->getRemoteAddress() ?: '');
        $slot = $this->slotLock->acquire($storeId);
        $retryAfter = null;
        try {
            $this->counters->bump($binding->sessionId, $ip, $agentConfig);
        } catch (LimitExceeded $exception) {
            $retryAfter = $exception->getRetryAfter();
        }
        $turnsSoFar = $binding->row !== null ? (int)($binding->row['turns'] ?? 0) : 0;
        $overSessionCap = $turnsSoFar >= $agentConfig->turnsPerSession;
        $this->sessionManager->writeClose();
        if ($slot === null || $retryAfter !== null || $overSessionCap) {
            $slot?->release();
            $busyRetryAfter = $retryAfter
                ?? ($overSessionCap ? self::SESSION_CAP_RETRY_AFTER : self::SLOT_RETRY_AFTER);
            return $this->busyResult($body, $busyRetryAfter);
        }
        if ($body->wantsStream && $agentConfig->streaming !== Streaming::OFF) {
            return $this->eventStreamFactory->create()->setTurn($binding, $body->message, $context, $slot);
        }
        return $this->jsonTurnFactory->create()->setTurn($binding, $body->message, $context, $slot);
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return $this->formKeyGuard->isValid($request);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode(403);
        $result->setData(['error' => 'form key']);
        $this->response->setNoCacheHeaders();
        $this->response->setMetadata('NotCacheable', true);
        return new InvalidRequestException($result);
    }

    private function busyResult(TurnRequest $body, int $retryAfter): ResultInterface
    {
        $event = $this->busyEvent->event($retryAfter);
        if ($body->wantsStream) {
            return $this->eventStreamFactory->create()->setBusy($event);
        }
        return $this->jsonTurnFactory->create()->setBusy($event);
    }

    private function badRequest(): ResultInterface
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode(400);
        $result->setData(['error' => 'bad request']);
        $this->response->setNoCacheHeaders();
        $this->response->setMetadata('NotCacheable', true);
        return $result;
    }

    private function noRoute(): ResultInterface
    {
        $forward = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_FORWARD);
        $forward->forward('noroute');
        return $forward;
    }
}
