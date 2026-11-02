"""Score private browser validation benchmarks without inventing generic material labels."""

from __future__ import annotations

import argparse
from collections import Counter
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import re
from statistics import median
import sys
from typing import Any

from analyze_waste import analyze_image, summarize
from evaluate_waste import score_predictions
from prepare_browser_waste import validate_manifest
from train_waste import (
    CLASSES, ArgumentParser, TrainingError, finite_number, integer, read_json,
    validate_dataset, validate_output, write_json_report,
)

SETTINGS = {"worker_score_threshold": 0.2, "max_results": 10, "ui_score_threshold": 0.5,
            "category_selection": "first_category_as_application", "frame_max_side": 1280}


def file_hash(path: Path) -> str:
    with path.open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()


def validate_benchmark(report: dict[str, Any], labels: dict[str, Any], labels_hash: str,
                       image_hashes: dict[str, str], *, diagnostic: dict[str, Any] | None = None) -> None:
    split = "train" if diagnostic is not None else "validation"
    if diagnostic is not None:
        if (report.get("diagnostic_only") is not True or report.get("evaluation_scope") != "training_resubstitution"
                or report.get("snapshot_sha256") != diagnostic["summary_sha256"]
                or report.get("review_sha256") != diagnostic["review_sha256"] or report.get("deployment_ready") is not False):
            raise TrainingError("Training benchmarks must explicitly declare the exact reviewed resubstitution snapshot.")
    elif report.get("diagnostic_only") or report.get("evaluation_scope") == "training_resubstitution":
        raise TrainingError("Training diagnostics cannot be scored as validation accuracy.")
    if (report.get("status") != "inferred" or report.get("split") != split
            or report.get("promoted") is not False or report.get("settings") != SETTINGS
            or report.get("labels_sha256") != labels_hash or report.get("image_sha256") != image_hashes):
        raise TrainingError("Benchmark must match the exact validation images, labels, and application settings.")
    for key in ("worker_sha256", "benchmarker_sha256", "models_config_sha256"):
        if not isinstance(report.get(key), str) or not re.fullmatch(r"[a-f0-9]{64}", report[key]):
            raise TrainingError("Missing browser source provenance.")
    if not report.get("assets_sha256") or not report.get("application_sources_sha256"):
        raise TrainingError("Browser asset and application hashes are required.")
    for key in ("assets_sha256", "application_sources_sha256"):
        if not isinstance(report[key], dict) or not all(isinstance(v, str) and re.fullmatch(r"[a-f0-9]{64}", v) for v in report[key].values()):
            raise TrainingError("Invalid application asset SHA-256.")
    if report["worker_sha256"] not in report["assets_sha256"].values():
        raise TrainingError("Worker hash must match a served application asset.")
    models = report.get("models")
    if not isinstance(models, dict) or not models:
        raise TrainingError("A completed browser benchmark must contain model results.")
    images = {image["id"]: image for image in labels["images"]}
    for key, model in models.items():
        geometry = model.get("input_geometry", "application")
        preprocessing = model.get("input_preprocessing", "browser_canvas")
        if (not re.fullmatch(r"[a-z0-9_]+", key) or model.get("label_space") not in ("generic", "waste")
                or model.get("input_size") not in (256, 320, 448)
                or geometry not in ("application", "training_pad")
                or (geometry == "training_pad" and (model["label_space"] != "waste" or model["input_size"] != 256))
                or not re.fullmatch(r"[a-f0-9]{64}", model.get("model_sha256", ""))
                or preprocessing not in ("browser_canvas", "tensorflow_rgb")):
            raise TrainingError("Invalid browser model identity or label space.")
        prepared = {}
        if preprocessing == "tensorflow_rgb":
            if (geometry != "training_pad" or not isinstance(model.get("input_manifest"), dict)
                    or not re.fullmatch(r"[a-f0-9]{64}", model.get("input_manifest_sha256", ""))):
                raise TrainingError("TensorFlow RGB probes require an audited prepared-frame manifest.")
            validate_manifest(model["input_manifest"], labels, labels_hash, image_hashes)
            prepared = {frame["image_id"]: frame for frame in model["input_manifest"]["frames"]}
        elif "input_manifest" in model or "input_manifest_sha256" in model:
            raise TrainingError("Canvas probes cannot claim a prepared-frame manifest.")
        frames, predictions = model.get("frames"), model.get("predictions")
        if not isinstance(frames, list) or not isinstance(predictions, list):
            raise TrainingError("Browser frames and predictions are required.")
        frame_ids = [frame.get("image_id") for frame in frames]
        if len(frame_ids) != len(set(frame_ids)) or set(frame_ids) != set(images):
            raise TrainingError("Every validation image must have exactly one completed frame.")
        counts = Counter(prediction.get("image_id") for prediction in predictions)
        for frame in frames:
            image = images[frame["image_id"]]
            scale = (256 / max(image["width"], image["height"]) if geometry == "training_pad"
                     else min(1, 1280 / max(image["width"], image["height"])))
            content = tuple(max(1, int(image[axis] * scale + 0.5)) for axis in ("width", "height"))
            if prepared:
                entry = prepared[image["id"]]
                content = (entry["content_width"], entry["content_height"])
                if frame.get("input_png_sha256") != entry["png_sha256"]:
                    raise TrainingError("Inferred PNG hash disagrees with the prepared manifest.")
            elif "input_png_sha256" in frame:
                raise TrainingError("Canvas frames cannot claim prepared PNG provenance.")
            expected = (256, 256) if geometry == "training_pad" else content
            has_content = "content_width" in frame or "content_height" in frame
            if (not all(integer(frame.get(axis)) for axis in ("width", "height"))
                    or (frame.get("width"), frame.get("height")) != expected
                    or ((has_content or geometry == "training_pad") and (
                        not all(integer(frame.get(axis)) for axis in ("content_width", "content_height"))
                        or (frame.get("content_width"), frame.get("content_height")) != content))
                    or not finite_number(frame.get("inference_ms")) or frame["inference_ms"] < 0
                    or not isinstance(frame.get("detections"), list) or len(frame["detections"]) > 10
                    or counts[image["id"]] > len(frame["detections"])):
                raise TrainingError("Invalid frame geometry, timing, or result limit.")
        for prediction in predictions:
            image_id, score, box = prediction.get("image_id"), prediction.get("score"), prediction.get("bbox")
            if (not integer(image_id) or image_id not in images or not finite_number(score) or not 0.2 <= score <= 1
                    or not isinstance(box, list) or len(box) != 4 or not all(finite_number(v) for v in box)):
                raise TrainingError("Invalid saved browser prediction.")
            image = images[image_id]
            x, y, width, height = box
            if x < 0 or y < 0 or width <= 0 or height <= 0 or x + width > image["width"] + 1e-6 or y + height > image["height"] + 1e-6:
                raise TrainingError("Browser prediction is outside the labeled image.")
            label, category = prediction.get("label"), prediction.get("category_id")
            if not isinstance(label, str) or not label:
                raise TrainingError("The original browser label must be preserved.")
            if model["label_space"] == "generic":
                if category is not None:
                    raise TrainingError("Generic object labels cannot establish waste-material categories.")
            elif not integer(category) or category not in (1, 2, 3, 4) or label != CLASSES[category]:
                raise TrainingError("Waste labels must match canonical class IDs.")


