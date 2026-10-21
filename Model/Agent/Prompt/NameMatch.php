<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Prompt;

use MageOS\AiShoppingAssistant\Api\Data\ProductInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;

/**
 * Searches a short customer message as a product name, because the model reads names such as
 * "neat chair" as a description and searches the wrong words.
 */
class NameMatch
{
    public const PREFIX = '[Name match: ';

    public const MAX_WORDS = 8;

    public const MAX_MATCHES = 5;

    private const TITLE_MAX_LENGTH = 120;

    private const STOPWORDS = ['and', 'the', 'a', 'an', 'of', 'for', 'with', '&'];

    private const COMMON_WORDS = [
        'ok', 'okay', 'yes', 'yeah', 'yep', 'no', 'nope', 'not', 'more', 'less', 'is', 'are', 'was', 'be',
        'it', 'its', 'this', 'that', 'these', 'those', 'what', 'which', 'who', 'how', 'why', 'when', 'where',
        'agent', 'human', 'person', 'help', 'hi', 'hello', 'hey', 'thanks', 'thank', 'you', 'your', 'please',
        'sure', 'show', 'me', 'my', 'can', 'do', 'does', 'have', 'has', 'any', 'some', 'all', 'other', 'others',
        'else', 'again', 'next', 'back', 'good', 'great', 'nice', 'fine', 'maybe', 'just', 'also', 'too', 'very',
        'much', 'many', 'one', 'there', 'here', 'in', 'on', 'at', 'to', 'from', 'by', 'about', 'like', 'want',
        'need', 'see', 'look', 'find', 'get', 'buy', 'price', 'cost', 'stock', 'sale', 'new', 'now', 'or',
        'will', 'would', 'could', 'should', 'them', 'they', 'we', 'us', 'our', 'so', 'if', 'then', 'than',
        'out', 'up', 'only', 'still', 'same', 'different',
    ];

    private const CATEGORY_CHECK_LIMIT = 5;

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface $backend,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer $sanitizer,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
    }

    public function text(SessionContext $context, SessionState $state, string $message, int $limit): ?string
    {
        $query = trim($message);
        $words = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === [] || count($words) > self::MAX_WORDS) {
            return null;
        }
        $needles = $this->significantWords($query);
        if (!$this->hasDistinctiveWord($needles)) {
            return null;
        }
        try {
            if ($this->isCategoryName($context, $query)) {
                return null;
            }
            $records = $this->matches($context, $this->queries($query), $needles, max(1, $limit));
        } catch (\Throwable $exception) {
            $this->logger->warning(sprintf(
                'name match failed session=%s error=%s',
                substr(sha1($context->sessionId), 0, 12),
                $exception->getMessage()
            ));
            return null;
        }
        if ($records === []) {
            return null;
        }
        $state->rememberProducts($records);
        return $this->note($records);
    }

    private function queries(string $query): array
    {
        $queries = [$query];
        $ampersand = preg_replace('/(?<=\s)and(?=\s)/iu', '&', $query);
        if ($ampersand !== null && $ampersand !== $query) {
            $queries[] = $ampersand;
        }
        return $queries;
    }

    private function matches(SessionContext $context, array $queries, array $needles, int $limit): array
    {
        $records = [];
        foreach ($queries as $query) {
            foreach ($this->backend->searchProducts($context, $query, null, $limit) as $product) {
                if (!$product instanceof ProductInterface) {
                    continue;
                }
                $record = $product->toArray();
                $id = (string)($record['product_id'] ?? '');
                if ($id === '' || isset($records[$id])) {
                    continue;
                }
                if (!$this->titleHasEvery($needles, (string)($record['title'] ?? ''))) {
                    continue;
                }
                $records[$id] = $record;
                if (count($records) >= self::MAX_MATCHES) {
                    return array_values($records);
                }
            }
        }
        return array_values($records);
    }

    private function titleHasEvery(array $needles, string $title): bool
    {
        $titleWords = $this->words($title);
        foreach ($needles as $needle) {
            $found = false;
            foreach ($titleWords as $titleWord) {
                if ($this->sameWord($needle, $titleWord)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }
        return true;
    }

    private function sameWord(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        foreach (['s', 'es'] as $suffix) {
            if ($a . $suffix === $b || $b . $suffix === $a) {
                return true;
            }
        }
        return false;
    }

    private function hasDistinctiveWord(array $needles): bool
    {
        foreach ($needles as $needle) {
            if (!in_array($needle, self::COMMON_WORDS, true)) {
                return true;
            }
        }
        return false;
    }

    private function isCategoryName(SessionContext $context, string $query): bool
    {
        $queryWords = $this->words($query);
        foreach ($this->backend->searchCategories($context, $query, self::CATEGORY_CHECK_LIMIT) as $category) {
            $nameWords = $this->words($category->getName());
            if (count($nameWords) !== count($queryWords)) {
                continue;
            }
            $same = true;
            foreach ($queryWords as $index => $word) {
                if (!$this->sameWord($word, $nameWords[$index])) {
                    $same = false;
                    break;
                }
            }
            if ($same) {
                return true;
            }
        }
        return false;
    }

    private function significantWords(string $text): array
    {
        $significant = [];
        foreach ($this->words($text) as $word) {
            if (in_array($word, self::STOPWORDS, true) || in_array($word, $significant, true)) {
                continue;
            }
            if (mb_strlen($word) >= 2 || preg_match('/\d/u', $word) === 1) {
                $significant[] = $word;
            }
        }
        return $significant;
    }

    private function words(string $text): array
    {
        $folded = $this->fold($text);
        $words = preg_split('/[^\p{L}\p{N}&]+/u', $folded, -1, PREG_SPLIT_NO_EMPTY);
        return $words !== false ? $words : [];
    }

    private function fold(string $text): string
    {
        $lowered = mb_strtolower($text);
        $decomposed = \Normalizer::normalize($lowered, \Normalizer::FORM_D);
        if (!is_string($decomposed)) {
            return $lowered;
        }
        return preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $lowered;
    }

    private function note(array $records): string
    {
        $entries = [];
        foreach ($records as $record) {
            $title = $this->sanitizer->label((string)($record['title'] ?? ''), self::TITLE_MAX_LENGTH);
            $id = $this->sanitizer->label((string)$record['product_id'], self::TITLE_MAX_LENGTH);
            $entries[] = ($title !== '' ? $title : 'Untitled') . ' (product_id ' . $id . ')';
        }
        return self::PREFIX . 'products whose names contain every word the customer typed: '
            . implode('; ', $entries) . '. If the customer may mean one of these, lead with it.]';
    }
}
