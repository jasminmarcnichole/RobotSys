import { requestJson, setMessage, setPill, setText } from './robot-ui';

const page = document.querySelector('[data-camera-settings-page]');
const dashboard = window.robotDashboard;

if (page && dashboard) {
    const form = document.getElementById('cameraSettingsForm');
    const saveBtn = document.getElementById('saveCameraSettingsButton');
    const applyBtn = document.getElementById('applyEsp32SettingsButton');
    const resetBtn = document.getElementById('resetCameraSettingsButton');
    const messageEl = document.getElementById('cameraSettingsMessage');
    const savedBadge = document.getElementById('cameraSettingsSavedBadge');

    const previewImg = document.getElementById('settingsPreviewStream');
    const previewPlaceholder = document.getElementById('settingsPreviewPlaceholder');
    const previewBadge = document.getElementById('settingsPreviewBadge');
    const previewToggleBtn = document.getElementById('toggleSettingsPreviewButton');

    const esp32Base = (form?.dataset.esp32Base || 'http://192.168.4.2').replace(/\/+$/, '');
    const esp32Stream = form?.dataset.esp32Stream || `${esp32Base}:81/stream`;
    let previewRunning = false;

    const getVal = (id) => document.getElementById(id)?.value;
    const isChecked = (id) => Boolean(document.getElementById(id)?.checked);

    const readPayload = () => ({
        robot_id: dashboard.robotId || 'ROBOT-001',
        detection_model: String(getVal('settingsDetectionModel') || dashboard.vision?.defaultModel || 'waste_primary_20261026'),
        confidence_threshold: Number(getVal('settingsConfidenceThreshold') || 60),
        auto_grab_enabled: isChecked('settingsAutoGrab'),
        framesize: Number(getVal('settingsFramesize') || 8),
        quality: Number(getVal('settingsQuality') || 14),
        brightness: Number(getVal('settingsBrightness') || 0),
        contrast: Number(getVal('settingsContrast') || 1),
        saturation: Number(getVal('settingsSaturation') || 0),
        vflip: isChecked('settingsVflip'),
        hmirror: isChecked('settingsHmirror'),
        led_intensity: Number(getVal('settingsLedIntensity') || 0),
    });

    const populateForm = (data) => {
        if (!data) return;
        const setInput = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.value = String(value);
        };
        const setCheck = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.checked = Boolean(value);
        };

        setInput('settingsDetectionModel', data.detection_model);
        setInput('settingsConfidenceThreshold', data.confidence_threshold);
        setText('settingsConfidenceValue', `${data.confidence_threshold}%`);
        setCheck('settingsAutoGrab', data.auto_grab_enabled);
        setInput('settingsFramesize', data.framesize);
        setInput('settingsQuality', data.quality);
        setInput('settingsBrightness', data.brightness);
        setInput('settingsContrast', data.contrast);
        setInput('settingsSaturation', data.saturation);
        setInput('settingsLedIntensity', data.led_intensity);
        setText('settingsLedValue', String(data.led_intensity));
        setCheck('settingsVflip', data.vflip);
        setCheck('settingsHmirror', data.hmirror);
    };

    const pushSingleVarToEsp32 = async (variable, value) => {
        if (window.location.protocol === 'https:' && esp32Base.startsWith('http://')) {
            return false;
        }
        try {
            const res = await fetch(`${esp32Base}/control?var=${encodeURIComponent(variable)}&val=${encodeURIComponent(value)}`, {
                method: 'GET',
                mode: 'cors',
                cache: 'no-store',
                signal: AbortSignal.timeout(2500),
            });
            return res.ok;
        } catch {
            return false;
        }
    };

    const pushAllToEsp32 = async (payload) => {
        const pairs = [
            ['framesize', payload.framesize],
            ['quality', payload.quality],
            ['brightness', payload.brightness],
            ['contrast', payload.contrast],
            ['saturation', payload.saturation],
            ['vflip', payload.vflip ? 1 : 0],
            ['hmirror', payload.hmirror ? 1 : 0],
            ['led_intensity', payload.led_intensity],
        ];
        let applied = 0;
        for (const [variable, value] of pairs) {
            if (await pushSingleVarToEsp32(variable, value)) {
                applied++;
            } else {
                break;
            }
        }
        return applied === pairs.length;
    };

    document.getElementById('settingsConfidenceThreshold')?.addEventListener('input', (event) => {
        setText('settingsConfidenceValue', `${event.target.value}%`);
    });

    document.getElementById('settingsLedIntensity')?.addEventListener('input', (event) => {
        setText('settingsLedValue', String(event.target.value));
    });

    form?.querySelectorAll('[data-live-cam-var]').forEach((control) => {
        control.addEventListener('change', async () => {
            if (!previewRunning) return;
            const ok = await pushSingleVarToEsp32(control.dataset.liveCamVar, control.value);
            setMessage(
                messageEl,
                ok ? `Live preview updated (${control.dataset.liveCamVar}=${control.value}). Click Save Camera Settings to keep it.` : 'Could not reach ESP32-CAM for live preview. Click Save Camera Settings to store in database.',
                ok ? 'info' : 'warning'
            );
        });
    });

    form?.querySelectorAll('[data-live-cam-toggle]').forEach((checkbox) => {
        checkbox.addEventListener('change', async () => {
            if (!previewRunning) return;
            const val = checkbox.checked ? 1 : 0;
            const ok = await pushSingleVarToEsp32(checkbox.dataset.liveCamToggle, val);
            setMessage(
                messageEl,
                ok ? `Live preview updated (${checkbox.dataset.liveCamToggle}=${val}). Click Save Camera Settings to keep it.` : 'Could not reach ESP32-CAM for live preview. Click Save Camera Settings to store in database.',
                ok ? 'info' : 'warning'
            );
        });
    });

    const setBusy = (busy) => {
        if (saveBtn) saveBtn.disabled = busy;
        if (applyBtn) applyBtn.disabled = busy;
        if (resetBtn) resetBtn.disabled = busy;
    };

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const payload = readPayload();
        setBusy(true);
        setMessage(messageEl, 'Saving camera settings...', 'secondary');
        try {
            const response = await requestJson(dashboard.urls.cameraSettings, {
                method: 'PUT',
                body: JSON.stringify(payload),
            });
            populateForm(response.data);
            dashboard.cameraSettings = response.data;
            let synced = Boolean(response.esp32_synced);
            if (!synced) {
                synced = await pushAllToEsp32(payload);
            }
            setPill(savedBadge, 'success', `Saved for ${payload.robot_id}`);
            setMessage(
                messageEl,
                synced
                    ? 'Camera settings saved permanently and applied live to ESP32-CAM.'
                    : 'Camera settings saved permanently. They will auto-apply to ESP32-CAM when it connects.',
                'success'
            );
        } catch (error) {
            setMessage(messageEl, error.message || 'Unable to save camera settings.', 'danger');
        } finally {
            setBusy(false);
        }
    });

    applyBtn?.addEventListener('click', async () => {
        const payload = readPayload();
        setBusy(true);
        setMessage(messageEl, `Applying settings to ESP32-CAM (${esp32Base})...`, 'secondary');
        try {
            const synced = await pushAllToEsp32(payload);
            setMessage(
                messageEl,
                synced
                    ? 'Settings applied live to ESP32-CAM. Click Save Camera Settings if you want to store them permanently.'
                    : `Could not reach ESP32-CAM at ${esp32Base}. Check that you are connected to TrashCollector_Robot Wi-Fi.`,
                synced ? 'success' : 'warning'
            );
        } finally {
            setBusy(false);
        }
    });

    resetBtn?.addEventListener('click', async () => {
        setBusy(true);
        setMessage(messageEl, 'Resetting camera settings to defaults...', 'secondary');
        try {
            const response = await requestJson(dashboard.urls.cameraSettingsReset, {
                method: 'POST',
                body: JSON.stringify({ robot_id: dashboard.robotId || 'ROBOT-001' }),
            });
            populateForm(response.data);
            dashboard.cameraSettings = response.data;
            let synced = Boolean(response.esp32_synced);
            if (!synced) {
                synced = await pushAllToEsp32(response.data);
            }
            setPill(savedBadge, 'success', `Saved for ${response.data.robot_id}`);
            setMessage(
                messageEl,
                synced
                    ? 'Reset to recommended cool-running defaults and applied to ESP32-CAM.'
                    : 'Reset to recommended cool-running defaults and saved in database.',
                'success'
            );
        } catch (error) {
            setMessage(messageEl, error.message || 'Unable to reset camera settings.', 'danger');
        } finally {
            setBusy(false);
        }
    });

    const stopPreview = () => {
        previewRunning = false;
        if (previewImg) {
            previewImg.onload = null;
            previewImg.onerror = null;
            previewImg.removeAttribute('src');
            previewImg.classList.add('hidden');
        }
        previewPlaceholder?.classList.remove('hidden');
        setPill(previewBadge, 'secondary', 'Off');
        const label = previewToggleBtn?.querySelector('[data-preview-toggle-label]');
        if (label) label.textContent = 'Start Live Preview';
    };

    const startPreview = () => {
        if (!previewImg) return;
        previewRunning = true;
        setPill(previewBadge, 'warning', 'Connecting');
        const label = previewToggleBtn?.querySelector('[data-preview-toggle-label]');
        if (label) label.textContent = 'Stop Live Preview';
        previewImg.onload = () => {
            if (!previewRunning) return;
            previewImg.classList.remove('hidden');
            previewPlaceholder?.classList.add('hidden');
            setPill(previewBadge, 'success', 'Live');
        };
        previewImg.onerror = () => {
            stopPreview();
            setPill(previewBadge, 'danger', 'Offline');
            setMessage(messageEl, `ESP32-CAM preview unavailable at ${esp32Stream}.`, 'warning');
        };
        previewImg.src = esp32Stream;
    };

    previewToggleBtn?.addEventListener('click', () => {
        if (previewRunning) {
            stopPreview();
        } else {
            startPreview();
        }
    });

    window.addEventListener('pagehide', stopPreview);
}
