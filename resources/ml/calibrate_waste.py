"""Measure private validation-only confidence policies without changing a model."""

from __future__ import annotations

import argparse
import sys
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from analyze_waste import analyze_image, intersection_over_union, summarize, validate_report
from compare_waste import comparison_svg, file_hash
from score_browser_waste import validate_benchmark
from train_waste import (
    CLASSES, ArgumentParser, TrainingError, finite_number, integer, read_json,
    validate_dataset, validate_output, write_json_report,
)


def detection_summary(labels: dict[str, Any], predictions: list[dict[str, Any]], threshold: float) -> dict[str, Any]:
    reviews = [analyze_image(
        [item for item in labels["annotations"] if item["image_id"] == image["id"]],
        [item for item in predictions if item["image_id"] == image["id"]], threshold,
    ) for image in labels["images"]]
    summary = summarize(reviews)
    counts = Counter(item["category_id"] for review in reviews for item in review["false_positives"])
    summary["false_positives_by_class"] = {name: counts[key] for key, name in CLASSES.items() if key}
    summary["matched_by_class"] = {name: reasons.get("matched", 0) for name, reasons in summary["by_class"].items()}
    return summary


def confidence_policies(labels: dict[str, Any], predictions: list[dict[str, Any]], floor: float) -> dict[str, Any]:
    if not finite_number(floor) or not 0 < floor <= 1:
        raise TrainingError("The inference score floor must be finite and in (0, 1].")
    if any(not finite_number(p["score"]) or not floor <= p["score"] <= 1 for p in predictions):
        raise TrainingError("Predictions must be at or above the recorded inference score floor.")
    thresholds = sorted({floor, 1.0, *[value for value in (0.25, 0.35, 0.5, 0.75, 0.9) if value >= floor],
                         *[p["score"] for p in predictions]})
    curve = [{"threshold": threshold, **detection_summary(labels, predictions, threshold)} for threshold in thresholds]
    baseline = curve[0]
    eligible = [row for row in curve if row["matched_by_class"] == baseline["matched_by_class"]]
    global_policy = min(eligible, key=lambda row: (row["false_positives"], row["threshold"]))
    per_class = {}
    for key, name in CLASSES.items():
        if not key:
            continue
        matches = baseline["matched_by_class"][name]
        candidates = [row for row in curve if row["matched_by_class"][name] == matches]
        selected = min(candidates, key=lambda row: (row["false_positives_by_class"][name], row["threshold"])) if matches else baseline
        per_class[name] = {
            "threshold": selected["threshold"], "baseline_matches": matches,
            "reason": "preserves_validation_matches" if matches else "no_positive_matches_keep_inference_floor",
        }
    filtered = [p for p in predictions if p["score"] >= per_class[CLASSES[p["category_id"]]]["threshold"]]
    combined = detection_summary(labels, filtered, floor)
    if combined["matched_by_class"] != baseline["matched_by_class"]:
        raise TrainingError("Combined thresholds did not preserve every class's baseline matches.")
    return {"baseline": baseline, "global_policy": global_policy, "per_class_policy": per_class,
            "per_class_summary": combined, "curve": curve}


def class_conflicts(predictions: list[dict[str, Any]], threshold: float) -> list[dict[str, Any]]:
    if not finite_number(threshold) or not 0 < threshold <= 1:
        raise TrainingError("Conflict confidence threshold must be finite and in (0, 1].")
    conflicts = []
    for first_index, first in enumerate(predictions):
        if first["score"] < threshold:
            continue
        for second_index in range(first_index + 1, len(predictions)):
            second = predictions[second_index]
            if (second["score"] < threshold or first["image_id"] != second["image_id"]
                    or first["category_id"] == second["category_id"]):
                continue
            overlap = intersection_over_union(first["bbox"], second["bbox"])
            if overlap >= 0.5:
                conflicts.append({
                    "prediction_indices": [first_index, second_index], "iou": overlap,
                    "labels": [CLASSES[first["category_id"]], CLASSES[second["category_id"]]],
                    "scores": [first["score"], second["score"]],
                    "confidence_gap": abs(first["score"] - second["score"]),
                    "maximum_score": max(first["score"], second["score"]),
                })
    return sorted(conflicts, key=lambda item: (-item["maximum_score"], item["confidence_gap"], item["prediction_indices"]))


