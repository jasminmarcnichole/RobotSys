"""Run a bounded training-resubstitution diagnostic, never a deployment evaluation."""

from __future__ import annotations

import argparse
from collections import Counter
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import shutil
import sys
import tempfile
from types import SimpleNamespace
from typing import Any

from prepare_hitl import COLLECTION, approved_image, load_provenance, source_split
from train_waste import (
    CLASSES, ArgumentParser, TrainingError, check_environment, checkpoint_detector,
    contained_file, integer, load_training_api, read_json,
    run_configuration, validate_dataset, validate_output, validate_training_use, write_json_report,
)

SCOPE = "training_resubstitution"


def file_hash(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def validate_snapshot(root: Path, *, require_review: bool = True) -> dict[str, Any]:
    root = root.resolve(strict=True)
    summary = read_json(root, root / "summary.json")
    labels_path = contained_file(root, root / "train/labels.json")
    labels = read_json(root, labels_path)
    proof = load_provenance(root)
    if (summary.get("schema_version") != 1 or summary.get("status") != "diagnostic_selected"
            or summary.get("diagnostic_only") is not True or summary.get("evaluation_only") is not True
            or summary.get("full_dataset") is not False or summary.get("promoted") is not False
            or summary.get("evaluation_scope") != SCOPE or proof is None
            or file_hash(root / "hitl-provenance.json") != summary.get("provenance_sha256")
            or file_hash(labels_path) != summary.get("labels_sha256")
            or labels.get("categories") != [{"id": key, "name": value} for key, value in CLASSES.items()]):
        raise TrainingError("Expected an unchanged, explicitly diagnostic training snapshot.")
    images, annotations = labels.get("images", []), labels.get("annotations", [])
    per_class = summary.get("photos_per_class")
    if (type(per_class) is not int or not 5 <= per_class <= 10 or len(images) != 4 * per_class
            or len(annotations) != len(images) or len({image.get("id") for image in images}) != len(images)
            or len({item.get("id") for item in annotations}) != len(annotations)):
        raise TrainingError("Diagnostic selection needs 20-40 unique single-object photos, balanced across four classes.")
    counts, hashes = Counter(), {}
    for image in images:
        image_id = image.get("id")
        selected = [item for item in annotations if item.get("image_id") == image_id]
        if (not integer(image_id) or image.get("file_name") != f"{image_id}.jpg" or len(selected) != 1
                or selected[0].get("category_id") not in (1, 2, 3, 4)
                or image.get("source_collection") != COLLECTION
                or source_split(image.get("group_id", "").split(":")[-1]) != "train"):
            raise TrainingError("Diagnostic selection contains a held-out, unsupported, or ambiguous image.")
        path = contained_file(root, root / "train/data" / image["file_name"])
        if not approved_image(image, path, proof, "train", annotations):
            raise TrainingError("Diagnostic source labels, license, can review, or photo bytes changed.")
        hashes[str(image_id)] = file_hash(path)
        counts[CLASSES[selected[0]["category_id"]]] += 1
    if (hashes != summary.get("image_sha256") or len(set(hashes.values())) != len(images)
            or counts != Counter(dict.fromkeys(tuple(CLASSES.values())[1:], per_class))):
        raise TrainingError("Diagnostic photo hashes or class coverage changed.")
    if require_review:
        review = read_json(root, root / "review.json")
        accepted = review.get("accepted_image_ids")
        if (review.get("status") != "accepted" or review.get("summary_sha256") != file_hash(root / "summary.json")
                or not isinstance(review.get("reviewer"), str) or not review["reviewer"].strip()
                or not isinstance(accepted, list) or any(not integer(value) for value in accepted)
                or len(accepted) != len(images) or set(accepted) != {image["id"] for image in images}):
            raise TrainingError("Every diagnostic photo needs a hash-bound visual review before training or benchmarking.")
    return {"summary": summary, "labels": labels, "summary_sha256": file_hash(root / "summary.json"),
            "counts": {"train": {"images": len(images), "annotations": len(annotations), "annotations_by_class": dict(counts)}},
            "diagnostic_only": True, "evaluation_scope": SCOPE}


def prepare(args: argparse.Namespace) -> dict[str, Any]:
    from PIL import Image, ImageDraw, ImageFont, ImageOps

    validate_training_use(args.dataset)
    validate_dataset(args.dataset)
    validate_output(args.output, args.dataset)
    count = args.photos_per_class
    if type(count) is not int or not 5 <= count <= 10:
        raise TrainingError("Choose 5-10 photos per class for a bounded diagnostic.")
    proof = load_provenance(args.dataset)
    if proof is None:
        raise TrainingError("Reviewed HITL training provenance is required for this diagnostic.")
    labels_path = args.dataset / "train/labels.json"
    labels = read_json(args.dataset, labels_path)
    excluded = getattr(args, "exclude_image", None) or []
    if (not isinstance(excluded, list) or len(excluded) > 20 or len(set(excluded)) != len(excluded)
            or any(not integer(value) for value in excluded)
            or not set(excluded).issubset({image["id"] for image in labels["images"]})):
        raise TrainingError("Diagnostic exclusions must name at most 20 unique training-image IDs.")
    selected = []
    for category in (1, 2, 3, 4):
        candidates = []
        for image in labels["images"]:
            boxes = [item for item in labels["annotations"] if item["image_id"] == image["id"]]
            if image["id"] in excluded or len(boxes) != 1 or boxes[0]["category_id"] != category or image.get("source_collection") != COLLECTION:
                continue
            box = boxes[0]["bbox"]
            area = box[2] * box[3] / (image["width"] * image["height"])
            if area >= .04:
                candidates.append((area, image))
        candidates.sort(key=lambda row: (-row[0], row[1]["id"]))
        if len(candidates) < count:
            raise TrainingError(f"Not enough single-object training photos with >=4% box area for {CLASSES[category]}.")
        selected.extend(image for _, image in candidates[:count])
    ids = {image["id"] for image in selected}
    subset = {**labels, "images": selected, "annotations": [a for a in labels["annotations"] if a["image_id"] in ids]}
    base_hash = run_configuration(SimpleNamespace(dataset=args.dataset, epochs=1, batch_size=2), {})["dataset_sha256"]
    args.output.mkdir(parents=True, exist_ok=True)
    (args.output / "train/data").mkdir(parents=True)
    for image in selected:
        shutil.copy2(args.dataset / "train/data" / image["file_name"], args.output / "train/data" / image["file_name"])
    write_json_report(args.output / "train/labels.json", subset)
    retained = {image["source_image_id"] for image in selected}
    write_json_report(args.output / "hitl-provenance.json", {**proof, "diagnostic_only": True, "evaluation_only": True,
        "images": {key: entry for key, entry in proof["images"].items() if key in retained}})
    summary = {"schema_version": 1, "status": "diagnostic_selected", "full_dataset": False,
        "diagnostic_only": True, "evaluation_only": True, "evaluation_scope": SCOPE, "promoted": False,
        "photos_per_class": count, "source_dataset": str(args.dataset), "source_dataset_sha256": base_hash,
        "excluded_training_image_ids": excluded,
        "source_train_labels_sha256": file_hash(labels_path), "labels_sha256": file_hash(args.output / "train/labels.json"),
        "provenance_sha256": file_hash(args.output / "hitl-provenance.json"),
        "image_sha256": {str(image["id"]): file_hash(args.output / "train/data" / image["file_name"]) for image in selected},
        "selection_policy": "Train-group HITL photos only; one supported object per photo; box >=4% area; descending box area then ID; visual review still required.",
        "held_out_photos_used": False, "limitations": ["Deliberately easy, balanced training subset; cannot measure generalization or deployment readiness."]}
    write_json_report(args.output / "summary.json", summary)
    validate_snapshot(args.output, require_review=False)
    font = ImageFont.load_default(size=15)
    for start in range(0, len(selected), 12):
        canvas = Image.new("RGB", (1536, 1080), "white")
        for offset, image in enumerate(selected[start:start + 12]):
            annotation = next(a for a in subset["annotations"] if a["image_id"] == image["id"])
            left, top = (offset % 4) * 384 + 6, (offset // 4) * 360 + 6
            with Image.open(args.output / "train/data" / image["file_name"]) as original:
                thumb = ImageOps.contain(original.convert("RGB"), (370, 305))
            canvas.paste(thumb, (left, top + 42))
            draw = ImageDraw.Draw(canvas)
            draw.text((left, top), f"{image['id']} / {CLASSES[annotation['category_id']]}", fill="black", font=font)
            x, y, width, height = annotation["bbox"]
            sx, sy = thumb.width / image["width"], thumb.height / image["height"]
            draw.rectangle((left+x*sx, top+42+y*sy, left+(x+width)*sx, top+42+(y+height)*sy), outline="lime", width=2)
        canvas.save(args.output / f"review-{start//12+1}.png")
    return summary


def training_preprocessor(processor: Any) -> Any:
    class DeterministicPreprocessor:
        @property
        def anchor_boxes(self) -> Any:
            return processor.anchor_boxes

        def __call__(self, data: Any, is_training: bool = True) -> Any:
            return processor(data, is_training=False)
    return DeterministicPreprocessor()


def evaluate_checkpoint(model: Any, spec: Any, dataset: Path, output: Path) -> dict[str, Any]:
    import numpy as np
    import tensorflow as tf
    from official.vision.ops import preprocess_ops
    from mediapipe_model_maker.python.vision.object_detector import preprocessor
    from compare_waste import keras_predictions, postprocessed_predictions
    from evaluate_waste import score_predictions
    from calibrate_waste import detection_summary

    audit = validate_snapshot(dataset)
    labels = audit["labels"]
    processor = preprocessor.Preprocessor(spec)
    anchors = {key: tf.expand_dims(value, 0) for key, value in processor.anchor_boxes.items()}
    raw = tf.lite.Interpreter(model_path=str(output / "waste-detector.tflite"), num_threads=2)
    regular = tf.lite.Interpreter(model_path=str(output / "regular/waste-detector.tflite"), num_threads=1)
    for interpreter in (raw, regular):
        interpreter.allocate_tensors()
    outputs = {int(item["shape"][-1]): item for item in raw.get_output_details()}
    if set(outputs) != {4, 5}:
        raise TrainingError("Expected raw box offsets and five class scores.")
    predictions = {"keras_native": [], "tflite_regular_exact_input": []}
    differences = []
    for image in labels["images"]:
        pixels = tf.io.decode_jpeg((dataset / "train/data" / image["file_name"]).read_bytes(), channels=3)
        normalized = preprocess_ops.normalize_image(pixels, spec.mean_norm, spec.stddev_norm)
        padded, info = preprocess_ops.resize_and_crop_image(normalized, [256, 256], padded_size=[256, 256], aug_scale_min=1., aug_scale_max=1.)
        batch = tf.expand_dims(padded, 0)
        native = model._model(batch, image_shape=info[1:2], anchor_boxes=anchors, training=False)
        predictions["keras_native"].extend(keras_predictions(native, image, info[2].numpy().tolist()))
        raw.set_tensor(raw.get_input_details()[0]["index"], batch.numpy())
        raw.invoke()
        levels = sorted(native["box_outputs"], key=int)
        expected_boxes = tf.concat([tf.reshape(native["box_outputs"][key], [1, -1, 4]) for key in levels], axis=1).numpy()
        expected_scores = tf.sigmoid(tf.concat([tf.reshape(native["cls_outputs"][key], [1, -1, 5]) for key in levels], axis=1)).numpy()
        differences.append({"image_id": image["id"],
            "box_offsets_max_abs_error": float(np.max(np.abs(raw.get_tensor(outputs[4]["index"]) - expected_boxes))),
            "scores_max_abs_error": float(np.max(np.abs(raw.get_tensor(outputs[5]["index"]) - expected_scores)))})
        regular.set_tensor(regular.get_input_details()[0]["index"], batch.numpy())
        regular.invoke()
        tensors = [regular.get_tensor(item["index"]).tolist() for item in sorted(regular.get_output_details(), key=lambda item: item["index"])]
        predictions["tflite_regular_exact_input"].extend(postprocessed_predictions(tensors, image, info[2].numpy().tolist()))
        print(f"Training-resubstitution parity: image {image['id']}", flush=True)
    return {"evaluation_scope": SCOPE, "diagnostic_only": True, "split": "train", "promoted": False,
        "raw_output_differences": differences,
        "raw_parity_passed": all(row["box_offsets_max_abs_error"] <= .001 and row["scores_max_abs_error"] <= .0001 for row in differences),
        "metrics": {key: score_predictions(labels, values) for key, values in predictions.items()},
        "fixed_threshold": {key: {str(t): detection_summary(labels, values, t) for t in (.2, .5)} for key, values in predictions.items()},
        "predictions": predictions}


def train(args: argparse.Namespace) -> dict[str, Any]:
    from audit_learning import PIPELINES, batch_accounting, controlled_dataset, validate_batch_journal
    audit = validate_snapshot(args.dataset)
    if type(args.epochs) is not int or not 1 <= args.epochs <= 80:
        raise TrainingError("Diagnostic training is bounded to 1-80 epochs.")
    pipeline = getattr(args, "input_pipeline", "pinned")
    if pipeline not in PIPELINES:
        raise TrainingError("Unknown diagnostic input pipeline.")
    validate_output(args.output, args.dataset, args.resume)
    versions = check_environment()
    configuration = {"schema_version": 1, "diagnostic_only": True, "evaluation_scope": SCOPE,
        "snapshot_sha256": audit["summary_sha256"], "review_sha256": file_hash(args.dataset / "review.json"),
        "runner_sha256": file_hash(Path(__file__)), "versions": versions, "epochs": args.epochs,
        "batch_size": 2, "seed": 42, "augmentation": False, "learning_rate": .3,
        "cosine_decay_alpha": 1., "checkpoint_selection": "final_completed_epoch_not_validation_selection",
        "fit_validation_input": "same_training_subset_for_resubstitution_only", "held_out_photos_loaded": False,
        "promoted": False}
    if pipeline != "pinned":
        configuration["input_pipeline"] = {"mode": pipeline, "asserted_cardinality": True,
                                            "shuffle": pipeline == "full_shuffle",
                                            "shuffle_buffer": len(audit["labels"]["images"]) if pipeline == "full_shuffle" else None,
                                            "seed": 42, "strict_batch_accounting": True,
                                            "implementation_sha256": file_hash(Path(__file__).with_name("audit_learning.py"))}
    if args.resume and read_json(args.output, args.output / "run.json") != configuration:
        raise TrainingError("Diagnostic resume requires identical reviewed data, source, dependencies, and settings.")
    if args.resume and (args.output / "metrics.json").exists():
        raise TrainingError("Completed diagnostic runs cannot be resumed or overwritten.")
    api = load_training_api()
    import tensorflow as tf
    from mediapipe.tasks.python.metadata.metadata import MetadataDisplayer
    from mediapipe.tasks.python.metadata.metadata_writers import metadata_writer, object_detector
    from evaluate_waste import validate_model_labels
    from export_waste import export_module, postprocessing_config, validate_postprocessed_model

    tf.keras.utils.set_random_seed(42)
    if args.resume:
        checkpoint = tf.train.latest_checkpoint(str(args.output / "backup/chief"))
        if not checkpoint or not 1 <= int(tf.train.load_variable(checkpoint, "ckpt_saved_epoch/.ATTRIBUTES/VARIABLE_VALUE")) + 1 <= args.epochs:
            raise TrainingError("Diagnostic resume requires a recoverable completed-epoch backup.")
    args.output.mkdir(parents=True, exist_ok=True)
    if not args.resume:
        write_json_report(args.output / "run.json", configuration)
    base = checkpoint_detector(api, tf, args.output, args.resume)
    class DiagnosticDetector(base):
        def __init__(self, *values: Any, **kwargs: Any) -> None:
            super().__init__(*values, **kwargs)
            self._preprocessor = training_preprocessor(self._preprocessor)
            if pipeline != "pinned":
                self._batch_accounting = batch_accounting(tf, len(audit["labels"]["images"]) // 2, args.output)
                self._callbacks.insert(0, self._batch_accounting)
    with tempfile.TemporaryDirectory(prefix="diagnostic-inputs-", dir=args.output) as temporary:
        staging = Path(temporary)
        (staging / "images").symlink_to(args.dataset / "train/data", target_is_directory=True)
        (staging / "labels.json").symlink_to(args.dataset / "train/labels.json")
        data = api.Dataset.from_coco_folder(str(staging), cache_dir=str(staging / "cache"))
        if data.size != len(audit["labels"]["images"]) or list(data.label_names) != list(CLASSES.values()):
            raise TrainingError("Model Maker changed diagnostic source membership or labels.")
        data = controlled_dataset(api, tf, data, pipeline)
        options = api.ObjectDetectorOptions(supported_model=api.SupportedModels.MOBILENET_V2,
            hparams=api.HParams(epochs=args.epochs, batch_size=2, export_dir=str(args.output),
                learning_rate=.3, cosine_decay_epochs=args.epochs, cosine_decay_alpha=1.,
                shuffle=pipeline == "full_shuffle"))
        model = DiagnosticDetector.create(train_data=data, validation_data=data, options=options)
        backup = tf.train.latest_checkpoint(str(args.output / "backup/chief"))
        if not backup or int(tf.train.load_variable(backup, "ckpt_saved_epoch/.ATTRIBUTES/VARIABLE_VALUE")) + 1 != args.epochs:
            raise TrainingError("Diagnostic fitting did not commit the complete fixed epoch budget.")
        if pipeline != "pinned":
            configuration["batch_accounting"] = validate_batch_journal(
                args.output, args.epochs, len(audit["labels"]["images"]) // 2,
                int(model._model.optimizer.iterations.numpy()),
            )
        model.export_model(model_name="waste-detector.tflite")
    spec = api.SupportedModels.get(api.SupportedModels.MOBILENET_V2)
    config = postprocessing_config(model._model._model.detection_generator.get_config(), True)
    anchors = {key: tf.expand_dims(value, 0) for key, value in model._preprocessor.anchor_boxes.items()}
    folder = args.output / "regular"
    folder.mkdir()
    module = export_module(model._model._model, anchors, config)
    tf.saved_model.save(module, str(folder / "saved-model"), signatures={"serving_default": module.detect.get_concrete_function()})
    converter = tf.lite.TFLiteConverter.from_saved_model(str(folder / "saved-model"))
    converter.target_spec.supported_ops = (tf.lite.OpsSet.TFLITE_BUILTINS,)
    buffer = converter.convert()
    validate_postprocessed_model(buffer, config)
    writer = object_detector.MetadataWriter.create_for_models_with_nms(bytearray(buffer), spec.mean_rgb, spec.stddev_rgb,
        labels=metadata_writer.Labels().add(list(CLASSES.values())))
    populated, metadata = writer.populate()
    validate_model_labels(MetadataDisplayer.with_model_buffer(populated))
    with (folder / "waste-detector.tflite").open("xb") as destination:
        destination.write(populated)
    write_json_report(folder / "metadata.json", json.loads(metadata))
    result = evaluate_checkpoint(model, spec, args.dataset, args.output)
    validate_snapshot(args.dataset)
    if file_hash(args.dataset / "summary.json") != configuration["snapshot_sha256"] or file_hash(args.dataset / "review.json") != configuration["review_sha256"]:
        raise TrainingError("Diagnostic source review changed during training.")
    return {"status": "diagnostic_trained", "created_at": datetime.now(timezone.utc).isoformat(),
        **configuration, "result": result, "models_sha256": {name: file_hash(args.output / name) for name in (
            "waste-detector.tflite", "regular/waste-detector.tflite", "float_ckpt.index", "float_ckpt.data-00000-of-00001")},
        "deployment_ready": False, "limitations": ["Training-resubstitution results only, not validation or live accuracy.",
            "Augmentation disabled and easy examples selected intentionally; this model must not be promoted.",
            "Keras fit val_* logs refer to the same training subset, not held-out data.",
            "Native exact-input NMS and actual browser preprocessing are separate checks."]}


def main() -> int:
    parser = ArgumentParser(description=__doc__)
    parser.add_argument("--dataset", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--prepare", action="store_true")
    parser.add_argument("--photos-per-class", type=int, default=6)
    parser.add_argument("--exclude-image", type=int, action="append", help="Exclude a visually ambiguous training image; repeat up to 20 times.")
    parser.add_argument("--epochs", type=int, default=40)
    parser.add_argument("--resume", action="store_true")
    parser.add_argument("--input-pipeline", choices=("pinned", "cardinality", "full_shuffle"), default="pinned")
    try:
        args = parser.parse_args()
        args.dataset, args.output = args.dataset.resolve(strict=True), args.output.resolve()
        if args.prepare and args.resume:
            raise TrainingError("Selection preparation cannot resume or overwrite a snapshot.")
        if args.prepare and args.input_pipeline != "pinned":
            raise TrainingError("Pipeline controls apply to fitting, not snapshot preparation.")
        result = prepare(args) if args.prepare else train(args)
        if not args.prepare:
            write_json_report(args.output / "metrics.json", result)
        print(json.dumps({key: result[key] for key in ("status", "diagnostic_only", "evaluation_scope", "promoted")}, indent=2))
        return 0
    except (Exception, KeyboardInterrupt) as error:
        print(f"Training diagnostic failed: {error}; partial artifacts were preserved and no model was promoted.", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
