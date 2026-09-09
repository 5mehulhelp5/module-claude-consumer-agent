<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Client;

final class SseLineReader
{
    public function read(iterable $chunks): \Generator
    {
        $buffer = '';
        $eventName = null;
        $dataLines = [];
        foreach ($chunks as $chunk) {
            if ($chunk === '') {
                continue;
            }
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);
                if ($line === '') {
                    if ($eventName !== null && $dataLines !== []) {
                        yield new RawEvent($eventName, $this->decode($dataLines));
                    }
                    $eventName = null;
                    $dataLines = [];
                    continue;
                }
                if ($line[0] === ':') {
                    continue;
                }
                if (str_starts_with($line, 'event:')) {
                    $eventName = trim(substr($line, 6));
                    continue;
                }
                if (str_starts_with($line, 'data:')) {
                    $dataLines[] = ltrim(substr($line, 5));
                    continue;
                }
            }
        }
        if ($eventName !== null && $dataLines !== []) {
            yield new RawEvent($eventName, $this->decode($dataLines));
        }
    }

    private function decode(array $dataLines): array
    {
        $decoded = json_decode(implode("\n", $dataLines), true);
        return is_array($decoded) ? $decoded : [];
    }
}
