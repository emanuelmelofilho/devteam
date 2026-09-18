<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /** Execution columns shown in the list; the patch can be large, so it only comes with show(). */
    private const EXECUTION_COLUMNS = [
        'id', 'task_id', 'agent', 'provider', 'attempt', 'status', 'model', 'started_at', 'finished_at', 'duration_ms',
        'total_tokens', 'estimated_cost', 'summary', 'solution', 'branch', 'commit_hash', 'commit_message', 'files',
        'metadata', 'error_type', 'error_message', 'prompt_version',
    ];

    public function index(Request $request)
    {
        $data = $request->validate([
            'scope' => 'nullable|in:devteam,projects',
            'project_id' => 'nullable|integer|exists:projects,id',
            'per_page' => 'nullable|integer|in:5,10,20,50',
        ]);

        $scope = $data['scope'] ?? 'devteam';
        $perPage = $data['per_page'] ?? 5;

        return ActivityLog::query()
            ->with([
                'project:id,name',
                'task:id,title',
                'user:id,name',
                'execution' => fn ($query) => $query->select(self::EXECUTION_COLUMNS)->selectRaw('CASE WHEN patch IS NULL THEN 0 ELSE 1 END as has_patch'),
            ])
            ->when(isset($data['project_id']), fn ($query) => $query->where('scope', 'project')->where('project_id', $data['project_id']))
            ->when(! isset($data['project_id']) && $scope === 'devteam', fn ($query) => $query->where('scope', 'devteam'))
            ->when(! isset($data['project_id']) && $scope === 'projects', fn ($query) => $query->where('scope', 'project'))
            ->latest()
            ->paginate($perPage);
    }

    public function show(ActivityLog $log)
    {
        return $log->load(['project:id,name', 'task:id,title', 'user:id,name', 'execution']);
    }
}