def calibrate(args: argparse.Namespace) -> dict[str, Any]:
    if getattr(args, "browser_model", None) is not None:
        return calibrate_browser(args)
    audit = validate_dataset(args.dataset)
    validate_output(args.output, args.dataset)
    sources = [args.benchmark, args.model]
    if args.input_manifest is not None:
        sources.append(args.input_manifest)
    if any(args.output.is_relative_to(path.parent) or path.is_relative_to(args.output) for path in sources):
        raise TrainingError("Calibration output must be separate from all source artifacts.")
    proofs = {str(path): file_hash(path) for path in sources}
    report = read_json(args.benchmark.parent, args.benchmark)
    if report.get("status") != "evaluated" or report.get("split") != "validation":
        raise TrainingError("Calibration requires completed validation predictions, never test predictions.")
    settings = report.get("settings", {})
    floor, maximum = settings.get("score_threshold"), settings.get("max_results")
    if (not finite_number(floor) or not 0 < floor <= 1 or not integer(maximum) or not 1 <= maximum <= 100
            or settings.get("category_selection") != "highest_score_per_detection"):
        raise TrainingError("Expected recorded inference limits and highest-score category selection.")
    result = report["models"][args.mode]
    if file_hash(args.model) != result["model_sha256"]:
        raise TrainingError("Model bytes do not match the benchmark.")
    saved = result["geometries"][args.geometry]
    labels_path = args.dataset / "validation" / "labels.json"
    labels = read_json(args.dataset, labels_path)
    hashes = {str(image["id"]): file_hash(args.dataset / "validation" / "data" / image["file_name"]) for image in labels["images"]}
    validate_report({**report, "model_sha256": result["model_sha256"], "predictions": saved["predictions"]},
                    labels, file_hash(labels_path), hashes)
    if sorted(saved["completed_image_ids"]) != sorted(image["id"] for image in labels["images"]):
        raise TrainingError("Benchmark must cover every validation image exactly once.")
    if any(count > maximum for count in Counter(p["image_id"] for p in saved["predictions"]).values()):
        raise TrainingError("Saved predictions exceed the recorded per-image inference limit.")
    if args.geometry == "padded":
        if args.input_manifest is None or file_hash(args.input_manifest) != report["input_manifest_sha256"]:
            raise TrainingError("Padded calibration requires the exact benchmark input manifest.")
        manifest = read_json(args.input_manifest.parent, args.input_manifest)
        if manifest.get("status") != "prepared" or manifest.get("split") != "validation" or manifest.get("labels_sha256") != file_hash(labels_path):
            raise TrainingError("Padded inputs must match these validation labels.")
        frames = manifest["frames"]
        if sorted(frame["image"]["id"] for frame in frames) != sorted(image["id"] for image in labels["images"]):
            raise TrainingError("Padded manifest coverage is incomplete or duplicated.")
        for frame in frames:
            image_id = str(frame["image"]["id"])
            scale = frame.get("scale_yx")
            image = next(image for image in labels["images"] if str(image["id"]) == image_id)
            if frame["image"] != image or not isinstance(scale, list) or len(scale) != 2 or any(not finite_number(value) or value <= 0 for value in scale):
                raise TrainingError("Padded input geometry does not match the validation image.")
            path = args.input_manifest.parent / f"{image_id}.png"
            if frame["original_sha256"] != hashes[image_id] or file_hash(path) != frame["padded_sha256"] or frame["padded_sha256"] != report["padded_sha256"][image_id]:
                raise TrainingError("Padded image bytes do not match the recorded benchmark.")
            proofs[str(path)] = file_hash(path)
    policies = confidence_policies(labels, saved["predictions"], floor)
    if any(file_hash(Path(path)) != digest for path, digest in proofs.items()) or file_hash(labels_path) != report["labels_sha256"]:
        raise TrainingError("Source artifacts changed during calibration.")
    if {str(image["id"]): file_hash(args.dataset / "validation" / "data" / image["file_name"]) for image in labels["images"]} != hashes:
        raise TrainingError("Validation images changed during calibration.")
    output = {
        "status": "calibrated", "created_at": datetime.now(timezone.utc).isoformat(), "split": "validation",
        "mode": args.mode, "geometry": args.geometry, "model_sha256": result["model_sha256"],
        "labels_sha256": report["labels_sha256"], "image_sha256": hashes, "source_sha256": proofs,
        "calibrator_sha256": file_hash(Path(__file__)), "analyzer_sha256": file_hash(Path(__file__).with_name("analyze_waste.py")),
        "dataset_audit": audit, "inference_settings": settings, "iou_threshold": 0.5,
        "backend": report.get("backend"), "worker_sha256": report.get("worker_sha256"),
        "selection": "minimize_false_positives_preserving_baseline_matches_in_every_class; ties_choose_lower_threshold",
        **policies, "retrained": False, "promoted": False, "deployment_ready": False,
        "limitations": [
            "Thresholds were selected and measured on the same reused validation split, not an independent performance estimate.",
            "Precision and recall at IoU 0.5 are not COCO mAP or classification accuracy.",
            "Filtering applies after the recorded worker score floor and result cap; discarded candidates cannot be recovered.",
            "Categories with no correct baseline matches retain the inference floor, not an optimized threshold.",
            "No app settings, model weights, labels, source reports, or dataset splits were changed.",
            "Fresh robot-environment photos and negative scenes are required before deploying a policy.",
        ],
    }
    args.output.mkdir(parents=True, exist_ok=True)
    write_json_report(args.output / "calibration.json", output)
    return output


