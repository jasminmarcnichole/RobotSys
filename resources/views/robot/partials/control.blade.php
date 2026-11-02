<section class="control-workspace flex flex-col gap-8" aria-labelledby="control-heading" data-control-app>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><p class="portal-eyebrow">RobotSys on your device</p><h2 id="control-heading" class="mt-2 text-2xl font-semibold tracking-tight">Control & machine vision</h2><p class="mt-2 text-sm leading-6 text-zinc-500">Operate the robot and review what the camera sees from one workspace.</p></div>
        <button type="button" class="button button-secondary" data-app-install hidden><img src="{{ asset('icons/arrow-down-to-line.svg') }}" class="size-4" alt="" aria-hidden="true">Install app</button>
    </div>
    <nav aria-label="Robot app sections" class="control-navigation"><a href="#commands" class="button button-primary"><img src="{{ asset('icons/command.svg') }}" class="size-4 invert" alt="" aria-hidden="true">Manual control</a><a href="#camera" class="button button-secondary"><img src="{{ asset('icons/scan-line.svg') }}" class="size-4" alt="" aria-hidden="true">Machine vision</a><a href="{{ route('manual') }}#install-app" class="text-link">Installation help</a></nav>
    <div class="control-panel">
        <p data-control-readiness class="message message-neutral mb-5" role="status">Checking robot connection…</p>
        @include('robot.partials.commands')
        <p class="mt-5 border-t border-zinc-200 pt-4 text-xs leading-6 text-zinc-500">This version supports START, STOP, and RESET. Directional steering requires compatible ESP32 motor-control firmware. Queued commands are confirmed separately in command history.</p>
    </div>
    <div class="control-panel">@include('robot.partials.camera')</div>
</section>
