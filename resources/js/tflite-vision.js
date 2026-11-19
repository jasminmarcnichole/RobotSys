export class TensorFlowLiteVision {
    constructor(modelUrl, inputSize = 320, labels = null) {
        this.modelUrl = modelUrl;
        this.inputSize = inputSize;
        this.labels = labels;
        this.inferUrl = null;
        this.worker = null;
        this.ready = null;
        this.sequence = 0;
        this.generation = 0;
        this.requests = new Map();
    }

    load() {
        if (this.ready) {
            return this.ready;
        }

        if (!window.Worker || !window.OffscreenCanvas || !window.createImageBitmap) {
            return Promise.reject(new Error('Use a current Chrome, Edge, or Firefox browser for camera detection.'));
        }

        this.worker = new Worker(new URL('./vision-worker.js', import.meta.url), { type: 'module' });
        const worker = this.worker;
        this.worker.onmessage = ({ data }) => {
            if (this.worker !== worker) {
                return;
            }
            const pending = this.requests.get(data.id);
            if (!pending) {
                return;
            }
            clearTimeout(pending.timer);
            this.requests.delete(data.id);
            data.error ? pending.reject(new Error(data.error)) : pending.resolve(data);
        };
        this.worker.onerror = () => {
            if (this.worker === worker) {
                this.dispose(new Error('The detection worker stopped. Retry to reload the model.'));
            }
        };
        this.ready = this.request({ type: 'init', modelUrl: this.modelUrl, inputSize: this.inputSize, labels: this.labels }, [], 60000).catch((error) => {
            if (this.worker === worker) {
                this.dispose(error);
            }
            throw error;
        });
        return this.ready;
    }

    request(message, transfer = [], timeout = 20000, worker = this.worker) {
        return new Promise((resolve, reject) => {
            const id = ++this.sequence;
            const timer = setTimeout(() => {
                if (this.worker === worker) {
                    this.dispose(new Error('Detection timed out. Retry to reload the model.'));
                } else {
                    const pending = this.requests.get(id);
                    if (pending && pending.timer === timer) {
                        this.requests.delete(id);
                        pending.reject(new Error('Detection stopped.'));
                    }
                }
            }, timeout);
            this.requests.set(id, { resolve, reject, timer });
            try {
                if (!worker || this.worker !== worker) {
                    throw new Error('Detection stopped.');
                }
                worker.postMessage({ ...message, id }, transfer);
            } catch (error) {
                clearTimeout(timer);
                this.requests.delete(id);
                transfer.forEach((item) => item.close?.());
                reject(error);
            }
        });
    }

    async detect(canvas) {
        const generation = this.generation;
        let usedFallback = false;
        if (this.inferUrl && typeof canvas?.toDataURL === 'function' && typeof globalThis.fetch === 'function') {
            try {
                const csrf = globalThis.document?.querySelector?.('meta[name="csrf-token"]')?.content ?? '';
                const response = await globalThis.fetch(this.inferUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
                    },
                    body: JSON.stringify({ camera_snapshot: canvas.toDataURL('image/jpeg', 0.85) }),
                    signal: globalThis.AbortSignal?.timeout?.(4500),
                });
                const payload = await response.json().catch(() => null);
                if (this.generation !== generation) {
                    throw new Error('Detection stopped.');
                }
                if (response.ok && payload?.ok === true && Array.isArray(payload.detections)) {
                    return {
                        engine: String(payload.backend || 'YOLO26n Calibrated'),
                        engineMode: 'backend',
                        inferenceMs: Number(payload.inference_ms ?? 0),
                        detections: payload.detections.map((item) => ({
                            boundingBox: {
                                originX: Number(item.bbox?.x ?? 0),
                                originY: Number(item.bbox?.y ?? 0),
                                width: Number(item.bbox?.width ?? 0),
                                height: Number(item.bbox?.height ?? 0),
                            },
                            categories: [{
                                categoryName: String(item.label ?? 'Object'),
                                score: Number(item.score ?? 0),
                            }],
                        })),
                    };
                }
                usedFallback = true;
            } catch (error) {
                if (error?.message === 'Detection stopped.' || this.generation !== generation) {
                    throw new Error('Detection stopped.');
                }
                usedFallback = true;
            }
        }
        const ready = this.load();
        const worker = this.worker;
        await ready;
        if (this.worker !== worker || this.generation !== generation) {
            throw new Error('Detection stopped.');
        }
        const bitmap = await createImageBitmap(canvas);
        const result = await this.request({ type: 'detect', bitmap }, [bitmap], 20000, worker);
        return {
            ...result,
            engine: usedFallback ? 'Browser TFLite (Fallback)' : 'Browser TFLite',
            engineMode: usedFallback ? 'fallback' : 'browser',
        };
    }

    dispose(error = new Error('Detection stopped.')) {
        this.generation++;
        this.worker?.terminate();
        this.worker = null;
        this.ready = null;
        for (const pending of this.requests.values()) {
            clearTimeout(pending.timer);
            pending.reject(error);
        }
        this.requests.clear();
    }
}
