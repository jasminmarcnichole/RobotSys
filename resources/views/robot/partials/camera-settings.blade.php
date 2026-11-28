@php
    $savedSettings = $cameraSetting;
    $esp32Host = parse_url((string) config('services.robot.camera_snapshot_url', 'http://192.168.4.2/capture'), PHP_URL_HOST) ?: '192.168.4.2';
    $esp32BaseUrl = 'http://'.$esp32Host;
    $esp32StreamUrl = config('services.robot.camera_stream_url') ?: 'http://'.$esp32Host.':81/stream';
@endphp

<section id="camera-settings" class="flex flex-col gap-6" data-camera-settings-page>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <h2 class="text-base font-semibold">Camera Settings</h2>
            <span id="cameraSettingsSavedBadge" class="status-badge {{ $savedSettings->exists ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-zinc-100 text-zinc-600 ring-zinc-200' }}" role="status">
                <span class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span>
                {{ $savedSettings->exists ? 'Saved for '.$robotId : 'Using defaults' }}
            </span>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('robot.dashboard.control', ['robot_id' => $robotId]) }}#camera" class="button button-secondary">
                <img src="{{ asset('icons/camera.svg') }}" class="size-4" alt="" aria-hidden="true">
                Open Camera Vision
            </a>
            <a href="{{ $esp32BaseUrl }}" target="_blank" rel="noopener noreferrer" class="button button-secondary">
                <img src="{{ asset('icons/arrow-right.svg') }}" class="size-4" alt="" aria-hidden="true">
                ESP32-CAM Panel
            </a>
        </div>
    </div>

    <div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1.4fr)_minmax(280px,1fr)]">
        <form id="cameraSettingsForm" class="flex flex-col gap-6" data-esp32-base="{{ $esp32BaseUrl }}" data-esp32-stream="{{ $esp32StreamUrl }}">
            <section class="workspace-panel flex flex-col gap-4" aria-labelledby="detection-defaults-heading">
                <div class="section-heading">
                    <div>
                        <h3 id="detection-defaults-heading">Detection &amp; Automation Defaults</h3>
                        <p class="mt-0.5 text-xs text-zinc-500">Loaded automatically when you open Robot App or Camera Detection.</p>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="field-group sm:col-span-2">
                        <label for="settingsDetectionModel" class="field-label">Default detection model</label>
                        <select id="settingsDetectionModel" name="detection_model" class="form-input">
                            @foreach ($visionModels as $modelId => $model)
                                <option value="{{ $modelId }}" @selected($modelId === $savedSettings->detection_model)>{{ $model['name'] }} ({{ $model['inputSize'] }} &times; {{ $model['inputSize'] }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field-group sm:col-span-2">
                        <div class="flex items-center justify-between gap-2">
                            <label for="settingsConfidenceThreshold" class="field-label">Default minimum confidence</label>
                            <output id="settingsConfidenceValue" for="settingsConfidenceThreshold" class="text-sm font-semibold tabular-nums text-emerald-800">{{ $savedSettings->confidence_threshold }}%</output>
                        </div>
                        <input type="range" id="settingsConfidenceThreshold" name="confidence_threshold" min="20" max="90" step="5" value="{{ $savedSettings->confidence_threshold }}" class="min-h-10 w-full accent-emerald-700">
                        <p class="text-xs text-zinc-500">Higher values (60%–75%) reduce false boxes on empty floors and textured backgrounds.</p>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="flex cursor-pointer items-center justify-between gap-3 rounded-xl border border-zinc-200/80 bg-zinc-50/70 px-3.5 py-3 text-sm font-medium text-zinc-800">
                            <span>
                                <span class="block">Enable Auto-Grab &amp; Sort by default</span>
                                <span class="mt-0.5 block text-xs font-normal text-zinc-500">Automatically checks the Auto-Grab toggle when Camera Detection opens.</span>
                            </span>
                            <input type="checkbox" id="settingsAutoGrab" name="auto_grab_enabled" @checked($savedSettings->auto_grab_enabled) class="size-4 shrink-0 accent-emerald-700">
                        </label>
                    </div>
                </div>
            </section>

            <section class="workspace-panel flex flex-col gap-4" aria-labelledby="esp32-sensor-heading">
                <div class="section-heading">
                    <div>
                        <h3 id="esp32-sensor-heading">ESP32-S3-CAM Image &amp; Sensor Settings ({{ $esp32Host }})</h3>
                        <p class="mt-0.5 text-xs text-zinc-500">Saved in Laravel and automatically re-applied whenever the ESP32-CAM connects.</p>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="field-group">
                        <label for="settingsFramesize" class="field-label">Resolution</label>
                        <select id="settingsFramesize" name="framesize" data-live-cam-var="framesize" class="form-input">
                            <option value="5" @selected((int) $savedSettings->framesize === 5)>QVGA (320 &times; 240 — Coolest / Fastest)</option>
                            <option value="6" @selected((int) $savedSettings->framesize === 6)>HVGA (480 &times; 320)</option>
                            <option value="8" @selected((int) $savedSettings->framesize === 8)>VGA (640 &times; 480 — Balanced)</option>
                            <option value="9" @selected((int) $savedSettings->framesize === 9)>SVGA (800 &times; 600 — Sharp / Recommended)</option>
                            <option value="10" @selected((int) $savedSettings->framesize === 10)>XGA (1024 &times; 768 — Ultra Sharp)</option>
                        </select>
                    </div>

                    <div class="field-group">
                        <label for="settingsQuality" class="field-label">JPEG Compression / Quality</label>
                        <select id="settingsQuality" name="quality" data-live-cam-var="quality" class="form-input">
                            <option value="6" @selected((int) $savedSettings->quality === 6)>Ultra Sharp (6)</option>
                            <option value="10" @selected((int) $savedSettings->quality === 10)>High Quality (10)</option>
                            <option value="14" @selected((int) $savedSettings->quality === 14)>Balanced / Cool (14 — Recommended)</option>
                            <option value="20" @selected((int) $savedSettings->quality === 20)>Fast Stream (20)</option>
                            <option value="26" @selected((int) $savedSettings->quality === 26)>Ultra Light (26)</option>
                        </select>
                    </div>

                    <div class="field-group">
                        <label for="settingsBrightness" class="field-label">Brightness</label>
                        <select id="settingsBrightness" name="brightness" data-live-cam-var="brightness" class="form-input">
                            <option value="2" @selected((int) $savedSettings->brightness === 2)>+2 (Brightest)</option>
                            <option value="1" @selected((int) $savedSettings->brightness === 1)>+1 (Bright)</option>
                            <option value="0" @selected((int) $savedSettings->brightness === 0)>0 (Normal)</option>
                            <option value="-1" @selected((int) $savedSettings->brightness === -1)>-1 (Darker)</option>
                            <option value="-2" @selected((int) $savedSettings->brightness === -2)>-2 (Darkest)</option>
                        </select>
                    </div>

                    <div class="field-group">
                        <label for="settingsContrast" class="field-label">Contrast</label>
                        <select id="settingsContrast" name="contrast" data-live-cam-var="contrast" class="form-input">
                            <option value="2" @selected((int) $savedSettings->contrast === 2)>+2 (High)</option>
                            <option value="1" @selected((int) $savedSettings->contrast === 1)>+1 (Crisp — Recommended)</option>
                            <option value="0" @selected((int) $savedSettings->contrast === 0)>0 (Normal)</option>
                            <option value="-1" @selected((int) $savedSettings->contrast === -1)>-1 (Soft)</option>
                            <option value="-2" @selected((int) $savedSettings->contrast === -2)>-2 (Lowest)</option>
                        </select>
                    </div>

                    <div class="field-group">
                        <label for="settingsSaturation" class="field-label">Saturation</label>
                        <select id="settingsSaturation" name="saturation" data-live-cam-var="saturation" class="form-input">
                            <option value="2" @selected((int) $savedSettings->saturation === 2)>+2 (Vivid)</option>
                            <option value="1" @selected((int) $savedSettings->saturation === 1)>+1 (Slightly Vivid)</option>
                            <option value="0" @selected((int) $savedSettings->saturation === 0)>0 (Normal)</option>
                            <option value="-1" @selected((int) $savedSettings->saturation === -1)>-1 (Muted)</option>
                            <option value="-2" @selected((int) $savedSettings->saturation === -2)>-2 (Low)</option>
                        </select>
                    </div>

                    <div class="field-group">
                        <div class="flex items-center justify-between gap-2">
                            <label for="settingsLedIntensity" class="field-label">Flash LED Intensity</label>
                            <output id="settingsLedValue" for="settingsLedIntensity" class="text-sm font-semibold tabular-nums text-zinc-700">{{ $savedSettings->led_intensity }}</output>
                        </div>
                        <input type="range" id="settingsLedIntensity" name="led_intensity" data-live-cam-var="led_intensity" min="0" max="255" step="5" value="{{ $savedSettings->led_intensity }}" class="min-h-10 w-full accent-emerald-700">
                        <p class="text-xs text-zinc-500">Keep at 0 unless lighting is very dark (reduces camera heat).</p>
                    </div>

                    <div class="flex flex-wrap gap-4 border-t border-zinc-200/70 pt-3 sm:col-span-2">
                        <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-zinc-700">
                            <input type="checkbox" id="settingsVflip" name="vflip" data-live-cam-toggle="vflip" @checked($savedSettings->vflip) class="size-4 accent-emerald-700">
                            <span>Vertical Flip (V-Flip)</span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-zinc-700">
                            <input type="checkbox" id="settingsHmirror" name="hmirror" data-live-cam-toggle="hmirror" @checked($savedSettings->hmirror) class="size-4 accent-emerald-700">
                            <span>Horizontal Mirror (H-Mirror)</span>
                        </label>
                    </div>
                </div>
            </section>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" id="saveCameraSettingsButton" class="button button-primary">
                    <img src="{{ asset('icons/check.svg') }}" class="size-4 invert" alt="" aria-hidden="true">
                    <span>Save Camera Settings</span>
                </button>
                <button type="button" id="applyEsp32SettingsButton" class="button button-secondary">
                    <img src="{{ asset('icons/wifi.svg') }}" class="size-4" alt="" aria-hidden="true">
                    <span>Apply to ESP32-CAM Now</span>
                </button>
                <button type="button" id="resetCameraSettingsButton" class="button button-secondary">
                    <img src="{{ asset('icons/refresh-cw.svg') }}" class="size-4" alt="" aria-hidden="true">
                    <span>Reset Defaults</span>
                </button>
            </div>
            <p id="cameraSettingsMessage" class="message message-neutral" role="status" aria-live="polite">Adjust settings and click Save Camera Settings to store them permanently.</p>
        </form>

        <aside class="workspace-panel flex flex-col gap-4 self-start" aria-labelledby="camera-settings-preview-heading">
            <div class="section-heading">
                <div>
                    <h3 id="camera-settings-preview-heading">Live ESP32-CAM Preview</h3>
                    <p class="mt-0.5 text-xs text-zinc-500">Preview changes like flip, brightness, and contrast live.</p>
                </div>
                <span id="settingsPreviewBadge" class="status-badge bg-zinc-100 text-zinc-600 ring-zinc-200">Off</span>
            </div>
            <div class="camera-media">
                <img id="settingsPreviewStream" class="absolute inset-0 hidden size-full object-contain" alt="ESP32-CAM live settings preview" crossorigin="anonymous" referrerpolicy="no-referrer">
                <div id="settingsPreviewPlaceholder" class="absolute inset-0 flex flex-col items-center justify-center gap-2 px-6 text-center">
                    <span class="flex size-11 items-center justify-center rounded-2xl bg-white/10 ring-1 ring-white/15">
                        <img src="{{ asset('icons/camera.svg') }}" class="size-5 invert opacity-80" alt="" aria-hidden="true">
                    </span>
                    <p class="text-sm font-medium text-zinc-200">Preview is off</p>
                    <span class="text-xs text-zinc-400">Connect to {{ $esp32Host }} while tuning image settings</span>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" id="toggleSettingsPreviewButton" class="button button-primary flex-1">
                    <img src="{{ asset('icons/play.svg') }}" class="size-4 invert" alt="" aria-hidden="true">
                    <span data-preview-toggle-label>Start Live Preview</span>
                </button>
            </div>
            <dl class="divide-y divide-zinc-100 text-xs">
                <div class="flex items-center justify-between py-2"><dt class="text-zinc-500">Robot ID</dt><dd class="font-semibold text-zinc-800">{{ $robotId }}</dd></div>
                <div class="flex items-center justify-between py-2"><dt class="text-zinc-500">ESP32-CAM Host</dt><dd class="font-mono text-zinc-800">{{ $esp32Host }}</dd></div>
                <div class="flex items-center justify-between py-2"><dt class="text-zinc-500">Stream URL</dt><dd class="truncate font-mono text-zinc-800">{{ $esp32StreamUrl }}</dd></div>
            </dl>
        </aside>
    </div>
</section>
