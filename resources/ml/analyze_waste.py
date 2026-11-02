"""Diagnose saved waste predictions on validation images without retraining."""

from __future__ import annotations

import argparse
import csv
import json
import re
import sys
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from compare_waste import comparison_svg, file_hash
from prepare_waste import CLASS_SOURCES
from train_waste import (
    CLASSES, ArgumentParser, TrainingError, finite_number, integer,
    read_json, validate_dataset, validate_output,
)


def intersection_over_union(first: list[float], second: list[float]) -> float:
    x, y, width, height = first
    sx, sy, swidth, sheight = second
    overlap = max(0, min(x + width, sx + swidth) - max(x, sx)) * max(
        0, min(y + height, sy + sheight) - max(y, sy),
    )
    union = width * height + swidth * sheight - overlap
    return overlap / union if union > 0 else 0.0


def validate_report(
    report: dict[str, Any], labels: dict[str, Any], labels_hash: str, image_hashes: dict[str, str],
) -> None:
    if report.get("status") != "evaluated" or report.get("split") != "validation":
        raise TrainingError("Error analysis requires a completed validation evaluation, not test predictions.")
    if report.get("labels_sha256") != labels_hash or report.get("image_sha256") != image_hashes:
        raise TrainingError("Saved evaluation does not match these exact validation labels and image bytes.")
    if not isinstance(report.get("model_sha256"), str) or not re.fullmatch(r"[a-f0-9]{64}", report["model_sha256"]):
        raise TrainingError("Saved evaluation requires a model SHA-256.")
    predictions = report.get("predictions")
    if not isinstance(predictions, list):
        raise TrainingError("Saved evaluation requires a prediction list.")
    images = {image["id"]: image for image in labels["images"]}
    for prediction in predictions:
        if not isinstance(prediction, dict):
            raise TrainingError("Invalid saved prediction.")
        image_id, category = prediction.get("image_id"), prediction.get("category_id")
        if not integer(image_id) or image_id not in images or not integer(category) or category not in (1, 2, 3, 4):
            raise TrainingError("Prediction references an unknown image or waste class.")
        score, box = prediction.get("score"), prediction.get("bbox")
        if not finite_number(score) or not 0 <= score <= 1:
            raise TrainingError("Prediction score must be finite and between zero and one.")
        if not isinstance(box, list) or len(box) != 4 or not all(finite_number(value) for value in box):
            raise TrainingError("Prediction requires a finite xywh bounding box.")
        x, y, width, height = box
        image = images[image_id]
        if x < 0 or y < 0 or width <= 0 or height <= 0 or x + width > image["width"] + 1e-6 or y + height > image["height"] + 1e-6:
            raise TrainingError("Prediction box is outside the validation image.")


