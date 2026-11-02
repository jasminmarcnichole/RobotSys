<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilterRobotLogsRequest;
use App\Services\RobotWorkspaceInsights;
use Illuminate\Http\JsonResponse;

class RobotInsightsController extends Controller
{
    public function __construct(private RobotWorkspaceInsights $insights) {}

    public function __invoke(FilterRobotLogsRequest $request): JsonResponse
    {
        return response()->json($this->insights->snapshot($request->validated('robot_id') ?? 'ROBOT-001'))
            ->header('Cache-Control', 'no-store, private');
    }
}