def calibrate_browser(args: argparse.Namespace) -> dict[str, Any]:
    if args.geometry != "original" or args.input_manifest is not None or args.mode != "regular":
        raise TrainingError("Browser calibration uses recorded benchmark geometry, not native mode or an input manifest.")
    audit = validate_dataset(args.dataset)
    validate_output(args.output, args.dataset)
    sources = (args.benchmark, args.model)
    if any(args.output.is_relative_to(path.parent) or path.is_relative_to(args.output) for path in sources):
        raise TrainingError("Calibration output must be separate from all source artifacts.")
    proofs = {str(path): file_hash(path) for path in sources}
    report = read_json(args.benchmark.parent, args.benchmark)
    labels_path = args.dataset / "validation" / "labels.json"
    labels = read_json(args.dataset, labels_path)
    labels_hash = file_hash(labels_path)
    hashes = {str(image["id"]): file_hash(args.dataset / "validation/data" / image["file_name"])
              for image in labels["images"]}
    validate_benchmark(report, labels, labels_hash, hashes)
    model = report["models"].get(args.browser_model)
    if model is None or model["label_space"] != "waste":
        raise TrainingError("Browser calibration requires a recorded waste model, not generic material guesses.")
    if proofs[str(args.model)] != model["model_sha256"]:
        raise TrainingError("Model bytes do not match the browser benchmark.")
    settings = report["settings"]
    floor, ui_threshold = settings["worker_score_threshold"], settings["ui_score_threshold"]
    predictions = model["predictions"]
    policies = confidence_policies(labels, predictions, floor)
    reviews, overlays = [], {}
    for image in labels["images"]:
        annotations = [a for a in labels["annotations"] if a["image_id"] == image["id"]]
        detections = [p for p in predictions if p["image_id"] == image["id"]]
        review = analyze_image(annotations, detections, ui_threshold)
        conflicts = {
            str(threshold): class_conflicts(detections, threshold) for threshold in (floor, ui_threshold)
        }
        reviews.append({"image_id": image["id"], **review, "class_conflicts": conflicts})
        if (review["false_positives"] or any(item["reason"] != "matched" for item in review["ground_truth"])
                or any(conflicts.values())):
            filtered = [p for p in detections if p["score"] >= policies["per_class_policy"][CLASSES[p["category_id"]]]["threshold"]]
            panels = {
                "ground_truth": annotations,
                "worker_floor_0.2": detections,
                "ui_threshold_0.5": [p for p in detections if p["score"] >= ui_threshold],
                "validation_selected_policy": filtered,
            }
            path = args.dataset / "validation/data" / image["file_name"]
            overlays[f"review-{image['id']}.svg"] = comparison_svg(image, path.read_bytes(), panels)
    if any(file_hash(Path(path)) != digest for path, digest in proofs.items()) or file_hash(labels_path) != labels_hash:
        raise TrainingError("Browser source artifacts changed during calibration.")
    if any(file_hash(args.dataset / "validation/data" / image["file_name"]) != hashes[str(image["id"])] for image in labels["images"]):
        raise TrainingError("Validation images changed during browser calibration.")
    output = {
        "status": "calibrated", "created_at": datetime.now(timezone.utc).isoformat(), "split": "validation",
        "browser_model": args.browser_model, "backend": "application browser worker / recorded predictions",
        "input_geometry": model.get("input_geometry", "application"),
        "model_sha256": model["model_sha256"], "labels_sha256": labels_hash, "image_sha256": hashes,
        "source_sha256": proofs, "dataset_audit": audit, "inference_settings": settings,
        "calibrator_sha256": file_hash(Path(__file__)),
        "analyzer_sha256": file_hash(Path(__file__).with_name("analyze_waste.py")),
        "browser_validator_sha256": file_hash(Path(__file__).with_name("score_browser_waste.py")),
        "review_renderer_sha256": file_hash(Path(__file__).with_name("compare_waste.py")),
        "browser_contract": {key: report[key] for key in ("worker_sha256", "assets_sha256", "application_sources_sha256",
                                                          "benchmarker_sha256", "browser_version")},
        "iou_threshold": 0.5, "selection": "minimize_false_positives_preserving_worker_floor_matches_in_every_class",
        **policies, "ui_baseline": detection_summary(labels, predictions, ui_threshold),
        "ui_reviews": reviews, "review_files": sorted(overlays),
        "class_conflict_settings": {
            "iou_threshold": 0.5, "matching": "all_unordered_different_label_pairs_within_one_image",
            "prediction_indices": "zero_based_in_this_image_saved_worker_predictions",
        },
        "class_conflict_summary": {
            str(threshold): {
                "pairs": sum(len(review["class_conflicts"][str(threshold)]) for review in reviews),
                "images": sum(bool(review["class_conflicts"][str(threshold)]) for review in reviews),
            } for threshold in (floor, ui_threshold)
        },
        "retrained": False, "promoted": False, "deployment_ready": False,
        "limitations": [
            "Thresholds selected and measured on the same small, reused validation set are not independent accuracy.",
            "Selected policies preserve matches at the worker floor, not just the stricter UI baseline.",
            "Filtering cannot relabel high-confidence mistakes, recover capped results, or change model weights.",
            "Overlapping different labels are review flags, not proof of an incorrect class: distinct objects can overlap. Pair counts are not object counts; no labels are suppressed automatically.",
            "Generic labels are not mapped to waste materials; categories without correct matches retain the worker floor.",
            "Public positive-object photos do not measure false alarms on empty ground or laptop/ESP32-CAM field accuracy.",
            "No app settings, source artifacts, annotations, or split assignments changed. Nothing was deployed.",
            "training_pad results, when present, are a private Canvas geometry probe, not live-camera preprocessing.",
        ],
    }
    args.output.mkdir(parents=True, exist_ok=True)
    for name, svg in overlays.items():
        (args.output / name).write_text(svg, encoding="utf-8")
    write_json_report(args.output / "calibration.json", output)
    return output


