@extends('auth.portal')

@section('title', 'Operator manual')

@section('content')
    <div class="portal-container py-10 sm:py-14">
        <div class="max-w-2xl"><p class="portal-eyebrow"><img src="{{ asset('icons/clipboard-list.svg') }}" class="size-4" alt="" aria-hidden="true">The RobotSys handbook</p><h1 class="mt-4 text-4xl font-semibold tracking-tight sm:text-5xl">A guide to your workspace.</h1><p class="mt-5 text-base leading-8 text-zinc-500">Everything you need to get started, review detections, and follow your robot’s activity.</p></div>
        <div class="mt-10 grid items-start gap-8 lg:grid-cols-[220px_minmax(0,1fr)] lg:gap-14">
            <aside class="manual-navigation">
                <nav aria-label="Manual contents" class="flex flex-wrap gap-1 lg:flex-col">
                    <p class="mb-2 hidden px-3 text-xs font-semibold tracking-wider text-zinc-400 uppercase lg:block">In this guide</p>
                    @foreach (['install-app' => 'Install the app', 'getting-started' => 'Getting started', 'camera' => 'Camera & detection', 'reviewing' => 'Reviewing & saving', 'robot-controls' => 'Robot operations', 'activity-logs' => 'Activity & reports', 'troubleshooting' => 'Troubleshooting'] as $anchor => $heading)
                        <a href="#{{ $anchor }}" class="manual-nav-link">{{ $heading }}</a>
                    @endforeach
                </nav>
                <div class="mt-6 hidden rounded-xl bg-emerald-50 p-4 text-xs leading-6 text-emerald-900 lg:block"><p class="font-semibold">Start with what you have.</p><p class="mt-1">A laptop camera or a photo is enough to explore the review workflow.</p></div>
            </aside>
            <div class="manual-content">
                <section id="install-app" class="manual-section" aria-labelledby="install-heading">
                    <p class="portal-eyebrow">RobotSys on your device</p><h2 id="install-heading" class="manual-heading">Install the app</h2>
                    <p>Open RobotSys through HTTPS in your device’s browser and sign in. On supported Android or desktop browsers, use <strong>Install app</strong> below or the browser’s installation menu. On iPhone or iPad, use Safari’s Share menu and choose <strong>Add to Home Screen</strong>.</p>
                    <button type="button" class="button button-primary mt-4" data-app-install hidden><img src="{{ asset('icons/arrow-down-to-line.svg') }}" class="size-4 invert" alt="" aria-hidden="true">Install app</button>
                    <p>The installed app opens <strong>Robot app</strong>, with manual START, STOP, and RESET controls alongside machine vision. It uses the same account and camera review flow as the website. Directional steering needs compatible motor-control firmware on the robot.</p>
                    <p>An internet or server connection is required. Commands are not saved for offline replay. START and RESET need recent robot telemetry and expire after five seconds if still pending. STOP requests remain queued until the robot receives them; check command history for acknowledgement.</p>
                </section>
                <section id="getting-started" class="manual-section" aria-labelledby="getting-started-heading">
                    <p class="portal-eyebrow">01 / Getting started</p><h2 id="getting-started-heading" class="manual-heading">Before your first session</h2>
                    <p>You’ll need an administrator account and a current browser. Use a laptop camera or a JPEG, PNG, or WebP image to try detection. Robot telemetry and hardware commands require a connected robot.</p>
                    <ol class="manual-steps">
                        <li><strong>Sign in.</strong> Use the account provided by your system administrator. If you don’t have credentials, ask your administrator to arrange access.</li>
                        <li><strong>Open Overview.</strong> Review the latest telemetry and its timestamp. Empty or stale data means the system has no recent robot report.</li>
                        <li><strong>Choose a task.</strong> Open Camera Detection to review an image, Command Panel for robot commands, or Recent Activity Logs to explore saved records.</li>
                    </ol>
                    <a href="{{ auth()->check() ? route('robot.dashboard') : route('login').'#sign-in' }}" class="button button-primary mt-5">{{ auth()->check() ? 'Open your workspace' : 'Go to sign in' }}<img src="{{ asset('icons/arrow-right.svg') }}" class="size-4 invert" alt="" aria-hidden="true"></a>
                    <h3 class="mt-7 font-semibold">Make the workspace your own.</h3>
                    <p>Use <strong>Active robot</strong> to choose which robot you are monitoring. Navigation, commands, camera records, and activity insights follow that selection. Activity Logs also supports searching across all robots.</p>
                    <p>Select <strong>Search workspace</strong>, or press <strong>Ctrl K</strong> on Windows or <strong>Command K</strong> on Mac, to find a page. Use the arrow keys and Enter to open a result, or Escape to close the search.</p>
                    <p>Open the health summary below the page heading to inspect connection, reported battery, and bin-level signals. Workspace activity shows actual record counts and recent battery reports. Camera observations and robot pickup totals are tracked separately.</p>
                </section>
                <section id="camera" class="manual-section" aria-labelledby="camera-guide-heading">
                    <p class="portal-eyebrow">02 / Camera & detection</p><h2 id="camera-guide-heading" class="manual-heading">Choose how you want to see.</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="manual-callout"><h3 class="flex items-center gap-2 text-sm font-semibold text-zinc-900"><img src="{{ asset('icons/camera.svg') }}" class="size-4" alt="" aria-hidden="true">Laptop camera</h3><p>Select <strong>Use Laptop Camera</strong> and allow browser camera access. Use HTTPS or localhost, keep the object in focus, and provide even lighting.</p></div>
                        <div class="manual-callout"><h3 class="flex items-center gap-2 text-sm font-semibold text-zinc-900"><img src="{{ asset('icons/upload.svg') }}" class="size-4" alt="" aria-hidden="true">Upload an image</h3><p>Select <strong>Upload image</strong> and choose a JPEG, PNG, or WebP photo. This lets you try the review workflow without a connected camera.</p></div>
                    </div>
                    <p>For a configured ESP32-CAM, select <strong>Connect ESP32-CAM</strong>. If that control is disabled, ask your administrator to configure the camera connection. The camera and the computer running RobotSys must be able to reach one another.</p>
                    <p>When a stream address is configured, the preview uses continuous MJPEG playback while detection analyzes separate snapshots. Check the <strong>Preview</strong> status: <strong>Snapshot preview</strong> means live streaming is unavailable. Close other camera streaming tabs, allow local-network access if your browser requests it, then use <strong>Retry live stream</strong>. For an HTTPS installation, the stream must also be served over HTTPS through a trusted camera gateway.</p>
                    <p>Select <strong>Live Detection</strong> for repeated camera analysis, or <strong>Review frame</strong> to inspect a frame. Use <strong>Stop camera</strong> when you finish.</p>
                    <p><strong>RobotSys Waste (Experimental)</strong> is the custom trained detector for plastic bottles, other plastics, paper, and drink/food cans. Its current validation does not meet deployment criteria, and leaves, wood, and rocks are untrained. Always review its labels. Stop the camera before selecting an EfficientDet model to return to general-object detection.</p>
                    <p>Use <strong>Expand view</strong> for a larger camera workspace. Collapse the view or press Escape to return. Once a frame is ready for review, <strong>Download review frame</strong> saves a JPEG copy to your device.</p>
                </section>
                <section id="reviewing" class="manual-section" aria-labelledby="review-guide-heading">
                    <p class="portal-eyebrow">03 / Reviewing & saving</p><h2 id="review-guide-heading" class="manual-heading">Turn a detection into a reviewed record.</h2>
                    <ol class="manual-steps">
                        <li><strong>Review a frame.</strong> Inspect the image and use the Detected object selector when several objects are present.</li>
                        <li><strong>Check the result.</strong> Compare the object label and box with what is actually visible. Confidence is a model score, not a guarantee that the object or material is correct.</li>
                        <li><strong>Confirm the waste category.</strong> Choose the material yourself. The available category choices do not mean the detection model can automatically identify every material.</li>
                        <li><strong>Confirm and save.</strong> Save the reviewed detection, then find it in Detection History and the activity logs. A saved detection does not confirm that the robot picked up the object.</li>
                    </ol>
                    <div class="manual-note"><img src="{{ asset('icons/eye.svg') }}" class="mt-0.5 size-4 shrink-0" alt="" aria-hidden="true"><p><strong>Keep a person in the review loop.</strong> If the model label is wrong or the image is unclear, review another frame before saving.</p></div>
                </section>
                <section id="robot-controls" class="manual-section" aria-labelledby="controls-guide-heading">
                    <p class="portal-eyebrow">04 / Robot operations</p><h2 id="controls-guide-heading" class="manual-heading">Understand what the robot reports.</h2>
                    <p>Overview summarizes reported battery, movement, collection counts, and operating time. Check <strong>Last telemetry</strong> and the connection indicator before relying on these values.</p>
                    <p>Use the <strong>Command Panel</strong> to send a supported command. A queued or pending command means it has been recorded; check command history for the robot’s acknowledgement or completion before assuming it ran.</p>
                    <div class="manual-note"><img src="{{ asset('icons/command.svg') }}" class="mt-0.5 size-4 shrink-0" alt="" aria-hidden="true"><p>Camera analysis can work without robot hardware. Robot movement and pickup results depend on connected hardware and its reported status.</p></div>
                </section>
                <section id="activity-logs" class="manual-section" aria-labelledby="logs-guide-heading">
                    <p class="portal-eyebrow">05 / Activity & reports</p><h2 id="logs-guide-heading" class="manual-heading">Keep the full picture.</h2>
                    <p>Open <strong>Recent Activity Logs</strong> to find saved camera reviews and robot reports. Use the available search and filters to narrow the records, inspect saved evidence when present, and export the filtered logs for reporting.</p>
                    <p>Use <strong>Today</strong>, <strong>Last 7 days</strong>, or <strong>Last 30 days</strong> to set a date range while keeping your other filters. Choose <strong>All time</strong> to remove just the dates, or remove an individual filter with its chip. Date ranges use the time zone shown on the filter form.</p>
                    <p><strong>Waste Sorting</strong> summarizes reported collection categories. <strong>Performance Evaluation</strong> shows operational measures from the available records. Neither page establishes the camera model’s real-world detection accuracy.</p>
                </section>
                <section id="troubleshooting" class="manual-section" aria-labelledby="troubleshooting-heading">
                    <p class="portal-eyebrow">06 / Troubleshooting</p><h2 id="troubleshooting-heading" class="manual-heading">A little help when you need it.</h2>
                    <div class="mt-5 divide-y divide-zinc-200">
                        @foreach ([['Why can’t I sign in?', 'Check your email address and password and confirm that your account has administrator access. If you’re still unable to sign in, contact your system administrator.'], ['Why won’t the laptop camera open?', 'Allow camera access in the browser’s site settings, close any other app using the camera, and retry. Open RobotSys through HTTPS or localhost so the browser can grant camera access. You can also use Upload image.'], ['Why is Connect ESP32-CAM disabled?', 'The camera connection has not been configured for this installation. You can continue with your laptop camera or an uploaded photo while your administrator prepares the ESP32-CAM connection.'], ['Why are objects missing or labeled incorrectly?', 'Try better lighting, a sharper image, a simpler background, and an object that fills more of the frame. Review the confidence setting and inspect another frame. Always confirm the material yourself before saving.'], ['Why is telemetry empty or stale?', 'Check the latest timestamp. The robot may be offline or not sending recent reports. Refresh the dashboard and check the hardware connection; camera detection can still work independently.'], ['Why is a command still pending?', 'A pending command has been queued but has not yet been confirmed by the robot. Check the robot connection and command history. Avoid repeatedly sending a command while waiting for confirmation.']] as [$question, $answer])
                            <details class="manual-faq"><summary><span>{{ $question }}</span><img src="{{ asset('icons/chevron-down.svg') }}" class="details-chevron size-4 shrink-0" alt="" aria-hidden="true"></summary><p>{{ $answer }}</p></details>
                        @endforeach
                    </div>
                </section>
                <a href="#main-content" class="text-link">Back to top<span aria-hidden="true">&uarr;</span></a>
            </div>
        </div>
    </div>
@endsection
