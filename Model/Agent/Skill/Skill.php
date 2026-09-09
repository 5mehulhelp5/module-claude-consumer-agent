<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Agent\Skill;

final class Skill
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $body
    ) {
    }
}
