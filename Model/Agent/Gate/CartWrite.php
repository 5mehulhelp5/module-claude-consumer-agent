<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Gate;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\SalesRule\Api\Exception\CodeRequestLimitException;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\Event;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\ToolOutcome;

class CartWrite
{
    private const LOCK_TIMEOUT = 5;

    public const COUPON_GATE = 'coupon';

    public const COUPON_MAX_LENGTH = 64;

    public const COUPON_REJECTION_CAP = 5;

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface $backend,
        private readonly \Magento\Framework\Lock\LockManagerInterface $lockManager,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Gate\Options $options,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Gate\Provenance $provenance,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Serializer $serializer,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly \MageOS\AiShoppingAssistant\Api\Cart\CouponApplierInterface $couponApplier
    ) {
    }

    public function lockName(string $sessionId): string
    {
        return 'aiagent:cart:' . substr($sessionId, 0, 32);
    }

    public function add(
        SessionContext $ctx,
        SessionState $state,
        AgentConfig $config,
        string $productId,
        int $quantity,
        array $options = []
    ): ToolOutcome {
        $held = $this->provenance->check($state, $productId) ?? $this->options->check($state, $productId, $options);
        if ($held !== null) {
            return $held;
        }
        $requested = max(1, $quantity);
        $cap = $config->maxQuantityPerItem;
        $lockName = $this->lockName($ctx->sessionId);
        if (!$this->lockManager->lock($lockName, self::LOCK_TIMEOUT)) {
            $this->logger->warning('cart lock timed out session=' . $ctx->sessionId . ' lock=' . $lockName);
            return ToolOutcome::error('The cart is busy; try again in a moment.');
        }
        try {
            $cart = $this->backend->getCart($ctx);
            $line = $cart->find($productId);
            if ($line === null && count($cart->getItems()) >= $config->maxCartLines) {
                return ToolOutcome::error('The cart is full.');
            }
            $existingQuantity = $line !== null ? $line->getQuantity() : 0;
            $allowed = min($requested, max(0, $cap - $existingQuantity));
            if ($allowed <= 0) {
                return ToolOutcome::error('This item is already at the per-item limit of ' . $cap . '.');
            }
            $cart = $this->backend->addToCart($ctx, $productId, $allowed, $options);
        } finally {
            $this->lockManager->unlock($lockName);
        }
        $capped = $allowed < $requested ? ' (capped at the per-item limit of ' . $cap . ')' : '';
        return ToolOutcome::ok(
            'Added ' . $productId . ' x' . $allowed . $capped . '. Cart now has '
            . $this->serializer->cartSummary($cart) . '.',
            [Event::cartUpdate($this->serializer->cart($cart))]
        );
    }

    public function update(
        SessionContext $ctx,
        SessionState $state,
        AgentConfig $config,
        string $productId,
        int $quantity
    ): ToolOutcome {
        $requested = max(1, $quantity);
        $cap = $config->maxQuantityPerItem;
        $applied = min($requested, $cap);
        $lockName = $this->lockName($ctx->sessionId);
        if (!$this->lockManager->lock($lockName, self::LOCK_TIMEOUT)) {
            $this->logger->warning('cart lock timed out session=' . $ctx->sessionId . ' lock=' . $lockName);
            return ToolOutcome::error('The cart is busy; try again in a moment.');
        }
        try {
            $held = $this->checkProvenanceOrCartLine($ctx, $state, $productId);
            if ($held !== null) {
                return $held;
            }
            $cart = $this->backend->updateCartItem($ctx, $productId, $applied);
        } finally {
            $this->lockManager->unlock($lockName);
        }
        $capped = $applied < $requested ? ' (capped at the per-item limit of ' . $cap . ')' : '';
        return ToolOutcome::ok(
            'Updated quantity' . $capped . '. Cart now has ' . $this->serializer->cartSummary($cart) . '.',
            [Event::cartUpdate($this->serializer->cart($cart))]
        );
    }

    public function remove(SessionContext $ctx, SessionState $state, string $productId): ToolOutcome
    {
        $lockName = $this->lockName($ctx->sessionId);
        if (!$this->lockManager->lock($lockName, self::LOCK_TIMEOUT)) {
            $this->logger->warning('cart lock timed out session=' . $ctx->sessionId . ' lock=' . $lockName);
            return ToolOutcome::error('The cart is busy; try again in a moment.');
        }
        try {
            $held = $this->checkProvenanceOrCartLine($ctx, $state, $productId);
            if ($held !== null) {
                return $held;
            }
            $cart = $this->backend->removeFromCart($ctx, $productId);
        } finally {
            $this->lockManager->unlock($lockName);
        }
        return ToolOutcome::ok(
            'Removed. Cart now has ' . $this->serializer->cartSummary($cart) . '.',
            [Event::cartUpdate($this->serializer->cart($cart))]
        );
    }

    public function applyCoupon(SessionContext $ctx, SessionState $state, string $code): ToolOutcome
    {
        $code = trim($code);
        if ($code === ''
            || strlen($code) > self::COUPON_MAX_LENGTH
            || preg_match('/^[A-Za-z0-9_-]+$/', $code) !== 1
        ) {
            return ToolOutcome::error(
                'Nothing was applied: a discount code is letters, digits, hyphens and underscores, at most '
                . self::COUPON_MAX_LENGTH . ' characters. Ask the customer to type the code exactly as they have it.'
            );
        }
        if (!$this->customerTyped($state, $code)) {
            return ToolOutcome::held(
                self::COUPON_GATE,
                'Nothing was applied: the customer has not typed this code in this conversation. Apply only '
                . 'a code the customer wrote; ask them for their code, and do not suggest one.'
            );
        }
        if ($state->couponRejections >= self::COUPON_REJECTION_CAP) {
            return ToolOutcome::error(
                'Nothing was applied: the store turned down ' . self::COUPON_REJECTION_CAP . ' codes in this '
                . 'conversation, so no more are tried here. Tell the customer they can enter the code at checkout.'
            );
        }
        $lockName = $this->lockName($ctx->sessionId);
        if (!$this->lockManager->lock($lockName, self::LOCK_TIMEOUT)) {
            $this->logger->warning('cart lock timed out session=' . $ctx->sessionId . ' lock=' . $lockName);
            return ToolOutcome::error('The cart is busy; try again in a moment.');
        }
        try {
            $cart = $this->backend->getCart($ctx);
            if ($cart->isEmpty()) {
                return ToolOutcome::error(
                    'Nothing was applied: the cart is empty. Tell the customer the code can be applied once '
                    . 'an item is in the cart.'
                );
            }
            $previousCode = $cart->getCouponCode();
            try {
                $this->couponApplier->apply($ctx, $code);
            } catch (NoSuchEntityException) {
                $state->couponRejections++;
                $this->restoreCoupon($ctx, $previousCode, $code);
                return ToolOutcome::error('The store did not accept this code.');
            } catch (CodeRequestLimitException $exception) {
                $this->logger->warning('coupon request limit session=' . $ctx->sessionId . ' error=' . $exception->getMessage());
                return ToolOutcome::error(
                    'Nothing was applied: the store is limiting code attempts right now. Tell the customer '
                    . 'they can enter the code at checkout.'
                );
            } catch (LocalizedException $exception) {
                $this->logger->warning('coupon apply failed session=' . $ctx->sessionId . ' error=' . $exception->getMessage());
                return ToolOutcome::error(
                    'Nothing was applied: the code could not be applied right now. Tell the customer they can '
                    . 'enter it at checkout.'
                );
            }
            $cart = $this->backend->getCart($ctx);
        } finally {
            $this->lockManager->unlock($lockName);
        }
        return ToolOutcome::ok(
            'Applied code ' . $code . '. Cart now has ' . $this->serializer->cartSummary($cart)
            . '. Shipping and tax are added at checkout.',
            [Event::cartUpdate($this->serializer->cart($cart))]
        );
    }

    private function customerTyped(SessionState $state, string $code): bool
    {
        $pattern = '/(?<![A-Za-z0-9_-])' . preg_quote($code, '/') . '(?![A-Za-z0-9_-])/i';
        foreach ($state->recentCustomerText as $text) {
            if (preg_match($pattern, (string)$text) === 1) {
                return true;
            }
        }
        return false;
    }

    private function restoreCoupon(SessionContext $ctx, string $previousCode, string $rejectedCode): void
    {
        if ($previousCode === '' || strcasecmp($previousCode, $rejectedCode) === 0) {
            return;
        }
        try {
            $this->couponApplier->apply($ctx, $previousCode);
        } catch (LocalizedException $exception) {
            $this->logger->warning('coupon restore failed session=' . $ctx->sessionId . ' error=' . $exception->getMessage());
        }
    }

    private function checkProvenanceOrCartLine(SessionContext $ctx, SessionState $state, string $productId): ?ToolOutcome
    {
        $held = $this->provenance->check($state, $productId);
        if ($held === null) {
            return null;
        }
        $cart = $this->backend->getCart($ctx);
        foreach ($cart->getItems() as $item) {
            if ($item->getProductId() === $productId) {
                return null;
            }
        }
        $this->logger->warning('cart version conflict session=' . $ctx->sessionId . ' product_id=' . $productId);
        return $held;
    }
}