def analyze_image(
    annotations: list[dict[str, Any]], predictions: list[dict[str, Any]],
    score_threshold: float = 0.2, iou_threshold: float = 0.5,
) -> dict[str, Any]:
    if any(not finite_number(value) or not 0 < value <= 1 for value in (score_threshold, iou_threshold)):
        raise TrainingError("Diagnostic thresholds must be finite and in (0, 1].")
    ordered = sorted(enumerate(predictions), key=lambda item: (-item[1]["score"], item[0]))
    matches, used_predictions = {}, set()
    # Confidence-ordered, class-aware, one-to-one matching prevents duplicate credit.
    for index, prediction in ordered:
        if prediction["score"] < score_threshold:
            continue
        available = [
            (intersection_over_union(annotation["bbox"], prediction["bbox"]), annotation["id"])
            for annotation in annotations
            if annotation["id"] not in matches and annotation["category_id"] == prediction["category_id"]
        ]
        if available:
            overlap, annotation_id = max(available)
            if overlap >= iou_threshold:
                matches[annotation_id] = index
                used_predictions.add(index)

    details = []
    for annotation in annotations:
        ranked = sorted([
            {"prediction_index": index, "iou": intersection_over_union(annotation["bbox"], prediction["bbox"]),
             "category": CLASSES[prediction["category_id"]], "score": prediction["score"], "bbox": prediction["bbox"]}
            for index, prediction in enumerate(predictions)
        ], key=lambda item: (item["iou"], item["score"]), reverse=True)
        same = [item for item in ranked if item["category"] == CLASSES[annotation["category_id"]]]
        qualifying = [item for item in same if item["iou"] >= iou_threshold]
        if annotation["id"] in matches:
            reason = "matched"
        elif any(item["score"] >= score_threshold for item in qualifying):
            reason = "competing_match"
        elif qualifying:
            reason = "low_confidence"
        elif any(item["iou"] >= iou_threshold and item["score"] >= score_threshold for item in ranked):
            reason = "wrong_class"
        elif any(item["iou"] >= 0.1 and item["score"] >= score_threshold for item in same):
            reason = "localization"
        else:
            reason = "missed"
        details.append({
            "annotation_id": annotation["id"], "category": CLASSES[annotation["category_id"]],
            "bbox": annotation["bbox"], "reason": reason,
            "matched_prediction_index": matches.get(annotation["id"]),
            "best_same_class": same[0] if same else None,
            "best_any_class": ranked[0] if ranked else None,
        })
    false_positives = []
    for index, prediction in ordered:
        if prediction["score"] < score_threshold or index in used_predictions:
            continue
        overlaps = [(intersection_over_union(annotation["bbox"], prediction["bbox"]), annotation) for annotation in annotations]
        same = [(overlap, annotation) for overlap, annotation in overlaps if annotation["category_id"] == prediction["category_id"]]
        if any(overlap >= iou_threshold for overlap, _ in same):
            reason = "duplicate"
        elif any(overlap >= iou_threshold for overlap, _ in overlaps):
            reason = "wrong_class"
        elif any(overlap >= 0.1 for overlap, _ in same):
            reason = "localization"
        else:
            reason = "background_or_poor_overlap"
        false_positives.append({"prediction_index": index, "reason": reason, **prediction})
    return {"ground_truth": details, "false_positives": false_positives}


def summarize(images: list[dict[str, Any]]) -> dict[str, Any]:
    truth = [item for image in images for item in image["ground_truth"]]
    false_positives = [item for image in images for item in image["false_positives"]]
    true_positives = sum(item["reason"] == "matched" for item in truth)
    return {
        "annotations": len(truth), "true_positives": true_positives,
        "false_negatives": len(truth) - true_positives, "false_positives": len(false_positives),
        "precision": true_positives / (true_positives + len(false_positives)) if true_positives + len(false_positives) else 0.0,
        "recall": true_positives / len(truth) if truth else 0.0,
        "ground_truth_reasons": dict(Counter(item["reason"] for item in truth)),
        "false_positive_reasons": dict(Counter(item["reason"] for item in false_positives)),
        "by_class": {
            name: dict(Counter(item["reason"] for item in truth if item["category"] == name))
            for key, name in CLASSES.items() if key != 0
        },
    }


def summarize_history(path: Path) -> dict[str, Any]:
    with path.open(encoding="utf-8", newline="") as source:
        reader = csv.DictReader(source)
        if not reader.fieldnames or len(reader.fieldnames) != len(set(reader.fieldnames)):
            raise TrainingError("Training history requires distinct CSV headers.")
        rows = list(reader)
    history = []
    for index, row in enumerate(rows):
        if row.get("epoch") != str(index):
            raise TrainingError("Training history must contain consecutive, unique epochs starting at zero.")
        try:
            train_loss, validation_loss = float(row["total_loss"]), float(row["val_total_loss"])
        except (KeyError, TypeError, ValueError) as error:
            raise TrainingError("Training history requires numeric total_loss and val_total_loss.") from error
        if any(not finite_number(value) or value < 0 for value in (train_loss, validation_loss)):
            raise TrainingError("Training losses must be finite and nonnegative.")
        history.append({"epoch": index + 1, "training_loss": train_loss, "validation_loss": validation_loss})
    if not history:
        raise TrainingError("Training history cannot be empty.")
    return {
        "sha256": file_hash(path), "epochs": len(history), "first_epoch": history[0],
        "last_epoch": history[-1], "lowest_validation_loss_epoch": min(history, key=lambda row: row["validation_loss"]),
        "interpretation": "Diverging training and validation losses can suggest overfitting; loss is not detection AP and does not select a deployable checkpoint.",
    }


