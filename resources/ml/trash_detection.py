"""Isolated, private Ultralytics waste experiment; never promotes a model."""

from __future__ import annotations

import argparse
from collections import Counter
from datetime import datetime, timezone
import hashlib
import importlib.metadata
import json
import math
import os
from pathlib import Path
import re
import shutil
import sys
import time

from train_waste import (
    CLASSES, TrainingError, contained_file, finite_number, integer, positive_int,
    read_json, validate_dataset, validate_output, validate_training_use, write_json_report,
)

NAMES = [CLASSES[index] for index in (1, 2, 3, 4)]
SETTINGS = {"worker_score_threshold": 0.2, "ui_score_threshold": 0.5, "max_results": 10, "iou": 0.5}
ROOT = Path(__file__).resolve().parents[2]
os.environ["YOLO_CONFIG_DIR"] = str(ROOT / "storage/app/private/waste-training/yolo-settings")
os.environ["YOLO_AUTOINSTALL"] = "false"
os.environ["WANDB_MODE"] = "disabled"


def file_hash(path: Path) -> str:
    with path.open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()


def yolo_api():
    import ultralytics

    ultralytics.settings.update({"sync": False})
    return ultralytics.YOLO


def versions() -> dict[str, str]:
    return {name: importlib.metadata.version(name) for name in ("ultralytics", "torch", "torchvision", "opencv-python", "numpy")}


def verify_names(names) -> None:
    if isinstance(names, dict):
        if set(names) != set(range(len(NAMES))):
            raise TrainingError("Custom label indices must be consecutive integers from zero.")
        actual = [names[index] for index in range(len(NAMES))]
    elif isinstance(names, (list, tuple)):
        actual = list(names)
    else:
        raise TrainingError("Custom model label names are missing or invalid.")
    if actual != NAMES:
        raise TrainingError("Use custom waste weights with the four canonical labels, not generic COCO weights.")


def verify_converted_boxes(labels: dict, folder: Path) -> None:
    for image in labels["images"]:
        expected = []
        for annotation in labels["annotations"]:
            if annotation["image_id"] == image["id"]:
                x, y, width, height = annotation["bbox"]
                expected.append([annotation["category_id"] - 1, (x + width / 2) / image["width"],
                                 (y + height / 2) / image["height"], width / image["width"], height / image["height"]])
        path = folder / f'{image["id"]}.txt'
        actual = [[float(value) for value in row.split()] for row in path.read_text(encoding="utf-8").splitlines() if row.strip()]
        if len(actual) != len(expected) or any(len(row) != 5 for row in actual):
            raise TrainingError("Conversion dropped or added object annotations.")
        for left, right in zip(sorted(actual), sorted(expected)):
            if any(not math.isfinite(value) for value in left) or left[0] != right[0] or any(
                not math.isclose(a, b, rel_tol=2e-5, abs_tol=2e-6) for a, b in zip(left[1:], right[1:])
            ):
                raise TrainingError("Converted class or box differs from the approved source annotation.")


def prepare(dataset: Path, output: Path) -> dict:
    dataset = dataset.resolve(strict=True)
    output = output.resolve()
    validate_training_use(dataset)
    audit = validate_dataset(dataset)
    validate_output(output, dataset)
    yolo_api()
    from ultralytics.data.converter import convert_coco

    output.mkdir(parents=True, exist_ok=True)
    annotations = output / "annotations"
    annotations.mkdir()
    source_labels = {}
    source_images = {}
    for split, target in (("train", "train"), ("validation", "val")):
        labels = read_json(dataset, dataset / split / "labels.json")
        write_json_report(annotations / f"instances_{target}.json", labels)
        source_labels[split] = file_hash(dataset / split / "labels.json")
        for image in labels["images"]:
            source_images[f'{split}/{image["file_name"]}'] = file_hash(dataset / split / "data" / image["file_name"])
    source_labels["test"] = file_hash(dataset / "test/labels.json")
    converted = output / "converted"
    convert_coco(labels_dir=str(annotations), save_dir=str(converted), cls91to80=False)
    files = {}
    for split, target in (("train", "train"), ("validation", "val")):
        labels = read_json(dataset, dataset / split / "labels.json")
        destination = converted / "images" / target
        destination.mkdir(parents=True)
        verify_converted_boxes(labels, converted / "labels" / target)
        for image in labels["images"]:
            source = contained_file(dataset, dataset / split / "data" / image["file_name"])
            copied = destination / image["file_name"]
            shutil.copy2(source, copied)
            if file_hash(copied) != source_images[f'{split}/{image["file_name"]}']:
                raise TrainingError("Copied image bytes differ from the approved source.")
            label = converted / "labels" / target / f'{image["id"]}.txt'
            for path in (copied, label):
                files[path.relative_to(output).as_posix()] = file_hash(path)
    configuration = {"path": str(converted), "train": "images/train", "val": "images/val", "names": NAMES}
    # JSON is valid YAML and keeps the generated training configuration structured.
    write_json_report(output / "data.yaml", configuration)
    files["data.yaml"] = file_hash(output / "data.yaml")
    result = {"schema_version": 1, "status": "prepared", "created_at": datetime.now(timezone.utc).isoformat(),
              "source_dataset": str(dataset), "names": NAMES, "counts": audit["counts"],
              "source_labels_sha256": source_labels, "source_images_sha256": source_images,
              "files_sha256": files, "configuration": configuration,
              "converter": "ultralytics.data.converter.convert_coco(cls91to80=False)",
              "versions": versions(), "protected_test_exported": False, "promoted": False}
    write_json_report(output / "manifest.json", result)
    return result


