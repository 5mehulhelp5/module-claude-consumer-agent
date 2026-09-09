<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Agent\Prompt;

use MageOS\ClaudeConsumerAgent\Api\Data\PageContextInterface;
use MageOS\ClaudeConsumerAgent\Model\Data\PageContext;

final class PageNote
{
    public const PREFIX = '[Page: ';

    public function text(PageContextInterface $current, array $previous): ?string
    {
        $currentType = $current->getPageType();
        if ($currentType === PageContextInterface::PAGE_TYPE_HOME || $currentType === PageContextInterface::PAGE_TYPE_OTHER) {
            return null;
        }

        $previousPage = PageContext::fromArray($previous);
        if ($this->samePage($current, $previousPage)) {
            return null;
        }

        $note = self::PREFIX . 'the customer is now on ' . $this->describe($current) . '.';
        $previousDescription = $this->describePrevious($previousPage);
        if ($previousDescription !== null) {
            $note .= ' Before this message they were on ' . $previousDescription . '.';
        }
        return $note . ']';
    }

    private function samePage(PageContextInterface $current, PageContextInterface $previous): bool
    {
        if ($current->getPageType() !== $previous->getPageType()) {
            return false;
        }
        return match ($current->getPageType()) {
            PageContextInterface::PAGE_TYPE_PRODUCT => $current->getProductId() === $previous->getProductId(),
            PageContextInterface::PAGE_TYPE_CATEGORY => $current->getCategoryId() === $previous->getCategoryId(),
            PageContextInterface::PAGE_TYPE_SEARCH => $current->getQuery() === $previous->getQuery(),
            default => true,
        };
    }

    private function describePrevious(PageContextInterface $previous): ?string
    {
        $type = $previous->getPageType();
        if ($type === PageContextInterface::PAGE_TYPE_HOME || $type === PageContextInterface::PAGE_TYPE_OTHER) {
            return null;
        }
        return $this->describe($previous);
    }

    private function describe(PageContextInterface $page): string
    {
        return match ($page->getPageType()) {
            PageContextInterface::PAGE_TYPE_PRODUCT => $this->describeProduct($page),
            PageContextInterface::PAGE_TYPE_CATEGORY => $this->describeCategory($page),
            PageContextInterface::PAGE_TYPE_SEARCH => $this->describeSearch($page),
            PageContextInterface::PAGE_TYPE_CART => 'the cart page',
            PageContextInterface::PAGE_TYPE_ORDERS => 'their order pages',
            default => 'the store',
        };
    }

    private function describeProduct(PageContextInterface $page): string
    {
        $id = $page->getProductId();
        if ($id === null) {
            return 'the product page';
        }
        $name = $page->getProductName();
        if ($name === null) {
            return 'the product page for product_id ' . $id;
        }
        return 'the product page for "' . $name . '" (product_id ' . $id . ')';
    }

    private function describeCategory(PageContextInterface $page): string
    {
        $id = $page->getCategoryId();
        if ($id === null) {
            return 'the category page';
        }
        $name = $page->getCategoryName();
        if ($name === null) {
            return 'the category page for category_id ' . $id;
        }
        return 'the category page "' . $name . '" (category_id ' . $id . ')';
    }

    private function describeSearch(PageContextInterface $page): string
    {
        $query = $page->getQuery();
        if ($query === null) {
            return 'the search results page';
        }
        return 'the search results for "' . $query . '"';
    }
}