def audit_training_annotations(dataset: Path, source_path: Path) -> dict[str, Any]:
    labels_path = dataset / "train" / "labels.json"
    labels = read_json(dataset, labels_path)
    source = read_json(source_path.parent, source_path)
    if any(not isinstance(source.get(key), list) for key in ("images", "annotations", "categories")):
        raise TrainingError("Source annotation audit requires COCO images, annotations, and categories.")
    source_images = {image["id"]: image for image in source["images"]}
    source_categories = {category["id"]: category["name"] for category in source["categories"]}
    mapping = {name: next(key for key, value in CLASSES.items() if value == target)
               for target, names in CLASS_SOURCES.items() for name in names}
    excluded, mismatches, cap_review, audited, separate = Counter(), [], [], 0, 0
    for image in labels["images"]:
        if image.get("source_collection") is not None:
            separate += 1
            continue
        original = source_images.get(image["id"])
        if original is None or image.get("source_file_name") != original.get("file_name"):
            raise TrainingError("Source annotations do not match the retained training photo identities.")
        if any(not finite_number(original.get(axis)) or original[axis] <= 0 for axis in ("width", "height")):
            raise TrainingError("Source annotations require valid image dimensions.")
        audited += 1
        annotations = [a for a in source["annotations"] if a["image_id"] == image["id"]]
        retained = {a["id"]: a for a in labels["annotations"] if a["image_id"] == image["id"]}
        expected_ids = set()
        for annotation in annotations:
            name = source_categories.get(annotation["category_id"])
            if name not in mapping:
                excluded[name or "unknown_source_category"] += 1
                continue
            box = annotation.get("bbox")
            if not isinstance(box, list) or len(box) != 4 or any(not finite_number(value) for value in box) or box[2] <= 0 or box[3] <= 0:
                raise TrainingError("Source annotation contains invalid supported-class geometry.")
            expected_ids.add(annotation["id"])
            kept = retained.get(annotation["id"])
            sx, sy = image["width"] / original["width"], image["height"] / original["height"]
            x, y, width, height = box
            projected = [x * sx, y * sy, min(width * sx, image["width"] - x * sx), min(height * sy, image["height"] - y * sy)]
            if (kept is None or kept["category_id"] != mapping[name]
                    or any(abs(a - b) > 1e-5 for a, b in zip(kept["bbox"], projected))):
                mismatches.append({"image_id": image["id"], "source_annotation_id": annotation["id"],
                                   "source_category": name, "reason": "missing_or_changed_mapped_annotation"})
        for annotation_id in retained.keys() - expected_ids:
            mismatches.append({"image_id": image["id"], "source_annotation_id": annotation_id,
                               "reason": "unexpected_retained_annotation"})
        bottles = [a for a in annotations if source_categories.get(a["category_id"]) in CLASS_SOURCES["plastic_bottle"]]
        caps = [a for a in annotations if source_categories.get(a["category_id"]) == "Plastic bottle cap"]
        for cap in caps:
            x, y, width, height = cap["bbox"]
            for bottle in bottles:
                bx, by, bw, bh = bottle["bbox"]
                overlap = max(0, min(x + width, bx + bw) - max(x, bx)) * max(0, min(y + height, by + bh) - max(y, by))
                if overlap / (width * height) >= 0.9:
                    cap_review.append({"image_id": image["id"], "cap_annotation_id": cap["id"],
                                       "bottle_annotation_id": bottle["id"], "reason": "cap_box_mostly_inside_bottle_box"})
    object_counts = Counter(a["category_id"] for a in labels["annotations"])
    return {
        "status": "audited", "split": "train", "source_sha256": file_hash(source_path),
        "labels_sha256": file_hash(labels_path), "class_source_names": CLASS_SOURCES,
        "audited_source_images": audited, "separately_provenanced_images": separate,
        "class_coverage": {name: {"objects": object_counts[key], "images": len({a["image_id"] for a in labels["annotations"] if a["category_id"] == key})}
                           for key, name in CLASSES.items() if key != 0},
        "structural_mismatches": mismatches, "excluded_source_objects": dict(sorted(excluded.items())),
        "cap_overlap_review_queue": cap_review,
        "limitations": [
            "Mapping and coordinate checks do not verify material identity or manual annotation quality.",
            "A cap inside a bottle box is a review flag, not proof that the cap is attached or its label is incorrect.",
            "Aerosols remain outside metal_can, which includes only Food Can and Drink can.",
            "Separately-provenanced collection images are not compared with this TACO source file.",
            "No labels or split assignments were changed by this audit.",
        ],
    }


