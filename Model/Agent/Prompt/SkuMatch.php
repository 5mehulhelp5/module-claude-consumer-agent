<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Prompt;

use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;

/**
 * Names the product behind a SKU the customer typed. The forced product lookup that a SKU
 * used to trigger is ignored by models that refuse a forced tool, and the model cannot see SKUs.
 */
class SkuMatch
{
    public const PREFIX = '[SKU match: ';

    private const LABEL_MAX_LENGTH = 120;

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Grounding\SkuCandidates $candidates,
        private readonly \MageOS\AiShoppingAssistant\Api\Backend\SkuMatcherInterface $skuMatcher,
        private readonly \MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface $backend,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer $sanitizer,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
    }

    public function text(SessionContext $context, SessionState $state, string $message): ?string
    {
        $candidates = $this->candidates->fromText($message);
        if ($candidates === []) {
            return null;
        }
        try {
            $sku = $this->skuMatcher->firstExisting($candidates, $context->storeId);
            $details = $sku !== null ? $this->backend->getProductDetails($context, $sku) : null;
        } catch (\Throwable $exception) {
            $this->logger->warning(sprintf(
                'sku match failed session=%s error=%s',
                substr(sha1($context->sessionId), 0, 12),
                $exception->getMessage()
            ));
            return null;
        }
        if ($sku === null || $details === null) {
            return null;
        }
        $record = $details->toArray();
        $state->rememberProducts([$record]);
        $title = $this->sanitizer->label((string)($record['title'] ?? ''), self::LABEL_MAX_LENGTH);
        return self::PREFIX . 'the customer typed SKU ' . $this->sanitizer->label($sku, self::LABEL_MAX_LENGTH)
            . ', the SKU of ' . ($title !== '' ? $title : 'a product') . ' (product_id '
            . $this->sanitizer->label((string)$record['product_id'], self::LABEL_MAX_LENGTH)
            . '). Treat it as that product.]';
    }
}
