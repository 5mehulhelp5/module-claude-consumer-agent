<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Session;

final class IdGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(32));
    }
}
