<section id="camera" class="{{ $activePage === 'camera' ? 'control-panel' : '' }} flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <h2 class="text-base font-semibold">Camera Detection</h2>
            <span id="camera_status" class="status-badge bg-zinc-100 text-zinc-600 ring-zinc-200" role="status"><span class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span><span data-status-label>Camera off</span></span>
            <span id="cameraEngineBadge" class="status-badge bg-zinc-100 text-zinc-600 ring-zinc-200" role="status"><span class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span><span data-status-label>Engine: Idle</span></span>
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
                <img id="cameraStream" class="absolute inset-0 hidden size-full object-contain" alt="ESP32-CAM live stream" crossorigin="anonymous" referrerpolicy="no-referrer">
                <canvas id="cameraSnapshot" class="absolute inset-0 hidden size-full object-contain" aria-label="Captured camera frame"></canvas>
                <canvas id="detectionOverlay" class="pointer-events-none absolute inset-0 hidden size-full" aria-hidden="true"></canvas>
                <div id="cameraPlaceholder" class="absolute inset-0 flex flex-col items-center justify-center gap-2.5 px-6 text-center">
                    <span class="flex size-12 items-center justify-center rounded-2xl bg-white/10 ring-1 ring-white/15">
                        <img src="{{ asset('icons/camera.svg') }}" class="size-6 invert opacity-80" alt="" aria-hidden="true">
                    </span>
                    <p class="text-sm font-medium text-zinc-200">Camera feed is inactive</p>
                    <span class="text-xs text-zinc-400">Connect a camera or upload a photo to inspect waste</span>
                </div>
            </div>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2.5 rounded-xl border border-zinc-200/80 bg-zinc-50/70 p-2.5">
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

            <details class="mt-4 rounded-xl border border-zinc-200/80 bg-zinc-50/40 px-4" open>
                <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 text-sm font-semibold">
                    <span class="flex items-center gap-2"><img src="{{ asset('icons/settings-2.svg') }}" class="size-4 opacity-75" alt="" aria-hidden="true">Detection settings</span>
                    <img src="{{ asset('icons/chevron-down.svg') }}" class="details-chevron size-4 opacity-60" alt="" aria-hidden="true">
                </summary>
                <div class="grid gap-4 border-t border-zinc-200/70 pt-4 pb-4 sm:grid-cols-2">
                    <div class="field-group sm:col-span-2">
                        <label for="cameraModelSelect" class="field-label">Detection model</label>
                        <select id="cameraModelSelect" class="form-input" aria-describedby="cameraModelWarning">
                            @foreach ($visionModels as $modelId => $model)
                                <option value="{{ $modelId }}" @selected($modelId === $defaultVisionModel)>{{ $model['name'] }} / {{ $model['inputSize'] }} &times; {{ $model['inputSize'] }}{{ $modelId === 'efficientdet_lite0' ? ' / Faster' : ($modelId === 'efficientdet_lite2' ? ' / General objects' : '') }}</option>
                            @endforeach
                        </select>
                        <p id="cameraModelWarning" class="mt-1.5 text-xs leading-5 text-amber-800 {{ $visionModels[$defaultVisionModel]['warning'] ? '' : 'hidden' }}" role="status">{{ $visionModels[$defaultVisionModel]['warning'] }}</p>
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
                            <output id="cameraThresholdLabel" for="cameraThreshold" class="text-sm font-semibold tabular-nums text-emerald-800">60%</output>
                        </div>
                        <input type="range" id="cameraThreshold" min="20" max="90" value="60" step="5" class="min-h-10 w-full accent-emerald-700">
                    </div>
                    <dl class="grid grid-cols-2 gap-3 rounded-xl border border-zinc-200/70 bg-white p-3 text-xs sm:col-span-2 sm:grid-cols-4">
                        <div><dt class="text-zinc-500">Runtime / model</dt><dd id="cameraActiveModel" class="mt-0.5 font-medium text-zinc-800">TensorFlow Lite / {{ $visionModels[$defaultVisionModel]['name'] }}</dd></div>
                        <div><dt class="text-zinc-500">Model status</dt><dd id="cameraModelLabel" class="mt-0.5 font-medium text-zinc-800" role="status">Not loaded</dd></div>
                        <div><dt class="text-zinc-500">Preview</dt><dd id="cameraPreviewStatus" class="mt-0.5 font-medium text-zinc-800" role="status">Inactive</dd></div>
                        <div><dt class="text-zinc-500">Inference</dt><dd class="mt-0.5 font-medium tabular-nums text-zinc-800"><span id="cameraInferenceTime">--</span> ms</dd></div>
                    </dl>
                    <div class="rounded-xl border border-zinc-200/70 bg-white p-3.5 sm:col-span-2">
                        <div class="flex items-center justify-between gap-2">
                            <h4 class="text-xs font-semibold text-zinc-800">ESP32-CAM Live Image Settings (192.168.4.2)</h4>
                            <span id="espCamControlStatus" class="text-xs text-zinc-500">Ready</span>
                        </div>
                        <div class="mt-3 grid gap-3 sm:grid-cols-4">
                            <div class="field-group">
                                <label for="espCamFramesize" class="field-label text-xs">Resolution</label>
                                <select id="espCamFramesize" data-esp-cam-var="framesize" class="form-input text-xs">
                                    <option value="9">SVGA (800 &times; 600)</option>
                                    <option value="8" selected>VGA (640 &times; 480)</option>
                                    <option value="6">HVGA (480 &times; 320)</option>
                                    <option value="5">QVGA (320 &times; 240)</option>
                                </select>
                            </div>
                            <div class="field-group">
                                <label for="espCamQuality" class="field-label text-xs">Sharpness / Quality</label>
                                <select id="espCamQuality" data-esp-cam-var="quality" class="form-input text-xs">
                                    <option value="6">Ultra Sharp (6)</option>
                                    <option value="10" selected>High Quality (10)</option>
                                    <option value="14">Balanced (14)</option>
                                    <option value="20">Fast Stream (20)</option>
                                </select>
                            </div>
                            <div class="field-group">
                                <label for="espCamBrightness" class="field-label text-xs">Brightness</label>
                                <select id="espCamBrightness" data-esp-cam-var="brightness" class="form-input text-xs">
                                    <option value="2">+2 (Brightest)</option>
                                    <option value="1">+1 (Bright)</option>
                                    <option value="0" selected>0 (Normal)</option>
                                    <option value="-1">-1 (Darker)</option>
                                    <option value="-2">-2 (Darkest)</option>
                                </select>
                            </div>
                            <div class="field-group">
                                <label for="espCamContrast" class="field-label text-xs">Contrast</label>
                                <select id="espCamContrast" data-esp-cam-var="contrast" class="form-input text-xs">
                                    <option value="2">+2 (High)</option>
                                    <option value="1" selected>+1 (Crisp)</option>
                                    <option value="0">0 (Normal)</option>
                                    <option value="-1">-1 (Soft)</option>
                                    <option value="-2">-2 (Lowest)</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-4 border-t border-zinc-100 pt-2.5 text-xs">
                            <label class="flex cursor-pointer items-center gap-1.5 font-medium text-zinc-700">
                                <input type="checkbox" data-esp-cam-toggle="vflip" checked class="size-3.5 accent-emerald-700">
                                <span>Vertical Flip (V-Flip)</span>
                            </label>
                            <label class="flex cursor-pointer items-center gap-1.5 font-medium text-zinc-700">
                                <input type="checkbox" data-esp-cam-toggle="hmirror" class="size-3.5 accent-emerald-700">
                                <span>Horizontal Mirror (H-Mirror)</span>
                            </label>
                            <a href="http://192.168.4.2" target="_blank" rel="noopener noreferrer" class="ml-auto text-emerald-700 hover:underline">Open full ESP32-CAM panel &rarr;</a>
                        </div>
                    </div>
                </div>
            </details>
        </div>

        <aside class="min-w-0 xl:border-l xl:border-zinc-200/80 xl:pl-6" aria-labelledby="detection-review-heading">
            <div class="section-heading">
                <h3 id="detection-review-heading">Detection review</h3>
                <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs text-zinc-600"><span id="cameraObjectCount" class="font-semibold tabular-nums text-zinc-800">0</span> objects</span>
            </div>
            <div class="mt-4 rounded-xl border border-zinc-200/80 bg-zinc-50/50 p-4">
                <div class="field-group">
                    <label for="cameraObjectSelect" class="field-label">Detected object</label>
                    <select id="cameraObjectSelect" disabled class="form-input">
                        <option value="">No detected objects</option>
                    </select>
                </div>
                <dl class="mt-3.5 flex items-baseline justify-between gap-2">
                    <dt class="text-xs text-zinc-500">Selected object</dt>
                    <dd id="cameraObjectLabel" class="break-words text-sm font-semibold text-zinc-900">Awaiting detection</dd>
                </dl>
                <div class="mt-3 border-t border-zinc-200/70 pt-3">
                    <div class="flex items-center justify-between gap-3 text-xs">
                        <span class="text-zinc-500">Object confidence</span>
                        <span id="cameraConfidenceLabel" class="font-semibold tabular-nums text-zinc-800">--</span>
                    </div>
                    <div class="progress-track mt-2"><span id="cameraConfidenceBar" class="progress-fill bg-emerald-600" style="width: 0%"></span></div>
                    <div class="mt-3 grid grid-cols-2 gap-2 border-t border-zinc-200/70 pt-2.5 text-xs">
                        <div>
                            <span class="text-zinc-500">Arm Aim (S0):</span>
                            <span id="cameraAimDirection" class="ml-1 font-semibold text-zinc-800">--</span>
                        </div>
                        <div class="text-right">
                            <span class="text-zinc-500">Sort Bin:</span>
                            <span id="cameraTargetBin" class="ml-1 font-semibold text-emerald-700">--</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-3 rounded-xl border border-emerald-200/80 bg-emerald-50/40 p-3">
                <label class="flex cursor-pointer items-center justify-between gap-2 text-xs font-semibold text-zinc-800">
                    <span>Auto-Grab &amp; Sort when trash detected</span>
                    <input type="checkbox" id="autoGrabToggle" class="size-4 accent-emerald-700">
                </label>
                <button id="grabDetectedWasteButton" type="button" disabled class="button button-secondary mt-2.5 w-full">
                    <span>Grab &amp; Sort Detected Waste Now</span>
                </button>
                <p id="armGrabStatus" class="mt-1.5 text-xs text-zinc-600" role="status">Arm ready (192.168.4.1)</p>
            </div>
            <img id="cameraEvidencePreview" class="mt-4 hidden aspect-video w-full rounded-xl border border-zinc-200 bg-zinc-100 object-contain" alt="Frame selected for operator review">
            <button type="button" class="text-link mt-2 text-xs disabled:cursor-not-allowed disabled:opacity-40" data-reviewed-download disabled><img src="{{ asset('icons/arrow-down-to-line.svg') }}" class="size-3.5" alt="" aria-hidden="true">Download review frame</button>
            <div class="field-group mt-4">
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
            <section class="mt-6 border-t border-zinc-200/80 pt-5" aria-labelledby="camera-history-heading">
                <div class="section-heading">
                    <h3 id="camera-history-heading">Detection History</h3>
                    <a href="{{ route('robot.dashboard.logs', ['source' => 'laptop_camera', 'robot_id' => $robotId]) }}" class="icon-button size-8 min-h-8 min-w-8" title="View saved camera logs" aria-label="View saved camera logs">
                        <img src="{{ asset('icons/arrow-right.svg') }}" class="size-3.5" alt="" aria-hidden="true">
                    </a>
                </div>
                <div id="cameraDetectionHistory" class="max-h-72 overflow-y-auto divide-y divide-zinc-100">
                    @isset($cameraDetections)
                        @forelse ($cameraDetections as $detection)
                            <div class="detection-history-row" data-detection-id="{{ $detection->id }}">
                                @if ($detection->camera_snapshot_url)
                                    <a href="{{ $detection->camera_snapshot_url }}" target="_blank" rel="noopener noreferrer" class="shrink-0" aria-label="Open saved detection snapshot">
                                        <img src="{{ $detection->camera_snapshot_url }}" class="h-12 w-16 rounded-lg border border-zinc-200 bg-zinc-100 object-contain" alt="{{ $detection->detected_object_label ?? 'Saved detection' }}" loading="lazy">
                                    </a>
                                @endif
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start justify-between gap-2 text-sm font-medium">
                                        <p class="break-words">{{ $detection->detected_object_label ?? 'Reviewed object' }}</p>
                                        <span class="shrink-0 text-xs tabular-nums text-zinc-500">{{ $detection->detection_confidence !== null ? number_format((float) $detection->detection_confidence, 0).'%' : '--' }}</span>
                                    </div>
                                    <p class="mt-0.5 break-words text-xs text-zinc-500">{{ $detection->last_detected_trash_type ?? 'Uncategorized' }}</p>
                                    <p class="mt-0.5 text-xs text-zinc-400">{{ $detection->created_at?->format('M d, h:i A') }}</p>
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
<details class="workspace-panel mt-4 p-4 sm:px-6 sm:py-4">
    <summary class="flex cursor-pointer list-none items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <h3 class="text-sm font-semibold text-zinc-800">Onboard Vision (ESP32-S3)</h3>
            <span id="onboardVisionStatus" class="status-badge bg-zinc-100 text-zinc-600 ring-zinc-200">Offline</span>
        </div>
        <img src="{{ asset('icons/chevron-down.svg') }}" class="details-chevron size-4 opacity-60" alt="" aria-hidden="true">
    </summary>
    <div class="mt-4 grid gap-4 border-t border-zinc-100 pt-4 md:grid-cols-2">
        <div>
            <img id="onboardVisionImage" src="" alt="Onboard vision frame" class="w-full rounded-xl border border-zinc-200 hidden" />
            <p id="onboardVisionNoImage" class="text-sm text-zinc-500">No recent frame</p>
        </div>
        <div>
            <div class="mb-2 flex items-center justify-between">
                <p class="text-sm font-medium">Detections</p>
                <p id="onboardVisionCount" class="text-xs font-medium text-zinc-600">0 objects</p>
            </div>
            <div id="onboardVisionDetections" class="space-y-2 text-sm"></div>
            <div class="mt-3 flex flex-wrap gap-4 text-xs text-zinc-500">
                <p>Device: <span id="onboardVisionDevice" class="font-medium text-zinc-700">-</span></p>
                <p>Last updated: <span id="onboardVisionTime" class="font-medium text-zinc-700">-</span></p>
            </div>
        </div>
    </div>
</details>
