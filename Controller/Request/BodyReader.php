<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Controller\Request;

use MageOS\ClaudeConsumerAgent\Model\Agent\AgentConfig;

final class BodyReader
{
    private const SESSION_ID_PATTERN = '/^[0-9a-f]{64}$/';

    public function read(\Magento\Framework\App\RequestInterface $request, AgentConfig $config): TurnRequest
    {
        $data = $this->decode($request);
        $message = trim((string)($data['message'] ?? ''));
        if ($message === '' || mb_strlen($message) > $config->maxMessageLength) {
            throw new \InvalidArgumentException('message is required and must not exceed the configured length');
        }
        $page = is_array($data['page'] ?? null) ? $data['page'] : [];
        $stream = ($data['stream'] ?? 1) == 1;
        return new TurnRequest($this->normalizeSessionId($data['session'] ?? null), $message, $page, $stream);
    }

    public function readStart(\Magento\Framework\App\RequestInterface $request): TurnRequest
    {
        $data = $this->decode($request);
        $page = is_array($data['page'] ?? null) ? $data['page'] : [];
        return new TurnRequest($this->normalizeSessionId($data['session'] ?? null), '', $page, true);
    }

    private function decode(\Magento\Framework\App\RequestInterface $request): array
    {
        $raw = (string)$request->getContent();
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('request body must be a JSON object');
        }
        return $data;
    }

    private function normalizeSessionId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        return preg_match(self::SESSION_ID_PATTERN, $value) === 1 ? $value : null;
    }
}
