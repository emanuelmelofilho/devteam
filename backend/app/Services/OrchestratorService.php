<?php

namespace App\Services;

use Agents\Orchestrator\TaskRouter;
use Agents\Orchestrator\TaskStateMachine;
use App\Models\Task;

/**
 * Thin Laravel-aware wrapper around agents/orchestrator/*.php. The backend
 * is the single authority for the workflow: it is the only place that
 * decides lane transitions, so the state machine definition lives in one
 * file (shared read-only with the workers via docker-compose's agents bind
 * mount) instead of being reimplemented per agent.
 */
class OrchestratorService
{
    public function __construct()
    {
        require_once base_path('agents/orchestrator/orchestrator.php');
    }

    public function lanes(): array
    {
        return TaskStateMachine::LANES;
    }

    public function canTransition(string $from, string $to): bool
    {
        return TaskStateMachine::canTransition($from, $to);
    }

    public function agentForLane(string $lane): ?string
    {
        return TaskStateMachine::agentForLane($lane);
    }

    public function retryAgentForLane(string $lane): ?string
    {
        return TaskStateMachine::retryAgentForLane($lane);
    }

    public function branchFor(Task $task): string
    {
        return TaskStateMachine::branchFor($task->id);
    }

    public function queueFor(string $agent): ?string
    {
        return TaskRouter::queueFor($agent);
    }

    /**
     * @return array{lane:string, attempt:int, blocked:bool}
     */
    public function decideAfterOutcome(Task $task, string $outcome): array
    {
        return TaskStateMachine::decideAfterOutcome($task->lane, $outcome, $task->attempt, $task->max_attempts);
    }
}
