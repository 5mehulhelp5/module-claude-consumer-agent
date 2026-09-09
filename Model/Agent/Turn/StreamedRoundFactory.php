<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Agent\Turn;

final class StreamedRoundFactory
{
    public function create(): StreamedRound
    {
        return new StreamedRound();
    }
}
