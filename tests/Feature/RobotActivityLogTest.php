<?php

namespace Tests\Feature;

use App\Models\RobotLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RobotActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_date_range_includes_both_days_and_combines_with_existing_filters(): void
    {
        $attributes = ['robot_id' => 'ROBOT-002', 'detection_source' => 'laptop_camera', 'last_detected_trash_type' => 'Plastic Waste'];
        $first = RobotLog::factory()->create([...$attributes, 'created_at' => '2026-09-01 00:00:00']);
        $last = RobotLog::factory()->create([...$attributes, 'created_at' => '2026-09-02 23:59:59']);
        RobotLog::factory()->create([...$attributes, 'created_at' => '2026-09-03 00:00:00']);
        RobotLog::factory()->create([...$attributes, 'created_at' => '2026-08-31 23:59:59']);
        RobotLog::factory()->create([...$attributes, 'robot_id' => 'ROBOT-003', 'created_at' => '2026-09-02 12:00:00']);

        $response = $this->actingAs(User::factory()->admin()->create())->get(route('robot.dashboard.logs', [
            'robot_id' => 'ROBOT-002', 'source' => 'laptop_camera', 'trash_type' => 'Plastic',
            'from' => '2026-09-01', 'to' => '2026-09-02',
        ]));

        $response->assertOk()->assertSee('Export CSV')->assertSee('evidenceDialog', false);
        $this->assertSame([$last->id, $first->id], $response->viewData('logs')->pluck('id')->all());
    }

    public function test_telemetry_filter_includes_legacy_null_sources_only(): void
    {
        $legacy = RobotLog::factory()->create(['detection_source' => null]);
        $sensor = RobotLog::factory()->create();
        RobotLog::factory()->create(['detection_source' => 'robot_camera']);
        RobotLog::factory()->create(['detection_source' => 'laptop_camera']);

        $response = $this->actingAs(User::factory()->admin()->create())->get('/dashboard/logs?source=robot_sensor');

        $response->assertOk();
        $this->assertSame([$sensor->id, $legacy->id], $response->viewData('logs')->pluck('id')->all());
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected_without_server_errors(array $filters, string $field): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('robot.dashboard.logs', $filters))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->getJson(route('robot.dashboard.logs.export', $filters))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidFilters(): array
    {
        return [
            'array robot' => [['robot_id' => ['ROBOT-001']], 'robot_id'],
            'array category' => [['trash_type' => ['Plastic']], 'trash_type'],
            'unknown source' => [['source' => 'unknown'], 'source'],
            'invalid date' => [['from' => '2026-02-30'], 'from'],
            'array start with end' => [['from' => ['2026-09-01'], 'to' => '2026-09-02'], 'from'],
            'null byte start with end' => [['from' => "2026-09-01\0junk", 'to' => '2026-09-02'], 'from'],
            'reversed range' => [['from' => '2026-09-03', 'to' => '2026-09-01'], 'to'],
            'invalid page size' => [['per_page' => 10000], 'per_page'],
            'array page' => [['page' => [2]], 'page'],
        ];
    }

    public function test_invalid_browser_filters_redirect_to_a_working_form_with_errors(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->from('/dashboard/logs?from=invalid')
            ->get('/dashboard/logs?from=invalid&robot_id[]=invalid')
            ->assertRedirect(route('robot.dashboard.logs'))->assertSessionHasErrors(['from', 'robot_id']);

        $this->get('/dashboard/logs')->assertOk()->assertSee('Enter a valid start date');
    }

    public function test_page_size_and_pagination_preserve_filters(): void
    {
        RobotLog::factory()->count(26)->create(['created_at' => '2026-09-02 12:00:00']);

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get('/dashboard/logs?per_page=25&from=2026-09-01');

        $response->assertOk()->assertSee('per_page=25', false)->assertSee('from=2026-09-01', false);
        $this->assertCount(25, $response->viewData('logs'));
    }

    public function test_zero_filters_are_not_ignored(): void
    {
        $match = RobotLog::factory()->create(['robot_id' => '0', 'last_detected_trash_type' => '0']);
        RobotLog::factory()->create(['robot_id' => '0', 'last_detected_trash_type' => 'Plastic']);
        RobotLog::factory()->create(['robot_id' => 'OTHER', 'last_detected_trash_type' => '0']);

        $response = $this->actingAs(User::factory()->admin()->create())->get('/dashboard/logs?robot_id=0&trash_type=0');

        $response->assertOk();
        $this->assertSame([$match->id], $response->viewData('logs')->pluck('id')->all());
        $export = $this->get('/dashboard/logs/export?robot_id=0&trash_type=0');
        $export->assertOk();
        $this->assertCount(2, $this->csvRows($export->streamedContent()));
    }

    public function test_out_of_range_page_offers_navigation_without_claiming_there_is_no_activity(): void
    {
        RobotLog::factory()->count(26)->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get('/dashboard/logs?page=3&source=robot_sensor')
            ->assertOk()->assertSee('No records on this page.')
            ->assertSee('Return to first page')->assertSee('source=robot_sensor', false)
            ->assertDontSee('No activity recorded yet.')->assertDontSee('No activity matches these filters.');
    }

    public function test_csv_exports_all_filtered_pages_and_neutralizes_spreadsheet_formulas(): void
    {
        RobotLog::factory()->count(26)->create([
            'robot_id' => 'ROBOT-002', 'created_at' => '2026-09-02 12:00:00',
            'remarks' => '=HYPERLINK("https://example.test","open")',
        ]);
        RobotLog::factory()->create(['robot_id' => 'EXCLUDED']);

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get('/dashboard/logs/export?robot_id=ROBOT-002&from=2026-09-02&to=2026-09-02&page=2');

        $response->assertOk()->assertDownload();
        $rows = $this->csvRows($response->streamedContent());
        $this->assertCount(27, $rows);
        $remarksIndex = array_search('Remarks', $rows[0], true);
        $this->assertNotFalse($remarksIndex);
        $this->assertSame("'=HYPERLINK(\"https://example.test\",\"open\")", $rows[1][$remarksIndex]);
        $this->assertNotContains('EXCLUDED', array_column($rows, 2));
    }

    public function test_browser_observations_do_not_export_copied_physical_counters(): void
    {
        RobotLog::factory()->create(['detection_source' => 'laptop_camera', 'detected_trash_count' => 9, 'successful_pickup_count' => 8]);

        $response = $this->actingAs(User::factory()->admin()->create())->get('/dashboard/logs/export');

        $response->assertOk();
        $rows = $this->csvRows($response->streamedContent());
        $record = array_combine($rows[0], $rows[1]);
        $this->assertSame('', $record['Battery (%)']);
        $this->assertSame('', $record['Detected count']);
        $this->assertSame('', $record['Successful pickups']);
    }

    public function test_empty_csv_contains_headers_and_export_is_admin_only(): void
    {
        $this->get('/dashboard/logs/export')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get('/dashboard/logs/export')->assertForbidden();
        $response = $this->actingAs(User::factory()->admin()->create())->get('/dashboard/logs/export');

        $response->assertOk();
        $this->assertCount(1, $this->csvRows($response->streamedContent()));
        $this->getJson('/dashboard/logs/export?from=bad')->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    /** @return list<array<int, string|null>> */
    private function csvRows(string $content): array
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, ltrim($content, "\xEF\xBB\xBF"));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