def summarize_model(labels: dict[str, Any], model: dict[str, Any]) -> dict[str, Any]:
    timings = [frame["inference_ms"] for frame in model["frames"]]
    result = {"model_sha256": model["model_sha256"], "label_space": model["label_space"],
              "input_geometry": model.get("input_geometry", "application"),
              "input_preprocessing": model.get("input_preprocessing", "browser_canvas"),
              **({"input_manifest_sha256": model["input_manifest_sha256"]} if "input_manifest_sha256" in model else {}),
              "input_size": model["input_size"], "frames": len(timings),
              "median_inference_ms_including_first": median(timings),
              "median_inference_ms_after_first": median(timings[1:]) if len(timings) > 1 else None,
              "thresholds": {}}
    for threshold in (0.2, 0.5):
        predictions = [p for p in model["predictions"] if p["score"] >= threshold]
        localization, classification = [], []
        for image in labels["images"]:
            annotations = [a for a in labels["annotations"] if a["image_id"] == image["id"]]
            detections = [p for p in predictions if p["image_id"] == image["id"]]
            localization.append(analyze_image([{**a, "category_id": 1} for a in annotations],
                                               [{**p, "category_id": 1} for p in detections], threshold))
            if model["label_space"] == "waste":
                classification.append(analyze_image(annotations, detections, threshold))
        localized = summarize(localization)
        localized.pop("by_class")
        result["thresholds"][str(threshold)] = {
            "localization_only_iou_05": localized,
            "class_aware_iou_05": summarize(classification) if classification else None,
            "waste_coco_AP": score_predictions(labels, predictions) if model["label_space"] == "waste" else None,
            "visible_labels": dict(Counter(p["label"] for p in predictions)),
        }
    return result


