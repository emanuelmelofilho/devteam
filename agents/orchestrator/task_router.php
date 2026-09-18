<?php

namespace Agents\Orchestrator;

/**
 * Maps an agent identifier to the RabbitMQ queue that carries its work, and
 * to the short role name used in metrics and prompt files. Single source of
 * truth shared by the Laravel dispatcher (AgentQueueService) and documented
 * here for the legacy standalone PHP workers in agents/workers/.
 */
final class TaskRouter
{
    public const QUEUE_BY_AGENT = [
        'developer_worker' => 'tasks_developer',
        'tester_worker' => 'tasks_tester',
        'devops_worker' => 'tasks_devops',
    ];

    public const ROLE_BY_AGENT = [
        'developer_worker' => 'developer',
        'tester_worker' => 'tester',
        'devops_worker' => 'devops',
    ];

    public static function queueFor(string $agent): ?string
    {
        return self::QUEUE_BY_AGENT[$agent] ?? null;
    }

    public static function roleFor(string $agent): ?string
    {
        return self::ROLE_BY_AGENT[$agent] ?? null;
    }
}
