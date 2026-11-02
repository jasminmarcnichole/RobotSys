@php
    $filterValue = static function (string $key) use ($filters): string {
        $value = old($key, $filters[$key] ?? '');
        return is_scalar($value) ? (string) $value : '';
    };
    $hasFilters = collect($filters)->except('per_page')->filter(fn ($value) => filled($value))->isNotEmpty();
@endphp
<section id="logs" class="flex min-w-0 flex-col gap-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-semibold">Recent Activity Logs</h2>
        <a href="{{ route('robot.dashboard.logs.export', $filters) }}" class="button button-secondary">
            <img src="{{ asset('icons/arrow-down-to-line.svg') }}" class="size-4" alt="" aria-hidden="true">
            Export CSV
        </a>
    </div>
    <nav class="flex flex-wrap items-center gap-2" aria-label="Quick activity filters">
        <span class="mr-1 text-xs font-medium text-zinc-500">Quick range</span>
        @foreach (['Today' => 0, 'Last 7 days' => 6, 'Last 30 days' => 29] as $label => $days)
            <a href="{{ route('robot.dashboard.logs', array_merge(collect($filters)->except('page')->all(), ['from' => now()->subDays($days)->toDateString(), 'to' => now()->toDateString()])) }}" class="filter-shortcut">{{ $label }}</a>
        @endforeach
        <a href="{{ route('robot.dashboard.logs', collect($filters)->except(['page', 'from', 'to'])->all()) }}" class="filter-shortcut">All time</a>
    </nav>
    @if ($hasFilters)
        <div class="flex flex-wrap gap-2" aria-label="Active activity filters">
            @foreach (collect($filters)->except('per_page')->filter(fn ($value) => filled($value)) as $key => $value)
                <a href="{{ route('robot.dashboard.logs', collect($filters)->except([$key, 'page'])->all()) }}" class="filter-chip" aria-label="Remove {{ str_replace('_', ' ', $key) }} filter"><span>{{ str_replace('_', ' ', ucfirst($key)) }}: {{ $value }}</span><span aria-hidden="true">×</span></a>
            @endforeach
        </div>
    @endif
    @if ($errors->any())
        <div class="message bg-red-50 text-red-800" role="alert">
            <p class="font-medium">Check the activity filters.</p>
            <ul class="mt-1 list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    <form method="GET" action="{{ route('robot.dashboard.logs') }}" class="grid items-end gap-4 border-y border-zinc-200 py-4 sm:grid-cols-2 xl:grid-cols-3">
        <div class="field-group">
            <label for="logRobotId" class="field-label">Robot ID</label>
            <input id="logRobotId" name="robot_id" value="{{ $filterValue('robot_id') }}" placeholder="All robots" list="knownRobots" maxlength="50" class="form-input" aria-invalid="{{ $errors->has('robot_id') ? 'true' : 'false' }}">
            <datalist id="knownRobots">@foreach ($robotOptions as $option)<option value="{{ $option }}"></option>@endforeach</datalist>
        </div>
        <div class="field-group">
            <label for="logTrashType" class="field-label">Waste category</label>
            <input id="logTrashType" name="trash_type" value="{{ $filterValue('trash_type') }}" placeholder="All categories" maxlength="100" class="form-input" aria-invalid="{{ $errors->has('trash_type') ? 'true' : 'false' }}">
        </div>
        <div class="field-group">
            <label for="logSource" class="field-label">Source</label>
            <select id="logSource" name="source" class="form-input">
                <option value="">All sources</option>
                <option value="robot_sensor" @selected($filterValue('source') === 'robot_sensor')>Robot telemetry</option>
                <option value="laptop_camera" @selected($filterValue('source') === 'laptop_camera')>Browser detection</option>
                <option value="robot_camera" @selected($filterValue('source') === 'robot_camera')>Robot camera</option>
            </select>
        </div>
        <div class="field-group">
            <label for="logFrom" class="field-label">From date</label>
            <input id="logFrom" type="date" name="from" value="{{ $filterValue('from') }}" class="form-input" aria-invalid="{{ $errors->has('from') ? 'true' : 'false' }}">
        </div>
        <div class="field-group">
            <label for="logTo" class="field-label">To date</label>
            <input id="logTo" type="date" name="to" value="{{ $filterValue('to') }}" class="form-input" aria-invalid="{{ $errors->has('to') ? 'true' : 'false' }}">
        </div>
        <div class="field-group">
            <label for="logPerPage" class="field-label">Records per page</label>
            <select id="logPerPage" name="per_page" class="form-input">
                @foreach ([15, 25, 50] as $size)
                    <option value="{{ $size }}" @selected(($filterValue('per_page') ?: '15') === (string) $size)>{{ $size }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 sm:col-span-2 xl:col-span-3">
            <span class="text-xs text-zinc-500">Time zone: {{ config('app.timezone') }}</span>
            <div class="flex items-center gap-2">
            <button type="submit" class="button button-primary">
                <img src="{{ asset('icons/search.svg') }}" class="size-4 invert" alt="" aria-hidden="true">
                Apply filters
            </button>
                <a href="{{ route('robot.dashboard.logs') }}" class="button button-secondary">Clear</a>
            </div>
        </div>
    </form>

    <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-zinc-500">
        <p><strong class="font-semibold text-zinc-800">{{ number_format($logs->total()) }}</strong> {{ $logs->total() === 1 ? 'record' : 'records' }}{{ $hasFilters ? ' matching filters' : '' }}</p>
        @if ($logs->isNotEmpty())
            <span>{{ number_format($logs->firstItem()) }}&ndash;{{ number_format($logs->lastItem()) }} of {{ number_format($logs->total()) }}</span>
        @endif
    </div>

    @if ($logs->isEmpty())
        <div class="flex flex-col items-center gap-3 border-b border-zinc-200 py-14 text-center">
            <img src="{{ asset('icons/clipboard-list.svg') }}" class="size-8 opacity-50" alt="" aria-hidden="true">
            @if ($logs->total() > 0)
                <p class="font-medium">No records on this page.</p>
                <a href="{{ $logs->url(1) }}" class="text-link">Return to first page<img src="{{ asset('icons/arrow-right.svg') }}" class="size-4" alt="" aria-hidden="true"></a>
            @else
                <p class="font-medium">{{ $hasFilters ? 'No activity matches these filters.' : 'No activity recorded yet.' }}</p>
            @endif
            @if ($hasFilters && $logs->total() === 0)
                <a href="{{ route('robot.dashboard.logs') }}" class="text-link">Clear filters<img src="{{ asset('icons/arrow-right.svg') }}" class="size-4" alt="" aria-hidden="true"></a>
            @endif
        </div>
    @else
        <div class="table-scroll" role="region" aria-label="Activity logs" tabindex="0">
            <table class="operations-table min-w-[1180px]">
                <caption class="sr-only">Robot telemetry and saved camera detections</caption>
                <thead>
                    <tr>
                        <th scope="col">Time</th>
                        <th scope="col">Robot / source</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-right">Battery</th>
                        <th scope="col" class="text-right">Detected</th>
                        <th scope="col">Pickups</th>
                        <th scope="col">Waste counts</th>
                        <th scope="col">Category / object</th>
                        <th scope="col" class="text-right">Confidence</th>
                        <th scope="col">Snapshot</th>
                        <th scope="col">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($logs as $log)
                        <tr>
                            <td class="whitespace-nowrap"><time datetime="{{ $log->created_at?->toIso8601String() }}" class="text-xs">{{ $log->created_at?->format('M d, Y') }}<span class="mt-1 block text-zinc-500">{{ $log->created_at?->format('h:i:s A') }}</span></time></td>
                            <td class="max-w-48"><span class="break-words font-medium text-zinc-950">{{ $log->robot_id }}</span><span class="mt-1 block text-xs text-zinc-500">{{ match ($log->detection_source) { 'laptop_camera' => 'Browser detection', 'robot_camera' => 'Robot camera', default => 'Robot telemetry' } }}</span></td>
                            <td class="min-w-36"><span class="status-badge bg-zinc-100 text-zinc-600 ring-zinc-200">{{ $log->movement_status }}</span></td>
                            <td class="text-right tabular-nums">{{ $log->detection_source === 'laptop_camera' ? '--' : number_format((float) $log->battery_level, 0).'%' }}</td>
                            <td class="text-right tabular-nums">{{ $log->detection_source === 'laptop_camera' ? '--' : $log->detected_trash_count }}</td>
                            <td class="whitespace-nowrap text-xs">
                                @if ($log->detection_source === 'laptop_camera')
                                    <span class="text-zinc-400">--</span>
                                @else
                                    <span class="text-emerald-800">{{ $log->successful_pickup_count }} successful</span><span class="mt-1 block text-red-700">{{ $log->failed_pickup_count }} failed</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-xs">
                                @if ($log->detection_source === 'laptop_camera')
                                    <span class="text-zinc-400">--</span>
                                @else
                                    <span>{{ $log->plastic_waste_count }} plastic</span><span class="mt-1 block text-zinc-500">{{ $log->general_waste_count }} general</span>
                                @endif
                            </td>
                            <td class="max-w-48"><span class="break-words text-xs font-medium">{{ $log->last_detected_trash_type ?? '--' }}</span><span class="mt-1 block break-words text-xs text-zinc-500">{{ $log->detected_object_label ?? '--' }}</span></td>
                            <td class="text-right tabular-nums">{{ $log->detection_confidence !== null ? number_format((float) $log->detection_confidence, 0).'%' : '--' }}</td>
                            <td>
                                @if ($log->camera_snapshot_url)
                                    @php
                                        $evidence = [
                                            'id' => $log->id,
                                            'robot' => $log->robot_id,
                                            'time' => $log->created_at?->format('M d, Y H:i:s').' '.config('app.timezone'),
                                            'category' => $log->last_detected_trash_type ?? '--',
                                            'object' => $log->detected_object_label ?? '--',
                                            'confidence' => $log->detection_confidence !== null ? number_format((float) $log->detection_confidence, 1).'%' : '--',
                                            'model' => $log->classification_model ?? '--',
                                            'remarks' => $log->remarks ?? 'No remarks recorded.',
                                        ];
                                    @endphp
                                    <a href="{{ $log->camera_snapshot_url }}" data-evidence='@json($evidence)' target="_blank" rel="noopener noreferrer" class="group block w-16" aria-label="View evidence for record {{ $log->id }}" aria-haspopup="dialog">
                                        <img class="h-12 w-16 rounded-md border border-zinc-200 bg-zinc-100 object-contain" src="{{ $log->camera_snapshot_url }}" alt="{{ $log->detected_object_label ?? 'Detection snapshot' }}" loading="lazy">
                                        <span class="mt-1 block text-center text-xs font-medium text-emerald-800 group-hover:underline">View</span>
                                    </a>
                                @else
                                    <span class="text-zinc-400">--</span>
                                @endif
                            </td>
                            <td class="w-56 min-w-40 text-xs">
                                @if ($log->remarks)
                                    <details>
                                        <summary class="text-link text-xs">View remarks</summary>
                                        <p class="mt-2 break-words leading-5">{{ $log->remarks }}</p>
                                    </details>
                                @else
                                    <span class="text-zinc-400">--</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @if ($logs->total() > 0)
        <div class="pt-1">{{ $logs->links() }}</div>
    @endif

    <dialog id="evidenceDialog" class="evidence-dialog" aria-labelledby="evidenceTitle">
        <header class="flex items-center justify-between gap-3 border-b border-zinc-200 px-5 py-3">
            <div class="min-w-0">
                <h2 id="evidenceTitle" class="text-base font-semibold">Detection evidence</h2>
                <p data-evidence-field="time" class="mt-1 text-xs text-zinc-500"></p>
            </div>
            <form method="dialog">
                <button class="icon-button" aria-label="Close evidence" title="Close evidence" autofocus>
                    <img src="{{ asset('icons/x.svg') }}" class="size-5" alt="" aria-hidden="true">
                </button>
            </form>
        </header>
        <div class="grid min-w-0 gap-5 p-5 md:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
            <div class="min-w-0">
                <div class="relative flex aspect-[4/3] items-center justify-center overflow-hidden rounded-md bg-zinc-100">
                    <img id="evidenceImage" class="h-full w-full object-contain" alt="">
                    <p id="evidenceImageStatus" class="absolute inset-0 flex items-center justify-center bg-zinc-100 p-4 text-center text-sm text-zinc-500" role="status">Loading image...</p>
                </div>
                <a id="evidenceOriginal" class="text-link mt-2" target="_blank" rel="noopener noreferrer">
                    Open original
                    <img src="{{ asset('icons/arrow-right.svg') }}" class="size-4" alt="" aria-hidden="true">
                </a>
            </div>
            <div class="min-w-0">
                <dl class="divide-y divide-zinc-200">
                    @foreach (['robot' => 'Robot', 'object' => 'Detected object', 'category' => 'Waste category', 'confidence' => 'Model confidence', 'model' => 'Model'] as $field => $label)
                        <div class="flex flex-wrap justify-between gap-x-4 gap-y-1 py-3 first:pt-0">
                            <dt class="text-sm text-zinc-500">{{ $label }}</dt>
                            <dd data-evidence-field="{{ $field }}" class="min-w-0 text-sm font-medium [overflow-wrap:anywhere]"></dd>
                        </div>
                    @endforeach
                </dl>
                <h3 class="mt-4 text-sm font-medium">Remarks</h3>
                <p data-evidence-field="remarks" class="mt-2 whitespace-pre-wrap text-sm leading-6 text-zinc-600 [overflow-wrap:anywhere]"></p>
            </div>
        </div>
    </dialog>
</section>
