// Benchmark validation frames with the application's compiled detection worker.
import { createHash } from 'node:crypto';
import { readFile, realpath, readdir, stat, mkdir, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { basename, dirname, isAbsolute, relative, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { parseArgs } from 'node:util';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const classes = ['background', 'plastic_bottle', 'other_plastic', 'plastic_wrapper', 'paper', 'metal_can', 'aluminum_can'];
export const sha256 = (bytes) => createHash('sha256').update(bytes).digest('hex');
const inside = (parent, child) => {
    const path = relative(parent, child);
    return path === '' || (!isAbsolute(path) && path !== '..' && !path.startsWith('../') && !path.startsWith('..\\'));
};

export function frameGeometry(image, inputSize, geometry = 'application') {
    if (![image.width, image.height].every((value) => Number.isSafeInteger(value) && value > 0)
        || ![256, 320, 448].includes(inputSize) || !['application', 'training_pad'].includes(geometry)) {
        throw new Error('Invalid diagnostic frame geometry.');
    }
    const scale = geometry === 'training_pad' ? inputSize / Math.max(image.width, image.height)
        : Math.min(1, 1280 / Math.max(image.width, image.height));
    const contentWidth = Math.max(1, Math.round(image.width * scale));
    const contentHeight = Math.max(1, Math.round(image.height * scale));
    return { width: geometry === 'training_pad' ? inputSize : contentWidth,
        height: geometry === 'training_pad' ? inputSize : contentHeight,
        content_width: contentWidth, content_height: contentHeight };
}

export function normalizeDetections(detections, image, frame, labelSpace) {
    if (!Array.isArray(detections) || !['generic', 'waste'].includes(labelSpace)
        || ![image.width, image.height, frame.width, frame.height].every((value) => Number.isInteger(value) && value > 0)) {
        throw new Error('Unexpected detection result or label space.');
    }
    const contentWidth = 'content_width' in frame ? frame.content_width : frame.width;
    const contentHeight = 'content_height' in frame ? frame.content_height : frame.height;
    if (![contentWidth, contentHeight].every((value) => Number.isInteger(value) && value > 0)
        || contentWidth > frame.width || contentHeight > frame.height
        || (('content_width' in frame) !== ('content_height' in frame))) {
        throw new Error('Invalid content dimensions for prediction projection.');
    }
    return detections.flatMap((detection) => {
        const box = detection.boundingBox;
        const category = detection.categories?.[0];
        if (!box || !category || !Number.isFinite(category.score)) {
            return [];
        }
        if (![box.originX, box.originY, box.width, box.height].every(Number.isFinite)
            || category.score < 0 || category.score > 1) {
            throw new Error('Invalid browser score or bounding box.');
        }
        const roundedX = Math.round(box.originX), roundedY = Math.round(box.originY);
        if ((contentWidth !== frame.width || contentHeight !== frame.height)
            && (roundedX >= contentWidth || roundedY >= contentHeight)) return [];
        const x = Math.max(0, Math.min(contentWidth - 1, roundedX));
        const y = Math.max(0, Math.min(contentHeight - 1, roundedY));
        const width = Math.min(contentWidth - x, Math.round(box.width));
        const height = Math.min(contentHeight - y, Math.round(box.height));
        if (width < 1 || height < 1) {
            return [];
        }
        const label = category.categoryName || category.displayName || 'Object';
        const categoryId = classes.indexOf(label);
        if (labelSpace === 'waste' && (categoryId < 1
            || (category.index !== -1 && category.index !== categoryId))) {
            throw new Error(`Unexpected waste label/index: ${label}/${category.index}`);
        }
        return [{ image_id: image.id, label, category_id: labelSpace === 'waste' ? categoryId : null,
            score: category.score, bbox: [x * image.width / contentWidth, y * image.height / contentHeight,
                width * image.width / contentWidth, height * image.height / contentHeight] }];
    }).sort((a, b) => b.score - a.score);
}

export async function loadPreparedFrames(manifestPath, inputs) {
    const path = await realpath(resolve(manifestPath));
    const folder = dirname(path);
    const publicRoot = await realpath(resolve(root, 'public'));
    if (inside(publicRoot, folder) || (inputs.dataset
        && (inside(inputs.dataset, folder) || inside(folder, inputs.dataset)))) {
        throw new Error('Prepared frame manifests must be private and separate from the dataset.');
    }
    const bytes = await readFile(path);
    const manifest = JSON.parse(bytes);
    const recipe = { decoder: 'tf.io.decode_jpeg(channels=3)',
        resize: 'official.vision.ops.preprocess_ops.resize_and_crop_image', input_size: 256,
        mean_rgb: [127.5], std_rgb: [127.5], padding: 'top_left_normalized_zero',
        augmentation: false, rgb_conversion: 'clip_rint_uint8', format: 'RGB_PNG' };
    const hashPattern = /^[a-f0-9]{64}$/;
    const expectedImages = Object.fromEntries([...inputs.images].map(([id, image]) => [String(id), image.sha256]));
    const sameObject = (left, right) => left && Object.keys(left).length === Object.keys(right).length
        && Object.entries(right).every(([key, value]) => JSON.stringify(left[key]) === JSON.stringify(value));
    if (manifest.schema_version !== 1 || manifest.status !== 'prepared' || manifest.split !== 'validation'
        || manifest.architecture !== 'MOBILENET_V2' || manifest.promoted !== false
        || !sameObject(manifest.recipe, recipe) || manifest.labels_sha256 !== inputs.labels_sha256
        || !sameObject(manifest.image_sha256, expectedImages) || !hashPattern.test(manifest.generator_sha256 ?? '')
        || !Array.isArray(manifest.frames) || manifest.frames.length !== inputs.images.size) {
        throw new Error('Prepared frame manifest must match the validation sources and training recipe.');
    }
    const frames = new Map();
    for (const frame of manifest.frames) {
        const image = inputs.images.get(frame.image_id);
        const scale = image ? 256 / Math.max(image.width, image.height) : NaN;
        if (!image || frames.has(frame.image_id) || frame.file !== `${image.id}.png`
            || frame.original_sha256 !== image.sha256 || frame.width !== 256 || frame.height !== 256
            || ![frame.content_width, frame.content_height].every((v) => Number.isSafeInteger(v) && v > 0 && v <= 256)
            || Math.max(frame.content_width, frame.content_height) !== 256
            || Math.abs(frame.content_width - image.width * scale) > 0.5001
            || Math.abs(frame.content_height - image.height * scale) > 0.5001
            || !hashPattern.test(frame.png_sha256 ?? '')
            || !Number.isFinite(frame.max_normalized_roundtrip_error)
            || frame.max_normalized_roundtrip_error < 0 || frame.max_normalized_roundtrip_error > 0.5 / 127.5 + 1e-6) {
            throw new Error('Invalid prepared frame identity, geometry or rounding error.');
        }
        const pngPath = await realpath(resolve(folder, frame.file));
        const png = await readFile(pngPath);
        if (!inside(folder, pngPath) || sha256(png) !== frame.png_sha256 || png.length < 33
            || png.subarray(0, 8).toString('hex') !== '89504e470d0a1a0a'
            || png.toString('ascii', 12, 16) !== 'IHDR' || png.readUInt32BE(16) !== 256
            || png.readUInt32BE(20) !== 256 || png[24] !== 8 || png[25] !== 2) {
            throw new Error('Prepared frame PNG bytes or dimensions disagree with the manifest.');
        }
        frames.set(image.id, { ...frame, path: pngPath, sha256: frame.png_sha256 });
    }
    return { path, sha256: sha256(bytes), manifest, frames };
}

export async function loadInputs(dataset, modelsPath, output, { diagnosticTraining = false } = {}) {
    if (typeof diagnosticTraining !== 'boolean') throw new Error('Diagnostic training scope must be explicit and boolean.');
    const split = diagnosticTraining ? 'train' : 'validation';
    dataset = await realpath(dataset);
    const publicRoot = await realpath(resolve(root, 'public'));
    const outputParent = resolve(dirname(output));
    await mkdir(outputParent, { recursive: true });
    if (inside(publicRoot, await realpath(outputParent)) || inside(dataset, await realpath(outputParent))) {
        throw new Error('Benchmark reports must be private and outside the dataset.');
    }
    try {
        await stat(output);
        throw new Error('Refusing to overwrite a benchmark report.');
    } catch (error) {
        if (error.code !== 'ENOENT') {
            throw error;
        }
    }
    const labelsPath = await realpath(resolve(dataset, `${split}/labels.json`));
    if (!inside(dataset, labelsPath)) {
        throw new Error('Validation labels escape the dataset.');
    }
    const labelsBytes = await readFile(labelsPath);
    const labels = JSON.parse(labelsBytes);
    if (!Array.isArray(labels.images) || !labels.images.length || !Array.isArray(labels.annotations)
        || JSON.stringify([...labels.categories].sort((a, b) => a.id - b.id).map((c) => [c.id, c.name]))
            !== JSON.stringify(classes.map((name, id) => [id, name]))) {
        throw new Error('Expected the prepared waste validation dataset.');
    }
    const images = new Map();
    for (const image of labels.images) {
        if (!Number.isSafeInteger(image.id) || image.id < 0 || images.has(image.id)
            || image.file_name !== `${image.id}.jpg`
            || ![image.width, image.height].every((v) => Number.isSafeInteger(v) && v > 0)) {
            throw new Error('Invalid or duplicate validation image.');
        }
        const path = await realpath(resolve(dataset, `${split}/data`, image.file_name));
        if (!inside(dataset, path)) {
            throw new Error('Validation image escapes the dataset.');
        }
        images.set(image.id, { ...image, path, sha256: sha256(await readFile(path)) });
    }
    const diagnosticFiles = new Map();
    let diagnostic = null;
    if (diagnosticTraining) {
        const documents = {};
        for (const name of ['summary.json', 'review.json', 'hitl-provenance.json']) {
            const path = await realpath(resolve(dataset, name));
            if (!inside(dataset, path)) throw new Error('Diagnostic metadata escapes its snapshot.');
            const bytes = await readFile(path);
            documents[name] = JSON.parse(bytes);
            diagnosticFiles.set(name, { path, sha256: sha256(bytes) });
        }
        const summary = documents['summary.json'], review = documents['review.json'], proof = documents['hitl-provenance.json'];
        const ids = [...images.keys()];
        const counts = new Map([1, 2, 3, 4].map((id) => [id, 0]));
        if (summary.schema_version !== 1 || summary.status !== 'diagnostic_selected' || summary.diagnostic_only !== true
            || summary.evaluation_only !== true || summary.full_dataset !== false || summary.promoted !== false
            || summary.evaluation_scope !== 'training_resubstitution' || !Number.isSafeInteger(summary.photos_per_class)
            || summary.photos_per_class < 5 || summary.photos_per_class > 10 || images.size !== summary.photos_per_class * 4
            || summary.labels_sha256 !== sha256(labelsBytes) || summary.provenance_sha256 !== diagnosticFiles.get('hitl-provenance.json').sha256
            || review.status !== 'accepted' || review.summary_sha256 !== diagnosticFiles.get('summary.json').sha256
            || typeof review.reviewer !== 'string' || !review.reviewer.trim() || !Array.isArray(review.accepted_image_ids)
            || review.accepted_image_ids.length !== ids.length || new Set(review.accepted_image_ids).size !== ids.length
            || !review.accepted_image_ids.every((id) => images.has(id)) || labels.annotations.length !== images.size
            || Object.keys(summary.image_sha256 ?? {}).length !== images.size
            || new Set([...images.values()].map((image) => image.sha256)).size !== images.size) {
            throw new Error('Expected a reviewed, unchanged 20-40-photo training-only diagnostic snapshot.');
        }
        for (const image of images.values()) {
            const boxes = labels.annotations.filter((item) => item.image_id === image.id);
            const entry = proof.images?.[image.source_image_id];
            if (boxes.length !== 1 || !counts.has(boxes[0].category_id) || summary.image_sha256[String(image.id)] !== image.sha256
                || entry?.split !== 'train' || entry.image_id !== image.id || entry.prepared_sha256 !== image.sha256
                || typeof entry.source_annotation_json !== 'string' || sha256(Buffer.from(entry.source_annotation_json)) !== entry.annotation_sha256) {
                throw new Error('Training diagnostic source identity, split, label coverage or image bytes changed.');
            }
            counts.set(boxes[0].category_id, counts.get(boxes[0].category_id) + 1);
        }
        if ([...counts.values()].some((count) => count !== summary.photos_per_class)) throw new Error('Diagnostic classes must remain balanced.');
        diagnostic = { diagnostic_only: true, evaluation_scope: 'training_resubstitution',
            snapshot_sha256: diagnosticFiles.get('summary.json').sha256, review_sha256: diagnosticFiles.get('review.json').sha256,
            deployment_ready: false };
    }
    const modelsBytes = await readFile(modelsPath);
    const configs = JSON.parse(modelsBytes);
    if (!Array.isArray(configs) || !configs.length || configs.length > 4) {
        throw new Error('Expected one to four explicit model configurations.');
    }
    const models = new Map();
    const preparedFiles = new Map();
    for (const config of configs) {
        const geometry = config.input_geometry === undefined ? 'application' : config.input_geometry;
        const preprocessing = config.input_preprocessing === undefined ? 'browser_canvas' : config.input_preprocessing;
        if (diagnosticTraining && preprocessing !== 'browser_canvas') throw new Error('Training diagnostics currently require Canvas inputs.');
        if (!/^[a-z0-9_]+$/.test(config.id ?? '') || models.has(config.id)
            || ![256, 320, 448].includes(config.input_size) || !['generic', 'waste'].includes(config.label_space)
            || !['application', 'training_pad'].includes(geometry)
            || (geometry === 'training_pad' && (config.label_space !== 'waste' || config.input_size !== 256))
            || !['browser_canvas', 'tensorflow_rgb'].includes(preprocessing)
            || (preprocessing === 'tensorflow_rgb' && (geometry !== 'training_pad' || typeof config.input_manifest !== 'string'))
            || (preprocessing === 'browser_canvas' && config.input_manifest !== undefined)) {
            throw new Error('Invalid model identity, input size, or label space.');
        }
        const path = await realpath(resolve(config.path));
        const bytes = await readFile(path);
        if (bytes.length < 8 || bytes.length > 32_000_000 || bytes.toString('ascii', 4, 8) !== 'TFL3') {
            throw new Error('Expected a bounded TFLite model.');
        }
        const prepared = preprocessing === 'tensorflow_rgb'
            ? await loadPreparedFrames(config.input_manifest, { images, dataset, labels_sha256: sha256(labelsBytes) }) : null;
        if (prepared) {
            preparedFiles.set(prepared.path, { path: prepared.path, sha256: prepared.sha256 });
            for (const frame of prepared.frames.values()) preparedFiles.set(frame.path, frame);
        }
        models.set(config.id, { ...config, input_geometry: geometry, input_preprocessing: preprocessing,
            prepared, path, sha256: sha256(bytes) });
    }
    const assetsFolder = resolve(publicRoot, 'build/assets');
    const assetNames = await readdir(assetsFolder);
    const workers = assetNames.filter((name) => /^vision-worker-[\w-]+\.js$/.test(name));
    if (workers.length !== 1) {
        throw new Error('Expected one current compiled application worker.');
    }
    const worker = workers[0];
    const assets = new Map();
    for (const name of assetNames.filter((name) => name === worker || /^vision_wasm_module_internal-[\w-]+\.(js|wasm)$/.test(name))) {
        const path = await realpath(resolve(assetsFolder, name));
        if (!inside(assetsFolder, path)) {
            throw new Error('Built asset escapes its directory.');
        }
        assets.set(name, { path, sha256: sha256(await readFile(path)) });
    }
    const workerSourcePath = resolve(root, 'resources/js/vision-worker.js');
    const cameraSourcePath = resolve(root, 'resources/js/robot-camera.js');
    const workerSource = await readFile(workerSourcePath, 'utf8');
    if (!/scoreThreshold:\s*0\.25\s*,/.test(workerSource) || !/maxResults:\s*10\s*,/.test(workerSource)) {
        throw new Error('Worker settings changed; review this benchmark contract.');
    }
    return { dataset, split, diagnostic, diagnosticFiles, labels, labelsPath, modelsPath: await realpath(modelsPath), labels_sha256: sha256(labelsBytes), models, images, assets, worker, preparedFiles,
        sourceFiles: [workerSourcePath, cameraSourcePath, resolve(root, 'resources/js/tflite-vision.js')],
        models_config_sha256: sha256(modelsBytes) };
}

export function createAssetServer(inputs) {
    return createServer(async (request, response) => {
        try {
            if (request.method !== 'GET') {
                response.writeHead(405).end();
                return;
            }
            const path = new URL(request.url, 'http://localhost').pathname;
            if (path === '/') {
                response.writeHead(200, { 'Content-Type': 'text/html', 'Cache-Control': 'no-store' })
                    .end('<!doctype html><title>Private validation benchmark</title>');
                return;
            }
            let file, mime;
            if (path.startsWith('/build/assets/')) {
                file = inputs.assets.get(path.slice(14));
                mime = path.endsWith('.wasm') ? 'application/wasm' : 'text/javascript';
            } else if (path.startsWith('/models/')) {
                file = inputs.models.get(path.slice(8));
                mime = 'application/octet-stream';
            } else if (/^\/frames\/\d+$/.test(path)) {
                file = inputs.images.get(Number(path.slice(8)));
                mime = 'image/jpeg';
            } else if (/^\/prepared\/[a-z0-9_]+\/\d+$/.test(path)) {
                const [, , modelId, imageId] = path.split('/');
                file = inputs.models.get(modelId)?.prepared?.frames.get(Number(imageId));
                mime = 'image/png';
            }
            if (!file) {
                response.writeHead(404).end();
                return;
            }
            const bytes = await readFile(file.path);
            if (sha256(bytes) !== file.sha256) {
                throw new Error('Benchmark input bytes changed.');
            }
            response.writeHead(200, { 'Content-Type': mime, 'Cache-Control': 'no-store' }).end(bytes);
        } catch {
            response.writeHead(500).end('Private benchmark input failed.');
        }
    });
}

export async function benchmark(options) {
    const inputs = await loadInputs(resolve(options.dataset), resolve(options.models), resolve(options.output),
        { diagnosticTraining: options['diagnostic-training'] ?? false });
    const sourceHashes = {};
    for (const path of inputs.sourceFiles) {
        sourceHashes[basename(path)] = sha256(await readFile(path));
    }
    const playwright = await import(pathToFileURL(await realpath(options['playwright-module'])).href);
    const server = createAssetServer(inputs);
    let browser;
    const errors = [];
    try {
        await new Promise((accept, reject) => {
            server.once('error', reject);
            server.listen(0, '127.0.0.1', accept);
        });
        const origin = `http://127.0.0.1:${server.address().port}`;
        browser = await playwright.chromium.launch({ channel: 'chrome', headless: true, args: ['--disable-gpu'] });
        const page = await browser.newPage();
        page.on('pageerror', (error) => errors.push(error.message));
        await page.route('**/*', (route) => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
        await page.goto(origin);
        const report = { status: 'inferred', created_at: new Date().toISOString(), split: inputs.split,
            ...(inputs.diagnostic ?? {}),
            backend: 'Compiled application browser worker / MediaPipe Tasks Vision CPU',
            browser_version: browser.version(), worker_sha256: inputs.assets.get(inputs.worker).sha256,
            assets_sha256: Object.fromEntries([...inputs.assets].map(([name, file]) => [name, file.sha256])),
            application_sources_sha256: sourceHashes, benchmarker_sha256: sha256(await readFile(fileURLToPath(import.meta.url))),
            models_config_sha256: inputs.models_config_sha256, labels_sha256: inputs.labels_sha256,
            image_sha256: Object.fromEntries([...inputs.images].map(([id, image]) => [String(id), image.sha256])),
            settings: { worker_score_threshold: 0.2, max_results: 10, ui_score_threshold: 0.5,
                category_selection: 'first_category_as_application', frame_max_side: 1280 }, models: {}, promoted: false,
            limitations: [inputs.diagnostic
                ? 'Training resubstitution on deliberately easy, previously trained photos; not validation, generalization, or deployment accuracy.'
                : 'Public validation photos, not laptop or ESP32-CAM field measurements.',
                'Generic COCO labels are not mapped to waste materials; localization is compared separately.',
                'First-frame timing includes warmup; capture, network, and UI scheduling delays are excluded.',
                'training_pad is a private top-left gray-padded Canvas geometry probe, not the live capture path or exact TensorFlow pixel preprocessing.',
                'tensorflow_rgb uses audited training-prepared RGB PNGs; uint8 rounding is not exact float tensor parity and no live preprocessing is changed.',
                'Unannotated non-target objects can contribute apparent false positives in localization comparisons.'] };
        for (const [id, model] of inputs.models) {
            await page.evaluate(async ({ workerPath, modelUrl, inputSize }) => {
                window.worker = new Worker(workerPath, { type: 'module' });
                window.sequence = 0;
                window.pending = new Map();
                window.worker.onmessage = ({ data }) => {
                    const pending = window.pending.get(data.id);
                    if (!pending) return;
                    clearTimeout(pending.timer);
                    window.pending.delete(data.id);
                    data.error ? pending.reject(new Error(data.error)) : pending.accept(data);
                };
                window.worker.onerror = (event) => {
                    for (const pending of window.pending.values()) {
                        clearTimeout(pending.timer);
                        pending.reject(new Error(event.message || 'Worker failed'));
                    }
                    window.pending.clear();
                };
                window.request = (payload, transfer = []) => new Promise((accept, reject) => {
                    const id = ++window.sequence;
                    const timer = setTimeout(() => {
                        window.pending.delete(id);
                        reject(new Error('Browser inference timed out'));
                    }, 60000);
                    window.pending.set(id, { accept, reject, timer });
                    window.worker.postMessage({ ...payload, id }, transfer);
                });
                await window.request({ type: 'init', modelUrl, inputSize });
            }, { workerPath: `/build/assets/${inputs.worker}`, modelUrl: `/models/${id}`, inputSize: model.input_size });
            const results = { model_sha256: model.sha256, input_size: model.input_size, label_space: model.label_space,
                input_geometry: model.input_geometry, input_preprocessing: model.input_preprocessing,
                ...(model.prepared ? { input_manifest_sha256: model.prepared.sha256, input_manifest: model.prepared.manifest } : {}),
                predictions: [], frames: [] };
            for (const image of inputs.images.values()) {
                const prepared = model.prepared?.frames.get(image.id);
                const geometry = prepared ? { width: 256, height: 256,
                    content_width: prepared.content_width, content_height: prepared.content_height }
                    : frameGeometry(image, model.input_size, model.input_geometry);
                const result = await page.evaluate(async (image) => {
                    const response = await fetch(image.url);
                    if (!response.ok) throw new Error('Frame download failed');
                    const bitmap = await createImageBitmap(await response.blob());
                    const expectedWidth = image.prepared ? 256 : image.width;
                    const expectedHeight = image.prepared ? 256 : image.height;
                    if (bitmap.width !== expectedWidth || bitmap.height !== expectedHeight) {
                        bitmap.close();
                        throw new Error('Decoded image dimensions disagree with labels');
                    }
                    if (image.prepared) {
                        const result = await window.request({ type: 'detect', bitmap }, [bitmap]);
                        return { ...result, ...image.geometry };
                    }
                    const canvas = document.createElement('canvas');
                    canvas.width = image.geometry.width;
                    canvas.height = image.geometry.height;
                    const context = canvas.getContext('2d');
                    if (image.input_geometry === 'training_pad') {
                        context.fillStyle = 'rgb(128, 128, 128)';
                        context.fillRect(0, 0, canvas.width, canvas.height);
                    }
                    context.drawImage(bitmap, 0, 0, image.geometry.content_width, image.geometry.content_height);
                    bitmap.close();
                    const frame = await createImageBitmap(canvas);
                    const result = await window.request({ type: 'detect', bitmap: frame }, [frame]);
                    return { ...result, ...image.geometry };
                }, { id: image.id, width: image.width, height: image.height, geometry, input_geometry: model.input_geometry,
                    prepared: Boolean(prepared), url: prepared ? `/prepared/${id}/${image.id}` : `/frames/${image.id}` });
                if (!Number.isFinite(result.inferenceMs) || result.inferenceMs < 0) {
                    throw new Error('Invalid inference timing.');
                }
                results.predictions.push(...normalizeDetections(result.detections, image, result, model.label_space));
                results.frames.push({ image_id: image.id, width: result.width, height: result.height,
                    content_width: result.content_width, content_height: result.content_height,
                    ...(prepared ? { input_png_sha256: prepared.sha256 } : {}),
                    inference_ms: result.inferenceMs, detections: result.detections });
                process.stdout.write(`${id}: ${inputs.split} image ${image.id} complete\n`);
            }
            await page.evaluate(() => window.worker.terminate());
            report.models[id] = results;
        }
        if (errors.length) throw new Error(`Browser errors: ${errors.join('; ')}`);
        if (sha256(await readFile(fileURLToPath(import.meta.url))) !== report.benchmarker_sha256) throw new Error('Benchmarker changed.');
        if (sha256(await readFile(inputs.modelsPath)) !== inputs.models_config_sha256) throw new Error('Model configuration changed.');
        if (sha256(await readFile(inputs.labelsPath)) !== inputs.labels_sha256) throw new Error('Validation labels changed.');
        for (const path of inputs.sourceFiles) {
            if (sha256(await readFile(path)) !== sourceHashes[basename(path)]) throw new Error('Application source changed.');
        }
        for (const file of [...inputs.images.values(), ...inputs.models.values(), ...inputs.assets.values(), ...inputs.preparedFiles.values(), ...inputs.diagnosticFiles.values()]) {
            if (sha256(await readFile(file.path)) !== file.sha256) throw new Error('Benchmark input changed.');
        }
        await writeFile(resolve(options.output), JSON.stringify(report, null, 2) + '\n', { flag: 'wx' });
        process.stdout.write(`Saved private browser benchmark: ${resolve(options.output)}\n`);
        return report;
    } finally {
        try {
            await browser?.close();
        } finally {
            server.closeAllConnections();
            if (server.listening) await new Promise((accept, reject) => server.close((error) => error ? reject(error) : accept()));
        }
    }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    try {
        const { values } = parseArgs({ options: { ...Object.fromEntries(['dataset', 'models', 'output', 'playwright-module']
            .map((name) => [name, { type: 'string' }])), 'diagnostic-training': { type: 'boolean' } } });
        if (['dataset', 'models', 'output', 'playwright-module'].some((name) => !values[name])) {
            throw new Error('Required: --dataset --models --output --playwright-module (existing installation; no packages installed).');
        }
        await benchmark(values);
    } catch (error) {
        process.stderr.write(`Browser benchmark failed: ${error.message}\n`);
        process.exitCode = 1;
    }
}