def verify_prepared(folder: Path) -> dict:
    folder = folder.resolve(strict=True)
    report = read_json(folder, folder / "manifest.json")
    if (report.get("schema_version") != 1 or report.get("status") != "prepared" or report.get("names") != NAMES
            or report.get("promoted") is not False or report.get("protected_test_exported") is not False):
        raise TrainingError("Not an approved, prepared YOLO training experiment.")
    source = Path(report["source_dataset"]).resolve(strict=True)
    validate_training_use(source)
    audit = validate_dataset(source)
    if audit["counts"] != report["counts"]:
        raise TrainingError("Source dataset counts changed.")
    expected = {"path": str(folder / "converted"), "train": "images/train", "val": "images/val", "names": NAMES}
    if report.get("configuration") != expected or read_json(folder, folder / "data.yaml") != expected:
        raise TrainingError("YOLO configuration changed or attempts to use a different split.")
    expected_images, expected_files = set(), {"data.yaml"}
    for split, target in (("train", "train"), ("validation", "val")):
        labels = read_json(source, source / split / "labels.json")
        for image in labels["images"]:
            expected_images.add(f'{split}/{image["file_name"]}')
            expected_files.update((f'converted/images/{target}/{image["file_name"]}', f'converted/labels/{target}/{image["id"]}.txt'))
    if set(report.get("source_images_sha256", {})) != expected_images or set(report.get("files_sha256", {})) != expected_files:
        raise TrainingError("Prepared manifest does not cover every training and validation image and label.")
    for split in ("train", "validation", "test"):
        if file_hash(source / split / "labels.json") != report["source_labels_sha256"][split]:
            raise TrainingError("Protected source labels changed.")
    for relative, digest in report["source_images_sha256"].items():
        split, filename = relative.split("/", 1)
        if split not in ("train", "validation") or file_hash(contained_file(source, source / split / "data" / filename)) != digest:
            raise TrainingError("Source image changed.")
    for relative, digest in report["files_sha256"].items():
        if file_hash(contained_file(folder, folder / relative)) != digest:
            raise TrainingError("Prepared image, label, or configuration changed.")
    return report


def train(args) -> dict:
    prepared = verify_prepared(args.dataset)
    output = args.output.resolve()
    validate_output(output, args.dataset.resolve())
    weights = args.weights.resolve(strict=True)
    weights_sha256 = file_hash(weights)
    model = yolo_api()(str(weights))
    output.mkdir(parents=True, exist_ok=True)
    identity = {"schema_version": 1, "status": "training", "dataset_manifest_sha256": file_hash(args.dataset / "manifest.json"),
                "initial_weights_sha256": weights_sha256, "initial_weights": str(weights), "names": NAMES,
                "epochs": args.epochs, "batch_size": args.batch_size, "imgsz": args.imgsz,
                "device": "cpu", "workers": 0, "seed": 42, "versions": versions(),
                "dataset": str(args.dataset.resolve()), "promoted": False, "test_inference": False}
    write_json_report(output / "run.json", identity)
    started = time.monotonic()
    result = model.train(data=str(args.dataset.resolve() / "data.yaml"), epochs=args.epochs, batch=args.batch_size,
                         imgsz=args.imgsz, device="cpu", workers=0, seed=42, deterministic=True, amp=False,
                         cache=False, plots=False, project=str(output.parent), name=output.name, exist_ok=True,
                         pretrained=True, val=True, save=True, verbose=False)
    best = output / "weights/best.pt"
    if not best.is_file():
        raise TrainingError("Training did not produce a best checkpoint.")
    verify_names(yolo_api()(str(best)).names)
    verify_prepared(args.dataset)
    if identity["initial_weights_sha256"] != file_hash(weights):
        raise TrainingError("Initial weights changed during training.")
    report = {**identity, "status": "trained", "elapsed_seconds": time.monotonic() - started,
              "best_weights": str(best), "best_weights_sha256": file_hash(best),
              "framework_metrics": {key: float(value) for key, value in result.results_dict.items()},
              "counts": prepared["counts"], "deployment_ready": False}
    write_json_report(output / "metrics.json", report)
    return report


