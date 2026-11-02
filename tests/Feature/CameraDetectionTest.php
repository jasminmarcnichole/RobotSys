<?php

namespace Tests\Feature;

use App\Models\RobotLog;
use App\Models\User;
use Database\Factories\RobotLogFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CameraDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_camera_observations_do_not_replace_robot_telemetry(): void
    {
        $telemetry = RobotLogFactory::new()->create(['battery_level' => 82, 'created_at' => now()->subMinute()]);
        RobotLogFactory::new()->create(['detection_source' => 'laptop_camera', 'movement_status' => 'Camera Detection']);

        $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('dashboard.robot.latest', ['robot_id' => 'ROBOT-001']))
            ->assertOk()
            ->assertJsonPath('id', $telemetry->id)
            ->assertJsonPath('battery_level', '82.00');
    }

    public function test_reviewed_detection_saves_evidence_without_inventing_collection_counts(): void
    {
        Storage::fake('public');
        RobotLogFactory::new()->create(['plastic_waste_count' => 3, 'battery_level' => 82]);

        $response = $this->actingAs(User::factory()->admin()->create())
            ->postJson('/dashboard/api/camera/detections', $this->detectionPayload() + [
                'plastic_waste_count' => 999,
                'battery_level' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.detection_source', 'laptop_camera')
            ->assertJsonPath('data.classification_model', 'TensorFlow Lite / EfficientDet Lite0')
            ->assertJsonPath('data.detected_object_label', 'bottle');

        Storage::disk('public')->assertExists($response->json('data.camera_snapshot_path'));
        $this->assertSame(3, (int) RobotLog::find($response->json('data.id'))->plastic_waste_count);
        $this->getJson(route('dashboard.robot.latest'))->assertJsonPath('battery_level', '82.00');
    }

    public function test_detection_requires_an_explicit_category_and_complete_evidence(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/dashboard/api/camera/detections', ['robot_id' => 'ROBOT-001'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['last_detected_trash_type', 'camera_snapshot', 'detected_object_label', 'detection_confidence', 'detection_bbox']);
    }

    public function test_saved_evidence_records_the_selected_detection_model(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->admin()->create());

        foreach (['efficientdet_lite2' => 'EfficientDet Lite2', 'efficientdet_lite0' => 'EfficientDet Lite0', 'waste_primary_20261026' => 'RobotSys Waste (Experimental)'] as $model => $name) {
            $this->postJson('/dashboard/api/camera/detections', $this->detectionPayload() + [
                'detection_model' => $model,
                'classification_model' => 'Untrusted model name',
            ])->assertCreated()->assertJsonPath('data.classification_model', 'TensorFlow Lite / '.$name);
        }
    }

    public function test_unknown_detection_models_are_rejected_before_storing_evidence(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->admin()->create());

        foreach (['made-up-model', '', ['efficientdet_lite2']] as $model) {
            $this->postJson('/dashboard/api/camera/detections', $this->detectionPayload() + [
                'detection_model' => $model,
            ])->assertUnprocessable()->assertJsonValidationErrors('detection_model');
        }

        $this->assertDatabaseCount('robot_logs', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_camera_defaults_to_lite2_and_offers_a_faster_model(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('robot.dashboard.camera'))->assertOk()
            ->assertSee('id="cameraModelSelect"', false)
            ->assertSee('EfficientDet Lite2')
            ->assertSee('EfficientDet Lite0');

        $this->assertSame('efficientdet_lite2', config('services.robot.vision_default'));
        foreach (config('services.robot.vision_models') as $model) {
            $this->assertFileExists(public_path($model['path']));
        }
    }

    public function test_trained_waste_model_has_verified_bytes_and_explicit_scope(): void
    {
        $model = config('services.robot.vision_models.waste_primary_20261026');

        $this->assertSame(256, $model['input_size']);
        $this->assertFalse($model['experimental']);
        $this->assertSame(['plastic_bottle', 'other_plastic', 'plastic_wrapper', 'paper', 'metal_can', 'aluminum_can'], $model['labels']);
        $this->assertSame('31eb829e80a27c490dd951172eb84ce419badf0dabf74fdcb5473dd8d9d615ba', $model['sha256']);
        $this->assertSame($model['sha256'], hash_file('sha256', public_path($model['path'])));

        config(['services.robot.vision_default' => 'waste_primary_20261026']);
        foreach (['/dashboard/camera', '/dashboard/control'] as $page) {
            $this->actingAs(User::factory()->admin()->create())->get($page)->assertOk()
                ->assertSee('defaultModel: "waste_primary_20261026"', false)
                ->assertSee('value="waste_primary_20261026" selected', false)
                ->assertSee('EfficientDet Lite2');
        }
    }

    public function test_unknown_default_model_configuration_falls_back_to_the_existing_detector(): void
    {
        config(['services.robot.vision_default' => 'missing-model']);

        $this->actingAs(User::factory()->admin()->create())->get('/dashboard/camera')
            ->assertOk()->assertSee('defaultModel: "efficientdet_lite2"', false);
    }

    public function test_detection_rejects_forged_images_and_does_not_write_a_log(): void
    {
        Storage::fake('public');

        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/dashboard/api/camera/detections', array_replace($this->detectionPayload(), [
                'camera_snapshot' => 'data:image/png;base64,'.base64_encode('not an image'),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('camera_snapshot');

        $this->assertDatabaseCount('robot_logs', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_detection_rejects_out_of_bounds_boxes_and_invalid_categories(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/dashboard/api/camera/detections', array_replace($this->detectionPayload(), [
                'last_detected_trash_type' => 'Unknown material',
                'detection_confidence' => 120,
                'detection_bbox' => ['x' => -1, 'y' => 0, 'width' => 0, 'height' => 20],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['last_detected_trash_type', 'detection_confidence', 'detection_bbox.x', 'detection_bbox.width']);
    }

    public function test_camera_history_survives_a_page_reload(): void
    {
        RobotLogFactory::new()->create([
            'detection_source' => 'laptop_camera',
            'detected_object_label' => 'bottle',
            'last_detected_trash_type' => 'Glass Waste',
            'detection_confidence' => 87,
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('robot.dashboard.camera'))
            ->assertOk()
            ->assertViewHas('cameraDetections', fn ($detections): bool => $detections->count() === 1);
    }

    public function test_only_admins_can_save_reviewed_detections(): void
    {
        $this->postJson('/dashboard/api/camera/detections', $this->detectionPayload())->assertUnauthorized();
        $this->actingAs(User::factory()->create())
            ->postJson('/dashboard/api/camera/detections', $this->detectionPayload())
            ->assertForbidden();
    }

    public function test_esp32_camera_is_unavailable_until_configured(): void
    {
        config(['services.robot.camera_snapshot_url' => null]);
        Http::fake();

        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/dashboard/api/camera/snapshot')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_esp32_snapshot_uses_only_the_configured_address(): void
    {
        config(['services.robot.camera_snapshot_url' => 'http://192.168.1.50/capture']);
        $image = UploadedFile::fake()->image('frame.jpg', 320, 240)->getContent();
        Http::fake(['http://192.168.1.50/capture' => Http::response($image, 200, ['Content-Type' => 'image/jpeg'])]);

        $this->actingAs(User::factory()->admin()->create())
            ->get('/dashboard/api/camera/snapshot?url=http://untrusted.example/capture')
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertContent($image);

        Http::assertSent(fn ($request): bool => $request->url() === 'http://192.168.1.50/capture');
    }

    public function test_esp32_camera_failures_are_reported_without_forwarding_invalid_content(): void
    {
        config(['services.robot.camera_snapshot_url' => 'http://192.168.1.50/capture']);
        Http::fake(['*' => Http::response('<html>camera error</html>')]);

        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/dashboard/api/camera/snapshot')->assertStatus(502);
    }

    public function test_esp32_live_preview_is_configured_separately_from_the_snapshot_proxy(): void
    {
        config([
            'services.robot.camera_snapshot_url' => 'http://192.168.1.50/capture',
            'services.robot.camera_stream_url' => 'http://192.168.1.50:81/stream',
        ]);
        Http::fake();

        foreach (['/dashboard/camera', '/dashboard/control'] as $page) {
            $this->actingAs(User::factory()->admin()->create())->get($page)
                ->assertOk()
                ->assertSee('id="cameraStream"', false)
                ->assertSee('id="retryEspStreamButton"', false)
                ->assertSee('cameraStream: '.json_encode('http://192.168.1.50:81/stream'), false)
                ->assertSee('cameraSnapshot: '.json_encode(route('dashboard.camera.snapshot')), false);
        }

        Http::assertNothingSent();
    }

    public function test_detection_box_must_fit_inside_saved_image(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/dashboard/api/camera/detections', array_replace($this->detectionPayload(), [
                'detection_bbox' => ['x' => 310, 'y' => 0, 'width' => 100, 'height' => 20],
            ]))->assertUnprocessable()->assertJsonValidationErrors('detection_bbox');
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_detection_monitor_lists_browser_and_robot_results_for_only_the_selected_robot(): void
    {
        RobotLogFactory::new()->create(['robot_id' => 'ROBOT-002', 'detection_source' => 'robot_camera']);
        RobotLogFactory::new()->create(['detection_source' => 'robot_sensor']);
        RobotLogFactory::new()->create(['detection_source' => 'laptop_camera']);
        $edge = RobotLogFactory::new()->create(['detection_source' => 'robot_camera', 'detected_object_label' => 'bottle']);

        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/dashboard/api/camera/detections?robot_id=ROBOT-001')
            ->assertOk()->assertJsonCount(2)->assertJsonPath('0.id', $edge->id);
    }

    public function test_snapshot_polling_does_not_consume_the_detection_save_limit(): void
    {
        Storage::fake('public');
        config(['services.robot.camera_snapshot_url' => 'http://192.168.1.50/capture']);
        $image = UploadedFile::fake()->image('frame.jpg', 320, 240)->getContent();
        Http::fake(['*' => Http::response($image, 200, ['Content-Type' => 'image/jpeg'])]);
        $this->actingAs(User::factory()->admin()->create());

        for ($index = 0; $index < 35; $index++) {
            $this->get('/dashboard/api/camera/snapshot')->assertOk();
        }

        $this->postJson('/dashboard/api/camera/detections', $this->detectionPayload())->assertCreated();
    }

    public function test_recognizable_but_undecodable_image_headers_are_rejected(): void
    {
        Storage::fake('public');
        $header = substr(UploadedFile::fake()->image('broken.png', 320, 240)->getContent(), 0, 33);
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/dashboard/api/camera/detections', array_replace($this->detectionPayload(), [
                'camera_snapshot' => 'data:image/png;base64,'.base64_encode($header),
            ]))->assertUnprocessable()->assertJsonValidationErrors('camera_snapshot');

        config(['services.robot.camera_snapshot_url' => 'http://192.168.1.50/capture']);
        Http::fake(['*' => Http::response($header, 200, ['Content-Type' => 'image/png'])]);
        $this->getJson('/dashboard/api/camera/snapshot')->assertStatus(502);
        $this->assertDatabaseCount('robot_logs', 0);
    }

    public function test_detection_worker_discards_bitmaps_and_callbacks_from_stopped_sessions(): void
    {
        $this->runJavaScript(<<<'JS'
import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';
const { TensorFlowLiteVision } = await import(pathToFileURL(`${process.argv[2]}/tflite-vision.js`));
const timers = new Map();
let timerId = 0;
globalThis.setTimeout = (callback) => { timers.set(++timerId, callback); return timerId; };
globalThis.clearTimeout = (id) => timers.delete(id);
class Worker {
    static instances = [];
    constructor() { this.messages = []; Worker.instances.push(this); }
    postMessage(message) {
        if (this.fail) throw new Error('Transfer failed');
        this.messages.push(message);
        if (message.type === 'init') queueMicrotask(() => this.onmessage({ data: { id: message.id } }));
    }
    terminate() { this.terminated = true; }
}
globalThis.Worker = Worker;
globalThis.window = { Worker, OffscreenCanvas: class {}, createImageBitmap() {} };
const vision = new TensorFlowLiteVision('/model.tflite');
await vision.load();
const firstWorker = vision.worker;
let resolveBitmap;
globalThis.createImageBitmap = () => new Promise((resolve) => { resolveBitmap = resolve; });
const stoppedDetection = vision.detect({});
const stoppedAssertion = assert.rejects(stoppedDetection, /Detection stopped/);
await Promise.resolve();
vision.dispose();
await vision.load();
const secondWorker = vision.worker;
const oldBitmap = { close() { this.closed = true; } };
resolveBitmap(oldBitmap);
await stoppedAssertion;
assert.equal(oldBitmap.closed, true);
assert.equal(firstWorker.terminated, true);
assert.deepEqual(secondWorker.messages.map((message) => message.type), ['init']);
assert.equal(vision.requests.size, 0);

globalThis.createImageBitmap = async () => ({ close() { this.closed = true; } });
const detection = vision.detect({});
await Promise.resolve();
await Promise.resolve();
const pending = secondWorker.messages.at(-1);
assert.equal(pending.type, 'detect');
firstWorker.onmessage({ data: { id: pending.id, detections: ['obsolete'] } });
assert.equal(vision.requests.has(pending.id), true);
secondWorker.onmessage({ data: { id: pending.id, detections: ['current'] } });
assert.deepEqual((await detection).detections, ['current']);

const timedDetection = vision.detect({});
const timedAssertion = assert.rejects(timedDetection, /Detection stopped/);
await Promise.resolve();
await Promise.resolve();
const obsoleteTimeout = [...timers.values()][0];
vision.dispose();
await timedAssertion;
await vision.load();
const thirdWorker = vision.worker;
obsoleteTimeout();
assert.equal(vision.worker, thirdWorker);
assert.equal(thirdWorker.terminated, undefined);

const failedBitmap = { close() { this.closed = true; } };
globalThis.createImageBitmap = async () => failedBitmap;
thirdWorker.fail = true;
await assert.rejects(vision.detect({}), /Transfer failed/);
assert.equal(failedBitmap.closed, true);
assert.equal(vision.requests.size, 0);
assert.equal(timers.size, 0);
thirdWorker.fail = false;
const timeoutDetection = vision.detect({});
const timeoutAssertion = assert.rejects(timeoutDetection, /Detection timed out/);
await Promise.resolve();
await Promise.resolve();
[...timers.values()][0]();
await timeoutAssertion;
assert.equal(vision.worker, null);
assert.equal(vision.requests.size, 0);
assert.equal(timers.size, 0);
console.log('worker lifecycle verified');
JS);
    }

    public function test_camera_requires_new_frames_and_clears_stale_live_results(): void
    {
        $this->runJavaScript(<<<'JS'
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createContext, SourceTextModule, SyntheticModule } from 'node:vm';
const source = await readFile(`${process.argv[2]}/robot-camera.js`, 'utf8');
const uiSource = await readFile(`${process.argv[2]}/robot-ui.js`, 'utf8');
const flush = async () => { for (let index = 0; index < 30; index++) await Promise.resolve(); };
async function harness(callbackSupport = true, cameraStream = null, protocol = 'http:') {
    let now = 0, sequence = 0;
    const timers = new Map(), callbacks = new Map(), elements = new Map();
    class Element {
        constructor() {
            this.value = ''; this.style = {}; this.events = new Map(); this.children = [];
            this.hiddenClasses = new Set();
            this.classList = { add: (value) => this.hiddenClasses.add(value), remove: (value) => this.hiddenClasses.delete(value) };
        }
        addEventListener(name, callback) { this.events.set(name, callback); }
        querySelector() { return null; }
        replaceChildren(...children) { this.children = children; }
        removeAttribute(name) { delete this[name]; }
        getBoundingClientRect() { return { width: 640, height: 480 }; }
        getContext() { return { drawImage() {}, scale() {}, clearRect() {}, strokeRect() {}, fillRect() {}, fillText() {}, measureText() { return { width: 50 }; } }; }
        toDataURL() { return 'data:image/jpeg;base64,fixture'; }
    }
    const get = (id) => {
        if (id === 'cameraDetectionHistory') return null;
        if (!elements.has(id)) elements.set(id, new Element());
        return elements.get(id);
    };
    const video = get('cameraFeed');
    Object.assign(video, { readyState: 2, videoWidth: 640, videoHeight: 480, currentTime: 1, play: async () => {} });
    if (callbackSupport) {
        video.requestVideoFrameCallback = (callback) => { callbacks.set(++sequence, callback); return sequence; };
        video.cancelVideoFrameCallback = (id) => callbacks.delete(id);
    }
    get('cameraThreshold').value = '50';
    const document = { getElementById: get, createElement: () => new Element(), addEventListener() {}, querySelector() { return null; } };
    const track = { stop() {}, addEventListener() {}, getSettings() { return {}; } };
    const stream = { getTracks: () => [track], getVideoTracks: () => [track] };
    const detectors = [];
    class Vision {
        constructor() { this.calls = 0; detectors.push(this); }
        async load() {}
        dispose() {}
        async detect() {
            this.calls++;
            if (this.pending) return this.pending;
            return { inferenceMs: 5, detections: [{ boundingBox: { originX: 10, originY: 20, width: 30, height: 40 }, categories: [{ categoryName: 'bottle', score: 0.8 }] }] };
        }
    }
    const saved = [];
    const context = createContext({
        console, document, window: { addEventListener() {}, location: { protocol } }, performance: { now: () => now }, URL,
        navigator: { mediaDevices: { getUserMedia: async () => stream, enumerateDevices: async () => [] } },
        AbortController, AbortSignal, DOMException, Option: class { constructor(label, value) { this.label = label; this.value = value; } },
        ResizeObserver: class { observe() {} },
        setTimeout: (callback, delay) => { timers.set(++sequence, { callback, delay }); return sequence; },
        clearTimeout: (id) => timers.delete(id),
        createImageBitmap: async () => ({ width: 640, height: 480, close() {} }),
        fetch: async (url, options) => {
            if (options.method === 'POST') { saved.push(JSON.parse(options.body)); return { ok: true, json: async () => ({ success: true, data: { id: 1 } }) }; }
            return { ok: true, blob: async () => ({}) };
        },
    });
    const visionModule = new SyntheticModule(['TensorFlowLiteVision'], function () { this.setExport('TensorFlowLiteVision', Vision); }, { context });
    const ui = new SourceTextModule(uiSource, { context });
    const camera = new SourceTextModule(source, { context });
    await camera.link((name) => name === './tflite-vision' ? visionModule : ui);
    await camera.evaluate();
    camera.namespace.setupCamera({ robotId: 'ROBOT-001', vision: { defaultModel: 'efficientdet_lite2', models: { efficientdet_lite2: { url: '/model', inputSize: 448 } } }, urls: { cameraSnapshot: '/snapshot', cameraStream, log: '/save' } });
    return {
        get, video, timers, callbacks, detector: detectors[0], saved,
        click: async (id) => { await get(id).events.get('click')(); await flush(); },
        frame: async () => { const callback = [...callbacks.values()][0]; assert.ok(callback); callbacks.clear(); callback(); await flush(); },
        timeout: async (delay) => { const [id, timer] = [...timers].find(([, timer]) => timer.delay === delay); timers.delete(id); timer.callback(); await flush(); },
        advance: (duration) => { now += duration; },
    };
}
const camera = await harness();
await camera.click('startCameraButton');
assert.equal(camera.detector.calls, 0, 'wait for a freshly presented video frame');
await camera.frame();
assert.equal(camera.get('cameraObjectLabel').textContent, 'bottle');
await camera.click('captureDetectionButton');
camera.get('cameraTrashType').value = 'Plastic Waste';
camera.get('cameraTrashType').events.get('change')();
assert.equal(camera.get('saveCameraDetectionButton').disabled, false);
await camera.click('saveCameraDetectionButton');
assert.equal(camera.saved[0].capture_source, 'browser_camera');
assert.equal(camera.saved[0].detected_object_label, 'bottle');
await camera.click('liveDetectionButton');
assert.equal(camera.get('cameraObjectCount').textContent, 0);
assert.equal(camera.get('captureDetectionButton').disabled, true);
assert.equal(camera.get('saveCameraDetectionButton').disabled, true);
await camera.timeout(5000);
assert.equal(camera.get('camera_status').textContent, 'Detection paused');
assert.match(camera.get('cameraMessage').textContent, /No new camera frame/);
assert.equal(camera.callbacks.size, 0);
await camera.click('liveDetectionButton');
await camera.frame();
await camera.timeout(650);
assert.equal(camera.callbacks.size, 1);
await camera.click('stopCameraButton');
assert.equal(camera.callbacks.size, 0);
assert.equal(camera.timers.size, 0);
assert.equal(camera.get('camera_status').textContent, 'Camera off');

const slow = await harness();
await slow.click('startCameraButton');
let resolveResult;
slow.detector.pending = new Promise((resolve) => { resolveResult = resolve; });
await slow.frame();
slow.advance(5001);
resolveResult({ inferenceMs: 5001, detections: [] });
await flush();
assert.equal(slow.get('camera_status').textContent, 'Detection paused');
assert.match(slow.get('cameraMessage').textContent, /Detection took too long/);
assert.equal(slow.get('cameraObjectCount').textContent, 0);

const stopped = await harness();
await stopped.click('startCameraButton');
stopped.detector.pending = new Promise((resolve) => { resolveResult = resolve; });
await stopped.frame();
await stopped.click('stopCameraButton');
resolveResult({ inferenceMs: 5, detections: [] });
await flush();
assert.equal(stopped.get('camera_status').textContent, 'Camera off');
assert.equal(stopped.timers.size, 0);

const fallback = await harness(false);
await fallback.click('startCameraButton');
assert.equal(fallback.detector.calls, 1);
await fallback.timeout(650);
assert.equal(fallback.detector.calls, 1, 'do not reanalyze a frozen fallback frame');
fallback.advance(5001);
await fallback.timeout(650);
assert.match(fallback.get('cameraMessage').textContent, /No new camera frame/);
assert.equal(fallback.get('cameraObjectCount').textContent, 0);

const image = await harness();
await image.get('cameraImageInput').events.get('change')({ target: { files: [{ type: 'image/jpeg', size: 100 }] } });
assert.equal(image.detector.calls, 1);
assert.equal(image.callbacks.size, 0);
assert.equal(image.get('camera_status').textContent, 'Review frame');
const esp = await harness();
await esp.click('startEspCameraButton');
assert.equal(esp.detector.calls, 1);
assert.equal(esp.callbacks.size, 0);
assert.equal(esp.get('cameraObjectLabel').textContent, 'bottle');
assert.equal(esp.get('cameraPreviewStatus').textContent, 'Snapshot preview');
await esp.click('stopCameraButton');

const live = await harness(true, 'http://camera.example:81/stream');
await live.click('startEspCameraButton');
const liveImage = live.get('cameraStream');
const firstLoad = liveImage.onload;
assert.equal(live.get('cameraPreviewStatus').textContent, 'Connecting live stream');
assert.equal(liveImage.src, 'http://camera.example:81/stream');
firstLoad();
assert.equal(live.get('cameraPreviewStatus').textContent, 'Live MJPEG stream');
assert.equal(liveImage.hiddenClasses.has('hidden'), false);
assert.equal(live.get('cameraSnapshot').hiddenClasses.has('hidden'), true);
await live.timeout(1000);
assert.equal(live.detector.calls, 2);
assert.equal(live.get('cameraSnapshot').hiddenClasses.has('hidden'), true, 'inference must not replace live playback');
let finishInference;
live.detector.pending = new Promise((resolve) => { finishInference = resolve; });
await live.timeout(1000);
assert.equal(liveImage.hiddenClasses.has('hidden'), false, 'preview continues while inference is pending');
live.advance(5001);
finishInference({ inferenceMs: 5001, detections: [] });
await flush();
assert.equal(live.get('camera_status').textContent, 'Detection paused');
assert.equal(liveImage.hiddenClasses.has('hidden'), false, 'inference failure must not stop an active stream');
live.detector.pending = null;
await live.click('liveDetectionButton');
assert.equal(live.get('cameraPreviewStatus').textContent, 'Live MJPEG stream');
await live.click('captureDetectionButton');
assert.equal(liveImage.src, undefined, 'review releases the camera stream');
assert.equal(live.get('cameraPreviewStatus').textContent, 'Review frame');
assert.equal(live.get('cameraSnapshot').hiddenClasses.has('hidden'), false);
firstLoad();
assert.equal(live.get('cameraPreviewStatus').textContent, 'Review frame', 'late stream callbacks cannot replace a review');
await live.click('liveDetectionButton');
liveImage.onload();
await live.click('stopCameraButton');
assert.equal(liveImage.src, undefined);
assert.equal(liveImage.onload, null);
assert.equal(live.timers.size, 0);
assert.equal(live.get('cameraPreviewStatus').textContent, 'Inactive');

const unavailable = await harness(true, 'http://camera.example:81/stream');
await unavailable.click('startEspCameraButton');
const expiredLoad = unavailable.get('cameraStream').onload;
await unavailable.timeout(8000);
assert.equal(unavailable.get('cameraPreviewStatus').textContent, 'Snapshot preview (stream unavailable)');
assert.equal(unavailable.get('retryEspStreamButton').disabled, false);
await unavailable.timeout(1000);
assert.equal(unavailable.detector.calls, 2, 'snapshot detection continues after a stream timeout');
await unavailable.click('retryEspStreamButton');
expiredLoad();
assert.equal(unavailable.get('cameraPreviewStatus').textContent, 'Connecting live stream');
unavailable.get('cameraStream').onload();
assert.equal(unavailable.get('cameraPreviewStatus').textContent, 'Live MJPEG stream');
unavailable.get('cameraStream').onerror();
assert.equal(unavailable.get('cameraSnapshot').hiddenClasses.has('hidden'), false);
assert.equal(unavailable.get('cameraPreviewStatus').textContent, 'Snapshot preview (stream unavailable)');
await unavailable.click('stopCameraButton');

const mixedContent = await harness(true, 'http://camera.example:81/stream', 'https:');
await mixedContent.click('startEspCameraButton');
assert.equal(mixedContent.get('cameraPreviewStatus').textContent, 'Snapshot preview (HTTPS stream required)');
assert.equal(mixedContent.get('cameraStream').src, undefined);
await mixedContent.click('stopCameraButton');
const invalidStream = await harness(true, 'javascript:alert(1)');
await invalidStream.click('startEspCameraButton');
assert.equal(invalidStream.get('cameraPreviewStatus').textContent, 'Snapshot preview (invalid stream address)');
await invalidStream.click('stopCameraButton');
console.log('camera freshness and review verified');
JS);
    }

    public function test_built_camera_runs_in_chrome_without_esp32_hardware(): void
    {
        if (! is_file(public_path('build/manifest.json'))) {
            $this->markTestSkipped('Build the frontend before running the Chrome camera smoke test.');
        }

        $response = $this->actingAs(User::factory()->admin()->create())->get(route('robot.dashboard.camera'))->assertOk();
        $directory = storage_path('framework/testing/camera-browser');
        File::ensureDirectoryExists($directory);
        $code = 'const html = '.json_encode($response->getContent(), JSON_THROW_ON_ERROR).';'.PHP_EOL;
        $code .= 'const projectUrl = '.json_encode(config('app.url'), JSON_THROW_ON_ERROR).';'.PHP_EOL;
        $code .= <<<'JS'
import assert from 'node:assert/strict';
import { readFile, readdir, access } from 'node:fs/promises';
import { resolve, sep, extname, join } from 'node:path';
import { pathToFileURL } from 'node:url';
let playwright;
try { playwright = await import('playwright'); } catch {
    const cache = process.env.LOCALAPPDATA ? join(process.env.LOCALAPPDATA, 'npm-cache', '_npx') : '';
    for (const folder of cache ? await readdir(cache).catch(() => []) : []) {
        const module = join(cache, folder, 'node_modules/playwright/index.mjs');
        try { await access(module); playwright = await import(pathToFileURL(module)); break; } catch {}
    }
}
if (!playwright) { console.log('Playwright is not installed; Chrome smoke test skipped.'); process.exit(77); }
const publicPath = resolve(process.argv[2], '../../public');
const output = resolve(process.argv[2], '../../storage/framework/testing/camera-browser');
let browser;
let streamServer;
try {
    browser = await playwright.chromium.launch({ channel: 'chrome', headless: true, args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'] });
} catch (error) {
    if (!/Executable doesn't exist|Chromium distribution.*not found/.test(error.message)) throw error;
    console.log('Chrome is not installed; camera smoke test skipped.'); process.exit(77);
}
try {
    const context = await browser.newContext({ viewport: { width: 1366, height: 900 }, permissions: ['camera', 'local-network-access'], serviceWorkers: 'block' });
    const errors = [];
    await context.route('**/*', async (route) => {
        const url = new URL(route.request().url());
        if (!['localhost', '127.0.0.1'].includes(url.hostname)) return route.abort();
        if (url.pathname === '/dashboard/camera') return route.fulfill({ contentType: 'text/html', body: html });
        if (url.pathname.startsWith('/dashboard/api/')) return route.fulfill({ contentType: 'application/json', body: url.pathname.endsWith('/latest') ? 'null' : '[]' });
        const path = resolve(publicPath, `.${decodeURIComponent(url.pathname)}`);
        if (!path.startsWith(`${publicPath}${sep}`)) return route.abort();
        const mime = { '.js': 'application/javascript', '.css': 'text/css', '.wasm': 'application/wasm', '.svg': 'image/svg+xml' };
        try { return await route.fulfill({ contentType: mime[extname(path)] ?? 'application/octet-stream', body: await readFile(path) }); }
        catch { return route.fulfill({ status: 404, body: 'Not found' }); }
    });
    const page = await context.newPage();
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error' && message.text() !== 'INFO: Created TensorFlow Lite XNNPACK delegate for CPU.') errors.push(message.text());
    });
    const waitForModel = async () => {
        await page.waitForFunction(() => document.getElementById('cameraModelLabel').textContent === 'TensorFlow Lite ready' || document.getElementById('camera_status').textContent === 'Connection failed', null, { timeout: 65000 });
        assert.equal(await page.locator('#cameraModelLabel').textContent(), 'TensorFlow Lite ready', JSON.stringify({ errors, message: await page.locator('#cameraMessage').textContent() }));
    };
    await page.goto(new URL('/dashboard/camera', projectUrl).href);
    await page.locator('#startCameraButton').click();
    await waitForModel();
    await page.waitForFunction(() => document.getElementById('cameraInferenceTime').textContent !== '--', null, { timeout: 20000 });
    assert.equal(await page.locator('#camera_status').textContent(), 'Live detection');
    await page.locator('#liveDetectionButton').click();
    assert.equal(await page.locator('#camera_status').textContent(), 'Review frame');
    const pixels = await page.locator('#cameraSnapshot').evaluate((canvas) => {
        const context = canvas.getContext('2d');
        const values = new Set();
        for (let y = 0; y < canvas.height; y += 30) {
            for (let x = 0; x < canvas.width; x += 30) values.add(context.getImageData(x, y, 1, 1).data.slice(0, 3).join(','));
        }
        return { width: canvas.width, height: canvas.height, colors: values.size };
    });
    assert.ok(pixels.width > 0 && pixels.height > 0 && pixels.colors > 5, 'review frame must contain real camera pixels');
    await page.screenshot({ path: join(output, 'desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, 'mobile page must not overflow');
    await page.screenshot({ path: join(output, 'mobile.png'), fullPage: true });
    await page.locator('#liveDetectionButton').click();
    await page.waitForFunction(() => document.getElementById('cameraInferenceTime').textContent !== '--');
    await page.locator('#stopCameraButton').click();
    assert.equal(await page.locator('#cameraObjectCount').textContent(), '0');
    await page.locator('#cameraModelSelect').selectOption('efficientdet_lite0');
    await page.locator('#startCameraButton').click();
    await waitForModel();
    await page.waitForFunction(() => document.getElementById('cameraInferenceTime').textContent !== '--', null, { timeout: 20000 });
    await page.locator('#stopCameraButton').click();
    await page.locator('#cameraModelSelect').selectOption('waste_primary_20261026');
    assert.equal(await page.locator('#cameraModelWarning').isVisible(), true);
    assert.match(await page.locator('#cameraModelWarning').textContent(), /Leaves, wood, and rocks are untrained/);
    await page.locator('#startCameraButton').click();
    await waitForModel();
    await page.waitForFunction(() => document.getElementById('cameraInferenceTime').textContent !== '--', null, { timeout: 20000 });
    assert.equal(await page.locator('#camera_status').textContent(), 'Live detection');
    assert.match(await page.locator('#cameraActiveModel').textContent(), /RobotSys Waste/);
    const trainedLabels = await page.locator('#cameraObjectSelect option').allTextContents();
    assert.ok(trainedLabels.every((label) => /^(plastic_bottle|other_plastic|paper|metal_can) \(/.test(label)));
    await page.locator('#stopCameraButton').click();
    await page.locator('#cameraModelSelect').selectOption('efficientdet_lite2');
    assert.equal(await page.locator('#cameraModelWarning').isVisible(), false);
    await page.locator('#cameraModelSelect').selectOption('waste_primary_20261026');
    const fixtureFrames = await page.evaluate(() => Array.from({ length: 16 }, (_, index) => {
        const canvas = document.createElement('canvas'); canvas.width = 320; canvas.height = 240;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = `hsl(${index * 22}, 90%, 50%)`; ctx.fillRect(0, 0, 320, 240);
        ctx.fillStyle = '#ffffff'; ctx.fillRect(index * 16, 40, 50, 80);
        return canvas.toDataURL('image/jpeg').split(',')[1];
    }));
    const frames = fixtureFrames.map((frame) => Buffer.from(frame, 'base64'));
    let activeStreams = 0;
    const { createServer } = await import('node:http');
    streamServer = createServer((request, response) => {
        response.writeHead(200, { 'Content-Type': 'multipart/x-mixed-replace; boundary=robot-frame', 'Cache-Control': 'no-store', 'Access-Control-Allow-Origin': '*' });
        activeStreams++;
        let index = 0;
        const send = () => {
            const frame = frames[index++ % frames.length];
            response.write(`--robot-frame\r\nContent-Type: image/jpeg\r\nContent-Length: ${frame.length}\r\n\r\n`);
            response.write(frame); response.write('\r\n');
        };
        send();
        const timer = setInterval(send, 100);
        response.on('close', () => { clearInterval(timer); activeStreams--; });
    });
    await new Promise((resolve) => streamServer.listen(0, '127.0.0.1', resolve));
    const streamOrigin = `http://127.0.0.1:${streamServer.address().port}`;
    await context.route(`${streamOrigin}/**`, (route) => route.continue());
    await context.route('**/dashboard/api/camera/snapshot', (route) => route.fulfill({ contentType: 'image/jpeg', body: frames[0] }));
    await page.evaluate((url) => {
        window.robotDashboard.urls.cameraStream = url;
        window.robotDashboard.urls.cameraSnapshot = '/dashboard/api/camera/snapshot';
        document.getElementById('startEspCameraButton').disabled = false;
    }, `${streamOrigin}/stream`);
    await page.locator('#startEspCameraButton').click();
    await waitForModel();
    try {
        await page.waitForFunction(() => document.getElementById('cameraPreviewStatus').textContent === 'Live MJPEG stream', null, { timeout: 12000 });
    } catch (error) {
        console.log(JSON.stringify({ activeStreams, errors, preview: await page.locator('#cameraPreviewStatus').textContent(), source: await page.locator('#cameraStream').getAttribute('src'), message: await page.locator('#cameraMessage').textContent() }));
        throw error;
    }
    await page.waitForFunction(() => document.getElementById('cameraInferenceTime').textContent !== '--', null, { timeout: 20000 });
    assert.equal(await page.locator('#cameraStream').isVisible(), true);
    assert.equal(await page.locator('#cameraSnapshot').isVisible(), false);
    const streamPixels = await page.locator('#cameraStream').evaluate((image) => ({ width: image.naturalWidth, height: image.naturalHeight }));
    assert.deepEqual(streamPixels, { width: 320, height: 240 });
    const firstStreamFrame = await page.locator('#cameraStream').screenshot();
    await page.waitForTimeout(250);
    const nextStreamFrame = await page.locator('#cameraStream').screenshot();
    assert.equal(firstStreamFrame.equals(nextStreamFrame), false, 'native MJPEG preview must show changing frames');
    await page.setViewportSize({ width: 320, height: 740 });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, 'stream controls must fit narrow phones');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.evaluate(() => { document.activeElement?.blur(); window.scrollTo({ top: 0, left: 0, behavior: 'instant' }); });
    await page.screenshot({ path: join(output, 'esp-stream-mobile.png'), fullPage: true });
    await page.setViewportSize({ width: 1366, height: 900 });
    await page.evaluate(() => window.scrollTo({ top: 0, left: 0, behavior: 'instant' }));
    await page.screenshot({ path: join(output, 'esp-stream-desktop.png'), fullPage: true });
    await page.locator('#liveDetectionButton').click();
    assert.equal(await page.locator('#cameraPreviewStatus').textContent(), 'Review frame');
    assert.equal(await page.locator('#cameraStream').getAttribute('src'), null);
    assert.equal(await page.locator('#cameraSnapshot').isVisible(), true);
    await page.waitForTimeout(500);
    assert.equal(activeStreams, 0, 'review must release the stream connection');
    await page.locator('#liveDetectionButton').click();
    await page.waitForFunction(() => document.getElementById('cameraPreviewStatus').textContent === 'Live MJPEG stream');
    await page.locator('#stopCameraButton').click();
    await page.waitForTimeout(500);
    assert.equal(activeStreams, 0, 'stop must release the stream connection');
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ models: ['efficientdet_lite2', 'efficientdet_lite0', 'waste_primary_20261026'], synthetic_camera: true, synthetic_mjpeg: true, pixels, streamPixels, screenshots: ['desktop.png', 'mobile.png', 'esp-stream-desktop.png', 'esp-stream-mobile.png'], page_errors: errors }));
} finally {
    await browser.close();
    if (streamServer) { streamServer.closeAllConnections(); await new Promise((resolve) => streamServer.close(resolve)); }
}
JS;

        $this->runJavaScript($code, 180);
    }

    private function runJavaScript(string $code, int $timeout = 30): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('Node.js is required for browser camera regression tests.');
        }

        $process = new Process([$node, '--experimental-vm-modules', '--input-type=module', '-', resource_path('js')]);
        $process->setInput($code);
        $process->setTimeout($timeout);
        $process->run();

        if ($process->getExitCode() === 77) {
            $this->markTestSkipped(trim($process->getOutput()));
        }

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
    }

    /**
     * @return array<string, mixed>
     */
    private function detectionPayload(): array
    {
        $image = UploadedFile::fake()->image('evidence.jpg', 320, 240);

        return [
            'robot_id' => 'ROBOT-001',
            'last_detected_trash_type' => 'Plastic Waste',
            'detected_object_label' => 'bottle',
            'detection_confidence' => 87.5,
            'detection_bbox' => ['x' => 10, 'y' => 20, 'width' => 100, 'height' => 120],
            'camera_snapshot' => 'data:image/jpeg;base64,'.base64_encode($image->getContent()),
        ];
    }
}
