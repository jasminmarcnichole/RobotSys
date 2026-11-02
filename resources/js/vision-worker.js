import { ObjectDetector } from "@mediapipe/tasks-vision";
import wasmLoaderPath from "@mediapipe/tasks-vision/vision_wasm_module_internal.js?url";
import wasmBinaryPath from "@mediapipe/tasks-vision/vision_wasm_module_internal.wasm?url";

let detector;
let customLabels = null;

self.onmessage = async ({ data }) => {
    const { id, type, bitmap } = data;

    try {
        if (type === "init") {
            detector?.close();
            customLabels = data.labels || null;
            detector = await ObjectDetector.createFromOptions({ wasmLoaderPath, wasmBinaryPath }, {
                baseOptions: { modelAssetPath: data.modelUrl, delegate: "CPU" },
                runningMode: "IMAGE",
                scoreThreshold: data.scoreThreshold || 0.25,
                maxResults: data.maxResults || 10,
                canvas: new OffscreenCanvas(data.inputSize || 320, data.inputSize || 320),
            });
            self.postMessage({ id, ready: true });
            return;
        }

        if (!detector || !bitmap) {
            throw new Error("The detection model is not ready.");
        }

        const startedAt = performance.now();
        const result = detector.detect(bitmap);
        let detections = result.detections || [];
        if (customLabels && Array.isArray(customLabels)) {
            detections = detections.map(d => {
                if (d.categories && d.categories[0]) {
                    const idx = d.categories[0].index;
                    if (typeof idx === "number" && customLabels[idx]) {
                        d.categories[0].categoryName = customLabels[idx];
                        d.categories[0].displayName = customLabels[idx];
                    }
                }
                return d;
            });
        }
        self.postMessage({ id, detections, inferenceMs: performance.now() - startedAt });
    } catch (error) {
        self.postMessage({ id, error: error.message || "TensorFlow Lite could not process this frame." });
    } finally {
        bitmap?.close();
    }
};
