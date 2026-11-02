"""Evaluate a private waste TFLite detector using MediaPipe and COCO metrics."""

from __future__ import annotations

import argparse
from copy import deepcopy
import hashlib
import json
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from types import SimpleNamespace
from typing import Any

from train_waste import (
    CLASSES, ArgumentParser, TrainingError, check_environment, finite_number,
    json_value, read_json, validate_dataset, validate_output,
    write_json_report,
)


def deduplication_safe_graph(graph: Any) -> Any:
    copied = deepcopy(graph)
    nodes = [node for node in copied.node if node.calculator == "DetectionsDeduplicateCalculator"]
    if len(nodes) != 1 or len(nodes[0].input_stream) != 1 or len(nodes[0].output_stream) != 1:
        raise TrainingError("Expected one single-stream native detection deduplicator in the pinned graph.")
    node = nodes[0]
    if node.HasField("options") or node.node_options:
        raise TrainingError("Unexpected options on the pinned detection deduplicator.")
    # MediaPipe 0.10.14 keeps invalidated vector pointers when merging duplicate boxes.
    node.calculator = "PassThroughCalculator"
    return copied


def create_evaluation_detector(options: Any) -> Any:
    import mediapipe as mp

    if mp.__version__ != "0.10.14" or options.running_mode != mp.tasks.vision.RunningMode.IMAGE:
        raise TrainingError("The native deduplication workaround requires pinned MediaPipe 0.10.14 IMAGE mode.")
    if options.result_callback is not None:
        raise TrainingError("Native evaluation does not support asynchronous callbacks.")
    from mediapipe.framework.calculator_pb2 import CalculatorGraphConfig
    from mediapipe.tasks.python.core.task_info import TaskInfo

    task = TaskInfo(
        task_graph="mediapipe.tasks.vision.ObjectDetectorGraph",
        input_streams=["IMAGE:image_in", "NORM_RECT:norm_rect_in"],
        output_streams=["DETECTIONS:detections_out", "IMAGE:image_out"],
        task_options=options,
    )
    # Expand before TaskRunner adds callbacks and consumes graph output declarations.
    validated = mp.ValidatedGraphConfig()
    validated.initialize(graph_config=task.generate_graph_config())
    graph = deduplication_safe_graph(CalculatorGraphConfig.FromString(validated.binary_config))
    return mp.tasks.vision.ObjectDetector(graph, options.running_mode)


def merge_duplicate_detections(detections: list[Any]) -> list[Any]:
    merged = {}
    for detection in detections:
        box = detection.bounding_box
        coordinates = (box.origin_x, box.origin_y, box.width, box.height)
        if not all(finite_number(value) for value in coordinates):
            raise TrainingError("TFLite returned an invalid bounding box.")
        if coordinates not in merged:
            merged[coordinates] = SimpleNamespace(bounding_box=box, categories=[])
        merged[coordinates].categories.extend(detection.categories)
    return list(merged.values())


def coco_detection(detection: Any, image: dict[str, Any]) -> dict[str, Any] | None:
    if not detection.categories:
        raise TrainingError("Expected at least one category per MediaPipe detection.")
    candidates = []
    for category in detection.categories:
        category_id = next((key for key, name in CLASSES.items() if name == category.category_name), None)
        # The raw-output MediaPipe graph may omit label_id after attaching label text.
        if category_id is None or (category.index is not None and category.index != category_id):
            raise TrainingError(
                f"TFLite labels do not match the prepared waste categories: {category.index=}, {category.category_name=}."
            )
        if not finite_number(category.score) or not 0 <= category.score <= 1:
            raise TrainingError("TFLite returned an invalid confidence score.")
        candidates.append((category_id, category))
    # MediaPipe can attach several labels to one box; COCO requires one class per prediction.
    category_id, category = max(candidates, key=lambda candidate: candidate[1].score)
    if category_id == 0:
        return None
    box = detection.bounding_box
    if not all(finite_number(value) for value in (box.origin_x, box.origin_y, box.width, box.height)):
        raise TrainingError("TFLite returned an invalid bounding box.")
    if box.width <= 0 or box.height <= 0:
        return None
    left = max(0, min(image["width"], box.origin_x))
    top = max(0, min(image["height"], box.origin_y))
    right = max(0, min(image["width"], box.origin_x + box.width))
    bottom = max(0, min(image["height"], box.origin_y + box.height))
    if right <= left or bottom <= top:
        return None
    return {
        "image_id": image["id"], "category_id": category_id,
        "bbox": [left, top, right - left, bottom - top], "score": category.score,
    }


def validate_model_labels(displayer: Any) -> None:
    metadata = json.loads(displayer.get_metadata_json())
    label_files = [
        associated["name"]
        for graph in metadata.get("subgraph_metadata", [])
        for tensor in graph.get("output_tensor_metadata", [])
        for associated in tensor.get("associated_files", [])
        if associated.get("type") in ("TENSOR_AXIS_LABELS", "TENSOR_VALUE_LABELS") and not associated.get("locale")
    ]
    if len(label_files) != 1:
        raise TrainingError("Expected one default detection label file in TFLite metadata.")
    labels = displayer.get_associated_file_buffer(label_files[0]).decode("utf-8").splitlines()
    if labels != list(CLASSES.values()):
        raise TrainingError("TFLite metadata must contain background and the four waste classes in order.")


