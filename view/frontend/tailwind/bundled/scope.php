<?php
declare(strict_types=1);

final class ScopeBuildError extends \RuntimeException
{
}

final class CssScoper
{
    private const ROOTS = '#ai-agent-overlay, #ai-agent-cards, #ai-agent-drawer-panel, #ai-agent-launcher, #ai-agent-cart-ask';

    public function transform(string $css): string
    {
        $nodes = $this->parseNodes($css);
        return $this->renderTopLevelNodes($nodes);
    }

    private function renderTopLevelNodes(array $nodes): string
    {
        $out = '';
        $buffer = [];
        foreach ($nodes as $node) {
            if ($this->isPlainUnlayeredRule($node)) {
                $buffer[] = $node;
                continue;
            }
            if ($buffer !== []) {
                $out .= $this->wrapInScope($this->renderNodeList($buffer));
                $buffer = [];
            }
            $out .= $this->renderTopLevelBlock($node);
        }
        if ($buffer !== []) {
            $out .= $this->wrapInScope($this->renderNodeList($buffer));
        }
        return $out;
    }

    private function isPlainUnlayeredRule(array $node): bool
    {
        if ($node['type'] !== 'block') {
            return false;
        }
        $prelude = trim($node['prelude']);
        if ($prelude === '') {
            return false;
        }
        return $prelude[0] !== '@';
    }

    private function renderTopLevelBlock(array $node): string
    {
        if ($node['type'] !== 'block') {
            return $this->renderNode($node);
        }
        $prelude = trim($node['prelude']);
        if ($prelude === '@layer properties') {
            return '@layer properties{' . $this->wrapInnermostRules($node['inner']) . '}';
        }
        if ($prelude === '@layer theme') {
            return '@layer theme{' . $this->rewriteThemeLayer($node['inner']) . '}';
        }
        if ($prelude === '@layer base') {
            $withoutHtmlBody = $this->dropHtmlBodyRules($node['inner']);
            return '@layer base{' . $this->wrapInnermostRules($withoutHtmlBody) . '}';
        }
        if ($prelude === '@layer components' || $prelude === '@layer utilities') {
            return $prelude . '{' . $this->wrapInnermostRules($node['inner']) . '}';
        }
        if ($prelude[0] === '@') {
            return $this->renderNode($node);
        }
        throw new ScopeBuildError('Unexpected top level selector outside a recognised at-rule: ' . $prelude);
    }

    private function wrapInScope(string $body): string
    {
        if (trim($body) === '') {
            return $body;
        }
        return '@scope (' . self::ROOTS . '){' . $this->renderScopedContent($body) . '}';
    }

    private function renderScopedContent(string $inner): string
    {
        $nodes = $this->parseNodes($inner);
        $parts = [];
        foreach ($nodes as $node) {
            if ($node['type'] !== 'block') {
                $parts[] = $this->renderNode($node);
                continue;
            }
            $prelude = trim($node['prelude']);
            if (preg_match('/^@(media|supports)\b/', $prelude)) {
                $parts[] = $prelude . '{' . $this->renderScopedContent($node['inner']) . '}';
                continue;
            }
            $parts[] = $this->expandSelectorForSelfMatch($prelude) . '{' . $node['inner'] . '}';
        }
        return implode('', $parts);
    }

