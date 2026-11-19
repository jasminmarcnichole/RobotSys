<section id="commands" class="grid gap-6 xl:grid-cols-[minmax(0,0.85fr)_minmax(0,1.15fr)]">
    <section aria-labelledby="command-panel-heading" class="{{ $activePage === 'commands' ? 'workspace-panel' : '' }}">
        <div class="section-heading"><h2 id="command-panel-heading">Command Panel</h2></div>
        <dl class="divide-y divide-zinc-100 text-sm">
            <div class="data-row"><dt class="text-zinc-500">Last reported state</dt><dd id="command_reported_state" class="font-medium">{{ $latestStatus }}</dd></div>
            <div class="data-row"><dt class="text-zinc-500">Last telemetry</dt><dd id="command_last_updated" class="text-right font-medium">{{ $lastUpdated }}</dd></div>
        </dl>
        <div class="mt-5 grid gap-3 sm:grid-cols-2">
            <button type="button" data-command="START" class="button button-primary min-h-12">
                <img src="{{ asset('icons/play.svg') }}" class="size-4 invert" alt="" aria-hidden="true">
                START (AUTO MODE)
            </button>
            <button type="button" data-command="STOP" class="button button-danger min-h-12">
                <img src="{{ asset('icons/square.svg') }}" class="size-4 invert" alt="" aria-hidden="true">
                STOP
            </button>
            <button type="button" data-command="RESET" class="button button-secondary min-h-12 sm:col-span-2">
                <img src="{{ asset('icons/refresh-cw.svg') }}" class="size-4" alt="" aria-hidden="true">
                RESET (MANUAL MODE)
            </button>
        </div>
        <p id="commandMessage" class="message message-neutral mt-4" role="status" aria-live="polite">No command pending submission.</p>
        <p class="mt-3 text-xs leading-5 text-zinc-500">START enables autonomous ultrasonic obstacle-avoidance roaming. STOP halts the wheels immediately.</p>

        <div class="mt-5 border-t border-zinc-200 pt-4">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-zinc-800">Direct Wi-Fi Mode, Steering &amp; 6-DOF Arm (192.168.4.1)</h3>
                <span id="directRobotStatus" class="text-xs font-medium text-emerald-700">Ready</span>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-2">
                <button type="button" data-direct-cmd="A" class="button button-primary min-h-10 text-xs font-semibold">AUTO SENSOR ON (A)</button>
                <button type="button" data-direct-cmd="M" class="button button-secondary min-h-10 text-xs font-semibold">MANUAL MODE (M)</button>
            </div>
            <div class="mt-3 grid grid-cols-3 gap-2 rounded-2xl border border-zinc-200/90 bg-zinc-100/70 p-2.5 shadow-inner">
                <div></div>
                <button type="button" data-direct-drive="F" class="button button-secondary min-h-14 rounded-2xl px-2 text-xs font-semibold whitespace-nowrap transition-transform active:scale-95 sm:min-h-10 sm:rounded-xl">FORWARD</button>
                <div></div>
                <button type="button" data-direct-drive="L" class="button button-secondary min-h-14 rounded-2xl px-2 text-xs font-semibold whitespace-nowrap transition-transform active:scale-95 sm:min-h-10 sm:rounded-xl">LEFT</button>
                <button type="button" data-direct-cmd="S" class="button button-danger min-h-14 rounded-2xl px-2 text-xs font-semibold whitespace-nowrap transition-transform active:scale-95 sm:min-h-10 sm:rounded-xl">STOP</button>
                <button type="button" data-direct-drive="R" class="button button-secondary min-h-14 rounded-2xl px-2 text-xs font-semibold whitespace-nowrap transition-transform active:scale-95 sm:min-h-10 sm:rounded-xl">RIGHT</button>
                <div></div>
                <button type="button" data-direct-drive="B" class="button button-secondary min-h-14 rounded-2xl px-2 text-xs font-semibold whitespace-nowrap transition-transform active:scale-95 sm:min-h-10 sm:rounded-xl">BACKWARD</button>
                <div></div>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-2.5">
                <button type="button" data-direct-cmd="O" class="button button-secondary min-h-11 text-xs">Open Gripper (Ch 5)</button>
                <button type="button" data-direct-cmd="C" class="button button-secondary min-h-11 text-xs">Close Gripper (Ch 5)</button>
                <button type="button" data-direct-cmd="P" class="button button-secondary min-h-11 text-xs">Grab -&gt; Rear Plastic Bin</button>
                <button type="button" data-direct-cmd="G" class="button button-secondary min-h-11 text-xs">Grab -&gt; Rear General Bin</button>
            </div>
            <div class="mt-2.5 grid grid-cols-3 gap-2">
                <button type="button" data-direct-cmd="[" class="button button-secondary min-h-9 text-xs" title="Shift Channel 0 Base Center +5 deg Left &amp; Save">Base Left (+5&deg;)</button>
                <button type="button" data-direct-cmd="H" class="button button-secondary min-h-9 text-xs" title="Move Arm to Home Center">Home Arm (H)</button>
                <button type="button" data-direct-cmd="]" class="button button-secondary min-h-9 text-xs" title="Shift Channel 0 Base Center -5 deg Right &amp; Save">Base Right (-5&deg;)</button>
            </div>
        </div>
    </section>

    <section aria-labelledby="recent-commands-heading" class="{{ $activePage === 'commands' ? 'workspace-panel' : '' }}">
        <div class="section-heading"><h3 id="recent-commands-heading">Recent Commands</h3><img src="{{ asset('icons/clipboard-list.svg') }}" class="size-4 opacity-70" alt="" aria-hidden="true"></div>
        <div id="commandHistory" class="divide-y divide-zinc-100">
            @forelse ($recentCommands as $command)
                @php
                    $commandTone = match (strtolower($command->status)) {
                        'completed', 'received', 'executed', 'acknowledged' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                        'failed', 'cancelled', 'expired' => 'bg-red-50 text-red-700 ring-red-200',
                        'pending', 'queued' => 'bg-amber-50 text-amber-800 ring-amber-200',
                        default => 'bg-zinc-100 text-zinc-600 ring-zinc-200',
                    };
                @endphp
                <div class="command-history-row">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold">{{ $command->command }}</p>
                        <p class="mt-1 break-words text-xs text-zinc-500">{{ $command->created_at?->diffForHumans() }}</p>
                    </div>
                    <span class="status-badge {{ $commandTone }}">{{ $command->status }}</span>
                </div>
            @empty
                <p class="empty-state">No commands have been queued yet.</p>
            @endforelse
        </div>
    </section>

    <section aria-labelledby="arm-calibration-heading" class="{{ $activePage === 'commands' ? 'workspace-panel' : 'mt-6 border-t border-zinc-200 pt-5' }} xl:col-span-2">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h3 id="arm-calibration-heading" class="text-sm font-semibold text-zinc-800">6-DOF Arm Settings &amp; Servo Angle Calibration (192.168.4.1)</h3>
                <p class="mt-0.5 text-xs text-zinc-500">Move each servo live to test trash reach &amp; grip, then save the angles permanently to ESP32 flash.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <label for="armServoSpeedSelect" class="text-xs font-medium text-zinc-600">Servo Speed:</label>
                <select id="armServoSpeedSelect" class="form-input min-h-8 w-auto py-1 text-xs">
                    <option value="45">Ultra Gentle (45 ms/deg &mdash; No Sway)</option>
                    <option value="30" selected>Slow &amp; Smooth (30 ms/deg &mdash; Default)</option>
                    <option value="20">Medium (20 ms/deg)</option>
                    <option value="12">Fast (12 ms/deg)</option>
                </select>
                <span id="armCalibrationStatus" class="text-xs font-medium text-zinc-600">Ready</span>
                <button type="button" id="syncArmConfigBtn" class="button button-secondary min-h-8 px-3 text-xs">Sync from Robot</button>
            </div>
        </div>

        @php
            $armChannels = [
                ['ch' => 0, 'name' => 'Ch 0: Base Rotation', 'default' => 110, 'hint' => '0° Right <-> 180° Left'],
                ['ch' => 1, 'name' => 'Ch 1: Shoulder', 'default' => 90, 'hint' => '45° Rear <-> 90° Up <-> 135° Front'],
                ['ch' => 2, 'name' => 'Ch 2: Elbow', 'default' => 90, 'hint' => '50° Rear <-> 90° Up <-> 125° Front'],
                ['ch' => 3, 'name' => 'Ch 3: Wrist Pitch', 'default' => 90, 'hint' => '60° Rear <-> 90° Level <-> 115° Down'],
                ['ch' => 4, 'name' => 'Ch 4: Wrist Roll', 'default' => 90, 'hint' => '0° <-> 180° Twist'],
                ['ch' => 5, 'name' => 'Ch 5: Gripper', 'default' => 40, 'hint' => '40° Open <-> 130° Closed'],
            ];
        @endphp

        <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($armChannels as $servo)
                <div class="rounded-xl border border-zinc-200/80 bg-zinc-50/60 p-3" data-servo-card="{{ $servo['ch'] }}">
                    <div class="flex items-center justify-between gap-2">
                        <label for="armServoRange{{ $servo['ch'] }}" class="text-xs font-semibold text-zinc-800">{{ $servo['name'] }}</label>
                        <output id="armServoValue{{ $servo['ch'] }}" class="rounded bg-white px-2 py-0.5 text-xs font-bold tabular-nums text-emerald-700 ring-1 ring-zinc-200">{{ $servo['default'] }}&deg;</output>
                    </div>
                    <p class="mt-0.5 text-[11px] text-zinc-500">{{ $servo['hint'] }}</p>
                    <div class="mt-2 flex items-center gap-1.5">
                        <button type="button" data-servo-step="-5" data-servo-ch="{{ $servo['ch'] }}" class="button button-secondary min-h-7 px-2 text-[11px] font-semibold">-5</button>
                        <button type="button" data-servo-step="-1" data-servo-ch="{{ $servo['ch'] }}" class="button button-secondary min-h-7 px-2 text-[11px]">-1</button>
                        <input type="range" id="armServoRange{{ $servo['ch'] }}" data-servo-range="{{ $servo['ch'] }}" min="0" max="180" value="{{ $servo['default'] }}" step="1" class="min-h-8 w-full accent-emerald-700">
                        <button type="button" data-servo-step="1" data-servo-ch="{{ $servo['ch'] }}" class="button button-secondary min-h-7 px-2 text-[11px]">+1</button>
                        <button type="button" data-servo-step="5" data-servo-ch="{{ $servo['ch'] }}" class="button button-secondary min-h-7 px-2 text-[11px] font-semibold">+5</button>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4 grid gap-3 border-t border-zinc-200/80 pt-4 lg:grid-cols-2">
            <div class="rounded-xl border border-zinc-200/80 bg-zinc-50/50 p-3">
                <p class="text-xs font-semibold text-zinc-800">Test / Preview Saved Poses (Moves Arm)</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <button type="button" data-arm-preview="home" class="button button-secondary min-h-8 px-3 text-xs">Go Home Upright</button>
                    <button type="button" data-arm-preview="front" class="button button-secondary min-h-8 px-3 text-xs">Test Front Reach</button>
                    <button type="button" data-arm-preview="rear" class="button button-secondary min-h-8 px-3 text-xs">Test Rear Drop</button>
                    <button type="button" data-arm-preview="open" class="button button-secondary min-h-8 px-3 text-xs">Test Open</button>
                    <button type="button" data-arm-preview="closed" class="button button-secondary min-h-8 px-3 text-xs">Test Close</button>
                </div>
            </div>
            <div class="rounded-xl border border-emerald-200/80 bg-emerald-50/40 p-3">
                <p class="text-xs font-semibold text-zinc-800">Save Current Slider Position As (Permanent in Flash)</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <button type="button" data-arm-save="home" class="button button-primary min-h-8 px-3 text-xs">Save Home (All 6 Ch)</button>
                    <button type="button" data-arm-save="front" class="button button-primary min-h-8 px-3 text-xs">Save Front Reach (All 6 Ch)</button>
                    <button type="button" data-arm-save="rear" class="button button-primary min-h-8 px-3 text-xs">Save Rear Drop (All 6 Ch)</button>
                    <button type="button" data-arm-save="open" class="button button-secondary min-h-8 px-3 text-xs">Save Grip Open (Ch 5)</button>
                    <button type="button" data-arm-save="closed" class="button button-secondary min-h-8 px-3 text-xs">Save Grip Closed (Ch 5)</button>
                    <button type="button" id="resetArmCalibrationBtn" class="button button-danger min-h-8 px-3 text-xs">Reset Defaults</button>
                </div>
            </div>
        </div>
        <p id="armSavedPosesSummary" class="mt-2.5 text-xs text-zinc-500">Saved poses: sync with Main ESP32 (192.168.4.1) to view stored angles.</p>
    </section>
</section>
