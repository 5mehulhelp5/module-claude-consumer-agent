<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Controller\Session;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Data\PageContext;

class Transcript implements HttpGetActionInterface
{
    public function __construct(
        private readonly \Magento\Customer\Model\Session $customerSession,
        private readonly \Magento\Checkout\Model\Session $checkoutSession,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Session\Repository $sessionRepository,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Session\TranscriptRepository $transcriptRepository,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Session\TranscriptView $transcriptView,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Presentation\Registry $presentationRegistry,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig $storeConfig,
        private readonly \Magento\Framework\Session\SessionManagerInterface $sessionManager,
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
        $sessionIdParam = $this->request->getParam('session');
        $sessionId = is_string($sessionIdParam) && $sessionIdParam !== '' ? $sessionIdParam : null;
        $customerId = $this->customerSession->getCustomerId();
        $quoteId = (int)$this->checkoutSession->getQuote()->getId();
        $page = PageContext::fromArray([]);
        $now = new \DateTimeImmutable('now');
        $context = new SessionContext((string)($sessionId ?? ''), $customerId, $quoteId, $storeId, $page, $now);
        $binding = $this->sessionRepository->find($sessionId, $context);
        $this->sessionManager->writeClose();
        $result = $this->jsonFactory->create();
        if ($binding === null) {
            $result->setData(['session' => null, 'messages' => []]);
        } else {
            $rows = $this->transcriptRepository->load($binding->sessionId);
            $messages = $this->transcriptView->render($rows, $binding->state, $this->presentationRegistry);
            $result->setData(['session' => $binding->sessionId, 'messages' => $messages]);
        }
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
