<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Eval;

use MageOS\ClaudeConsumerAgent\Api\Turn\TurnLogInterface;

/**
 * Turn recording for the eval runner: every record() lands in memory instead of the
 * aiagent_turn table, so a case never writes to the database.
 */
final class InMemoryTurnLog implements TurnLogInterface
{
    private array $rows = [];

    public function record(array $row): void
    {
        $this->rows[] = $row;
    }
}
