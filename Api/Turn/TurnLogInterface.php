<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Api\Turn;

interface TurnLogInterface
{
    public function record(array $row): void;
}