def score(args: argparse.Namespace) -> dict[str, Any]:
    diagnostic = None
    if getattr(args, "diagnostic_training", False):
        from diagnose_waste import validate_snapshot
        diagnostic = validate_snapshot(args.dataset)
        diagnostic["review_sha256"] = file_hash(args.dataset / "review.json")
        audit = {key: diagnostic[key] for key in ("counts", "diagnostic_only", "evaluation_scope")}
    else:
        audit = validate_dataset(args.dataset)
    split = "train" if diagnostic is not None else "validation"
    validate_output(args.output, args.dataset)
    dataset_summary = read_json(args.dataset, args.dataset / "summary.json")
    dataset_use = {key: dataset_summary.get(key, False) is True for key in ("evaluation_only", "validation_only_refresh")}
    dataset_use["previous_validation_group_overlap"] = dataset_summary.get("previous_validation_group_overlap", [])
    labels_path = args.dataset / split / "labels.json"
    labels = read_json(args.dataset, labels_path)
    images = {str(image["id"]): file_hash(args.dataset / split / "data" / image["file_name"]) for image in labels["images"]}
    labels_hash = file_hash(labels_path)
    sources, models, reference = [], {}, None
    for path in args.benchmark:
        path = path.resolve(strict=True)
        if path.is_relative_to(args.output) or args.output.is_relative_to(path):
            raise TrainingError("Benchmark inputs and scoring output must be separate.")
        source_bytes = path.read_bytes()
        source_hash = hashlib.sha256(source_bytes).hexdigest()
        report = json.loads(source_bytes)
        validate_benchmark(report, labels, labels_hash, images, diagnostic=diagnostic)
        contract = {key: report[key] for key in ("worker_sha256", "assets_sha256", "application_sources_sha256",
                                               "benchmarker_sha256", "settings", "browser_version")}
        if reference is not None and contract != reference:
            raise TrainingError("Browser comparisons require identical application assets, settings, and browser versions.")
        reference = contract
        if set(models) & set(report["models"]):
            raise TrainingError("Duplicate model identity in browser comparison.")
        models.update({key: summarize_model(labels, model) for key, model in report["models"].items()})
        sources.append({"file": str(path), "sha256": source_hash})
    result = {"status": "evaluated", "created_at": datetime.now(timezone.utc).isoformat(),
              "split": split, "images": len(images), "dataset_audit": audit, "dataset_use": dataset_use,
              "labels_sha256": labels_hash, "image_sha256": images, "sources": sources,
              "scorer_sha256": file_hash(Path(__file__)), "browser_contract": reference, "models": models,
              "promoted": False, "deployment_ready": False,
              "limitations": ["Public validation photos are not a measured laptop or ESP32-CAM field accuracy.",
                  "Localization-only matches ignore predicted labels and are not waste-class accuracy.",
                  "Generic COCO labels have no fabricated waste material mapping; their waste-class metrics are null.",
                  "COCO AP is computed from browser-truncated predictions at the stated confidence cutoff, not full-score AP.",
                  "Inference timing excludes capture, network, and application scheduling delays.",
                  "training_pad results are a private Canvas geometry probe, not the live capture path or exact TensorFlow pixel preprocessing.",
                  "tensorflow_rgb is an audited training-prepared RGB PNG probe, not exact float tensor parity or live preprocessing.",
                  ("Fresh evaluation-only photos can share prior validation contributors; independent scenes are not established. No browser test-split evaluation or model promotion."
                   if dataset_use["evaluation_only"] and dataset_use["validation_only_refresh"] else
                   "Small, reused-for-selection validation set; no browser test-split evaluation or model promotion.")]}
    if diagnostic is not None:
        result.update(diagnostic_only=True, evaluation_scope="training_resubstitution",
                      snapshot_sha256=diagnostic["summary_sha256"], review_sha256=diagnostic["review_sha256"])
        result["limitations"][0] = "Training-resubstitution scores on selected training photos, not validation or live accuracy."
        result["limitations"][-1] = "Deliberately easy, balanced subset with no held-out inference; never promote this diagnostic model."
        validate_snapshot(args.dataset)
        if (file_hash(args.dataset / "summary.json") != diagnostic["summary_sha256"]
                or file_hash(args.dataset / "review.json") != diagnostic["review_sha256"]):
            raise TrainingError("Training snapshot or visual review changed during scoring.")
    if file_hash(labels_path) != labels_hash or any(file_hash(args.dataset / split / "data" / image["file_name"]) != images[str(image["id"])] for image in labels["images"]):
        raise TrainingError("Validation inputs changed during scoring.")
    if any(file_hash(Path(source["file"])) != source["sha256"] for source in sources):
        raise TrainingError("Browser benchmark changed during scoring.")
    args.output.mkdir(parents=True, exist_ok=True)
    write_json_report(args.output / "comparison.json", result)
    return result


def main() -> int:
    parser = ArgumentParser(description=__doc__)
    parser.add_argument("--dataset", type=Path, required=True)
    parser.add_argument("--benchmark", type=Path, action="append", required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--diagnostic-training", action="store_true", help="Score reviewed training snapshots as resubstitution only, never validation.")
    try:
        args = parser.parse_args()
        args.dataset = args.dataset.resolve(strict=True)
        args.output = args.output.resolve()
        result = score(args)
        print(json.dumps(result["models"], indent=2))
        return 0
    except (Exception, KeyboardInterrupt) as error:
        print(f"Browser scoring failed: {error}; no model was promoted.", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
