<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Test\Unit\Grounding;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\AgentConfig;
use MageOS\ClaudeConsumerAgent\Model\Agent\Grounding\Rules;
use MageOS\ClaudeConsumerAgent\Model\Agent\Lexicon;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionState;
use MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig;
use MageOS\ClaudeConsumerAgent\Model\Data\PageContext;
use PHPUnit\Framework\TestCase;

final class RulesTest extends TestCase
{
    private Rules $rules;

    protected function setUp(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $storeConfig = new StoreConfig($scopeConfig, $storeManager);
        $this->rules = new Rules(new Lexicon($storeConfig));
    }

    private function defaultConfig(): AgentConfig
    {
        return new AgentConfig(enablePolicies: true);
    }

    private function forced(
        string $text,
        ?AgentConfig $config = null,
        ?SessionState $state = null,
        ?PageContext $page = null,
        bool $isFirstTurn = false
    ): ?string {
        return $this->rules->firstForcedTool(
            $config ?? $this->defaultConfig(),
            $text,
            $state ?? new SessionState(),
            $page ?? new PageContext(),
            $isFirstTurn,
            1
        );
    }

    public function testPolicyTermAndCueForceSearchPolicies(): void
    {
        $this->assertSame('search_policies', $this->forced('How do returns work for opened items?'));
        $this->assertSame('search_policies', $this->forced('Is there a restocking fee if I send it back?'));
    }

    public function testOrderTermAndCueForceGetOrders(): void
    {
        $this->assertSame('get_orders', $this->forced("Where's my order?"));
        $this->assertSame('get_orders', $this->forced("Just cancel the dog bed order, I'm done waiting."));
    }

    public function testUnseenProductIdForcesGetProductDetails(): void
    {
        $this->assertSame('get_product_details', $this->forced('Add AR-1602 to my cart.'));
        $this->assertSame('get_product_details', $this->forced('is AL-STAY-101 available in June'));
    }

    public function testWholeWordMatchingIgnoresSubstringHits(): void
    {
        $this->assertNull($this->forced('show me lightweight tents under $200'));
        $this->assertNull($this->forced('let us return to the tent options'));
        $this->assertNull($this->forced('add two of the camp mugs to my cart'));
    }

    public function testPrecedenceRunsPolicyThenOrdersThenCatalog(): void
    {
        $this->assertSame('search_policies', $this->forced('Can I return my order?'));
        $this->assertSame('get_orders', $this->forced("What's the status of my order for AR-1602?"));
    }

    public function testAnIdTheSessionAlreadySawIsNotReRead(): void
    {
        $state = new SessionState();
        $state->rememberProducts([['product_id' => 'AR-1602', 'title' => 'Lantern', 'price' => 39.0]]);
        $this->assertNull($this->forced('add ar-1602 to my cart', null, $state));
        $this->assertSame('get_product_details', $this->forced('add AR-1603 to my cart', null, $state));
    }

    public function testThePolicyRuleHasAConfigSwitch(): void
    {
        $this->assertSame(
            'search_policies',
            $this->forced('How do returns work?', new AgentConfig(enablePolicies: true))
        );
        $this->assertNull($this->forced('How do returns work?', new AgentConfig(enablePolicies: false)));
    }

    public function testTheOrderRuleHasAConfigSwitch(): void
    {
        $this->assertSame('get_orders', $this->forced("Where's my order?", new AgentConfig(enableOrders: true)));
        $this->assertNull($this->forced("Where's my order?", new AgentConfig(enableOrders: false)));
    }

    public function testProductPageFirstTurnForcesGetProductDetailsWhenUnseen(): void
    {
        $page = PageContext::fromArray(['page_type' => 'product', 'product_id' => 'AR-9000']);
        $this->assertSame(
            'get_product_details',
            $this->forced('hello there', null, null, $page, true)
        );
    }

    public function testProductPageIsNotForcedOnALaterTurn(): void
    {
        $page = PageContext::fromArray(['page_type' => 'product', 'product_id' => 'AR-9000']);
        $this->assertNull($this->forced('hello there', null, null, $page, false));
    }

    public function testProductPageIsNotForcedWhenAlreadySeen(): void
    {
        $page = PageContext::fromArray(['page_type' => 'product', 'product_id' => 'AR-9000']);
        $state = new SessionState();
        $state->rememberProducts([['product_id' => 'AR-9000', 'title' => 'Lamp', 'price' => 20.0]]);
        $this->assertNull($this->forced('hello there', null, $state, $page, true));
    }

    public function testTextFactKeywordSuppressesSearchPolicies(): void
    {
        $config = new AgentConfig(
            enablePolicies: true,
            storeFacts: [
                [
                    'topic' => 'Price match',
                    'keywords' => ['price match'],
                    'source' => 'text',
                    'value' => 'We match any advertised Canadian price.',
                ],
            ]
        );
        $this->assertNull($this->forced('Do you price match?', $config));
    }

    public function testNotOfferedFactKeywordSuppressesGetOrders(): void
    {
        $config = new AgentConfig(
            enableOrders: true,
            storeFacts: [
                ['topic' => 'Gift message', 'keywords' => ['gift message'], 'source' => 'not_offered', 'value' => ''],
            ]
        );
        $this->assertNull($this->forced('Can I add a gift message to my order?', $config));
    }

    public function testCmsFactKeywordStillForcesSearchPolicies(): void
    {
        $config = new AgentConfig(
            enablePolicies: true,
            storeFacts: [
                [
                    'topic' => 'Returns',
                    'keywords' => ['return', 'refund', 'exchange'],
                    'source' => 'cms_page',
                    'value' => 'returns',
                ],
            ]
        );
        $this->assertSame('search_policies', $this->forced('How do returns work for opened items?', $config));
    }

    public function testNoStoreFactsLeavesTheOldBehaviour(): void
    {
        $config = new AgentConfig(enablePolicies: true, enableOrders: true, storeFacts: []);
        $this->assertSame('search_policies', $this->forced('How do returns work for opened items?', $config));
        $this->assertSame('get_orders', $this->forced("Where's my order?", $config));
    }
}