def evaluate(args) -> dict:
    prepared = verify_prepared(args.dataset)
    source = Path(prepared["source_dataset"])
    output = args.output.resolve()
    validate_output(output, source)
    weights = args.weights.resolve(strict=True)
    weights_sha256 = file_hash(weights)
    model = yolo_api()(str(weights))
    verify_names(model.names)
    labels = read_json(source, source / "validation/labels.json")
    predictions, images, timings = [], {}, []
    for image in labels["images"]:
        path = contained_file(source, source / "validation/data" / image["file_name"])
        images[str(image["id"])] = file_hash(path)
        started = time.monotonic()
        result = model.predict(source=str(path), imgsz=args.imgsz, device="cpu",
                               conf=SETTINGS["worker_score_threshold"], iou=SETTINGS["iou"],
                               max_det=SETTINGS["max_results"], verbose=False, save=False)[0]
        timings.append((time.monotonic() - started) * 1000)
        if tuple(result.orig_shape) != (image["height"], image["width"]):
            raise TrainingError("Decoded validation image dimensions differ from approved annotations.")
        for box in result.boxes:
            category = int(box.cls.item())
            x1, y1, x2, y2 = box.xyxy[0].tolist()
            x1, x2 = max(0, x1), min(image["width"], x2)
            y1, y2 = max(0, y1), min(image["height"], y2)
            if category not in range(4) or x2 <= x1 or y2 <= y1:
                raise TrainingError("YOLO returned an invalid class or bounding box.")
            predictions.append({"image_id": image["id"], "category_id": category + 1, "label": NAMES[category],
                                "bbox": [x1, y1, x2 - x1, y2 - y1], "score": float(box.conf.item())})
    verify_prepared(args.dataset)
    if weights_sha256 != file_hash(weights):
        raise TrainingError("Custom weights changed during validation inference.")
    report = {"schema_version": 1, "status": "inferred", "backend": "Ultralytics Python CPU", "split": "validation",
              "model_sha256": weights_sha256, "settings": SETTINGS, "imgsz": args.imgsz, "names": NAMES,
              "labels_sha256": file_hash(source / "validation/labels.json"), "image_sha256": images,
              "predictions": predictions, "inference_ms_including_preprocessing": timings,
              "versions": versions(), "promoted": False, "deployment_ready": False}
    validate_predictions(report, labels)
    output.mkdir(parents=True, exist_ok=True)
    write_json_report(output / "predictions.json", report)
    return report


def validate_predictions(report: dict, labels: dict) -> None:
    if (report.get("schema_version") != 1 or report.get("status") != "inferred"
            or report.get("backend") != "Ultralytics Python CPU" or report.get("split") != "validation"
            or report.get("names") != NAMES or report.get("settings") != SETTINGS or report.get("promoted") is not False
            or report.get("deployment_ready") is not False or not isinstance(report.get("predictions"), list)
            or not re.fullmatch(r"[a-f0-9]{64}", report.get("model_sha256", ""))):
        raise TrainingError("Expected a private, canonical YOLO validation report.")
    images = {image["id"]: image for image in labels["images"]}
    counts = Counter()
    for row in report["predictions"]:
        if not isinstance(row, dict):
            raise TrainingError("YOLO predictions must be objects.")
        image, category = images.get(row.get("image_id")), row.get("category_id")
        bbox, score = row.get("bbox"), row.get("score")
        if (not image or not integer(row.get("image_id")) or not integer(category) or category not in (1, 2, 3, 4) or row.get("label") != CLASSES[category]
                or not finite_number(score) or not 0.2 <= score <= 1 or not isinstance(bbox, list) or len(bbox) != 4
                or any(not finite_number(value) for value in bbox)):
            raise TrainingError("YOLO prediction identity, score, or coordinates are invalid.")
        x, y, width, height = bbox
        if x < 0 or y < 0 or width <= 0 or height <= 0 or x + width > image["width"] + 1e-5 or y + height > image["height"] + 1e-5:
            raise TrainingError("YOLO prediction is outside the source image.")
        counts[row["image_id"]] += 1
        if counts[row["image_id"]] > SETTINGS["max_results"]:
            raise TrainingError("YOLO report exceeds the frozen result limit.")


