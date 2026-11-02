<section id="camera" class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <h2 class="text-base font-semibold">Camera Detection</h2>
            <span id="camera_status" class="status-badge bg-zinc-100 text-zinc-600 ring-zinc-200" role="status">
                <span class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span>
                Camera off
            </span>
        </div>
        <div class="flex flex-wrap items-center gap-2">
        <button type="button" class="button button-secondary" data-camera-expand aria-pressed="false"><img src="{{ asset('icons/eye.svg') }}" class="size-4" alt="" aria-hidden="true"><span data-camera-expand-label>Expand view</span></button>
        <label class="button button-secondary relative cursor-pointer focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-emerald-700">
            <img src="{{ asset('icons/upload.svg') }}" class="size-4" alt="" aria-hidden="true">
            Upload image
            <input type="file" id="cameraImageInput" accept="image/jpeg,image/png,image/webp" class="sr-only">
        </label>
        </div>
    </div>

    <div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1.65fr)_minmax(280px,1fr)]">
        <div class="min-w-0">
            <div class="camera-media">
                <video id="cameraFeed" class="absolute inset-0 hidden size-full object-contain" autoplay muted playsinline aria-label="Live camera feed"></video>
                <img id="cameraStream" class="absolute inset-0 hidden size-full object-contain" alt="ESP32-CAM live stream" referrerpolicy="no-referrer">
                <canvas id="cameraSnapshot" class="absolute inset-0 hidden size-full object-contain" aria-label="Captured camera frame"></canvas>
                <canvas id="detectionOverlay" class="pointer-events-none absolute inset-0 hidden size-full" aria-hidden="true"></canvas>
                <div id="cameraPlaceholder" class="absolute inset-0 flex flex-col items-center justify-center gap-3 px-6 text-center">
                    <img src="{{ asset('icons/camera.svg') }}" class="size-9 invert opacity-70" alt="" aria-hidden="true">
                    <p class="text-sm font-medium text-zinc-200">Camera feed is inactive</p>
                    <span class="text-xs text-zinc-400">No input selected</span>
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 py-3">
                <div class="flex flex-wrap gap-2">
                    <button id="startCameraButton" type="button" class="button button-primary">
                        <img src="{{ asset('icons/play.svg') }}" class="size-4 invert" alt="" aria-hidden="true">
                        <span>{{ $activePage === 'control' ? 'Use device camera' : 'Use Laptop Camera' }}</span>
                    </button>
                    <button id="startEspCameraButton" type="button" @disabled(!config('services.robot.camera_snapshot_url')) class="button button-secondary">
                        <img src="{{ asset('icons/wifi.svg') }}" class="size-4" alt="" aria-hidden="true">
                        <span>Connect ESP32-CAM</span>
                    </button>
                    <button id="retryEspStreamButton" type="button" disabled class="icon-button hidden" title="Retry live stream" aria-label="Retry live stream">
                        <img src="{{ asset('icons/refresh-cw.svg') }}" class="size-4" alt="" aria-hidden="true">
                    </button>
                    <button id="liveDetectionButton" type="button" disabled class="button button-secondary">
                        <img src="{{ asset('icons/scan-line.svg') }}" class="size-4" alt="" aria-hidden="true">
                        <span data-button-label>Live Detection</span>
                    </button>
                    <button id="captureDetectionButton" type="button" disabled class="button button-secondary">
                        <img src="{{ asset('icons/eye.svg') }}" class="size-4" alt="" aria-hidden="true">
                        <span>Review frame</span>
                    </button>
                </div>
                <button id="stopCameraButton" type="button" disabled class="icon-button text-red-700" title="Stop camera" aria-label="Stop camera">
                    <img src="{{ asset('icons/square.svg') }}" class="size-4" alt="" aria-hidden="true">
                </button>
            </div>
            @unless(config('services.robot.camera_snapshot_url'))
                <p class="mt-3 flex items-center gap-2 text-xs text-zinc-500"><img src="{{ asset('icons/wifi-off.svg') }}" class="size-3.5" alt="" aria-hidden="true">ESP32-CAM not configured</p>
            @endunless
            <p id="cameraMessage" class="message message-neutral mt-3" role="status" aria-live="polite">Camera is ready to connect.</p>

            <details class="mt-5 border-y border-zinc-200" open>
                <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 text-sm font-semibold">
                    <span class="flex items-center gap-2"><img src="{{ asset('icons/settings-2.svg') }}" class="size-4" alt="" aria-hidden="true">Detection settings</span>
                    <img src="{{ asset('icons/chevron-down.svg') }}" class="details-chevron size-4" alt="" aria-hidden="true">
                </summary>
                <div class="grid gap-5 pb-5 sm:grid-cols-2">
                    <div class="field-group sm:col-span-2">
                        <label for="cameraModelSelect" class="field-label">Detection model</label>
                        <select id="cameraModelSelect" class="form-input" aria-describedby="cameraModelWarning">
                            @foreach ($visionModels as $modelId => $model)
                                <option value="{{ $modelId }}" @selected($modelId === $defaultVisionModel)>{{ $model['name'] }} / {{ $model['inputSize'] }} &times; {{ $model['inputSize'] }}{{ $modelId === 'efficientdet_lite0' ? ' / Faster' : ($modelId === 'efficientdet_lite2' ? ' / General objects' : '') }}</option>
                            @endforeach
                        </select>
                        <p id="cameraModelWarning" class="mt-2 text-xs leading-6 text-amber-800 {{ $visionModels[$defaultVisionModel]['warning'] ? '' : 'hidden' }}" role="status">{{ $visionModels[$defaultVisionModel]['warning'] }}</p>
                    </div>
                    <div class="field-group">
                        <label for="cameraDeviceSelect" class="field-label">Camera input</label>
                        <select id="cameraDeviceSelect" class="form-input">
                            <option value="">Automatic</option>
                        </select>
                    </div>
                    <div class="field-group">
                        <div class="flex items-center justify-between gap-2">
                            <label for="cameraThreshold" class="field-label">Minimum confidence</label>
                            <output id="cameraThresholdLabel" for="cameraThreshold" class="text-sm font-semibold tabular-nums text-emerald-800">50%</output>
                        </div>
                        <input type="range" id="cameraThreshold" min="20" max="90" value="50" step="5" class="min-h-10 w-full accent-emerald-700">
                    </div>
                    <dl class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3 text-xs sm:col-span-2">
                        <div><dt class="text-zinc-500">Runtime / model</dt><dd id="cameraActiveModel" class="mt-1 font-medium text-zinc-700">TensorFlow Lite / {{ $visionModels[$defaultVisionModel]['name'] }}</dd></div>
                        <div><dt class="text-zinc-500">Model status</dt><dd id="cameraModelLabel" class="mt-1 font-medium text-zinc-700" role="status">Not loaded</dd></div>
                        <div><dt class="text-zinc-500">Preview</dt><dd id="cameraPreviewStatus" class="mt-1 font-medium text-zinc-700" role="status">Inactive</dd></div>
                        <div><dt class="text-zinc-500">Inference</dt><dd class="mt-1 font-medium tabular-nums text-zinc-700"><span id="cameraInferenceTime">--</span> ms</dd></div>
                    </dl>
                </div>
            </details>
        </div>

        <aside class="min-w-0 xl:border-l xl:border-zinc-200 xl:pl-6" aria-labelledby="detection-review-heading">
            <div class="section-heading">
                <h3 id="detection-review-heading">Detection review</h3>
                <span class="text-xs text-zinc-500"><span id="cameraObjectCount" class="font-semibold tabular-nums text-zinc-700">0</span> objects</span>
            </div>
            <div class="field-group mt-4">
                <label for="cameraObjectSelect" class="field-label">Detected object</label>
                <select id="cameraObjectSelect" disabled class="form-input">
                    <option value="">No detected objects</option>
                </select>
            </div>
            <dl class="py-4">
                <dt class="text-xs text-zinc-500">Selected object</dt>
                <dd id="cameraObjectLabel" class="mt-2 break-words text-lg font-semibold">Awaiting detection</dd>
            </dl>
            <div class="border-b border-zinc-200 pb-5">
                <div class="flex items-center justify-between gap-3 text-sm">
                    <span class="text-zinc-600">Object confidence</span>
                    <span id="cameraConfidenceLabel" class="font-semibold tabular-nums">--</span>
                </div>
                <div class="progress-track mt-2"><span id="cameraConfidenceBar" class="progress-fill bg-blue-600" style="width: 0%"></span></div>
            </div>
            <img id="cameraEvidencePreview" class="mt-5 hidden aspect-video w-full rounded-md border border-zinc-200 bg-zinc-100 object-contain" alt="Frame selected for operator review">
            <button type="button" class="text-link mt-2 text-xs disabled:cursor-not-allowed disabled:opacity-40" data-reviewed-download disabled><img src="{{ asset('icons/arrow-down-to-line.svg') }}" class="size-3.5" alt="" aria-hidden="true">Download review frame</button>
            <div class="field-group mt-5">
                <label for="cameraTrashType" class="field-label">Waste category</label>
                <select id="cameraTrashType" disabled required aria-describedby="cameraCategoryHelp" class="form-input">
                    <option value="" selected disabled>Select material</option>
                    <option>Plastic Waste</option>
                    <option>General Waste</option>
                    <option>Paper Waste</option>
                    <option>Metal Waste</option>
                    <option>Glass Waste</option>
                    <option>Organic Waste</option>
                </select>
                <p id="cameraCategoryHelp" class="text-xs leading-5 text-zinc-500">Manual review required. Object detection does not identify material.</p>
            </div>
            <button id="saveCameraDetectionButton" type="button" disabled class="button button-primary mt-4 w-full">
                <img src="{{ asset('icons/check.svg') }}" class="size-4 invert" alt="" aria-hidden="true">
                <span>Confirm and save</span>
            </button>
            <section class="mt-8" aria-labelledby="camera-history-heading">
                <div class="section-heading">
                    <h3 id="camera-history-heading">Detection History</h3>
                    <a href="{{ route('robot.dashboard.logs', ['source' => 'laptop_camera', 'robot_id' => $robotId]) }}" class="icon-button" title="View saved camera logs" aria-label="View saved camera logs">
                        <img src="{{ asset('icons/arrow-right.svg') }}" class="size-4" alt="" aria-hidden="true">
                    </a>
                </div>
                <div id="cameraDetectionHistory" class="max-h-80 overflow-y-auto divide-y divide-zinc-200">
                    @isset($cameraDetections)
                        @forelse ($cameraDetections as $detection)
                            <div class="detection-history-row" data-detection-id="{{ $detection->id }}">
                                @if ($detection->camera_snapshot_url)
                                    <a href="{{ $detection->camera_snapshot_url }}" target="_blank" rel="noopener noreferrer" class="shrink-0" aria-label="Open saved detection snapshot">
                                        <img src="{{ $detection->camera_snapshot_url }}" class="h-12 w-16 rounded-md border border-zinc-200 bg-zinc-100 object-contain" alt="{{ $detection->detected_object_label ?? 'Saved detection' }}" loading="lazy">
                                    </a>
                                @endif
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start justify-between gap-2 text-sm font-medium">
                                        <p class="break-words">{{ $detection->detected_object_label ?? 'Reviewed object' }}</p>
                                        <span class="shrink-0 text-xs tabular-nums text-zinc-500">{{ $detection->detection_confidence !== null ? number_format((float) $detection->detection_confidence, 0).'%' : '--' }}</span>
                                    </div>
                                    <p class="mt-1 break-words text-xs text-zinc-500">{{ $detection->last_detected_trash_type ?? 'Uncategorized' }}</p>
                                    <p class="mt-1 text-xs text-zinc-500">{{ $detection->created_at?->format('M d, h:i A') }}</p>
                                </div>
                            </div>
                        @empty
                            <p class="empty-state">No saved camera detections yet.</p>
                        @endforelse
                    @else
                        <p class="empty-state">No saved camera detections yet.</p>
                    @endisset
                </div>
            </section>
        </aside>
    </div>
</section>
    <div class="rounded-lg border border-zinc-200 p-4 mt-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-base font-semibold">Onboard Vision (ESP32-S3)</h3>
            <span id="onboardVisionStatus" class="status-badge bg-zinc-100 text-zinc-600">Offline</span>
        </div>
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <img id="onboardVisionImage" src="" alt="Onboard vision frame" class="w-full rounded border border-zinc-200 hidden" />
                <p id="onboardVisionNoImage" class="text-sm text-zinc-500">No recent frame</p>
            </div>
            <div>
                <div class="mb-2">
                    <p class="text-sm font-medium">Detections</p>
                    <p id="onboardVisionCount" class="text-sm text-zinc-600">0 objects</p>
                </div>
                <div id="onboardVisionDetections" class="space-y-2 text-sm"></div>
                <div class="mt-3 text-xs text-zinc-500">
                    <p>Device: <span id="onboardVisionDevice">-</span></p>
                    <p>Last updated: <span id="onboardVisionTime">-</span></p>
                </div>
            </div>
        </div>
    </div>
