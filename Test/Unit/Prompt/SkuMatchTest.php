<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Prompt;

use MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer;
use MageOS\AiShoppingAssistant\Model\Agent\Grounding\SkuCandidates;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\SkuMatch;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use MageOS\AiShoppingAssistant\Model\Data\ProductDetails;
use MageOS\AiShoppingAssistant\Model\Eval\InMemorySkuMatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SkuMatchTest extends TestCase
{
    private function context(): SessionContext
    {
        return new SessionContext('sess-1', null, 1, 1, PageContext::fromArray([]), new \DateTimeImmutable('now'));
    }

    private function skuMatch(StorefrontBackendInterface $backend, array $skus): SkuMatch
    {
        return new SkuMatch(
            new SkuCandidates(),
            new InMemorySkuMatcher($skus),
            $backend,
            new Sanitizer(),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function backendWith(string $sku, ?ProductDetails $details): StorefrontBackendInterface
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->method('getProductDetails')->willReturnCallback(
            static fn (SessionContext $ctx, string $id): ?ProductDetails => $id === $sku ? $details : null
        );
        return $backend;
    }

    public function testATypedSkuNamesItsProductAndCountsAsSeen(): void
    {
        $details = ProductDetails::fromArray(['product_id' => '33110', 'title' => 'Ricchi Poveri Lamp', 'price' => 2142.0]);
        $state = new SessionState();
        $note = $this->skuMatch($this->backendWith('IM1914700-IMA', $details), ['IM1914700-IMA'])
            ->text($this->context(), $state, 'Do you have a delivery time for SKU: IM1914700-IMA?');

        $this->assertStringStartsWith(SkuMatch::PREFIX, (string)$note);
        $this->assertStringContainsString('IM1914700-IMA', (string)$note);
        $this->assertStringContainsString('Ricchi Poveri Lamp (product_id 33110)', (string)$note);
        $this->assertTrue($state->hasSeen('33110'));
    }

    public function testAMessageWithoutAKnownSkuAddsNoNote(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->expects($this->never())->method('getProductDetails');
        $state = new SessionState();

        $this->assertNull($this->skuMatch($backend, ['IM1914700-IMA'])->text($this->context(), $state, 'Is the 2 seater in stock?'));
        $this->assertNull($this->skuMatch($backend, [])->text($this->context(), $state, 'sofas'));
    }

    public function testASkuWithoutAVisibleProductAddsNoNote(): void
    {
        $state = new SessionState();
        $note = $this->skuMatch($this->backendWith('IM1914700-IMA', null), ['IM1914700-IMA'])
            ->text($this->context(), $state, 'IM1914700-IMA');

        $this->assertNull($note);
        $this->assertSame([], $state->seenProducts);
    }

    public function testALookupFailureAddsNoNote(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->method('getProductDetails')->willThrowException(new \RuntimeException('down'));

        $this->assertNull(
            $this->skuMatch($backend, ['IM1914700-IMA'])->text($this->context(), new SessionState(), 'IM1914700-IMA')
        );
    }
}
