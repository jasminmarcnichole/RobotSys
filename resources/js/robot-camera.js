import { TensorFlowLiteVision } from './tflite-vision';
import { requestJson, setMessage, setPill, setText } from './robot-ui';

export function setupCamera(dashboard) {
    const get = (id) => document.getElementById(id);
    const video = get('cameraFeed');
    const streamImage = get('cameraStream');
    const snapshot = get('cameraSnapshot');
    const overlay = get('detectionOverlay');
    const placeholder = get('cameraPlaceholder');
    const material = get('cameraTrashType');
    const objectSelect = get('cameraObjectSelect');
    const preview = get('cameraEvidencePreview');
    const modelSelect = get('cameraModelSelect');
    let modelId = dashboard.vision.defaultModel;
    const createVision = () => {
        const model = dashboard.vision.models[modelId];
        const v = new TensorFlowLiteVision(model.url, model.inputSize);
        v.labels = model.labels || null;
        return v;
    };
    let vision = createVision();
    let stream = null;
    let source = null;
    let mode = 'idle';
    let generation = 0;
    let timer = null;
    let fetching = null;
    let lastFrame = null;
    let selectedIndex = 0;
    let saving = false;
    let lastVideoTime = null;
    let waitingSince = null;
    let previewGeneration = 0;
    let previewTimer = null;
    let streamPreviewState = 'idle';
    const frameTimeout = 5000;

    const message = (text, tone = 'secondary') => setMessage(get('cameraMessage'), text, tone);
    const status = (label, tone = 'secondary') => setPill(get('camera_status'), tone, label);
    const threshold = () => Number(get('cameraThreshold')?.value ?? 50) / 100;
    const selectedDetection = () => lastFrame?.detections[selectedIndex];
    const setDisabled = (id, disabled) => { if (get(id)) { get(id).disabled = disabled; } };

    function updateModelWarning() {
        const warning = get('cameraModelWarning');
        if (warning) {
            warning.textContent = dashboard.vision.models[modelId].warning ?? '';
            warning.classList[warning.textContent ? 'remove' : 'add']('hidden');
        }
    }

    function controls() {
        const busy = mode === 'loading' || saving;
        setDisabled('startCameraButton', busy || Boolean(source && source !== 'uploaded_image'));
        setDisabled('startEspCameraButton', busy || !dashboard.urls.cameraSnapshot || Boolean(source && source !== 'uploaded_image'));
        setDisabled('stopCameraButton', !source && mode !== 'loading');
        setDisabled('liveDetectionButton', busy || !source || source === 'uploaded_image');
        setDisabled('captureDetectionButton', busy || mode !== 'live' || !selectedDetection());
        setDisabled('saveCameraDetectionButton', saving || mode !== 'review' || !selectedDetection() || !material.value || lastFrame?.saved);
        setDisabled('cameraImageInput', busy);
        setDisabled('cameraDeviceSelect', busy || Boolean(source && source !== 'uploaded_image'));
        setDisabled('cameraModelSelect', busy || Boolean(source && source !== 'uploaded_image'));
        setDisabled('cameraTrashType', saving || mode !== 'review' || lastFrame?.saved || !selectedDetection());
        setDisabled('cameraObjectSelect', saving || mode !== 'review' || lastFrame?.saved);
        setDisabled('cameraThreshold', saving || mode === 'loading');
        const retryStream = get('retryEspStreamButton');
        if (retryStream) {
            const canRetry = source === 'esp32_camera' && mode === 'live' && streamPreviewState === 'failed';
            retryStream.classList[canRetry ? 'remove' : 'add']('hidden');
            retryStream.disabled = !canRetry || busy;
        }
        const toggle = get('liveDetectionButton');
        if (toggle) {
            const label = toggle.querySelector('[data-button-label]');
            (label ?? toggle).textContent = mode === 'live' ? 'Pause detection' : 'Resume detection';
        }
    }

    function showFrame(canvas) {
        snapshot.width = canvas.width;
        snapshot.height = canvas.height;
        snapshot.getContext('2d').drawImage(canvas, 0, 0);
        snapshot.classList.remove('hidden');
        video.classList.add('hidden');
        streamImage?.classList.add('hidden');
        placeholder.classList.add('hidden');
    }

    function stopStreamPreview() {
        previewGeneration++;
        clearTimeout(previewTimer);
        if (streamImage) {
            streamImage.onload = null;
            streamImage.onerror = null;
            streamImage.removeAttribute('src');
            streamImage.classList.add('hidden');
        }
        streamPreviewState = 'idle';
    }

    function startStreamPreview() {
        if (streamPreviewState === 'connecting' || streamPreviewState === 'live') {
            return;
        }
        stopStreamPreview();
        if (!streamImage || !dashboard.urls.cameraStream) {
            streamPreviewState = 'unavailable';
            setText('cameraPreviewStatus', 'Snapshot preview');
            return;
        }
        const token = previewGeneration;
        const fail = (label = 'Snapshot preview (stream unavailable)') => {
            if (token !== previewGeneration || source !== 'esp32_camera') {
                return;
            }
            stopStreamPreview();
            streamPreviewState = 'failed';
            setText('cameraPreviewStatus', label);
            if (lastFrame && mode === 'live') {
                showFrame(lastFrame.canvas);
                drawOverlay();
            }
            controls();
        };
        let url;
        try {
            url = new URL(dashboard.urls.cameraStream);
            if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) {
                throw new Error('Invalid stream address.');
            }
        } catch {
            fail('Snapshot preview (invalid stream address)');
            return;
        }
        if (window.location?.protocol === 'https:' && url.protocol === 'http:') {
            fail('Snapshot preview (HTTPS stream required)');
            return;
        }
        streamPreviewState = 'connecting';
        setText('cameraPreviewStatus', 'Connecting live stream');
        streamImage.onload = () => {
            if (token !== previewGeneration || source !== 'esp32_camera' || !['live', 'loading'].includes(mode)) {
                return;
            }
            clearTimeout(previewTimer);
            streamPreviewState = 'live';
            streamImage.classList.remove('hidden');
            snapshot.classList.add('hidden');
            video.classList.add('hidden');
            placeholder.classList.add('hidden');
            setText('cameraPreviewStatus', 'Live MJPEG stream');
            controls();
        };
        streamImage.onerror = () => fail();
        previewTimer = setTimeout(() => fail(), 8000);
        streamImage.src = url.href;
        controls();
    }

    function drawOverlay() {
        if (lastFrame) {
            overlay.classList.remove('hidden');
        }
        const bounds = overlay.getBoundingClientRect();
        const ratio = window.devicePixelRatio || 1;
        overlay.width = Math.round(bounds.width * ratio);
        overlay.height = Math.round(bounds.height * ratio);
        const context = overlay.getContext('2d');
        context.scale(ratio, ratio);
        context.clearRect(0, 0, bounds.width, bounds.height);
        if (!lastFrame || !bounds.width) {
            return;
        }
        overlay.classList.remove('hidden');
        const { width, height } = lastFrame.canvas;
        const scale = Math.min(bounds.width / width, bounds.height / height);
        const offsetX = (bounds.width - width * scale) / 2;
        const offsetY = (bounds.height - height * scale) / 2;
        lastFrame.detections.forEach((detection, index) => {
            const { x, y, width: boxWidth, height: boxHeight } = detection.bbox;
            const left = offsetX + x * scale;
            const top = offsetY + y * scale;
            context.strokeStyle = index === selectedIndex ? '#34d399' : '#60a5fa';
            context.lineWidth = index === selectedIndex ? 3 : 1.5;
            context.strokeRect(left, top, boxWidth * scale, boxHeight * scale);
            context.font = '600 12px system-ui';
            const label = `${detection.label} ${Math.round(detection.score * 100)}%`;
            const labelWidth = Math.min(context.measureText(label).width + 12, bounds.width);
            const labelX = Math.max(0, Math.min(left, bounds.width - labelWidth));
            const labelY = Math.max(0, top - 24);
            context.fillStyle = '#18181b';
            context.fillRect(labelX, labelY, labelWidth, 22);
            context.fillStyle = '#ffffff';
            context.fillText(label, labelX + 6, labelY + 15, labelWidth - 12);
        });
    }

    function updateResult() {
        const detection = selectedDetection();
        setText('cameraObjectLabel', detection?.label ?? 'No object detected');
        const confidence = detection ? Math.round(detection.score * 100) : 0;
        setText('cameraConfidenceLabel', `${confidence}%`);
        get('cameraConfidenceBar').style.width = `${confidence}%`;
        setText('cameraObjectCount', lastFrame?.detections.length ?? 0);
        setText('cameraInferenceTime', lastFrame ? Math.round(lastFrame.inferenceMs) : '--');
        if (objectSelect) {
            objectSelect.replaceChildren(...(lastFrame?.detections ?? []).map((item, index) => new Option(`${item.label} (${Math.round(item.score * 100)}%)`, String(index))));
            objectSelect.value = String(selectedIndex);
        }
        drawOverlay();
        controls();
    }

    function filterDetections() {
        if (!lastFrame) {
            return;
        }
        lastFrame.detections = lastFrame.rawDetections.filter((detection) => detection.score >= threshold());
        selectedIndex = 0;
        material.value = '';
        updateResult();
    }

    function normalizeDetections(result, canvas) {
        return result.detections.flatMap((detection) => {
            const box = detection.boundingBox;
            const category = detection.categories?.[0];
            if (!box || !category || !Number.isFinite(category.score)) {
                return [];
            }
            const x = Math.max(0, Math.min(canvas.width - 1, Math.round(box.originX)));
            const y = Math.max(0, Math.min(canvas.height - 1, Math.round(box.originY)));
            const width = Math.min(canvas.width - x, Math.round(box.width));
            const height = Math.min(canvas.height - y, Math.round(box.height));
            if (width < 1 || height < 1) {
                return [];
            }
            return [{ label: category.categoryName || category.displayName || 'Object', score: category.score, bbox: { x, y, width, height } }];
        }).sort((left, right) => right.score - left.score);
    }

    function makeFrame(image, width, height) {
        const scale = Math.min(1, 1280 / Math.max(width, height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(width * scale));
        canvas.height = Math.max(1, Math.round(height * scale));
        canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
        return canvas;
    }

    async function captureFrame() {
        if (source === 'esp32_camera') {
            const controller = new AbortController();
            fetching = controller;
            const timeout = setTimeout(() => controller.abort(), 7000);
            try {
                const response = await fetch(dashboard.urls.cameraSnapshot, {
                    headers: { Accept: 'image/jpeg, application/json' }, cache: 'no-store', signal: controller.signal,
                });
                if (!response.ok) {
                    const result = await response.json().catch(() => null);
                    throw new Error(result?.message ?? 'The ESP32-CAM snapshot is unavailable.');
                }
                const bitmap = await createImageBitmap(await response.blob());
                try {
                    return makeFrame(bitmap, bitmap.width, bitmap.height);
                } finally {
                    bitmap.close();
                }
            } finally {
                clearTimeout(timeout);
                if (fetching === controller) {
                    fetching = null;
                }
            }
        }
        if (video.requestVideoFrameCallback) {
            const controller = new AbortController();
            fetching = controller;
            let callback;
            let timeout;
            let aborted;
            try {
                return await new Promise((resolve, reject) => {
                    aborted = () => reject(new DOMException('Frame capture stopped.', 'AbortError'));
                    controller.signal.addEventListener('abort', aborted, { once: true });
                    timeout = setTimeout(() => reject(new Error('No new camera frame arrived. Check the camera and resume detection.')), frameTimeout);
                    callback = video.requestVideoFrameCallback(() => {
                        try {
                            resolve(makeFrame(video, video.videoWidth, video.videoHeight));
                        } catch (error) {
                            reject(error);
                        }
                    });
                });
            } finally {
                clearTimeout(timeout);
                if (callback !== undefined) {
                    video.cancelVideoFrameCallback(callback);
                }
                controller.signal.removeEventListener('abort', aborted);
                if (fetching === controller) {
                    fetching = null;
                }
            }
        }
        if (video.readyState >= 2 && video.videoWidth && video.videoHeight && video.currentTime !== lastVideoTime) {
            lastVideoTime = video.currentTime;
            waitingSince = null;
            return makeFrame(video, video.videoWidth, video.videoHeight);
        }
        waitingSince ??= performance.now();
        if (performance.now() - waitingSince >= frameTimeout) {
            throw new Error('No new camera frame arrived. Check the camera and resume detection.');
        }
        return null;
    }

    async function infer(frame, token) {
        if (token !== generation) {
            return false;
        }
        const frameSource = source;
        const frameModel = modelId;
        const startedAt = performance.now();
        const result = await vision.detect(frame);
        if (token !== generation) {
            return false;
        }
        if (mode === 'live' && performance.now() - startedAt > frameTimeout) {
            throw new Error('Detection took too long. Resume detection or choose the faster model.');
        }
        lastFrame = { canvas: frame, rawDetections: normalizeDetections(result, frame), inferenceMs: result.inferenceMs, saved: false, source: frameSource, modelId: frameModel };
        filterDetections();
        return true;
    }

    async function tick(token) {
        if (mode !== 'live' || token !== generation) {
            return;
        }
        try {
            const frame = await captureFrame();
            if (token !== generation || mode !== 'live') {
                return;
            }
            if (frame && await infer(frame, token)) {
                if (source === 'esp32_camera' && streamPreviewState !== 'live') {
                    showFrame(frame);
                    drawOverlay();
                }
                message(lastFrame.detections.length ? `${lastFrame.detections.length} object(s) detected.` : 'No objects above the confidence threshold.');
            }
        } catch (error) {
            if (token === generation) {
                lastFrame = null;
                pause(true);
                updateResult();
                overlay.classList.add('hidden');
                status('Detection paused', 'warning');
                message(error.message, 'danger');
            }
            return;
        }
        if (mode === 'live' && token === generation) {
            timer = setTimeout(() => tick(token), source === 'esp32_camera' ? 1000 : 650);
        }
    }

    function pause(keepLivePreview = false) {
        generation++;
        clearTimeout(timer);
        fetching?.abort();
        mode = 'paused';
        const previewContinues = keepLivePreview && source === 'esp32_camera' && streamPreviewState === 'live';
        if (!previewContinues) {
            stopStreamPreview();
            setText('cameraPreviewStatus', 'Paused frame');
        }
        if (lastFrame && !previewContinues) {
            showFrame(lastFrame.canvas);
            drawOverlay();
        }
        controls();
    }

    function review() {
        pause();
        if (!lastFrame) {
            status('Detection paused', 'warning');
            message('Detection paused before the first frame completed.');
            return;
        }
        mode = 'review';
        setText('cameraPreviewStatus', 'Review frame');
        material.value = '';
        preview.src = lastFrame.canvas.toDataURL('image/jpeg', 0.85);
        preview.classList.remove('hidden');
        status('Review frame', 'info');
        message(selectedDetection() ? 'Frame captured. Material category pending review.' : 'No objects above the confidence threshold.', 'info');
        controls();
    }

    function resume() {
        if (!source || source === 'uploaded_image' || saving) {
            return;
        }
        generation++;
        mode = 'live';
        lastFrame = null;
        lastVideoTime = null;
        waitingSince = null;
        material.value = '';
        preview.classList.add('hidden');
        preview.removeAttribute('src');
        if (source === 'browser_camera') {
            video.classList.remove('hidden');
            snapshot.classList.add('hidden');
            setText('cameraPreviewStatus', 'Device camera');
        } else if (source === 'esp32_camera') {
            startStreamPreview();
        }
        status('Live detection', 'success');
        updateResult();
        overlay.classList.add('hidden');
        message('Waiting for a new camera frame...');
        tick(generation);
    }

    function stop() {
        generation++;
        clearTimeout(timer);
        fetching?.abort();
        stopStreamPreview();
        const previousStream = stream;
        stream = null;
        previousStream?.getTracks().forEach((track) => track.stop());
        video.srcObject = null;
        source = null;
        mode = 'idle';
        lastFrame = null;
        lastVideoTime = null;
        waitingSince = null;
        material.value = '';
        video.classList.add('hidden');
        snapshot.classList.add('hidden');
        overlay.classList.add('hidden');
        preview.classList.add('hidden');
        preview.removeAttribute('src');
        placeholder.classList.remove('hidden');
        status('Camera off');
        setText('cameraPreviewStatus', 'Inactive');
        updateResult();
        message('No active camera.');
        vision.dispose();
        setPill(get('cameraModelLabel'), 'secondary', 'TensorFlow Lite');
    }

    async function start(nextSource) {
        if (saving || mode === 'loading') {
            return;
        }
        stop();
        mode = 'loading';
        source = nextSource;
        const token = generation;
        controls();
        status('Connecting', 'warning');
        try {
            if (source === 'browser_camera') {
                if (!navigator.mediaDevices?.getUserMedia) {
                    throw new Error('Camera access requires HTTPS or localhost and a supported browser.');
                }
                const deviceId = get('cameraDeviceSelect')?.value;
                const acquiredStream = await navigator.mediaDevices.getUserMedia({
                    video: { ...(deviceId ? { deviceId: { exact: deviceId } } : { facingMode: { ideal: 'environment' } }), width: { ideal: 1280 }, height: { ideal: 720 } },
                    audio: false,
                });
                if (token !== generation) {
                    acquiredStream.getTracks().forEach((track) => track.stop());
                    return;
                }
                stream = acquiredStream;
                video.srcObject = stream;
                await video.play();
                stream.getVideoTracks()[0]?.addEventListener('ended', () => {
                    if (stream === acquiredStream) {
                        stop();
                        message('The camera was disconnected.', 'warning');
                    }
                });
                await populateDevices();
            }
            if (token !== generation) {
                return;
            }
            placeholder.classList.add('hidden');
            message('Loading the detection model...');
            setPill(get('cameraModelLabel'), 'warning', 'Loading model');
            await vision.load();
            if (token !== generation) {
                return;
            }
            setPill(get('cameraModelLabel'), 'success', 'TensorFlow Lite ready');
            resume();
        } catch (error) {
            if (token !== generation) {
                return;
            }
            stop();
            status('Connection failed', 'danger');
            message(error.name === 'NotAllowedError' ? 'Camera permission was denied. Allow camera access in your browser to retry.' : error.message, 'danger');
        }
    }

    async function populateDevices() {
        const select = get('cameraDeviceSelect');
        if (!select || !navigator.mediaDevices?.enumerateDevices) {
            return;
        }
        try {
            const selected = stream?.getVideoTracks()[0]?.getSettings().deviceId ?? select.value;
            const devices = (await navigator.mediaDevices.enumerateDevices()).filter((device) => device.kind === 'videoinput');
            select.replaceChildren(new Option('Default camera', ''), ...devices.map((device, index) => new Option(device.label || `Camera ${index + 1}`, device.deviceId)));
            select.value = selected;
        } catch {
            select.replaceChildren(new Option('Default camera', ''));
        }
    }

    async function upload(event) {
        const file = event.target.files?.[0];
        if (!file || saving) {
            return;
        }
        event.target.value = '';
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 10000000) {
            message('Choose a JPEG, PNG, or WebP image smaller than 10 MB.', 'warning');
            return;
        }
        stop();
        source = 'uploaded_image';
        mode = 'loading';
        const token = generation;
        controls();
        status('Analyzing image', 'warning');
        message('Loading image and detection model...');
        try {
            const bitmap = await createImageBitmap(file);
            if (bitmap.width * bitmap.height > 16777216) {
                bitmap.close();
                throw new Error('Choose an image no larger than 16 megapixels.');
            }
            let frame;
            try {
                frame = makeFrame(bitmap, bitmap.width, bitmap.height);
            } finally {
                bitmap.close();
            }
            if (token !== generation) {
                return;
            }
            showFrame(frame);
            if (await infer(frame, token)) {
                setPill(get('cameraModelLabel'), 'success', 'TensorFlow Lite ready');
                review();
            }
        } catch (error) {
            if (token === generation) {
                stop();
                status('Image failed', 'danger');
                message(error.message, 'danger');
            }
        }
    }

    async function changeModel() {
        if (saving || mode === 'loading' || (source && source !== 'uploaded_image')) {
            return;
        }
        const frame = source === 'uploaded_image' ? lastFrame?.canvas : null;
        stop();
        modelId = modelSelect.value;
        vision = createVision();
        updateModelWarning();
        setText('cameraActiveModel', `TensorFlow Lite / ${dashboard.vision.models[modelId].name}`);
        if (!frame) {
            return;
        }
        source = 'uploaded_image';
        mode = 'loading';
        const token = generation;
        controls();
        showFrame(frame);
        status('Analyzing image', 'warning');
        setPill(get('cameraModelLabel'), 'warning', 'Loading model');
        message('Analyzing the image with the selected model...');
        try {
            if (await infer(frame, token)) {
                setPill(get('cameraModelLabel'), 'success', 'TensorFlow Lite ready');
                review();
            }
        } catch (error) {
            if (token === generation) {
                stop();
                message(error.message, 'danger');
            }
        }
    }

    function prependHistory(log) {
        const history = get('cameraDetectionHistory');
        if (!history) {
            return;
        }
        history.querySelector('.empty-state')?.remove();
        history.querySelector(`[data-detection-id="${Number(log.id)}"]`)?.remove();
        const row = document.createElement('div');
        row.dataset.detectionId = String(log.id);
        row.className = 'flex items-center gap-3 border-b border-zinc-100 py-3 last:border-0';
        const image = document.createElement('img');
        image.src = log.camera_snapshot_url;
        image.alt = log.detected_object_label;
        image.className = 'h-12 w-16 rounded-md object-cover';
        const detail = document.createElement('div');
        detail.className = 'min-w-0 flex-1';
        const title = document.createElement('p');
        title.className = 'text-sm font-semibold text-zinc-900';
        title.textContent = `${log.detected_object_label} / ${Math.round(Number(log.detection_confidence))}%`;
        const meta = document.createElement('p');
        meta.className = 'mt-1 text-xs text-zinc-500';
        meta.textContent = `${log.last_detected_trash_type} / ${new Date(log.created_at).toLocaleTimeString()}`;
        detail.append(title, meta);
        row.append(image, detail);
        history.prepend(row);
        while (history.children.length > 8) {
            history.lastElementChild.remove();
        }
    }

    window.addEventListener('robot:detections', ({ detail }) => {
        const history = get('cameraDetectionHistory');
        if (!history || !detail.length) {
            return;
        }
        history.replaceChildren();
        [...detail].reverse().forEach(prependHistory);
    });

    async function save() {
        const detection = selectedDetection();
        if (saving || mode !== 'review' || !detection || !material.value || lastFrame.saved) {
            return;
        }
        saving = true;
        controls();
        const frame = lastFrame;
        const saveGeneration = generation;
        const category = material.value;
        message('Saving reviewed evidence...');
        try {
            const result = await requestJson(dashboard.urls.log, {
                method: 'POST',
                body: JSON.stringify({
                    robot_id: dashboard.robotId,
                    last_detected_trash_type: category,
                    detected_object_label: detection.label,
                    detection_confidence: Number((detection.score * 100).toFixed(2)),
                    detection_bbox: detection.bbox,
                    camera_snapshot: frame.canvas.toDataURL('image/jpeg', 0.85),
                    capture_source: frame.source,
                    detection_model: frame.modelId,
                }),
            });
            if (!result?.success) {
                throw new Error('The evidence could not be saved.');
            }
            frame.saved = true;
            prependHistory(result.data);
            if (saveGeneration === generation && lastFrame === frame) {
                status('Evidence saved', 'success');
                message(`${category} observation saved.`, 'success');
            }
        } catch (error) {
            if (saveGeneration === generation) {
                message(error.message, 'danger');
            }
        } finally {
            saving = false;
            controls();
        }
    }

    get('startCameraButton')?.addEventListener('click', () => start('browser_camera'));
    get('startEspCameraButton')?.addEventListener('click', () => start('esp32_camera'));
    get('retryEspStreamButton')?.addEventListener('click', startStreamPreview);
    get('stopCameraButton')?.addEventListener('click', stop);
    get('liveDetectionButton')?.addEventListener('click', () => mode === 'live' ? review() : resume());
    get('captureDetectionButton')?.addEventListener('click', review);
    get('cameraImageInput')?.addEventListener('change', upload);
    modelSelect?.addEventListener('change', changeModel);
    get('saveCameraDetectionButton')?.addEventListener('click', save);
    material.addEventListener('change', controls);
    objectSelect?.addEventListener('change', () => {
        selectedIndex = Number(objectSelect.value);
        material.value = '';
        updateResult();
    });
    get('cameraThreshold')?.addEventListener('input', () => {
        setText('cameraThresholdLabel', `${Math.round(threshold() * 100)}%`);
        filterDetections();
    });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden && (mode === 'live' || streamPreviewState === 'live' || streamPreviewState === 'connecting')) {
            review();
            status('Detection paused', 'warning');
        }
    });
    window.addEventListener('pagehide', stop);
    new ResizeObserver(drawOverlay).observe(overlay.parentElement);
    populateDevices();
    updateModelWarning();
    controls();
}


