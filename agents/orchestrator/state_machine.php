<?php

namespace Agents\Orchestrator;

/**
 * Single source of truth for the Kanban workflow: lanes, valid transitions,
 * which agent owns each lane, task branch naming, and the retry/blocked rule
 * after a worker reports an outcome.
 *
 * This file has no Laravel dependency on purpose: it is required directly by
 * the backend (via a read-only bind mount, see docker-compose.yml) so the
 * orchestrator stays the single authority instead of being reimplemented
 * inside individual workers.
 */
final class TaskStateMachine
{
    public const LANES = ['todo', 'in_progress', 'review', 'publishing', 'published', 'blocked'];

    public const TRANSITIONS = [
        'todo' => ['in_progress'],
        'in_progress' => ['review'],
        'review' => ['in_progress', 'publishing', 'blocked'],
        'publishing' => ['published', 'in_progress'],
        'published' => [],
        'blocked' => ['in_progress'],
    ];

    public const AGENT_BY_LANE = [
        'in_progress' => 'developer_worker',
        'review' => 'tester_worker',
        'publishing' => 'devops_worker',
        'published' => 'devops_worker',
        'blocked' => 'developer_worker',
    ];

    // Which agent a task should be handed to when a human manually requeues
    // it. Deliberately narrower than AGENT_BY_LANE: 'published' is excluded
    // (nothing to redo once shipped) and 'todo' is included (a task whose
    // very first dispatch failed can still be requeued as the developer).
    public const RETRY_AGENT_BY_LANE = [
        'todo' => 'developer_worker',
        'in_progress' => 'developer_worker',
        'review' => 'tester_worker',
        'publishing' => 'devops_worker',
    ];

    public static function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function agentForLane(string $lane): ?string
    {
        return self::AGENT_BY_LANE[$lane] ?? null;
    }

    public static function retryAgentForLane(string $lane): ?string
    {
        return self::RETRY_AGENT_BY_LANE[$lane] ?? null;
    }

    public static function branchFor(int $taskId): string
    {
        return "agent/task-{$taskId}";
    }

    /**
     * Decide the next lane after a worker reports an outcome for a task
     * currently sitting in $currentLane. The worker's own "next" suggestion
     * is advisory only — this is the only place that decides the lane.
     *
     * @return array{lane:string, attempt:int, blocked:bool}
     */
    public static function decideAfterOutcome(string $currentLane, string $outcome, int $attempt, int $maxAttempts): array
    {
        if ($outcome === 'success') {
            $nextLane = match ($currentLane) {
                'in_progress' => 'review',
                'review' => 'publishing',
                'publishing' => 'published',
                default => $currentLane,
            };

            return ['lane' => $nextLane, 'attempt' => $attempt, 'blocked' => false];
        }

        if ($outcome === 'bug') {
            $nextAttempt = $attempt + 1;
            if ($nextAttempt > $maxAttempts) {
                return ['lane' => 'blocked', 'attempt' => $nextAttempt, 'blocked' => true];
            }

            return ['lane' => 'in_progress', 'attempt' => $nextAttempt, 'blocked' => false];
        }

        // 'blocked' / 'publish_blocked': the agent itself could not proceed.
        // Keep a human in the loop instead of guessing a next lane.
        return ['lane' => $currentLane, 'attempt' => $attempt, 'blocked' => false];
    }
}
