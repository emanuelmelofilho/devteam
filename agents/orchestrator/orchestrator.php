<?php

// Entry point for the orchestrator: require this single file to load the
// whole state machine + task routing. Both are dependency-free PHP so they
// can be shared as-is between the Laravel backend (see
// backend/app/Services/OrchestratorService.php) and any other process that
// needs to reason about the workflow.

require_once __DIR__ . '/state_machine.php';
require_once __DIR__ . '/task_router.php';
