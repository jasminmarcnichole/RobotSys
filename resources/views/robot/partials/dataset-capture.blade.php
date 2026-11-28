@php
    $esp32Host = parse_url((string) config('services.robot.camera_snapshot_url', 'http://192.168.4.2/capture'), PHP_URL_HOST) ?: '192.168.4.2';
    $esp32StreamUrl = config('services.robot.camera_stream_url') ?: 'http://'.$esp32Host.':81/stream';
    $captureButtons = [
        ['key' => 'background', 'label' => 'Background (Empty)', 'hint' => 'No trash · auto .txt', 'tone' => 'border-zinc-300 bg-zinc-50 hover:bg-zinc-100 text-zinc-900'],
        ['key' => 'plastic_bottle', 'label' => 'Plastic Bottle', 'hint' => 'Bottles', 'tone' => 'border-emerald-200 bg-emerald-50/70 hover:bg-emerald-100/70 text-emerald-950'],
        ['key' => 'other_plastic', 'label' => 'Other Plastic', 'hint' => 'Cups / trays / caps', 'tone' => 'border-emerald-200 bg-emerald-50/70 hover:bg-emerald-100/70 text-emerald-950'],
        ['key' => 'plastic_wrapper', 'label' => 'Plastic Wrapper', 'hint' => 'Wrappers / sachets', 'tone' => 'border-emerald-200 bg-emerald-50/70 hover:bg-emerald-100/70 text-emerald-950'],
        ['key' => 'paper', 'label' => 'Paper', 'hint' => 'Paper / cardboard', 'tone' => 'border-amber-200 bg-amber-50/70 hover:bg-amber-100/70 text-amber-950'],
        ['key' => 'metal_can', 'label' => 'Metal Can', 'hint' => 'Tin / steel cans', 'tone' => 'border-blue-200 bg-blue-50/70 hover:bg-blue-100/70 text-blue-950'],
        ['key' => 'aluminum_can', 'label' => 'Aluminum Can', 'hint' => 'Soda / beverage cans', 'tone' => 'border-blue-200 bg-blue-50/70 hover:bg-blue-100/70 text-blue-950'],
        ['key' => 'trash', 'label' => 'Mixed / Multi-Trash', 'hint' => 'Multiple objects', 'tone' => 'border-zinc-300 bg-white hover:bg-zinc-50 text-zinc-900'],
    ];
@endphp

<section id="dataset-capture" class="flex flex-col gap-6" data-dataset-capture-page data-esp32-stream="{{ $esp32StreamUrl }}">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <h2 class="text-base font-semibold">Remote Dataset Capture (Phone &rarr; Laptop)</h2>
            <span id="datasetTotalBadge" class="status-badge bg-emerald-50 text-emerald-700 ring-emerald-200" role="status">0 photos saved</span>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" id="undoDatasetCaptureButton" disabled class="button button-secondary">
                <img src="{{ asset('icons/refresh-cw.svg') }}" class="size-4" alt="" aria-hidden="true">
                <span>Undo Last Shot</span>
            </button>
            <a href="{{ route('robot.dashboard.camera-settings', ['robot_id' => $robotId]) }}" class="button button-secondary">
                <img src="{{ asset('icons/settings-2.svg') }}" class="size-4" alt="" aria-hidden="true">
                <span>Camera Settings</span>
            </a>
        </div>
    </div>

    <div class="grid min-w-0 gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
        <div class="workspace-panel flex flex-col gap-4 self-start">
            <div class="section-heading">
                <div>
                    <h3>ESP32-CAM Viewfinder</h3>
                    <p class="mt-0.5 text-xs text-zinc-500">Aim the camera, then tap a category button below to snap straight to your laptop.</p>
                </div>
                <span id="datasetPreviewBadge" class="status-badge bg-zinc-100 text-zinc-600 ring-zinc-200">Off</span>
            </div>
            <div class="camera-media">
                <img id="datasetPreviewStream" class="absolute inset-0 hidden size-full object-contain" alt="ESP32-CAM dataset viewfinder" crossorigin="anonymous" referrerpolicy="no-referrer">
                <div id="datasetPreviewPlaceholder" class="absolute inset-0 flex flex-col items-center justify-center gap-2 px-6 text-center">
                    <span class="flex size-11 items-center justify-center rounded-2xl bg-white/10 ring-1 ring-white/15">
                        <img src="{{ asset('icons/camera.svg') }}" class="size-5 invert opacity-80" alt="" aria-hidden="true">
                    </span>
                    <p class="text-sm font-medium text-zinc-200">Viewfinder is off</p>
                    <span class="text-xs text-zinc-400">Tap Start Viewfinder (or capture directly without preview)</span>
                </div>
            </div>
            <button type="button" id="toggleDatasetPreviewButton" class="button button-primary w-full">
                <img src="{{ asset('icons/play.svg') }}" class="size-4 invert" alt="" aria-hidden="true">
                <span data-dataset-preview-label>Start Live Viewfinder</span>
            </button>
        </div>

        <div class="workspace-panel flex flex-col gap-4">
            <div>
                <h3 class="text-sm font-semibold text-zinc-900">Tap a Category to Capture &amp; Save to Laptop</h3>
                <p class="mt-0.5 text-xs text-zinc-500">Saves directly to <code class="rounded bg-zinc-100 px-1 py-0.5 text-[11px]">storage/app/private/waste-training/esp32-captures/</code>.</p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                @foreach ($captureButtons as $item)
                    <button
                        type="button"
                        data-dataset-capture="{{ $item['key'] }}"
                        class="flex min-h-20 flex-col items-start justify-between rounded-xl border p-3.5 text-left transition active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50 {{ $item['tone'] }}"
                    >
                        <div class="flex w-full items-center justify-between gap-2">
                            <span class="text-sm font-semibold leading-tight">{{ $item['label'] }}</span>
                            <span data-dataset-count="{{ $item['key'] }}" class="rounded-full bg-white/90 px-2 py-0.5 text-xs font-bold tabular-nums text-zinc-800 ring-1 ring-black/10">0</span>
                        </div>
                        <span class="mt-1.5 text-[11px] opacity-75">{{ $item['hint'] }}</span>
                    </button>
                @endforeach
            </div>

            <p id="datasetCaptureMessage" class="message message-neutral" role="status" aria-live="polite">Ready — tap any category button to snap a photo from {{ $esp32Host }}.</p>
        </div>
    </div>
</section>