def render_review(path: Path, image: dict[str, Any], jpeg_path: Path, panels: dict[str, list[dict[str, Any]]]) -> None:
    from PIL import Image, ImageDraw, ImageFont, ImageOps

    font = ImageFont.load_default(size=15)
    selected = {name: sorted(values, key=lambda p: p.get("score", 1), reverse=True)[:12] for name, values in panels.items()}
    canvas = Image.new("RGB", (500 * len(panels), 740 + 24 * max(len(values) for values in selected.values())), "white")
    drawing = ImageDraw.Draw(canvas)
    with Image.open(jpeg_path) as source:
        if source.size != (image["width"], image["height"]):
            raise TrainingError("Decoded review image dimensions disagree with annotations.")
        thumbnail = ImageOps.contain(source.convert("RGB"), (480, 640))
    scale = thumbnail.width / image["width"]
    for column, (name, values) in enumerate(selected.items()):
        origin = column * 500 + 10
        drawing.text((origin, 10), f'{name} / image {image["id"]}', fill="black", font=font)
        canvas.paste(thumbnail, (origin, 40))
        for index, prediction in enumerate(values, 1):
            color = "#007a43" if "score" not in prediction else "#c00020"
            x, y, width, height = (value * scale for value in prediction["bbox"])
            drawing.rectangle((origin + x, 40 + y, origin + x + width, 40 + y + height), outline=color, width=3)
            drawing.text((origin + x + 2, 42 + y), str(index), fill=color, stroke_fill="white", stroke_width=1, font=font)
            label = f'{index}. {CLASSES[prediction["category_id"]]}'
            if "score" in prediction:
                label += f' / score {prediction["score"]:.3f}'
            drawing.text((origin, 700 + index * 24), label, fill=color, font=font)
    canvas.save(path)


