export class TensorFlowLiteVision {
    constructor(modelUrl, inputSize = 320) {
        this.modelUrl = modelUrl;
        this.inputSize = inputSize;
        this.worker = null;
        this.ready = null;
        this.sequence = 0;
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
        const ready = this.load();
        const worker = this.worker;
        await ready;
        if (this.worker !== worker) {
            throw new Error('Detection stopped.');
        }
        const bitmap = await createImageBitmap(canvas);
        return this.request({ type: 'detect', bitmap }, [bitmap], 20000, worker);
    }

    dispose(error = new Error('Detection stopped.')) {
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
