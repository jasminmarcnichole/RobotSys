import { requestJson, setMessage, setPill } from './robot-ui';

const page = document.querySelector('[data-dataset-capture-page]');
const dashboard = window.robotDashboard;

if (page && dashboard?.urls?.datasetCapture) {
    const buttons = [...page.querySelectorAll('[data-dataset-capture]')];
    const messageEl = document.getElementById('datasetCaptureMessage');
    const totalBadge = document.getElementById('datasetTotalBadge');
    const undoBtn = document.getElementById('undoDatasetCaptureButton');

    const previewImg = document.getElementById('datasetPreviewStream');
    const previewPlaceholder = document.getElementById('datasetPreviewPlaceholder');
    const previewBadge = document.getElementById('datasetPreviewBadge');
    const previewToggleBtn = document.getElementById('toggleDatasetPreviewButton');
    const streamUrl = page.dataset.esp32Stream || 'http://192.168.4.2:81/stream';

    let busy = false;
    let lastCapture = null;
    let previewRunning = false;

    const updateCounts = (counts, total) => {
        if (counts && typeof counts === 'object') {
            Object.entries(counts).forEach(([key, value]) => {
                const badge = page.querySelector(`[data-dataset-count="${key}"]`);
                if (badge) {
                    badge.textContent = String(value);
                }
            });
        }
        if (totalBadge && Number.isFinite(Number(total))) {
            totalBadge.textContent = `${Number(total)} photo${Number(total) === 1 ? '' : 's'} saved`;
        }
    };

    const setBusy = (nextBusy) => {
        busy = nextBusy;
        buttons.forEach((btn) => {
            btn.disabled = busy;
        });
        if (undoBtn) {
            undoBtn.disabled = busy || !lastCapture;
        }
    };

    const loadCounts = async () => {
        try {
            const data = await requestJson(dashboard.urls.datasetCapture, { method: 'GET' });
            updateCounts(data.counts, data.total);
        } catch {
            // Keep default zero counts if offline
        }
    };

    buttons.forEach((btn) => {
        btn.addEventListener('click', async () => {
            if (busy) return;
            const category = btn.dataset.datasetCapture;
            setBusy(true);
            setMessage(messageEl, `Capturing ${category}...`, 'secondary');
            try {
                const data = await requestJson(dashboard.urls.datasetCapture, {
                    method: 'POST',
                    body: JSON.stringify({ category }),
                });
                lastCapture = data.capture ?? null;
                updateCounts(data.counts, data.total);
                setMessage(messageEl, data.message || `Saved ${category} photo.`, 'success');
            } catch (error) {
                setMessage(messageEl, error.message || 'Capture failed.', 'danger');
            } finally {
                setBusy(false);
            }
        });
    });

    undoBtn?.addEventListener('click', async () => {
        if (busy || !lastCapture) return;
        setBusy(true);
        setMessage(messageEl, `Removing ${lastCapture.filename}...`, 'secondary');
        try {
            const data = await requestJson(dashboard.urls.datasetCapture, {
                method: 'DELETE',
                body: JSON.stringify({
                    category: lastCapture.category,
                    filename: lastCapture.filename,
                }),
            });
            lastCapture = null;
            updateCounts(data.counts, data.total);
            setMessage(messageEl, data.message || 'Last capture deleted.', 'warning');
        } catch (error) {
            setMessage(messageEl, error.message || 'Could not undo last capture.', 'danger');
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
        const label = previewToggleBtn?.querySelector('[data-dataset-preview-label]');
        if (label) label.textContent = 'Start Live Viewfinder';
    };

    const startPreview = () => {
        if (!previewImg) return;
        previewRunning = true;
        setPill(previewBadge, 'warning', 'Connecting');
        const label = previewToggleBtn?.querySelector('[data-dataset-preview-label]');
        if (label) label.textContent = 'Stop Live Viewfinder';
        previewImg.onload = () => {
            if (!previewRunning) return;
            previewImg.classList.remove('hidden');
            previewPlaceholder?.classList.add('hidden');
            setPill(previewBadge, 'success', 'Live');
        };
        previewImg.onerror = () => {
            stopPreview();
            setPill(previewBadge, 'danger', 'Offline');
            setMessage(messageEl, `Live viewfinder unavailable at ${streamUrl} — you can still tap category buttons to capture.`, 'warning');
        };
        previewImg.src = streamUrl;
    };

    previewToggleBtn?.addEventListener('click', () => {
        if (previewRunning) {
            stopPreview();
        } else {
            startPreview();
        }
    });

    window.addEventListener('pagehide', stopPreview);
    loadCounts();
}