// Onboard Vision polling
function updateOnboardVision() {
    fetch('/dashboard/api/vision/latest?device_id=CAM-S3-001')
        .then(r => r.json())
        .then(data => {
            const statusEl = document.getElementById('onboardVisionStatus');
            const countEl = document.getElementById('onboardVisionCount');
            const detEl = document.getElementById('onboardVisionDetections');
            const imgEl = document.getElementById('onboardVisionImage');
            const noImgEl = document.getElementById('onboardVisionNoImage');
            const devEl = document.getElementById('onboardVisionDevice');
            const timeEl = document.getElementById('onboardVisionTime');
            
            if (statusEl) {
                statusEl.textContent = data.connection_status === 'connected' ? 'Connected' : 'Offline';
                statusEl.className = data.connection_status === 'connected' 
                    ? 'status-badge bg-emerald-100 text-emerald-700' 
                    : 'status-badge bg-zinc-100 text-zinc-600';
            }
            if (devEl) devEl.textContent = data.device_id || '-';
            if (timeEl) timeEl.textContent = data.server_time ? new Date(data.server_time).toLocaleTimeString() : '-';
            
            const detections = data.latest?.detections || [];
            if (countEl) countEl.textContent = detections.length + ' object(s)';
            
            if (detEl) {
                detEl.innerHTML = '';
                detections.forEach(d => {
                    const div = document.createElement('div');
                    div.className = 'p-2 bg-zinc-50 rounded border border-zinc-200';
                    div.innerHTML = '<div class="flex justify-between"><span class="font-medium">' + (d.label||d.class_id) + '</span><span>' + Math.round((d.confidence||0)*100) + '%</span></div><div class="text-xs text-zinc-500">Pos: ' + (d.direction||'-') + ' | Box: ' + (d.bbox?.x||0) + ',' + (d.bbox?.y||0) + ' ' + (d.bbox?.width||0) + 'x' + (d.bbox?.height||0) + '</div>';
                    detEl.appendChild(div);
                });
            }
            
            if (data.latest?.snapshot_url && imgEl) {
                imgEl.src = data.latest.snapshot_url;
                imgEl.classList.remove('hidden');
                if (noImgEl) noImgEl.classList.add('hidden');
            } else if (imgEl) {
                imgEl.classList.add('hidden');
                if (noImgEl) noImgEl.classList.remove('hidden');
            }
        })
        .catch(() => {});
}

if (document.getElementById('onboardVisionStatus')) {
    updateOnboardVision();
    setInterval(updateOnboardVision, 3000);
}
