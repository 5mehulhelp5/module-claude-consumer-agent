<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Agent\Grounding;

use MageOS\ClaudeConsumerAgent\Api\Data\PageContextInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\AgentConfig;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionState;

final class Rules
{
    public function __construct(
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Lexicon $lexicon
    ) {
    }

    public function firstForcedTool(
        AgentConfig $config,
        string $text,
        SessionState $state,
        PageContextInterface $page,
        bool $isFirstTurn,
        int $storeId
    ): ?string {
        if ($this->matchesStoreFact($text, $config->storeFacts)) {
            return null;
        }
        if ($config->enablePolicies
            && $this->matches($text, $this->lexicon->policyTerms($storeId), $this->lexicon->policyCues())
        ) {
            return 'search_policies';
        }
        if ($config->enableOrders
            && $this->matches($text, $this->lexicon->orderTerms($storeId), $this->lexicon->orderCues())
        ) {
            return 'get_orders';
        }
        $token = $this->longestMatch($text, $this->lexicon->idPatterns($storeId));
        if ($token !== null && !$state->hasSeenCaseInsensitive($token)) {
            return 'get_product_details';
        }
        $pageProductId = $page->getProductId();
        if ($isFirstTurn
            && $page->getPageType() === PageContextInterface::PAGE_TYPE_PRODUCT
            && $pageProductId !== null
            && !$state->hasSeen($pageProductId)
        ) {
            return 'get_product_details';
        }
        return null;
    }

    private function matchesStoreFact(string $text, array $facts): bool
    {
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $source = (string)($fact['source'] ?? 'text');
            if ($source !== 'text' && $source !== 'not_offered') {
                continue;
            }
            $keywords = is_array($fact['keywords'] ?? null) ? $fact['keywords'] : [];
            if ($keywords === []) {
                $keywords = [trim((string)($fact['topic'] ?? ''))];
            }
            if ($this->matchesAny($text, $keywords)) {
                return true;
            }
        }
        return false;
    }

    private function matches(string $text, array $terms, array $cues): bool
    {
        if ($text === '' || $terms === [] || $cues === []) {
            return false;
        }
        if (!$this->matchesAny($text, $cues)) {
            return false;
        }
        return $this->matchesAny($text, $terms);
    }

    private function matchesAny(string $text, array $needles): bool
    {
        $lowered = mb_strtolower($text);
        foreach ($needles as $needle) {
            $cleaned = trim(mb_strtolower((string)$needle));
            if ($cleaned === '') {
                continue;
            }
            if ($cleaned === '?') {
                if (str_contains($lowered, '?')) {
                    return true;
                }
                continue;
            }
            if (preg_match('/\b' . preg_quote($cleaned, '/') . '\b/u', $lowered) === 1) {
                return true;
            }
        }
        return false;
    }

    private function longestMatch(string $text, array $patterns): ?string
    {
        if ($text === '') {
            return null;
        }
        $token = null;
        foreach ($patterns as $pattern) {
            $result = preg_match('/' . $pattern . '/iu', $text, $matches);
            if ($result !== 1) {
                continue;
            }
            $candidate = $matches[0];
            if ($token === null || mb_strlen($candidate) > mb_strlen($token)) {
                $token = $candidate;
            }
        }
        return $token;
    }
}