def analyze(args: argparse.Namespace) -> dict[str, Any]:
    audit = validate_dataset(args.dataset)
    validate_output(args.output, args.dataset)
    annotation_audit = audit_training_annotations(args.dataset, args.source_annotations) if args.source_annotations is not None else None
    labels_path = args.dataset / "validation" / "labels.json"
    labels = read_json(args.dataset, labels_path)
    hashes = {str(image["id"]): file_hash(args.dataset / "validation" / "data" / image["file_name"]) for image in labels["images"]}
    paths = {"baseline": args.baseline, "candidate": args.evaluation}
    reports = {name: read_json(path.parent, path) for name, path in paths.items()}
    for name, report in reports.items():
        if args.output.is_relative_to(paths[name].parent) or paths[name].is_relative_to(args.output):
            raise TrainingError("Review output must be separate from evaluation artifacts.")
        validate_report(report, labels, file_hash(labels_path), hashes)
    if reports["baseline"].get("settings") != reports["candidate"].get("settings") or reports["baseline"].get("backend") != reports["candidate"].get("backend"):
        raise TrainingError("Baseline and candidate must use identical evaluation settings and backend.")
    training_history = None
    if args.history is not None:
        metrics = read_json(args.history.parent, args.history.parent / "metrics.json")
        if metrics.get("status") != "trained" or metrics.get("model", {}).get("sha256") != reports["candidate"]["model_sha256"]:
            raise TrainingError("History must belong to the candidate training run, verified by metrics.json.")
        training_history = {**summarize_history(args.history), "metrics_sha256": file_hash(args.history.parent / "metrics.json")}
    image_results = {name: [] for name in reports}
    for image in labels["images"]:
        annotations = [item for item in labels["annotations"] if item["image_id"] == image["id"]]
        for name, report in reports.items():
            predictions = [item for item in report["predictions"] if item["image_id"] == image["id"]]
            image_results[name].append({"image_id": image["id"], **analyze_image(annotations, predictions)})
    results = {
        name: {"model_sha256": report["model_sha256"], "report_sha256": file_hash(paths[name]),
               "summary": summarize(image_results[name]), "images": image_results[name]}
        for name, report in reports.items()
    }
    for name, report in reports.items():
        results[name]["threshold_diagnostics"] = {
            str(threshold): summarize([
                analyze_image([a for a in labels["annotations"] if a["image_id"] == image["id"]],
                              [p for p in report["predictions"] if p["image_id"] == image["id"]], threshold)
                for image in labels["images"]
            ]) for threshold in (0.05, 0.1, 0.2, 0.35, 0.5)
        }
    args.output.mkdir(parents=True, exist_ok=True)
    for image in labels["images"]:
        annotations = [a for a in labels["annotations"] if a["image_id"] == image["id"]]
        panels = {"Ground truth": annotations, **{
            name: [p for p in report["predictions"] if p["image_id"] == image["id"]]
            for name, report in reports.items()
        }}
        path = args.dataset / "validation" / "data" / image["file_name"]
        (args.output / f'review-{image["id"]}.svg').write_text(comparison_svg(image, path.read_bytes(), panels), encoding="utf-8")
        if any(a["category_id"] == 1 for a in annotations):
            visible = {name: [p for p in values if p.get("score", 1) >= 0.2] for name, values in panels.items()}
            render_review(args.output / f'bottles-{image["id"]}.png', image, path, visible)
    return {
        "status": "analyzed", "created_at": datetime.now(timezone.utc).isoformat(),
        "split": "validation", "labels_sha256": file_hash(labels_path), "image_sha256": hashes,
        "analyzer_sha256": file_hash(Path(__file__)), "dataset_audit": audit,
        "diagnostic_settings": {"score_threshold": 0.2, "iou_threshold": 0.5,
                                "matching": "confidence_ordered_class_aware_one_to_one",
                                "localization_min_iou": 0.1, "raster_max_boxes_per_panel": 12},
        "results": results, "candidate_training_history": training_history,
        "training_annotation_audit": annotation_audit, "promoted": False,
        "limitations": [
            "Fixed-threshold diagnostic precision and recall are not COCO mAP.",
            "Failure reasons are heuristic symptoms, not proof of a training or preprocessing root cause.",
            "Test predictions were not used; these small validation samples cannot establish deployment readiness.",
            "Raster panels show at most twelve boxes; SVGs show all predictions with score at least 0.20.",
            "Source attribution is retained in SVG metadata and dataset labels; predictions are untrusted suggestions.",
        ],
    }


def main(argv: list[str] | None = None) -> int:
    parser = ArgumentParser(description=__doc__)
    parser.add_argument("--dataset", type=Path, required=True)
    parser.add_argument("--baseline", type=Path, required=True, help="Saved baseline validation evaluation JSON.")
    parser.add_argument("--evaluation", type=Path, required=True, help="Saved candidate validation evaluation JSON.")
    parser.add_argument("--history", type=Path, help="Candidate history.csv, alongside its completed metrics.json.")
    parser.add_argument("--source-annotations", type=Path, help="Original TACO COCO JSON for a non-editing training-label audit.")
    parser.add_argument("--output", type=Path, required=True, help="New or empty private review directory.")
    try:
        args = parser.parse_args(argv)
        for name in ("dataset", "baseline", "evaluation"):
            setattr(args, name, getattr(args, name).resolve(strict=True))
        if args.history is not None:
            args.history = args.history.resolve(strict=True)
        if args.source_annotations is not None:
            args.source_annotations = args.source_annotations.resolve(strict=True)
        args.output = args.output.resolve()
        result = analyze(args)
        with (args.output / "error-analysis.json").open("x", encoding="utf-8") as destination:
            destination.write(json.dumps(result, indent=2, allow_nan=False) + "\n")
        print(json.dumps({name: value["summary"] for name, value in result["results"].items()}, indent=2))
        print(f"Validation review saved to {args.output}. No model was retrained or promoted.")
        return 0
    except (Exception, KeyboardInterrupt) as error:
        print(f"Error analysis failed: {error}. Partial output is not a completed report.", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