    private function expandSelectorForSelfMatch(string $preludeList): string
    {
        $items = $this->splitTopLevelCommas($preludeList);
        $expanded = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $expanded[] = $item;
            if ($this->hasTopLevelCombinator($item)) {
                continue;
            }
            $expanded[] = $this->selfMatchVariant($item);
        }
        return implode(',', $expanded);
    }

    private function hasTopLevelCombinator(string $selector): bool
    {
        $depth = 0;
        $len = strlen($selector);
        for ($i = 0; $i < $len; $i++) {
            $ch = $selector[$i];
            if ($ch === '(' || $ch === '[') {
                $depth++;
                continue;
            }
            if ($ch === ')' || $ch === ']') {
                $depth--;
                continue;
            }
            if ($depth > 0) {
                continue;
            }
            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === '>' || $ch === '+' || $ch === '~') {
                return true;
            }
        }
        return false;
    }

    private function selfMatchVariant(string $item): string
    {
        $pseudoElementPos = $this->findPseudoElementStart($item);
        if ($pseudoElementPos !== null) {
            $base = substr($item, 0, $pseudoElementPos);
            $pseudoElement = substr($item, $pseudoElementPos);
        } else {
            $base = $item;
            $pseudoElement = '';
        }
        return $this->insertScopeIntoCompound($base) . $pseudoElement;
    }

    private function findPseudoElementStart(string $selector): ?int
    {
        $depth = 0;
        $len = strlen($selector);
        for ($i = 0; $i < $len - 1; $i++) {
            $ch = $selector[$i];
            if ($ch === '(' || $ch === '[') {
                $depth++;
                continue;
            }
            if ($ch === ')' || $ch === ']') {
                $depth--;
                continue;
            }
            if ($depth === 0 && $ch === ':' && $selector[$i + 1] === ':') {
                return $i;
            }
        }
        return null;
    }

    private function insertScopeIntoCompound(string $base): string
    {
        if ($base === '') {
            return ':scope';
        }
        $first = $base[0];
        if ($first === '.' || $first === '#' || $first === '[' || $first === ':') {
            return ':scope' . $base;
        }
        if ($first === '*') {
            return '*:scope' . substr($base, 1);
        }
        $len = strlen($base);
        $i = 0;
        while ($i < $len) {
            $ch = $base[$i];
            if ($ch === '.' || $ch === '#' || $ch === '[' || $ch === ':') {
                break;
            }
            $i++;
        }
        $typePart = substr($base, 0, $i);
        $restPart = substr($base, $i);
        return $typePart . ':scope' . $restPart;
    }

    private function wrapInnermostRules(string $inner): string
    {
        $trimmed = trim($inner);
        if ($trimmed === '') {
            return $inner;
        }
        $nodes = $this->parseNodes($inner);
        if ($this->allConditionalGroupRules($nodes)) {
            $parts = [];
            foreach ($nodes as $node) {
                $prelude = trim($node['prelude']);
                $parts[] = $prelude . '{' . $this->wrapInnermostRules($node['inner']) . '}';
            }
            return implode('', $parts);
        }
        return $this->wrapInScope($inner);
    }

    private function allConditionalGroupRules(array $nodes): bool
    {
        if ($nodes === []) {
            return false;
        }
        foreach ($nodes as $node) {
            if ($node['type'] !== 'block') {
                return false;
            }
            if (!preg_match('/^@(media|supports)\b/', trim($node['prelude']))) {
                return false;
            }
        }
        return true;
    }

    private function rewriteThemeLayer(string $inner): string
    {
        $nodes = $this->parseNodes($inner);
        $parts = [];
        foreach ($nodes as $node) {
            if ($node['type'] !== 'block') {
                $parts[] = $this->renderNode($node);
                continue;
            }
            $prelude = trim($node['prelude']);
            if (preg_match('/^@(media|supports)\b/', $prelude)) {
                $parts[] = $prelude . '{' . $this->rewriteThemeLayer($node['inner']) . '}';
                continue;
            }
            $parts[] = $this->rewriteRootSelector($prelude) . '{' . $node['inner'] . '}';
        }
        return implode('', $parts);
    }

    private function rewriteRootSelector(string $prelude): string
    {
        if ($prelude === ':root,:host' || $prelude === ':root') {
            return ':where(' . self::ROOTS . ')';
        }
        throw new ScopeBuildError('Unexpected selector in the theme layer, expected :root,:host or :root: ' . $prelude);
    }

    private function dropHtmlBodyRules(string $inner): string
    {
        $nodes = $this->parseNodes($inner);
        $parts = [];
        foreach ($nodes as $node) {
            if ($node['type'] !== 'block') {
                $parts[] = $this->renderNode($node);
                continue;
            }
            $prelude = trim($node['prelude']);
            if (preg_match('/^@(media|supports)\b/', $prelude)) {
                $parts[] = $prelude . '{' . $this->dropHtmlBodyRules($node['inner']) . '}';
                continue;
            }
            if ($this->selectorTargetsHtmlOrBody($prelude)) {
                continue;
            }
            $parts[] = $prelude . '{' . $node['inner'] . '}';
        }
        return implode('', $parts);
    }

    private function selectorTargetsHtmlOrBody(string $prelude): bool
    {
        foreach ($this->splitTopLevelCommas($prelude) as $selector) {
            $selector = trim($selector);
            if ($selector === 'html' || $selector === 'body') {
                return true;
            }
        }
        return false;
    }

    private function splitTopLevelCommas(string $value): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $ch = $value[$i];
            if ($ch === '(' || $ch === '[') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']') {
                $depth--;
            }
            if ($ch === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $parts[] = $current;
        return $parts;
    }

    private function renderNodeList(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= $this->renderNode($node);
        }
        return $out;
    }

    private function renderNode(array $node): string
    {
        if ($node['type'] === 'block') {
            return $node['prelude'] . '{' . $node['inner'] . '}';
        }
        return $node['text'];
    }

    private function parseNodes(string $css): array
    {
        $nodes = [];
        $len = strlen($css);
        $i = 0;
        while ($i < $len) {
            while ($i < $len && $this->isWhitespace($css[$i])) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            if ($css[$i] === '/' && $i + 1 < $len && $css[$i + 1] === '*') {
                $end = strpos($css, '*/', $i + 2);
                if ($end === false) {
                    throw new ScopeBuildError('Unterminated comment starting at position ' . $i);
                }
                $end += 2;
                $nodes[] = ['type' => 'comment', 'text' => substr($css, $i, $end - $i)];
                $i = $end;
                continue;
            }
            $start = $i;
            $depth = 0;
            $quote = null;
            $found = false;
            while ($i < $len) {
                $ch = $css[$i];
                if ($quote !== null) {
                    if ($ch === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($ch === $quote) {
                        $quote = null;
                    }
                    $i++;
                    continue;
                }
                if ($ch === '"' || $ch === "'") {
                    $quote = $ch;
                    $i++;
                    continue;
                }
                if ($ch === '(') {
                    $depth++;
                    $i++;
                    continue;
                }
                if ($ch === ')') {
                    $depth--;
                    $i++;
                    continue;
                }
                if ($depth === 0 && $ch === ';') {
                    $nodes[] = ['type' => 'stmt', 'text' => substr($css, $start, $i + 1 - $start)];
                    $i++;
                    $found = true;
                    break;
                }
                if ($depth === 0 && $ch === '{') {
                    $prelude = substr($css, $start, $i - $start);
                    $close = $this->matchBrace($css, $i);
                    $inner = substr($css, $i + 1, $close - $i - 1);
                    $nodes[] = ['type' => 'block', 'prelude' => $prelude, 'inner' => $inner];
                    $i = $close + 1;
                    $found = true;
                    break;
                }
                $i++;
            }
            if (!$found) {
                throw new ScopeBuildError('Unterminated statement starting at position ' . $start);
            }
        }
        return $nodes;
    }

    private function matchBrace(string $css, int $openPos): int
    {
        $len = strlen($css);
        $depth = 0;
        $quote = null;
        for ($i = $openPos; $i < $len; $i++) {
            $ch = $css[$i];
            if ($quote !== null) {
                if ($ch === '\\') {
                    $i++;
                    continue;
                }
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                continue;
            }
            if ($ch === '{') {
                $depth++;
                continue;
            }
            if ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
                continue;
            }
        }
        throw new ScopeBuildError('Unbalanced braces, no matching close for open brace at position ' . $openPos);
    }

    private function isWhitespace(string $ch): bool
    {
        return $ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r" || $ch === "\f";
    }
}

function main(array $argv): int
{
    if (count($argv) < 2) {
        fwrite(STDERR, "Usage: php scope.php <raw.css>\n");
        return 1;
    }
    $path = $argv[1];
    $css = file_get_contents($path);
    if ($css === false) {
        fwrite(STDERR, 'Could not read ' . $path . "\n");
        return 1;
    }
    $scoper = new CssScoper();
    try {
        $result = $scoper->transform($css);
    } catch (ScopeBuildError $e) {
        fwrite(STDERR, 'Scope build failed: ' . $e->getMessage() . "\n");
        return 1;
    }
    fwrite(STDOUT, $result);
    return 0;
}

if (isset($argv)) {
    exit(main($argv));
}
