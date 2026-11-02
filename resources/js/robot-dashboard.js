import { requestJson, setMessage, setPill, setText } from './robot-ui';
import { setupCamera } from './robot-camera';

const dashboard = window.robotDashboard;

if (dashboard) {
    const percent = (value) => Math.max(0, Math.min(100, Math.round(Number(value) || 0)));
    const count = (value) => Math.max(0, Number(value) || 0);
    const duration = (value) => {
        const seconds = Math.round(count(value));
        return seconds >= 60 ? `${Math.floor(seconds / 60)}m ${seconds % 60}s` : `${seconds}s`;
    };
    const progress = (id, value) => {
        const element = document.getElementById(id);
        if (element) {
            element.style.width = `${percent(value)}%`;
        }
    };
    let pollTimer;
    let polling = false;
    const pendingCommands = new Set();
    const commandButtons = [...document.querySelectorAll('[data-command]')];
    const commandHistory = document.getElementById('commandHistory');
    const commandMessage = document.getElementById('commandMessage');
    let controlTelemetry = dashboard.latestLog;
    let readinessTimer;
    let latestDetectionId = dashboard.latestDetection?.id ?? null;

    function robotReady() {
        const age = Date.now() - Date.parse(controlTelemetry?.created_at ?? '');
        return Number.isFinite(age) && age >= 0 && age <= 15000
            && [null, undefined, 'robot_sensor'].includes(controlTelemetry?.detection_source);
    }

    function updateCommandControls() {
        const ready = robotReady();
        commandButtons.forEach((button) => {
            button.disabled = !navigator.onLine
                || (pendingCommands.size > 0 && (button.dataset.command !== 'STOP' || pendingCommands.has('STOP')))
                || (dashboard.controlApp && button.dataset.command !== 'STOP' && !ready);
        });
        const readiness = document.querySelector('[data-control-readiness]');
        if (readiness) {
            setMessage(readiness, !navigator.onLine ? 'Offline. Reconnect before sending a command.'
                : ready ? 'Recent robot telemetry received. START and RESET requests expire after five seconds if not acknowledged.'
                    : 'Waiting for recent robot telemetry. START and RESET are unavailable; STOP can still be queued.',
            navigator.onLine && ready ? 'success' : 'warning');
        }
    }

    function resumeReadiness() {
        clearInterval(readinessTimer);
        updateCommandControls();
        if (dashboard.controlApp && !document.hidden) readinessTimer = setInterval(updateCommandControls, 1000);
    }

    function updateTelemetry(data) {
        controlTelemetry = data;
        updateCommandControls();
        const status = data?.movement_status ?? 'No data';
        const active = ['moving', 'running', 'active', 'started', 'start', 'collecting', 'detecting'].includes(status.toLowerCase());
        const stopped = ['stopped', 'stop', 'emergency stop'].includes(status.toLowerCase());
        setPill(document.getElementById('movement_status'), active ? 'success' : stopped ? 'danger' : 'secondary', status);
        const age = data?.created_at ? Date.now() - Date.parse(data.created_at) : Infinity;
        const fresh = Number.isFinite(age) && age < 15000;
        setPill(document.getElementById('connection_status'), fresh ? 'success' : 'warning', !data ? 'Awaiting telemetry' : fresh ? 'Connected' : 'Telemetry stale');
        setText('wireless_status', !data ? 'No telemetry' : fresh ? 'Connected' : 'No recent signal');
        setText('last_updated', data?.created_at ? new Date(data.created_at).toLocaleString() : 'Not received');
        setText('command_reported_state', data?.movement_status ?? 'No data');
        setText('command_last_updated', data?.created_at ? new Date(data.created_at).toLocaleString() : 'Not received');
        const battery = percent(data?.battery_level);
        setText('battery_level', data ? battery : '--');
        progress('battery_bar', battery);
        const batteryBar = document.getElementById('battery_bar');
        if (batteryBar) {
            batteryBar.className = `block h-full rounded-full ${battery <= 20 ? 'bg-red-600' : battery <= 45 ? 'bg-amber-500' : 'bg-emerald-600'}`;
        }
        for (const id of ['detected_trash_count', 'successful_pickup_count', 'failed_pickup_count', 'obstacle_count', 'plastic_waste_count', 'general_waste_count']) {
            setText(id, data ? count(data[id]) : '--');
        }
        for (const id of ['plastic_bin_level', 'general_bin_level', 'last_detected_trash_type']) {
            setText(id, data?.[id] ?? 'Not reported');
        }
        setText('operating_duration', data ? duration(data.operating_duration) : '--');
        const attempts = count(data?.successful_pickup_count) + count(data?.failed_pickup_count);
        const success = attempts ? percent(count(data.successful_pickup_count) / attempts * 100) : null;
        const total = count(data?.plastic_waste_count) + count(data?.general_waste_count);
        const capacity = count(dashboard.loadCapacityLimit);
        const load = capacity ? percent(total / capacity * 100) : 0;
        setText('success_rate', success);
        setText('total_waste_count', data ? total : '--');
        setText('load_capacity_label', `${load}%`);
        progress('load_capacity_bar', load);
        setText('evaluation_collection_success', success);
        setText('evaluation_battery_endurance', data ? battery : '--');
        setText('evaluation_load_capacity', data ? load : '--');
        setText('evaluation_obstacle_count', data ? count(data.obstacle_count) : '--');
        setText('evaluation_collection_note', `${attempts} pickup attempts`);
        setText('evaluation_battery_note', `Runtime ${duration(data?.operating_duration)}`);
        setText('evaluation_load_note', `${total} / ${capacity} items`);
        progress('evaluation_collection_success_bar', success);
        progress('evaluation_battery_endurance_bar', battery);
        progress('evaluation_load_capacity_bar', load);
        window.dispatchEvent(new CustomEvent('robot:telemetry', { detail: data }));
    }

    function showCommands(commands) {
        if (!commandHistory || !Array.isArray(commands)) {
            return;
        }
        if (!commands.length) {
            const empty = document.createElement('p');
            empty.className = 'empty-state';
            empty.textContent = 'No commands have been queued yet.';
            commandHistory.replaceChildren(empty);
            return;
        }
        const rows = commands.map((command) => {
            const row = document.createElement('div');
            row.className = 'flex items-center justify-between gap-3 border-b border-zinc-100 py-3 last:border-0';
            const content = document.createElement('div');
            const title = document.createElement('p');
            title.className = 'text-sm font-semibold text-zinc-900';
            title.textContent = command.command;
            const meta = document.createElement('p');
            meta.className = 'mt-1 text-xs text-zinc-500';
            meta.textContent = `${command.robot_id} / ${new Date(command.created_at).toLocaleTimeString()}`;
            const badge = document.createElement('span');
            setPill(badge, command.status === 'completed' ? 'success' : command.status === 'failed' ? 'danger' : 'secondary', command.status);
            content.append(title, meta);
            row.append(content, badge);
            return row;
        });
        commandHistory.replaceChildren(...rows);
    }

    async function refresh() {
        clearTimeout(pollTimer);
        if (polling || document.hidden) {
            return;
        }
        polling = true;
        try {
            const url = new URL(dashboard.urls.latest, location.href);
            url.searchParams.set('robot_id', dashboard.robotId);
            const telemetry = await requestJson(url);
            updateTelemetry(telemetry?.id ? telemetry : null);
            setText('refreshStatus', '');
            if (commandHistory && dashboard.urls.commands) {
                const commandsUrl = new URL(dashboard.urls.commands, location.href);
                commandsUrl.searchParams.set('robot_id', dashboard.robotId);
                showCommands(await requestJson(commandsUrl));
            }
            if (dashboard.urls.detections && (document.getElementById('cameraDetectionHistory') || document.getElementById('evaluation_detection_accuracy'))) {
                const historyUrl = new URL(dashboard.urls.detections, location.href);
                historyUrl.searchParams.set('robot_id', dashboard.robotId);
                const detections = await requestJson(historyUrl);
                const latest = detections[0];
                setText('evaluation_detection_accuracy', latest?.detection_confidence !== null && latest?.detection_confidence !== undefined ? percent(latest.detection_confidence) : '--');
                progress('evaluation_detection_accuracy_bar', latest?.detection_confidence);
                setText('evaluation_detection_note', latest?.detected_object_label ?? 'Awaiting a saved detection');
                if ((latest?.id ?? null) !== latestDetectionId) {
                    latestDetectionId = latest?.id ?? null;
                    window.dispatchEvent(new CustomEvent('robot:detections', { detail: detections }));
                }
            }
        } catch (error) {
            controlTelemetry = null;
            updateCommandControls();
            setPill(document.getElementById('connection_status'), 'danger', 'Connection interrupted');
            setText('wireless_status', 'Connection interrupted');
            setText('refreshStatus', error.message);
        } finally {
            polling = false;
            if (!document.hidden) {
                pollTimer = setTimeout(refresh, 3000);
            }
        }
    }

    async function sendCommand(command) {
        if (!navigator.onLine || (dashboard.controlApp && command !== 'STOP' && !robotReady())) {
            updateCommandControls();
            setMessage(commandMessage, 'Reconnect and wait for recent robot telemetry before sending this command.', 'warning');
            return;
        }
        if (pendingCommands.has(command) || (pendingCommands.size && command !== 'STOP')) {
            return;
        }
        if (command === 'RESET' && !window.confirm(`Reset ${dashboard.robotId}?`)) {
            return;
        }
        pendingCommands.add(command);
        updateCommandControls();
        setMessage(commandMessage, `Sending ${command}...`);
        try {
            const result = await requestJson(dashboard.urls.command, {
                method: 'POST', body: JSON.stringify({ robot_id: dashboard.robotId, command }),
            });
            if (!result?.success) {
                throw new Error('The command could not be queued.');
            }
            setMessage(commandMessage, `${command} queued. Awaiting acknowledgement from ${dashboard.robotId}.`, 'success');
            refresh();
        } catch (error) {
            setMessage(commandMessage, error.message, 'danger');
        } finally {
            pendingCommands.delete(command);
            updateCommandControls();
        }
    }

    commandButtons.forEach((button) => button.addEventListener('click', () => sendCommand(button.dataset.command)));
    document.getElementById('refreshDashboardButton')?.addEventListener('click', refresh);
    document.addEventListener('visibilitychange', () => document.hidden ? clearTimeout(pollTimer) : refresh());
    window.addEventListener('pagehide', () => clearTimeout(pollTimer));
    window.addEventListener('pagehide', () => clearInterval(readinessTimer));
    window.addEventListener('pageshow', (event) => {
        resumeReadiness();
        if (event.persisted) refresh();
    });
    window.addEventListener('online', () => { resumeReadiness(); refresh(); });
    window.addEventListener('offline', resumeReadiness);
    document.addEventListener('visibilitychange', resumeReadiness);
    resumeReadiness();
    updateTelemetry(dashboard.latestLog);
    refresh();

    if (document.getElementById('cameraFeed')) {
        setupCamera(dashboard);
    }
}
