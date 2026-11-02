<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilterRobotLogsRequest;
use App\Models\RobotLog;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RobotLogExportController extends Controller
{
    public function __invoke(FilterRobotLogsRequest $request): StreamedResponse
    {
        $query = RobotLog::query()->filtered($request->validated());
        $lastId = (clone $query)->max('id') ?? 0;

        return response()->streamDownload(function () use ($query, $lastId): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, [
                'Record ID', 'Time ('.config('app.timezone').')', 'Robot ID', 'Source', 'Status',
                'Battery (%)', 'Detected count', 'Successful pickups', 'Failed pickups',
                'Plastic count', 'General count', 'Obstacles', 'Operating duration (seconds)',
                'Plastic bin level', 'General bin level', 'Waste category', 'Object',
                'Confidence (%)', 'Model', 'Snapshot URL', 'Remarks',
            ], escape: '');

            try {
                foreach ($query->where('id', '<=', $lastId)->latest()->orderByDesc('id')->lazy(500) as $log) {
                    $isTelemetry = $log->detection_source !== 'laptop_camera';
                    $row = [
                        $log->id, $log->created_at?->format('Y-m-d H:i:s'), $log->robot_id,
                        $log->detection_source ?? 'robot_sensor', $log->movement_status,
                        $isTelemetry ? $log->battery_level : null,
                        $isTelemetry ? $log->detected_trash_count : null,
                        $isTelemetry ? $log->successful_pickup_count : null,
                        $isTelemetry ? $log->failed_pickup_count : null,
                        $isTelemetry ? $log->plastic_waste_count : null,
                        $isTelemetry ? $log->general_waste_count : null,
                        $isTelemetry ? $log->obstacle_count : null,
                        $isTelemetry ? $log->operating_duration : null,
                        $isTelemetry ? $log->plastic_bin_level : null,
                        $isTelemetry ? $log->general_bin_level : null,
                        $log->last_detected_trash_type, $log->detected_object_label,
                        $log->detection_confidence, $log->classification_model,
                        $log->camera_snapshot_url, $log->remarks,
                    ];
                    fputcsv($stream, array_map($this->spreadsheetCell(...), $row), escape: '');
                }
            } finally {
                fclose($stream);
            }
        }, 'robot-activity-'.now()->format('Y-m-d-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function spreadsheetCell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        // CSV quoting alone does not prevent spreadsheet formula execution.
        return preg_match('/^\s*[=+@-]|^[\t\r\n]/u', $text) === 1 ? "'".$text : $text;
    }
}
