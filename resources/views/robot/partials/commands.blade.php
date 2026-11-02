<section id="commands" class="grid gap-8 xl:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
    <section aria-labelledby="command-panel-heading">
        <div class="section-heading"><h2 id="command-panel-heading">Command Panel</h2><span class="text-xs text-zinc-500">{{ $robotId }}</span></div>
        <dl class="divide-y divide-zinc-200 text-sm">
            <div class="data-row"><dt class="text-zinc-500">Last reported state</dt><dd id="command_reported_state" class="font-medium">{{ $latestStatus }}</dd></div>
            <div class="data-row"><dt class="text-zinc-500">Last telemetry</dt><dd id="command_last_updated" class="text-right font-medium">{{ $lastUpdated }}</dd></div>
        </dl>
        <div class="mt-5 grid gap-3 sm:grid-cols-2">
            <button type="button" data-command="START" class="button button-primary min-h-12">
                <img src="{{ asset('icons/play.svg') }}" class="size-4 invert" alt="" aria-hidden="true">
                START
            </button>
            <button type="button" data-command="STOP" class="button button-danger min-h-12">
                <img src="{{ asset('icons/square.svg') }}" class="size-4 invert" alt="" aria-hidden="true">
                STOP
            </button>
            <button type="button" data-command="RESET" class="button button-secondary min-h-12 sm:col-span-2">
                <img src="{{ asset('icons/refresh-cw.svg') }}" class="size-4" alt="" aria-hidden="true">
                RESET
            </button>
        </div>
        <p id="commandMessage" class="message message-neutral mt-4" role="status" aria-live="polite">No command pending submission.</p>
        <p class="mt-3 text-xs leading-5 text-zinc-500">Check command history for acknowledgement and completion from the robot.</p>
    </section>

    <section aria-labelledby="recent-commands-heading">
        <div class="section-heading"><h3 id="recent-commands-heading">Recent Commands</h3><img src="{{ asset('icons/clipboard-list.svg') }}" class="size-4" alt="" aria-hidden="true"></div>
        <div id="commandHistory" class="divide-y divide-zinc-200">
            @forelse ($recentCommands as $command)
                @php
                    $commandTone = match (strtolower($command->status)) {
                        'completed', 'executed', 'acknowledged' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                        'failed', 'cancelled' => 'bg-red-50 text-red-700 ring-red-200',
                        'pending', 'queued' => 'bg-amber-50 text-amber-800 ring-amber-200',
                        default => 'bg-zinc-100 text-zinc-600 ring-zinc-200',
                    };
                @endphp
                <div class="command-history-row">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold">{{ $command->command }}</p>
                        <p class="mt-1 break-words text-xs text-zinc-500">{{ $command->robot_id }} <span aria-hidden="true">&middot;</span> {{ $command->created_at?->diffForHumans() }}</p>
                    </div>
                    <span class="status-badge {{ $commandTone }}">{{ $command->status }}</span>
                </div>
            @empty
                <p class="empty-state">No commands have been queued yet.</p>
            @endforelse
        </div>
    </section>
</section>
