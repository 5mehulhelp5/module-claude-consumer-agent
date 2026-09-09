<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Test\Unit\Prompt;

use MageOS\ClaudeConsumerAgent\Model\Agent\Prompt\PageNote;
use MageOS\ClaudeConsumerAgent\Model\Data\PageContext;
use PHPUnit\Framework\TestCase;

final class PageNoteTest extends TestCase
{
    public function testReturnsNullWhenCurrentPageIsHome(): void
    {
        $current = new PageContext(PageContext::PAGE_TYPE_HOME);

        $this->assertNull((new PageNote())->text($current, []));
    }

    public function testReturnsNullWhenCurrentPageIsOther(): void
    {
        $current = new PageContext(PageContext::PAGE_TYPE_OTHER);

        $this->assertNull((new PageNote())->text($current, []));
    }

    public function testReturnsNullWhenPreviousDescribesTheSameProduct(): void
    {
        $current = PageContext::fromArray(['page_type' => 'product', 'product_id' => '34149']);
        $previous = ['page_type' => 'product', 'product_id' => '34149'];

        $this->assertNull((new PageNote())->text($current, $previous));
    }

    public function testReturnsNullWhenPreviousDescribesTheSameCategory(): void
    {
        $current = PageContext::fromArray(['page_type' => 'category', 'category_id' => '67']);
        $previous = ['page_type' => 'category', 'category_id' => '67'];

        $this->assertNull((new PageNote())->text($current, $previous));
    }

    public function testReturnsNullWhenPreviousDescribesTheSameSearch(): void
    {
        $current = PageContext::fromArray(['page_type' => 'search', 'query' => 'lamp']);
        $previous = ['page_type' => 'search', 'query' => 'lamp'];

        $this->assertNull((new PageNote())->text($current, $previous));
    }

    public function testProductToProductChangeNamesBothTitles(): void
    {
        $current = PageContext::fromArray([
            'page_type' => 'product',
            'product_id' => '29335',
            'product_name' => 'The Interior Design Handbook',
        ]);
        $previous = [
            'page_type' => 'product',
            'product_id' => '34149',
            'product_name' => 'Resident Dog (Volume Two)',
        ];

        $note = (new PageNote())->text($current, $previous);

        $this->assertSame(
            '[Page: the customer is now on the product page for "The Interior Design Handbook" '
                . '(product_id 29335). Before this message they were on the product page for '
                . '"Resident Dog (Volume Two)" (product_id 34149).]',
            $note
        );
    }

    public function testCategoryFirstVisitHasNoBeforeSentence(): void
    {
        $current = PageContext::fromArray([
            'page_type' => 'category',
            'category_id' => '67',
            'category_name' => 'Beds',
        ]);

        $note = (new PageNote())->text($current, []);

        $this->assertSame('[Page: the customer is now on the category page "Beds" (category_id 67).]', $note);
    }

    public function testPreviousPageOfHomeIsOmittedFromTheNote(): void
    {
        $current = PageContext::fromArray([
            'page_type' => 'category',
            'category_id' => '67',
            'category_name' => 'Beds',
        ]);
        $previous = ['page_type' => 'home'];

        $note = (new PageNote())->text($current, $previous);

        $this->assertSame('[Page: the customer is now on the category page "Beds" (category_id 67).]', $note);
    }

    public function testPreviousPageOfOtherIsOmittedFromTheNote(): void
    {
        $current = PageContext::fromArray(['page_type' => 'cart']);
        $previous = ['page_type' => 'other'];

        $note = (new PageNote())->text($current, $previous);

        $this->assertSame('[Page: the customer is now on the cart page.]', $note);
    }

    public function testNullProductTitleFallsBackToTheId(): void
    {
        $current = PageContext::fromArray(['page_type' => 'product', 'product_id' => '29335']);
        $previous = ['page_type' => 'product', 'product_id' => '34149'];

        $note = (new PageNote())->text($current, $previous);

        $this->assertSame(
            '[Page: the customer is now on the product page for product_id 29335. '
                . 'Before this message they were on the product page for product_id 34149.]',
            $note
        );
    }

    public function testNullCategoryNameFallsBackToTheId(): void
    {
        $current = PageContext::fromArray(['page_type' => 'category', 'category_id' => '67']);

        $note = (new PageNote())->text($current, []);

        $this->assertSame('[Page: the customer is now on the category page for category_id 67.]', $note);
    }

    public function testSearchPageNamesTheQuery(): void
    {
        $current = PageContext::fromArray(['page_type' => 'search', 'query' => 'lamp']);
        $previous = ['page_type' => 'product', 'product_id' => '1'];

        $note = (new PageNote())->text($current, $previous);

        $this->assertStringContainsString('the customer is now on the search results for "lamp"', $note);
    }

    public function testOrdersPageUsesThePluralPhrasing(): void
    {
        $current = PageContext::fromArray(['page_type' => 'orders']);

        $note = (new PageNote())->text($current, []);

        $this->assertSame('[Page: the customer is now on their order pages.]', $note);
    }

    public function testTextStartsWithThePrefixConstant(): void
    {
        $current = PageContext::fromArray(['page_type' => 'cart']);

        $note = (new PageNote())->text($current, []);

        $this->assertStringStartsWith(PageNote::PREFIX, $note);
    }
}