def score_predictions(labels: dict[str, Any], predictions: list[dict[str, Any]]) -> dict[str, Any]:
    from pycocotools.coco import COCO
    from pycocotools.cocoeval import COCOeval

    ground_truth = COCO()
    ground_truth.dataset = {
        **labels, "info": labels.get("info", {}),
        "annotations": [
            {**annotation, "area": annotation["bbox"][2] * annotation["bbox"][3], "iscrowd": 0}
            for annotation in labels["annotations"]
        ],
    }
    ground_truth.createIndex()
    if predictions:
        detected = ground_truth.loadRes(predictions)
    else:
        detected = COCO()
        detected.dataset = {**ground_truth.dataset, "annotations": []}
        detected.createIndex()
    evaluator = COCOeval(ground_truth, detected, "bbox")
    evaluator.params.catIds = [1, 2, 3, 4, 5, 6]
    evaluator.params.imgIds = sorted(image["id"] for image in labels["images"])
    evaluator.evaluate()
    evaluator.accumulate()
    evaluator.summarize()
    per_class = {}
    for index, category_id in enumerate(evaluator.params.catIds):
        precision = evaluator.eval["precision"][:, :, index, 0, -1]
        valid = precision[precision >= 0]
        per_class[CLASSES[category_id]] = float(valid.mean()) if valid.size else None
    return {
        "AP": float(evaluator.stats[0]), "AP50": float(evaluator.stats[1]),
        "AP75": float(evaluator.stats[2]), "AR100": float(evaluator.stats[8]),
        "AP_by_class": per_class,
    }


def evaluate(args: argparse.Namespace) -> dict[str, Any]:
    print("Loading the MediaPipe inference runtime...", flush=True)
    import mediapipe as mp
    from mediapipe.tasks.python.metadata.metadata import MetadataDisplayer

    versions = check_environment()
    audit = validate_dataset(args.dataset)
    validate_output(args.output, args.dataset)
    if args.model.is_relative_to(args.output):
        raise TrainingError("Evaluation output must be separate from the model directory.")
    with args.model.open("rb") as source:
        model_hash = hashlib.file_digest(source, "sha256").hexdigest()
    validate_model_labels(MetadataDisplayer.with_model_file(str(args.model)))
    labels = read_json(args.dataset, args.dataset / args.split / "labels.json")
    options = mp.tasks.vision.ObjectDetectorOptions(
        base_options=mp.tasks.BaseOptions(
            model_asset_path=str(args.model),
            delegate=mp.tasks.BaseOptions.Delegate.CPU,
        ),
        running_mode=mp.tasks.vision.RunningMode.IMAGE,
        score_threshold=0.001, max_results=100,
    )
    predictions = []
    multi_category_detections = 0
    durations = []
    image_hashes = {}
    print(f"Starting TFLite evaluation on {len(labels['images'])} {args.split} images...", flush=True)
    with create_evaluation_detector(options) as detector:
        for index, image in enumerate(labels["images"], 1):
            path = args.dataset / args.split / "data" / image["file_name"]
            with path.open("rb") as source:
                image_hashes[str(image["id"])] = hashlib.file_digest(source, "sha256").hexdigest()
            frame = mp.Image.create_from_file(str(path))
            if frame.width != image["width"] or frame.height != image["height"]:
                raise TrainingError(f"Decoded image dimensions disagree with annotations: {path}")
            started = time.perf_counter()
            result = detector.detect(frame)
            durations.append((time.perf_counter() - started) * 1000)
            for detection in merge_duplicate_detections(result.detections):
                if len(detection.categories) > 1:
                    multi_category_detections += 1
                converted = coco_detection(detection, image)
                if converted is not None:
                    predictions.append(converted)
            print(f"Evaluated {index}/{len(labels['images'])}: image {image['id']}", flush=True)

    return json_value({
        "status": "evaluated", "created_at": datetime.now(timezone.utc).isoformat(),
        "backend": "MediaPipe Tasks Python / TFLite CPU", "versions": versions,
        "model_sha256": model_hash, "split": args.split, "dataset_audit": audit,
        "labels_sha256": hashlib.sha256((args.dataset / args.split / "labels.json").read_bytes()).hexdigest(),
        "image_sha256": image_hashes,
        "settings": {"score_threshold": 0.001, "max_results": 100,
                     "category_selection": "highest_score_per_detection", "delegate": "explicit_cpu",
                     "deduplication": "python_exact_bbox_label_merge"},
        "evaluator_sha256": hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
        "multi_category_detections": multi_category_detections,
        "metrics": score_predictions(labels, predictions),
        "mean_inference_ms_including_first_call": sum(durations) / len(durations),
        "predictions": predictions, "promoted": False,
        "limitations": [
            "Small starter dataset; results do not establish deployment readiness.",
            "Python CPU evaluation does not verify browser or ESP32 compatibility or speed.",
            "Leaves, wood, and rock are not trained categories.",
        ],
    })


def main(argv: list[str] | None = None) -> int:
    parser = ArgumentParser(description=__doc__)
    parser.add_argument("--model", type=Path, required=True)
    parser.add_argument("--dataset", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True, help="New or empty private evaluation directory.")
    parser.add_argument("--split", choices=("validation", "test"), default="test")
    try:
        args = parser.parse_args(argv)
        args.model = args.model.resolve(strict=True)
        args.dataset = args.dataset.resolve(strict=True)
        args.output = args.output.resolve()
        result = evaluate(args)
        args.output.mkdir(parents=True, exist_ok=True)
        write_json_report(args.output / "tflite-evaluation.json", result)
        print(json.dumps(result["metrics"], indent=2))
        print(f"TFLite evaluation saved to {args.output}; model has not been promoted.")
        return 0
    except KeyboardInterrupt:
        print("Evaluation interrupted; no completed result was written.", file=sys.stderr)
        return 1
    except Exception as error:
        print(f"Evaluation failed: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