def score(args) -> dict:
    from analyze_waste import analyze_image, summarize
    from evaluate_waste import score_predictions

    source = args.dataset.resolve(strict=True)
    validate_training_use(source)
    validate_dataset(source)
    output = args.output.resolve()
    validate_output(output, source)
    labels = read_json(source, source / "validation/labels.json")
    report = read_json(args.report.parent.resolve(), args.report.resolve())
    validate_predictions(report, labels)
    if report["labels_sha256"] != file_hash(source / "validation/labels.json") or report["image_sha256"] != {
        str(image["id"]): file_hash(source / "validation/data" / image["file_name"]) for image in labels["images"]
    }:
        raise TrainingError("YOLO validation sources differ from the current annotations or photos.")
    metrics = {}
    for threshold in (0.2, 0.5):
        predictions = [row for row in report["predictions"] if row["score"] >= threshold]
        analyzed = [analyze_image([row for row in labels["annotations"] if row["image_id"] == image["id"]],
                                 [row for row in predictions if row["image_id"] == image["id"]], threshold) for image in labels["images"]]
        metrics[str(threshold)] = {"waste_coco_AP": score_predictions(labels, predictions), "class_aware_iou_05": summarize(analyzed)}
    result = {"status": "scored", "backend": report["backend"], "model_sha256": report["model_sha256"],
              "predictions_sha256": file_hash(args.report), "labels_sha256": report["labels_sha256"],
              "split": "validation", "images": len(labels["images"]), "thresholds": metrics,
              "promoted": False, "deployment_ready": False,
              "limitations": ["18 reused development photos are not live or independent field accuracy.",
                              "YOLO uses native Python preprocessing at its configured input size; browser timings are not directly comparable."]}
    output.mkdir(parents=True, exist_ok=True)
    write_json_report(output / "comparison.json", result)
    return result


def camera(args) -> None:
    model = yolo_api()(str(args.weights.resolve(strict=True)))
    verify_names(model.names)
    source = int(args.source) if args.source.isdecimal() else args.source
    results = model.predict(source=source, stream=True, stream_buffer=False, imgsz=args.imgsz, device="cpu",
                            conf=SETTINGS["ui_score_threshold"], iou=SETTINGS["iou"], max_det=SETTINGS["max_results"],
                            show=not args.headless, save=False, verbose=False)
    try:
        for index, result in enumerate(results, 1):
            print(json.dumps({"frame": index, "objects": dict(Counter(result.names[int(box.cls.item())] for box in result.boxes))}), flush=True)
            if args.limit and index >= args.limit:
                break
    finally:
        results.close()
        import cv2

        cv2.destroyAllWindows()


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    for name in ("prepare", "train", "evaluate", "score"):
        command = commands.add_parser(name)
        command.add_argument("--dataset", type=Path, required=True)
        command.add_argument("--output", type=Path, required=True)
        if name in ("train", "evaluate"):
            command.add_argument("--weights", type=Path, required=True)
            command.add_argument("--imgsz", type=positive_int, default=320)
        if name == "train":
            command.add_argument("--epochs", type=positive_int, default=3)
            command.add_argument("--batch-size", type=positive_int, default=4)
        if name == "score":
            command.add_argument("--report", type=Path, required=True)
    live = commands.add_parser("camera")
    live.add_argument("--weights", type=Path, required=True)
    live.add_argument("--source", required=True)
    live.add_argument("--imgsz", type=positive_int, default=320)
    live.add_argument("--limit", type=int, default=0)
    live.add_argument("--headless", action="store_true")
    try:
        args = parser.parse_args(argv)
        if args.command == "prepare":
            result = prepare(args.dataset, args.output)
        elif args.command == "train":
            result = train(args)
        elif args.command == "evaluate":
            result = evaluate(args)
        elif args.command == "score":
            result = score(args)
        else:
            if args.limit < 0:
                raise TrainingError("Frame limit cannot be negative.")
            camera(args)
            return 0
        print(json.dumps({key: result[key] for key in ("status", "promoted")}, indent=2))
        return 0
    except (Exception, KeyboardInterrupt) as error:
        print(f"YOLO experiment failed: {error}; artifacts preserved and no model promoted.", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
