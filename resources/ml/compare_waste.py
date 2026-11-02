"""Compare a saved waste checkpoint and TFLite export on validation images only."""

from __future__ import annotations

import argparse
import base64
import hashlib
import html
import json
import os
import subprocess
import sys
import tempfile
from dataclasses import replace
from datetime import datetime, timezone
from pathlib import Path
from types import SimpleNamespace
from typing import Any

from evaluate_waste import (
    coco_detection, create_evaluation_detector, merge_duplicate_detections, validate_model_labels,
)
from train_waste import (
    CLASSES, ArgumentParser, TrainingError, check_environment, contained_file,
    finite_number, integer, json_value, load_training_api, read_json, run_configuration,
    validate_dataset, validate_output,
    write_json_report,
)


def project_prediction(
    prediction: dict[str, Any], image: dict[str, Any], scale_yx: list[float],
    offset_yx: tuple[float, float] = (0, 0),
) -> dict[str, Any] | None:
    if len(scale_yx) != 2 or any(not finite_number(v) or v <= 0 for v in scale_yx):
        raise TrainingError("Coordinate scales must be two finite positive values.")
    if len(offset_yx) != 2 or any(not finite_number(v) for v in offset_yx):
        raise TrainingError("Coordinate offsets must be two finite values.")
    x, y, width, height = prediction["bbox"]
    if not all(finite_number(v) for v in (x, y, width, height)) or width <= 0 or height <= 0:
        raise TrainingError("Prediction coordinates must be finite with positive dimensions.")
    left = max(0, min(image["width"], (x + offset_yx[1]) / scale_yx[1]))
    top = max(0, min(image["height"], (y + offset_yx[0]) / scale_yx[0]))
    right = max(0, min(image["width"], (x + width + offset_yx[1]) / scale_yx[1]))
    bottom = max(0, min(image["height"], (y + height + offset_yx[0]) / scale_yx[0]))
    if right <= left or bottom <= top:
        return None
    return {**prediction, "bbox": [left, top, right - left, bottom - top]}


