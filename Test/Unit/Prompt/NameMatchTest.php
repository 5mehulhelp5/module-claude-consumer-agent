<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Prompt;

use MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\NameMatch;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use MageOS\AiShoppingAssistant\Model\Data\Product;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NameMatchTest extends TestCase
{
    private function context(): SessionContext
    {
        return new SessionContext('sess-1', null, 1, 1, PageContext::fromArray([]), new \DateTimeImmutable('now'));
    }

    private function product(string $id, string $title): Product
    {
        return Product::fromArray(['product_id' => $id, 'title' => $title, 'price' => 100.0, 'in_stock' => true]);
    }

    private function backendReturning(array $byQuery, array &$queries = []): StorefrontBackendInterface&MockObject
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->method('searchProducts')->willReturnCallback(
            static function (SessionContext $ctx, string $query) use ($byQuery, &$queries): array {
                $queries[] = $query;
                return $byQuery[$query] ?? [];
            }
        );
        return $backend;
    }

    private function nameMatch(StorefrontBackendInterface $backend, ?LoggerInterface $logger = null): NameMatch
    {
        return new NameMatch($backend, new Sanitizer(), $logger ?? $this->createMock(LoggerInterface::class));
    }

    public function testNeatChairFindsTheNeatDiningChairAndRemembersIt(): void
    {
        $backend = $this->backendReturning(['neat chair' => [
            $this->product('101', 'Blu Dot Neat Velvet Dining Chair'),
            $this->product('102', 'Blu Dot Real Good Chair'),
        ]]);
        $state = new SessionState();

        $note = $this->nameMatch($backend)->text($this->context(), $state, 'neat chair', 12);

        $this->assertSame(
            NameMatch::PREFIX . 'products whose names contain every word the customer typed: Blu Dot Neat Velvet '
            . 'Dining Chair (product_id 101). If the customer may mean one of these, lead with it.]',
            $note
        );
        $this->assertTrue($state->hasSeen('101'));
        $this->assertFalse($state->hasSeen('102'));
    }

    public function testEmecoOnAndOnAlsoSearchesWithAnAmpersand(): void
    {
        $queries = [];
        $backend = $this->backendReturning([
            'emeco on and on' => [$this->product('201', 'Emeco Navy Chair')],
            'emeco on & on' => [$this->product('202', 'Emeco On & On Stacking Chair')],
        ], $queries);
        $state = new SessionState();

        $note = $this->nameMatch($backend)->text($this->context(), $state, 'emeco on and on', 12);

        $this->assertSame(['emeco on and on', 'emeco on & on'], $queries);
        $this->assertStringContainsString('Emeco On & On Stacking Chair (product_id 202)', (string)$note);
        $this->assertStringNotContainsString('Navy', (string)$note);
        $this->assertTrue($state->hasSeen('202'));
    }

    public function testAccentsAndPluralsMatchAsWordPrefixes(): void
    {
        $backend = $this->backendReturning(['10 autre chaise' => [
            $this->product('301', '10 Autres Chaises'),
            $this->product('302', 'Chaise Longue'),
        ]]);

        $note = $this->nameMatch($backend)->text($this->context(), new SessionState(), '10 autre chaise', 12);

        $this->assertStringContainsString('10 Autres Chaises (product_id 301)', (string)$note);
        $this->assertStringNotContainsString('Longue', (string)$note);
    }

    public function testAccentsInTheCustomerTextAndTheTitleBothFold(): void
    {
        $backend = $this->backendReturning(['crème brulée dish' => [$this->product('401', 'Creme Brûlée Dishes')]]);

        $note = $this->nameMatch($backend)->text($this->context(), new SessionState(), 'crème brulée dish', 12);

        $this->assertStringContainsString('Creme Brûlée Dishes (product_id 401)', (string)$note);
    }

    public function testAWordInTheMiddleOfATitleWordDoesNotMatch(): void
    {
        $backend = $this->backendReturning(['board' => [$this->product('501', 'Sideboards Oak')]]);
        $state = new SessionState();

        $this->assertNull($this->nameMatch($backend)->text($this->context(), $state, 'board', 12));
        $this->assertSame([], $state->seenProducts);
    }

    public function testAStopwordOnlyMessageRunsNoSearch(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->expects($this->never())->method('searchProducts');

        $this->assertNull($this->nameMatch($backend)->text($this->context(), new SessionState(), 'and the a &', 12));
    }

    public function testANineWordMessageIsSkipped(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->expects($this->never())->method('searchProducts');

        $note = $this->nameMatch($backend)->text(
            $this->context(),
            new SessionState(),
            'i would like a neat chair for my dining room',
            12
        );

        $this->assertNull($note);
    }

    public function testAnEightWordMessageIsSearched(): void
    {
        $queries = [];
        $backend = $this->backendReturning([], $queries);

        $this->nameMatch($backend)->text($this->context(), new SessionState(), 'one two three four five six seven eight', 12);

        $this->assertSame(['one two three four five six seven eight'], $queries);
    }

    public function testNoMatchAddsNothing(): void
    {
        $backend = $this->backendReturning(['velvet sofa' => [$this->product('601', 'Velvet Armchair')]]);
        $state = new SessionState();

        $this->assertNull($this->nameMatch($backend)->text($this->context(), $state, 'velvet sofa', 12));
        $this->assertSame([], $state->seenProducts);
    }

    public function testMatchesAreCappedAtFiveAndDeduplicated(): void
    {
        $products = [];
        for ($i = 1; $i <= 7; $i++) {
            $products[] = $this->product((string)$i, 'Neat Chair ' . $i);
        }
        $backend = $this->backendReturning([
            'neat and chair' => array_slice($products, 0, 2),
            'neat & chair' => $products,
        ]);
        $state = new SessionState();

        $note = (string)$this->nameMatch($backend)->text($this->context(), $state, 'neat and chair', 12);

        $this->assertSame(5, substr_count($note, '(product_id '));
        $this->assertSame(1, substr_count($note, '(product_id 1)'));
        $this->assertCount(NameMatch::MAX_MATCHES, $state->seenProducts);
    }

    public function testASearchFailureIsLoggedAndSwallowed(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->method('searchProducts')->willThrowException(new \RuntimeException('search down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('search down'));
        $state = new SessionState();

        $this->assertNull($this->nameMatch($backend, $logger)->text($this->context(), $state, 'neat chair', 12));
        $this->assertSame([], $state->seenProducts);
    }

    public function testTheSearchUsesTheGivenLimitAndNoFilters(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->expects($this->once())->method('searchProducts')
            ->with($this->anything(), 'neat chair', null, 12)
            ->willReturn([]);

        $this->nameMatch($backend)->text($this->context(), new SessionState(), '  neat chair ', 12);
    }
}
