<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Session;

class IdGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(32));
    }
}
