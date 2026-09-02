<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
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
            ->with(['project:id,name', 'task:id,title', 'user:id,name'])
            ->when(isset($data['project_id']), fn ($query) => $query->where('scope', 'project')->where('project_id', $data['project_id']))
            ->when(! isset($data['project_id']) && $scope === 'devteam', fn ($query) => $query->where('scope', 'devteam'))
            ->when(! isset($data['project_id']) && $scope === 'projects', fn ($query) => $query->where('scope', 'project'))
            ->latest()
            ->paginate($perPage);
    }
}
