import { requestJson } from './robot-ui';
import { healthSignals } from './workspace-health';

const dashboard = window.robotDashboard;

if (dashboard) {
    const dialog = document.getElementById('workspaceSearch');
    const search = document.getElementById('workspaceSearchInput');
    const results = [...document.querySelectorAll('[data-workspace-result]')];
    const openers = [...document.querySelectorAll('[data-workspace-search-open]')];
    let searchTrigger;
    const visibleResults = () => results.filter((result) => !result.hidden);

    function filterPages() {
        const query = search.value.trim().toLocaleLowerCase();
        results.forEach((result) => { result.hidden = !result.textContent.toLocaleLowerCase().includes(query); });
        const count = visibleResults().length;
        document.querySelector('[data-workspace-search-empty]').hidden = count > 0;
        document.querySelector('[data-workspace-search-count]').textContent = `${count} ${count === 1 ? 'page' : 'pages'} found`;
    }

    function openSearch(trigger) {
        if (!dialog?.showModal) return;
        searchTrigger = trigger;
        search.value = '';
        filterPages();
        dialog.showModal();
        search.focus();
    }

    openers.forEach((button) => button.addEventListener('click', () => openSearch(button)));
    search?.addEventListener('input', filterPages);
    document.querySelector('[data-workspace-search-close]')?.addEventListener('click', () => dialog.close());
    dialog?.addEventListener('close', () => searchTrigger?.focus());
    dialog?.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
    dialog?.addEventListener('keydown', (event) => {
        const visible = visibleResults();
        if (['ArrowDown', 'ArrowUp'].includes(event.key) && visible.length) {
            event.preventDefault();
            const index = visible.indexOf(document.activeElement);
            visible[(index + (event.key === 'ArrowDown' ? 1 : -1) + visible.length) % visible.length].focus();
        } else if (event.key === 'Enter' && event.target === search && visible.length) {
            event.preventDefault();
            visible[0].click();
        }
    });
    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            dialog.open ? dialog.close() : openSearch(document.activeElement);
        }
    });
    document.querySelector('[data-robot-switcher] select')?.addEventListener('change', (event) => event.target.form.requestSubmit());

    let telemetry = dashboard.latestLog;
    let healthFingerprint;
    let healthTimer;
    function updateHealth() {
        const signals = healthSignals(telemetry, navigator.onLine !== false);
        const fingerprint = JSON.stringify(signals);
        if (healthFingerprint === fingerprint) return;
        healthFingerprint = fingerprint;
        const panel = document.querySelector('[data-health-panel]');
        if (!panel) return;
        const alerts = signals.filter((signal) => signal.tone !== 'success');
        panel.dataset.tone = signals.some((signal) => signal.tone === 'danger') ? 'danger' : alerts.length ? 'warning' : 'success';
        panel.querySelector('[data-health-summary]').textContent = alerts.length ? 'Attention needed' : 'Workspace status';
        panel.querySelector('[data-health-count]').textContent = alerts.length ? `${alerts.length} ${alerts.length === 1 ? 'signal' : 'signals'}` : 'No reported alerts';
        panel.querySelector('[data-health-items]').replaceChildren(...signals.map((signal) => {
            const item = document.createElement('li');
            const title = document.createElement('p');
            title.className = 'text-sm font-semibold text-zinc-800';
            title.textContent = signal.title;
            const detail = document.createElement('p');
            detail.className = 'mt-1 text-xs leading-6 text-zinc-500';
            detail.textContent = signal.detail;
            item.append(title, detail);
            return item;
        }));
    }
    window.addEventListener('robot:telemetry', ({ detail }) => { telemetry = detail; updateHealth(); });
    window.addEventListener('online', updateHealth);
    window.addEventListener('offline', updateHealth);
    function startHealth() {
        clearInterval(healthTimer);
        updateHealth();
        if (!document.hidden) healthTimer = setInterval(updateHealth, 1000);
    }
    document.addEventListener('visibilitychange', startHealth);
    window.addEventListener('pagehide', () => clearInterval(healthTimer));
    window.addEventListener('pageshow', startHealth);
    startHealth();

    const insightsPanel = document.querySelector('[data-workspace-insights]');
    let insightsTimer;
    let insightsRequest;
    let insightsPending = false;
    const dateLabel = (value) => new Date(value).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });

    function renderInsights(data) {
        if (!insightsPanel || data.robot_id !== dashboard.robotId) return;
        insightsPanel.querySelectorAll('[data-insight-count]').forEach((element) => {
            element.textContent = Number(data.today[element.dataset.insightCount]).toLocaleString();
        });
        const trend = data.battery_trend;
        const points = trend.map((point, index) => `${24 + index * 592 / Math.max(1, trend.length - 1)},${144 - point.battery * 1.2}`).join(' ');
        insightsPanel.querySelector('[data-battery-line]').setAttribute('points', points);
        insightsPanel.querySelector('[data-battery-chart]').toggleAttribute('hidden', trend.length < 2);
        insightsPanel.querySelector('[data-battery-empty]').hidden = trend.length >= 2;
        insightsPanel.querySelector('[data-insight-battery]').textContent = trend.length ? `${Math.round(trend.at(-1).battery)}%` : '—';
        insightsPanel.querySelector('[data-battery-range]').textContent = trend.length
            ? `${dateLabel(trend[0].reported_at)} → ${dateLabel(trend.at(-1).reported_at)} · Reports in time order; intervals vary.`
            : 'Reports are ordered by time; gaps between reports can vary.';
        const reviewContainer = insightsPanel.querySelector('[data-insight-reviews]');
        const rows = data.recent_observations.map((review) => {
            const row = document.createElement('div');
            row.className = 'flex min-w-0 items-start justify-between gap-3 py-3';
            const content = document.createElement('div'); content.className = 'min-w-0';
            const title = document.createElement('p'); title.className = 'break-words text-sm font-semibold'; title.textContent = review.label;
            const subtitle = document.createElement('p'); subtitle.className = 'mt-1 break-words text-xs text-zinc-500'; subtitle.textContent = `${review.category} · ${review.source}`;
            const time = document.createElement('time'); time.dateTime = review.reported_at; time.className = 'mt-1 block text-xs text-zinc-400'; time.textContent = dateLabel(review.reported_at);
            const confidence = document.createElement('span'); confidence.className = 'shrink-0 text-xs text-zinc-500'; confidence.textContent = review.confidence === null ? '—' : `${Math.round(review.confidence)}%`;
            content.append(title, subtitle, time); row.append(content, confidence); return row;
        });
        if (!rows.length) {
            const empty = document.createElement('p'); empty.className = 'empty-state'; empty.textContent = 'Your saved camera observations will appear here.'; rows.push(empty);
        }
        reviewContainer.replaceChildren(...rows);
        insightsPanel.querySelector('[data-insights-updated]').textContent = `Today (${data.timezone}) · Refreshed ${new Date(data.generated_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
    }

    async function refreshInsights() {
        clearTimeout(insightsTimer);
        if (!insightsPanel || !dashboard.urls.insights || document.hidden || insightsPending) return;
        if (navigator.onLine === false) {
            insightsPanel.querySelector('[data-insights-updated]').textContent = 'Offline · Showing the last received activity summary';
            return;
        }
        insightsPending = true;
        insightsRequest = new AbortController();
        const timeout = setTimeout(() => insightsRequest.abort(), 15000);
        try {
            const url = new URL(dashboard.urls.insights, location.href); url.searchParams.set('robot_id', dashboard.robotId);
            renderInsights(await requestJson(url, { signal: insightsRequest.signal }));
        } catch {
            if (!document.hidden) insightsPanel.querySelector('[data-insights-updated]').textContent = 'Activity refresh unavailable · Showing the last received summary';
        } finally {
            clearTimeout(timeout);
            insightsPending = false;
            if (!document.hidden) insightsTimer = setTimeout(refreshInsights, 30000);
        }
    }
    if (insightsPanel) {
        if (dashboard.insights) renderInsights(dashboard.insights);
        document.getElementById('refreshDashboardButton')?.addEventListener('click', refreshInsights);
        document.addEventListener('visibilitychange', () => { clearTimeout(insightsTimer); document.hidden ? insightsRequest?.abort() : refreshInsights(); });
        window.addEventListener('pagehide', () => { clearTimeout(insightsTimer); insightsRequest?.abort(); });
        window.addEventListener('pageshow', refreshInsights);
        window.addEventListener('online', refreshInsights);
        window.addEventListener('offline', refreshInsights);
        insightsTimer = setTimeout(refreshInsights, 30000);
    }

    const camera = document.getElementById('camera');
    const expand = document.querySelector('[data-camera-expand]');
    function cameraViewState() {
        const expanded = document.fullscreenElement === camera || camera?.classList.contains('camera-focus');
        expand?.setAttribute('aria-pressed', String(Boolean(expanded)));
        const label = document.querySelector('[data-camera-expand-label]'); if (label) label.textContent = expanded ? 'Collapse view' : 'Expand view';
        window.dispatchEvent(new Event('resize'));
    }
    expand?.addEventListener('click', async () => {
        if (document.fullscreenElement === camera) { await document.exitFullscreen(); return; }
        if (camera.classList.contains('camera-focus')) { camera.classList.remove('camera-focus'); cameraViewState(); return; }
        if (camera.requestFullscreen && document.fullscreenEnabled) {
            try { await camera.requestFullscreen(); return; } catch {}
        }
        camera.classList.add('camera-focus'); camera.scrollIntoView({ behavior: 'smooth', block: 'start' }); cameraViewState();
    });
    document.addEventListener('fullscreenchange', cameraViewState);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !dialog?.open && camera?.classList.contains('camera-focus')) { camera.classList.remove('camera-focus'); cameraViewState(); }
    });

    const preview = document.getElementById('cameraEvidencePreview');
    const download = document.querySelector('[data-reviewed-download]');
    if (preview && download) {
        const available = () => preview.src.startsWith('data:image/jpeg;base64,') && !preview.classList.contains('hidden');
        const updateDownload = () => { download.disabled = !available(); };
        new MutationObserver(updateDownload).observe(preview, { attributes: true, attributeFilter: ['src', 'class'] });
        updateDownload();
        download.addEventListener('click', () => {
            if (!available()) return;
            const link = document.createElement('a');
            link.href = preview.src;
            link.download = `robotsys-frame-${new Date().toISOString().replace(/[:.]/g, '-')}.jpg`;
            link.click();
        });
    }
}