def main(argv: list[str] | None = None) -> int:
    parser = ArgumentParser(description=__doc__)
    parser.add_argument("--dataset", type=Path, required=True)
    parser.add_argument("--benchmark", type=Path, required=True)
    parser.add_argument("--model", type=Path, required=True)
    parser.add_argument("--mode", choices=("raw", "regular", "fast"), default="regular")
    parser.add_argument("--geometry", choices=("original", "padded"), default="original")
    parser.add_argument("--input-manifest", type=Path)
    parser.add_argument("--browser-model", help="Calibrate this waste-model ID from an actual browser benchmark.")
    parser.add_argument("--output", type=Path, required=True)
    try:
        args = parser.parse_args(argv)
        for name in ("dataset", "benchmark", "model", "input_manifest"):
            if getattr(args, name) is not None:
                setattr(args, name, getattr(args, name).resolve(strict=True))
        args.output = args.output.resolve()
        result = calibrate(args)
        print(f"Validation baseline: {result['baseline']['true_positives']} matches, {result['baseline']['false_positives']} false positives.")
        print(f"Per-class filtering: {result['per_class_summary']['true_positives']} matches, {result['per_class_summary']['false_positives']} false positives.")
        print(f"Private calibration saved to {args.output}; no policy was deployed.")
        return 0
    except (Exception, KeyboardInterrupt) as error:
        print(f"Calibration failed: {error}. No completed result is claimed.", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
