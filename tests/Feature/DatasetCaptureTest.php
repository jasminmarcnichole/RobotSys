<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DatasetCaptureTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = storage_path('framework/testing/dataset-capture-'.uniqid());
        File::ensureDirectoryExists($this->tempDir);
        config([
            'services.robot.dataset_capture_dir' => $this->tempDir,
            'services.robot.camera_snapshot_url' => 'http://192.168.4.2/capture',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    public function test_dataset_capture_page_requires_admin(): void
    {
        $this->get(route('robot.dashboard.dataset-capture'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())
            ->get(route('robot.dashboard.dataset-capture'))
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('robot.dashboard.dataset-capture'))
            ->assertOk()
            ->assertSee('Remote Dataset Capture')
            ->assertSee('Background (Empty)')
            ->assertSee('Plastic Bottle');
    }

    public function test_admin_can_capture_background_and_trash_photos_remotely_and_undo(): void
    {
        $admin = User::factory()->admin()->create();
        $fakeImage = UploadedFile::fake()->image('frame.jpg', 800, 600);
        $jpegBytes = $fakeImage->getContent();

        Http::fake([
            'http://192.168.4.2/capture' => Http::response($jpegBytes, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->actingAs($admin)
            ->getJson(route('dashboard.camera.dataset-capture.index'))
            ->assertOk()
            ->assertJsonPath('total', 0);

        $bgResponse = $this->actingAs($admin)
            ->postJson(route('dashboard.camera.dataset-capture.store'), ['category' => 'background'])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('capture.category', 'background')
            ->assertJsonPath('capture.width', 800)
            ->assertJsonPath('capture.height', 600)
            ->assertJsonPath('counts.background', 1)
            ->assertJsonPath('total', 1);

        $bgFilename = (string) $bgResponse->json('capture.filename');
        $bgJpg = $this->tempDir.DIRECTORY_SEPARATOR.'background'.DIRECTORY_SEPARATOR.$bgFilename;
        $bgTxt = preg_replace('/\.jpg$/', '.txt', $bgJpg);
        $this->assertFileExists($bgJpg);
        $this->assertFileExists($bgTxt);
        $this->assertSame('', File::get($bgTxt));

        $this->actingAs($admin)
            ->postJson(route('dashboard.camera.dataset-capture.store'), ['category' => 'plastic_bottle'])
            ->assertCreated()
            ->assertJsonPath('counts.plastic_bottle', 1)
            ->assertJsonPath('total', 2);

        $this->actingAs($admin)
            ->deleteJson(route('dashboard.camera.dataset-capture.destroy'), [
                'category' => 'background',
                'filename' => $bgFilename,
            ])
            ->assertOk()
            ->assertJsonPath('counts.background', 0)
            ->assertJsonPath('total', 1);

        $this->assertFileDoesNotExist($bgJpg);
        $this->assertFileDoesNotExist($bgTxt);
    }

    public function test_dataset_capture_reports_camera_error_on_invalid_frame(): void
    {
        $admin = User::factory()->admin()->create();

        Http::fake([
            'http://192.168.4.2/capture' => Http::response('not-an-image', 200, ['Content-Type' => 'text/plain']),
        ]);

        $this->actingAs($admin)
            ->postJson(route('dashboard.camera.dataset-capture.store'), ['category' => 'trash'])
            ->assertStatus(502)
            ->assertJsonPath('ok', false);
    }

    public function test_dataset_capture_rejects_invalid_categories_and_path_traversal(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson(route('dashboard.camera.dataset-capture.store'), ['category' => '../etc'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category');

        $this->actingAs($admin)
            ->deleteJson(route('dashboard.camera.dataset-capture.destroy'), [
                'category' => 'background',
                'filename' => '../../.env',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('filename');
    }
}