def comparison_svg(image: dict[str, Any], jpeg: bytes, panels: dict[str, list[dict[str, Any]]]) -> str:
    panel_width = 480
    scale = panel_width / image["width"]
    image_height = image["height"] * scale
    panel_height = image_height + 56
    rows = (len(panels) + 1) // 2
    encoded = base64.b64encode(jpeg).decode("ascii")
    svg = [f'<svg xmlns="http://www.w3.org/2000/svg" width="980" height="{rows * panel_height}" viewBox="0 0 980 {rows * panel_height}">',
           f'<title>Validation image {image["id"]}: prediction comparison</title>',
           '<desc>Green boxes: ground truth. Yellow boxes: predictions with confidence at least 0.20. Metrics include lower-confidence predictions.</desc>',
           f'<metadata>{html.escape(json.dumps(image.get("attribution", {})))}</metadata>',
           '<rect width="100%" height="100%" fill="white"/>']
    for index, (name, predictions) in enumerate(panels.items()):
        x = (index % 2) * 500
        y = (index // 2) * panel_height
        svg.append(f'<g transform="translate({x},{y})"><text x="4" y="20" font-family="sans-serif" font-size="15" fill="#111827">{html.escape(name)}</text>')
        svg.append(f'<svg x="0" y="32" width="480" height="{image_height}" viewBox="0 0 {image["width"]} {image["height"]}">')
        svg.append(f'<image width="{image["width"]}" height="{image["height"]}" href="data:image/jpeg;base64,{encoded}"/>')
        visible = [p for p in predictions if "score" not in p or p["score"] >= 0.2]
        visible = sorted(visible, key=lambda p: p.get("score", 1), reverse=True)
        placed_labels = []
        for prediction in visible:
            bx, by, bw, bh = prediction["bbox"]
            color = "#00ff88" if "score" not in prediction else "#ffdf00"
            label = CLASSES[prediction["category_id"]]
            if "score" in prediction:
                label += f' {prediction["score"]:.2f}'
            svg.append(f'<rect x="{bx}" y="{by}" width="{bw}" height="{bh}" fill="none" stroke="{color}" stroke-width="{2 / scale}"><title>{html.escape(label)}</title></rect>')
            label_width, line_height = len(label) * 7.3 / scale, 16 / scale
            text_x = max(0, min(bx, image["width"] - label_width))
            text_y = max(line_height, min(by, image["height"] - 2 / scale))
            alternatives = sorted(
                [line_height * row for row in range(1, int(image["height"] / line_height) + 1)],
                key=lambda position: abs(position - text_y),
            )
            for candidate in [text_y] + alternatives:
                if not any(text_x < px + pw and text_x + label_width > px and abs(candidate - py) < line_height for px, py, pw in placed_labels):
                    text_y = candidate
                    break
            else:
                # Dense scenes retain every box; its full label remains in the tooltip.
                continue
            placed_labels.append((text_x, text_y, label_width))
            if abs(text_y - by) > line_height:
                svg.append(f'<line x1="{bx}" y1="{by}" x2="{text_x}" y2="{text_y}" stroke="{color}" stroke-width="{1 / scale}"/>')
            svg.append(f'<text x="{text_x}" y="{text_y}" font-family="monospace" font-size="{12 / scale}" stroke="black" stroke-width="{2 / scale}" paint-order="stroke" fill="{color}">{html.escape(label)}</text>')
        svg.append('</svg></g>')
    svg.append('</svg>')
    return "\n".join(svg)


def file_hash(path: Path) -> str:
    with path.open("rb") as source:
        return hashlib.file_digest(source, "sha256").hexdigest()


def keras_predictions(result: dict[str, Any], image: dict[str, Any], scale: list[float]) -> list[dict[str, Any]]:
    predictions = []
    for index in range(int(result["num_detections"][0])):
        top, left, bottom, right = result["detection_boxes"][0, index].numpy().tolist()
        if right <= left or bottom <= top:
            continue
        prediction = {
            "image_id": image["id"], "category_id": int(result["detection_classes"][0, index]),
            "score": float(result["detection_scores"][0, index]),
            "bbox": [left, top, right - left, bottom - top],
        }
        if prediction["category_id"] not in (1, 2, 3, 4):
            raise TrainingError("Unexpected Keras category ID.")
        projected = project_prediction(prediction, image, scale)
        if projected is not None:
            predictions.append(projected)
    return predictions


def postprocessed_predictions(result: list[Any], image: dict[str, Any], scale: list[float]) -> list[dict[str, Any]]:
    if not isinstance(result, list) or len(result) != 4:
        raise TrainingError("Expected four postprocessed tensors in validated kernel output order.")
    boxes, categories, scores, counts = result
    if (not isinstance(counts, list) or len(counts) != 1 or not finite_number(counts[0])
            or int(counts[0]) != counts[0] or not 0 <= counts[0] <= 100
            or any(not isinstance(value, list) or len(value) != 1 or not isinstance(value[0], list)
                   for value in (boxes, categories, scores))):
        raise TrainingError("Invalid postprocessed detection count or batch dimensions.")
    count = int(counts[0])
    if any(len(value[0]) < count for value in (boxes, categories, scores)):
        raise TrainingError("Postprocessed tensors do not cover their detection count.")
    predictions = []
    for box, category, score in zip(boxes[0][:count], categories[0][:count], scores[0][:count]):
        if (not isinstance(box, list) or len(box) != 4 or not all(finite_number(value) for value in box)
                or not finite_number(category) or int(category) != category or category not in (1, 2, 3, 4)
                or not finite_number(score) or not 0 <= score <= 1):
            raise TrainingError("Invalid normalized postprocessed box, foreground class, or score.")
        top, left, bottom, right = box
        if right <= left or bottom <= top:
            continue
        prediction = {"image_id": image["id"], "category_id": int(category), "score": score,
                      "bbox": [left * 256, top * 256, (right - left) * 256, (bottom - top) * 256]}
        projected = project_prediction(prediction, image, scale)
        if projected is not None:
            predictions.append(projected)
    return predictions


def validated_exports(folder: Path, source: dict[str, Any], versions: dict[str, str],
                      policy: dict[str, Any]) -> tuple[dict[str, Path], dict[str, str]]:
    from export_waste import postprocessing_config

    path = folder / "export.json"
    report = read_json(folder, path)
    if (report.get("status") != "exported" or report.get("promoted") is not False
            or report.get("retrained") is not False or report.get("versions") != versions
            or not isinstance(report.get("source"), dict)
            or any(report["source"].get(key) != value for key, value in source.items())
            or report.get("training_postprocessing") != policy or set(report.get("models", {})) != {"regular", "fast"}):
        raise TrainingError("Postprocessed exports must match this completed checkpoint, dataset, and decoder policy.")
    models, hashes = {}, {str(path): file_hash(path)}
    for mode, model in report["models"].items():
        config = json_value(postprocessing_config(policy, mode == "regular"))
        if (not isinstance(model, dict) or model.get("file") != f"{mode}/waste-detector.tflite"
                or model.get("postprocessing") != config or not integer(model.get("bytes"))):
            raise TrainingError("Invalid postprocessed export model or NMS policy.")
        model_path = contained_file(folder, folder / model["file"])
        if model_path.stat().st_size != model["bytes"] or file_hash(model_path) != model.get("sha256"):
            raise TrainingError("Postprocessed export model bytes changed.")
        models[mode] = model_path
        hashes[str(model_path)] = model["sha256"]
    return models, hashes


def mediapipe_probe(request_path: Path) -> dict[str, Any]:
    import mediapipe as mp
    from mediapipe.tasks.python.metadata.metadata import MetadataDisplayer

    request = read_json(request_path.parent, request_path)
    if request["mode"] not in ("mediapipe_default", "mediapipe_training_geometry"):
        raise TrainingError("Unknown MediaPipe comparison probe.")
    model_path = Path(request["model"])
    if file_hash(model_path) != request["model_sha256"]:
        raise TrainingError("Probe model bytes changed.")
    validate_model_labels(MetadataDisplayer.with_model_file(str(model_path)))
    options = mp.tasks.vision.ObjectDetectorOptions(
        base_options=mp.tasks.BaseOptions(model_asset_path=str(model_path), delegate=mp.tasks.BaseOptions.Delegate.CPU),
        running_mode=mp.tasks.vision.RunningMode.IMAGE, score_threshold=0.001, max_results=100,
    )
    predictions, image_hashes = [], {}
    graph = ""
    for entry in request["frames"]:
        # IMAGE probes are independent; do not reuse native graph buffers across inputs.
        with create_evaluation_detector(options) as detector:
            if not graph:
                graph = str(detector.get_graph_config())
            image = entry["image"]
            image_hashes[str(image["id"])] = file_hash(Path(entry["original"]))
            if file_hash(Path(entry["input"])) != entry["input_sha256"]:
                raise TrainingError("Probe input bytes changed.")
            frame = mp.Image.create_from_file(entry["input"])
            geometry = request["mode"] == "mediapipe_training_geometry"
            expected = (256, 256) if geometry else (image["width"], image["height"])
            if (frame.width, frame.height) != expected:
                raise TrainingError("Probe image dimensions do not match its request.")
            frame_info = {**image, "width": 256, "height": 256} if geometry else image
            for detection in merge_duplicate_detections(detector.detect(frame).detections):
                prediction = coco_detection(detection, frame_info)
                if prediction is not None and geometry:
                    prediction = project_prediction(prediction, image, entry["scale_yx"])
                if prediction is not None:
                    predictions.append(prediction)
            print(f'{request["mode"]}: validation image {image["id"]}', flush=True)
    return {
        "status": "evaluated", "split": "validation", "mode": request["mode"],
        "model_sha256": request["model_sha256"], "labels_sha256": request["labels_sha256"],
        "image_sha256": image_hashes, "predictions": predictions, "graph": graph,
        "images": len(request["frames"]), "promoted": False,
        "deduplication": "python_exact_bbox_label_merge",
    }


def run_mediapipe_probe(
    request_path: Path, log_path: Path, timeout_seconds: int = 300,
) -> dict[str, Any]:
    from analyze_waste import validate_report

    request = read_json(request_path.parent, request_path)
    environment = os.environ.copy()
    environment["PYTHONFAULTHANDLER"] = "1"
    for key in ("TF_NUM_INTRAOP_THREADS", "OMP_NUM_THREADS", "OPENBLAS_NUM_THREADS"):
        environment.setdefault(key, "2")
    environment.setdefault("TF_NUM_INTEROP_THREADS", "1")
    command = [sys.executable, "-u", str(Path(__file__).resolve()), "--mediapipe-probe", str(request_path)]
    try:
        with log_path.open("xb") as log:
            completed = subprocess.run(command, stdin=subprocess.DEVNULL, stdout=log,
                                       stderr=subprocess.STDOUT, env=environment, timeout=timeout_seconds, check=False)
        if completed.returncode != 0:
            raise TrainingError(f"MediaPipe comparison probe exited with code {completed.returncode}.")
        result = read_json(request_path.parent, request_path.with_suffix(".result.json"))
        validate_report(result, {"images": [entry["image"] for entry in request["frames"]]},
                        request["labels_sha256"], request["image_sha256"])
        if (result.get("model_sha256") != request["model_sha256"] or result.get("mode") != request["mode"]
                or type(result.get("images")) is not int or result["images"] != len(request["frames"])
                or result.get("promoted") is not False
                or not isinstance(result.get("graph"), str)):
            raise TrainingError("MediaPipe comparison result does not match its request.")
        result["process"] = {"exit_code": 0, "log": log_path.name, "timeout_seconds": timeout_seconds}
        return result
    except subprocess.TimeoutExpired as error:
        raise TrainingError(f"MediaPipe comparison probe timed out after {timeout_seconds}s; see {log_path}.") from error
    except Exception as error:
        raise TrainingError(f"MediaPipe comparison probe failed: {error}; see {log_path}.") from error


def compare(args: argparse.Namespace) -> dict[str, Any]:
    checkpoint_only = getattr(args, "checkpoint_only", False)
    export_folder = getattr(args, "postprocessed_export", None)
    postprocess_threads = getattr(args, "postprocess_threads", 1)
    if type(checkpoint_only) is not bool:
        raise TrainingError("Checkpoint-only selection must be boolean.")
    if not integer(postprocess_threads) or postprocess_threads not in (1, 2):
        raise TrainingError("Postprocessed interpreter threads must be one or two.")
    versions = check_environment()
    audit = validate_dataset(args.dataset)
    validate_output(args.output, args.dataset)
    if args.output.is_relative_to(args.run) or args.run.is_relative_to(args.output):
        raise TrainingError("Comparison output and training run must be separate directories.")
    if export_folder is not None and (args.output.is_relative_to(export_folder) or export_folder.is_relative_to(args.output)):
        raise TrainingError("Comparison output and postprocessed exports must be separate directories.")
    source_hashes = {str(path): file_hash(path) for path in (args.run / "metrics.json", args.run / "run.json")}
    metrics = read_json(args.run, args.run / "metrics.json")
    manifest = read_json(args.run, args.run / "run.json")
    if metrics.get("status") != "trained" or metrics.get("architecture") != "MOBILENET_V2":
        raise TrainingError("Comparison requires a completed MobileNetV2 training run.")
    configuration = run_configuration(SimpleNamespace(
        dataset=args.dataset, epochs=metrics["epochs"], batch_size=metrics["batch_size"],
    ), versions)
    if manifest["dataset_sha256"] != configuration["dataset_sha256"] or manifest["versions"] != versions:
        raise TrainingError("Dataset or pinned dependencies do not match the saved training run.")
    model_path = contained_file(args.run, args.run / "waste-detector.tflite")
    model_hash = file_hash(model_path)
    if model_hash != metrics["model"]["sha256"]:
        raise TrainingError("TFLite file does not match the saved training report.")
    checkpoint_files = [contained_file(args.run, args.run / name) for name in (
        "float_ckpt.index", "float_ckpt.data-00000-of-00001",
    )]
    checkpoint_hashes = {path.name: file_hash(path) for path in checkpoint_files}

    api = load_training_api()
    import numpy as np
    import tensorflow as tf
    from PIL import Image
    from mediapipe_model_maker.python.vision.object_detector import model as model_lib
    from mediapipe_model_maker.python.vision.object_detector import model_options, preprocessor
    from official.vision.ops import preprocess_ops
    from evaluate_waste import score_predictions

    # Reuse the local trained checkpoint even during constructor initialization;
    # this avoids fetching pretrained weights or running any training.
    spec = replace(api.SupportedModels.get(api.SupportedModels.MOBILENET_V2),
                   downloaded_files=SimpleNamespace(get_path=lambda: str(args.run)),
                   checkpoint_name="float_ckpt")
    model = model_lib.ObjectDetectorModel(spec, model_options.ObjectDetectorModelOptions(), len(CLASSES))
    model.load_checkpoint(str(args.run / "float_ckpt"), include_last_layer=True)
    processor = preprocessor.Preprocessor(spec)
    anchors = {key: tf.expand_dims(value, 0) for key, value in processor.anchor_boxes.items()}
    generator = model._model.detection_generator
    exports, export_hashes, processed_interpreters = {}, {}, {}
    if export_folder is not None:
        from export_waste import postprocessing_config, validate_postprocessed_model
        from mediapipe.tasks.python.metadata.metadata import MetadataDisplayer

        exports, export_hashes = validated_exports(export_folder, {
            "model_sha256": model_hash, "checkpoint_sha256": checkpoint_hashes,
            "metrics_sha256": file_hash(args.run / "metrics.json"), "dataset_sha256": configuration["dataset_sha256"],
        }, versions, json_value(generator.get_config()))
        for mode, path in exports.items():
            buffer = path.read_bytes()
            validate_postprocessed_model(buffer, postprocessing_config(generator.get_config(), mode == "regular"))
            validate_model_labels(MetadataDisplayer.with_model_buffer(buffer))
            # The pinned 2.15.1 regular NMS multicore scheduler can skip a class.
            processed = tf.lite.Interpreter(model_content=buffer, num_threads=postprocess_threads)
            processed.allocate_tensors()
            processed_interpreters[mode] = processed
    interpreter = tf.lite.Interpreter(model_path=str(model_path), num_threads=2)
    interpreter.allocate_tensors()
    input_details = interpreter.get_input_details()
    if len(input_details) != 1 or input_details[0]["shape"].tolist() != [1, 256, 256, 3] or input_details[0]["dtype"] != np.float32:
        raise TrainingError("Expected a float32 256x256 RGB input.")
    outputs = {int(item["shape"][-1]): item for item in interpreter.get_output_details()}
    if set(outputs) != {4, 5}:
        raise TrainingError("Expected raw TFLite box offsets and five class scores.")
    labels_path = args.dataset / "validation" / "labels.json"
    labels_hash = file_hash(labels_path)
    labels = read_json(args.dataset, labels_path)
    checkpoint_names = ("keras_native", "tflite_matched_pipeline", *(f"tflite_{mode}_exact_input" for mode in exports))
    mediapipe_names = () if checkpoint_only else ("mediapipe_default", "mediapipe_training_geometry")
    names = (*checkpoint_names, *mediapipe_names)
    predictions = {name: [] for name in names}
    differences = []
    image_hashes = {}
    args.output.mkdir(parents=True, exist_ok=False)
    probe_processes = {}
    with tempfile.TemporaryDirectory(prefix=".comparison-inputs-", dir=args.output) as temporary:
        staging = Path(temporary)
        frames = []
        for image in labels["images"]:
            path = args.dataset / "validation" / "data" / image["file_name"]
            jpeg = path.read_bytes()
            image_hashes[str(image["id"])] = hashlib.sha256(jpeg).hexdigest()
            pixels = tf.io.decode_jpeg(jpeg, channels=3)
            if (int(pixels.shape[1]), int(pixels.shape[0])) != (image["width"], image["height"]):
                raise TrainingError("Decoded dimensions do not match validation annotations.")
            normalized = preprocess_ops.normalize_image(pixels, spec.mean_norm, spec.stddev_norm)
            padded, info = preprocess_ops.resize_and_crop_image(normalized, [256, 256], padded_size=[256, 256], aug_scale_min=1.0, aug_scale_max=1.0)
            batch = tf.expand_dims(padded, 0)
            scale = info[2].numpy().tolist()
            result = model(batch, image_shape=info[1:2], anchor_boxes=anchors, training=False)
            current = {"keras_native": keras_predictions(result, image, scale)}
            interpreter.set_tensor(input_details[0]["index"], batch.numpy())
            interpreter.invoke()
            raw_boxes = interpreter.get_tensor(outputs[4]["index"])
            raw_scores = interpreter.get_tensor(outputs[5]["index"])
            levels = sorted(result["box_outputs"], key=int)
            expected_boxes = tf.concat([tf.reshape(result["box_outputs"][key], [1, -1, 4]) for key in levels], axis=1).numpy()
            expected_scores = tf.sigmoid(tf.concat([tf.reshape(result["cls_outputs"][key], [1, -1, 5]) for key in levels], axis=1)).numpy()
            if raw_boxes.shape != expected_boxes.shape or raw_scores.shape != expected_scores.shape:
                raise TrainingError("Raw Keras and TFLite output shapes differ.")
            differences.append({
                "image_id": image["id"], "image_scale_yx": scale,
                "box_offsets_max_abs_error": float(np.max(np.abs(raw_boxes - expected_boxes))),
                "scores_max_abs_error": float(np.max(np.abs(raw_scores - expected_scores))),
                "box_offsets_mean_abs_error": float(np.mean(np.abs(raw_boxes - expected_boxes))),
                "scores_mean_abs_error": float(np.mean(np.abs(raw_scores - expected_scores))),
                "foreground_scores_max_abs_error": float(np.max(np.abs(raw_scores[..., 1:] - expected_scores[..., 1:]))),
                "foreground_scores_mean_abs_error": float(np.mean(np.abs(raw_scores[..., 1:] - expected_scores[..., 1:]))),
                "foreground_argmax_agreement": float(np.mean(np.argmax(raw_scores[..., 1:], axis=-1)
                                                               == np.argmax(expected_scores[..., 1:], axis=-1))),
            })
            # Run TFLite outputs through the exact same official Keras decoder/NMS.
            # Exported probabilities are converted back to logits for that API.
            clipped = np.clip(raw_scores, 1e-7, 1 - 1e-7)
            logits = np.log(clipped) - np.log1p(-clipped)
            box_levels, score_levels, offset = {}, {}, 0
            for key in levels:
                shape = result["box_outputs"][key].shape
                count = int(np.prod(shape[1:])) // 4
                box_levels[key] = tf.reshape(raw_boxes[:, offset:offset + count], shape)
                score_levels[key] = tf.reshape(logits[:, offset:offset + count], result["cls_outputs"][key].shape)
                offset += count
            matched = generator(box_levels, score_levels, anchors, info[1:2])
            current["tflite_matched_pipeline"] = keras_predictions(matched, image, scale)
            for mode, processed in processed_interpreters.items():
                processed.set_tensor(processed.get_input_details()[0]["index"], batch.numpy())
                processed.invoke()
                tensors = [processed.get_tensor(item["index"]).tolist()
                           for item in sorted(processed.get_output_details(), key=lambda item: item["index"])]
                current[f"tflite_{mode}_exact_input"] = postprocessed_predictions(tensors, image, scale)
            # This probe changes geometry only. uint8 rounding differs from the
            # exact float training input by at most half a pixel-value unit.
            if mediapipe_names:
                padded_rgb = np.clip(np.rint((padded.numpy() + 1) * 127.5), 0, 255).astype(np.uint8)
                square_path = staging / f'{image["id"]}.png'
                Image.fromarray(padded_rgb).save(square_path)
                frames.append({"image": image, "original": str(path), "padded": str(square_path), "scale_yx": scale})
            for name in current:
                predictions[name].extend(current[name])
            print(f'Compared checkpoint and TFLite validation image {image["id"]}', flush=True)
        checkpoint_metrics = {name: score_predictions(labels, predictions[name]) for name in checkpoint_names}
        write_json_report(args.output / "checkpoint-comparison.json", {
            "status": "checkpoint_compared", "split": "validation", "model_sha256": model_hash,
            "checkpoint_sha256": checkpoint_hashes,
            "labels_sha256": labels_hash,
            "image_sha256": image_hashes, "raw_output_differences": differences,
            "metrics": checkpoint_metrics, "predictions": {name: predictions[name] for name in checkpoint_names},
            "training_source_sha256": source_hashes,
            "comparison_scope": "checkpoint_only" if checkpoint_only else "checkpoint_and_mediapipe",
            "postprocessed_source_sha256": export_hashes,
            "tflite_runtime_settings": {"raw_threads": 2, "postprocessed_threads": postprocess_threads},
            "mediapipe_completed": False, "promoted": False,
        })
        # Each native runtime runs separately, with stable geometry per process.
        for name in mediapipe_names:
            request_path = staging / f"{name}.json"
            entries = []
            for entry in frames:
                input_path = entry["original"] if name == "mediapipe_default" else entry["padded"]
                entries.append({**entry, "input": input_path, "input_sha256": file_hash(Path(input_path))})
            write_json_report(request_path, {
                "mode": name, "model": str(model_path), "model_sha256": model_hash,
                "labels_sha256": labels_hash,
                "image_sha256": image_hashes, "frames": entries,
            })
            result = run_mediapipe_probe(request_path, args.output / f"{name}.log")
            write_json_report(args.output / f"{name}.json", result)
            predictions[name] = result["predictions"]
            probe_processes[name] = result["process"]
            if name == "mediapipe_default":
                (args.output / "mediapipe-graph.pbtxt").write_text(result["graph"], encoding="utf-8")
        for image in labels["images"]:
            ground_truth = [a for a in labels["annotations"] if a["image_id"] == image["id"]]
            panels = {"Ground truth": ground_truth, **{
                name: [p for p in predictions[name] if p["image_id"] == image["id"]] for name in names
            }}
            jpeg = (args.dataset / "validation" / "data" / image["file_name"]).read_bytes()
            (args.output / f'comparison-{image["id"]}.svg').write_text(comparison_svg(image, jpeg, panels), encoding="utf-8")
    if (file_hash(model_path) != model_hash or file_hash(labels_path) != labels_hash
            or {path.name: file_hash(path) for path in checkpoint_files} != checkpoint_hashes
            or any(file_hash(Path(path)) != digest for path, digest in export_hashes.items())
            or any(file_hash(Path(path)) != digest for path, digest in source_hashes.items())
            or any(file_hash(args.dataset / "validation/data" / image["file_name"]) != image_hashes[str(image["id"])] for image in labels["images"])
            or run_configuration(SimpleNamespace(dataset=args.dataset, epochs=0, batch_size=1), versions)["dataset_sha256"] != configuration["dataset_sha256"]):
        raise TrainingError("Comparison model, labels, or checkpoints changed during inference.")
    results = {name: score_predictions(labels, values) for name, values in predictions.items()}
    return json_value({
        "status": "compared", "created_at": datetime.now(timezone.utc).isoformat(),
        "comparison_scope": "checkpoint_only" if checkpoint_only else "checkpoint_and_mediapipe",
        "comparer_sha256": file_hash(Path(__file__)), "postprocessed_source_sha256": export_hashes,
        "training_source_sha256": source_hashes,
        "tflite_runtime_settings": {"raw_threads": 2, "postprocessed_threads": postprocess_threads},
        "split": "validation", "dataset_audit": audit, "dataset_sha256": configuration["dataset_sha256"],
        "model_sha256": model_hash, "checkpoint_sha256": checkpoint_hashes,
        "labels_sha256": labels_hash,
        "image_sha256": image_hashes, "versions": versions,
        "keras_postprocessing": generator.get_config(),
        "mediapipe_settings": {"score_threshold": 0.001, "max_results": 100, "graph": "mediapipe-graph.pbtxt",
                               "deduplication": "python_exact_bbox_label_merge"} if mediapipe_names else None,
        "probe_processes": probe_processes,
        "raw_output_differences": differences, "metrics": results, "predictions": predictions,
        "visualization_score_threshold": 0.2, "promoted": False, "deployment_ready": False,
        "limitations": [
            "Diagnostic comparison on validation images only; not a fresh test-set result.",
            "Training-geometry MediaPipe probe includes uint8 rounding; its NMS still differs from Keras.",
            "Raw numerical parity alone does not establish useful detection accuracy.",
            "Exact-input exports use the identical float TensorFlow-preprocessed tensor, not MediaPipe or browser preprocessing.",
            "Checkpoint-only mode skips native MediaPipe probes; it is not a measured browser or camera result.",
            "Pinned TensorFlow 2.15.1 multicore regular NMS can omit a class; two-thread results are a diagnostic control, not the default.",
            "Checkpoint inputs use TensorFlow JPEG decoding; MediaPipe default inputs use its own JPEG decoder.",
        ],
    })


def main(argv: list[str] | None = None) -> int:
    parser = ArgumentParser(description=__doc__)
    parser.add_argument("--run", type=Path, required=True)
    parser.add_argument("--dataset", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--checkpoint-only", action="store_true", help="Skip native MediaPipe probes; compare identical float inputs only.")
    parser.add_argument("--postprocessed-export", type=Path, help="Include hash-bound regular/fast exports from this checkpoint.")
    parser.add_argument("--postprocess-threads", type=int, choices=(1, 2), default=1,
                        help="Use one decoder thread by default; two threads reproduce the pinned multicore diagnostic.")
    try:
        args = parser.parse_args(argv)
        args.run = args.run.resolve(strict=True)
        args.dataset = args.dataset.resolve(strict=True)
        args.output = args.output.resolve()
        if args.postprocessed_export is not None:
            args.postprocessed_export = args.postprocessed_export.resolve(strict=True)
        result = compare(args)
        write_json_report(args.output / "comparison.json", result)
        print(json.dumps(result["metrics"], indent=2))
        print(f"Comparison saved to {args.output}. No model was retrained or promoted.")
        return 0
    except (Exception, KeyboardInterrupt) as error:
        print(f"Comparison failed: {error}. Partial output is not a completed report.", file=sys.stderr)
        return 1


if __name__ == "__main__":
    if len(sys.argv) == 3 and sys.argv[1] == "--mediapipe-probe":
        request_path = Path(sys.argv[2]).resolve(strict=True)
        write_json_report(request_path.with_suffix(".result.json"), mediapipe_probe(request_path))
    else:
        sys.exit(main())
