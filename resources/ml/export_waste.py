"""Export private class-aware and fast-NMS controls from an existing waste checkpoint."""

from __future__ import annotations

import argparse
import json
import math
import sys
from dataclasses import replace
from datetime import datetime, timezone
from pathlib import Path
from types import SimpleNamespace
from typing import Any

from compare_waste import file_hash
from evaluate_waste import validate_model_labels
from train_waste import (
    CLASSES, ArgumentParser, TrainingError, check_environment, contained_file,
    finite_number, load_training_api, read_json, run_configuration,
    validate_dataset, validate_output, write_json_report,
)


def postprocessing_config(policy: dict[str, Any], regular_nms: bool) -> dict[str, Any]:
    if type(regular_nms) is not bool or policy.get("use_class_agnostic_nms") is not False:
        raise TrainingError("This experiment requires a class-aware training decoder and a boolean NMS mode.")
    score, overlap = policy.get("pre_nms_score_threshold"), policy.get("nms_iou_threshold")
    maximum = policy.get("max_num_detections")
    if (not finite_number(score) or not 0 < score < 1 or not finite_number(overlap) or not 0 < overlap <= 1
            or type(maximum) is not int or not 1 <= maximum <= 100):
        raise TrainingError("Training decoder thresholds and detection limit are invalid or unsupported.")
    return {
        "max_detections": maximum, "max_classes_per_detection": 1,
        "use_regular_nms": regular_nms, "nms_score_threshold": score,
        "nms_iou_threshold": overlap, "normalize_anchor_coordinates": True,
        "omit_nms": False, "input_image_size": (256, 256),
    }


def mask_background_logits(scores: Any) -> Any:
    import tensorflow as tf

    if len(scores.shape) != 4 or scores.shape[-1] is None or int(scores.shape[-1]) % len(CLASSES):
        raise TrainingError("Expected five logits per anchor in a four-dimensional prediction tensor.")
    grouped = tf.reshape(scores, [*scores.shape[:3], -1, len(CLASSES)])
    masked = tf.concat([tf.fill(tf.shape(grouped[..., :1]), tf.cast(-100, scores.dtype)), grouped[..., 1:]], axis=-1)
    return tf.reshape(masked, scores.shape)


def export_module(raw_model: Any, anchors: dict[str, Any], config: dict[str, Any]) -> Any:
    import tensorflow as tf
    from official.vision.modeling.layers import detection_generator

    class PostprocessedDetector(tf.Module):
        def __init__(self) -> None:
            super().__init__()
            self.model = raw_model
            self.anchors = anchors

        @tf.function(input_signature=[tf.TensorSpec([1, 256, 256, 3], tf.float32, name="image")])
        def detect(self, images: Any) -> dict[str, Any]:
            features = self.model.backbone(images, training=False)
            if self.model.decoder is not None:
                features = self.model.decoder(features, training=False)
            scores, boxes, _ = self.model.head(features, training=False)
            scores = {key: mask_background_logits(value) for key, value in scores.items()}
            # The official converter replaces this annotated placeholder with its registered NMS kernel.
            boxes, classes, scores, count = detection_generator._generate_detections_tflite(
                boxes, scores, self.anchors, config.copy(),
            )
            return {"detection_boxes": boxes, "detection_classes": classes,
                    "detection_scores": scores, "num_detections": count}

    return PostprocessedDetector()


def validate_postprocessed_model(model_buffer: bytes, config: dict[str, Any]) -> None:
    from flatbuffers import flexbuffers
    from tensorflow.lite.python import schema_py_generated as schema

    model = schema.Model.GetRootAsModel(model_buffer, 0)
    if model.SubgraphsLength() != 1:
        raise TrainingError("Expected one TFLite subgraph.")
    graph = model.Subgraphs(0)
    if graph.InputsLength() != 1 or graph.OutputsLength() != 4:
        raise TrainingError("Expected one image input and four postprocessed outputs.")
    image = graph.Tensors(graph.Inputs(0))
    if image.ShapeAsNumpy().tolist() != [1, 256, 256, 3] or image.Type() != schema.TensorType.FLOAT32:
        raise TrainingError("Expected a float32 256x256 RGB image input.")
    postprocessors = []
    for index in range(graph.OperatorsLength()):
        operator = graph.Operators(index)
        code = model.OperatorCodes(operator.OpcodeIndex())
        if code.BuiltinCode() == schema.BuiltinOperator.CUSTOM:
            if code.CustomCode() != b"TFLite_Detection_PostProcess":
                raise TrainingError("Export contains an unsupported custom operation.")
            postprocessors.append(operator)
    if len(postprocessors) != 1:
        raise TrainingError("Expected exactly one registered TFLite detection postprocessor.")
    operator = postprocessors[0]
    outputs = operator.OutputsAsNumpy().tolist()
    if set(graph.OutputsAsNumpy().tolist()) != set(outputs) or outputs != sorted(outputs):
        raise TrainingError("Output layout is incompatible with the official detection metadata writer.")
    options = flexbuffers.Loads(operator.CustomOptionsAsNumpy().tobytes())
    expected = {key: config[key] for key in (
        "max_detections", "max_classes_per_detection", "use_regular_nms", "nms_score_threshold", "nms_iou_threshold",
    )}
    expected.update(num_classes=len(CLASSES), y_scale=1.0, x_scale=1.0, h_scale=1.0, w_scale=1.0)
    for key, value in expected.items():
        actual = options.get(key)
        if type(value) is bool:
            # The pinned MLIR converter encodes this boolean custom option as integer 0 or 1.
            valid = type(actual) in (bool, int) and actual == int(value)
        elif type(value) is float:
            valid = finite_number(actual) and math.isclose(actual, value, rel_tol=1e-6, abs_tol=1e-8)
        else:
            valid = type(actual) is type(value) and actual == value
        if not valid:
            raise TrainingError(f"Exported postprocessing option does not match the requested policy: {key} "
                                f"(expected {value!r}, got {actual!r}, type {type(actual).__name__}).")


def validate_source(args: argparse.Namespace, versions: dict[str, str]) -> dict[str, Any]:
    validate_output(args.output, args.dataset)
    if args.output.is_relative_to(args.run) or args.run.is_relative_to(args.output):
        raise TrainingError("Experimental exports and the source run must be separate without nesting.")
    metrics = read_json(args.run, args.run / "metrics.json")
    manifest = read_json(args.run, args.run / "run.json")
    if metrics.get("status") != "trained" or metrics.get("architecture") != "MOBILENET_V2":
        raise TrainingError("A completed MobileNetV2 training run is required.")
    configuration = run_configuration(SimpleNamespace(
        dataset=args.dataset, epochs=metrics["epochs"], batch_size=metrics["batch_size"],
    ), versions)
    if manifest["dataset_sha256"] != configuration["dataset_sha256"] or manifest["versions"] != versions:
        raise TrainingError("The dataset or pinned dependencies do not match the source run.")
    model = contained_file(args.run, args.run / "waste-detector.tflite")
    if file_hash(model) != metrics["model"]["sha256"]:
        raise TrainingError("Source TFLite bytes do not match the training report.")
    checkpoints = [contained_file(args.run, args.run / name) for name in (
        "float_ckpt.index", "float_ckpt.data-00000-of-00001",
    )]
    return {
        "run": str(args.run), "model_sha256": file_hash(model),
        "checkpoint_sha256": {path.name: file_hash(path) for path in checkpoints},
        "metrics_sha256": file_hash(args.run / "metrics.json"), "dataset_sha256": configuration["dataset_sha256"],
    }


def export(args: argparse.Namespace) -> dict[str, Any]:
    versions = check_environment()
    audit = validate_dataset(args.dataset)
    source = validate_source(args, versions)
    args.output.mkdir(parents=True, exist_ok=True)
    report = {
        "status": "exporting", "created_at": datetime.now(timezone.utc).isoformat(),
        "source": source, "versions": versions, "dataset_audit": audit,
        "exporter_sha256": file_hash(Path(__file__)), "models": {},
        "retrained": False, "promoted": False,
    }
    write_json_report(args.output / "export.json", report)
    args.output_owned = True
    api = load_training_api()
    import tensorflow as tf
    from mediapipe.tasks.python.metadata.metadata import MetadataDisplayer
    from mediapipe.tasks.python.metadata.metadata_writers import metadata_writer, object_detector
    from mediapipe_model_maker.python.vision.object_detector import model as model_lib
    from mediapipe_model_maker.python.vision.object_detector import model_options, preprocessor

    spec = replace(api.SupportedModels.get(api.SupportedModels.MOBILENET_V2),
                   downloaded_files=SimpleNamespace(get_path=lambda: str(args.run)), checkpoint_name="float_ckpt")
    model = model_lib.ObjectDetectorModel(spec, model_options.ObjectDetectorModelOptions(), len(CLASSES))
    model.load_checkpoint(str(args.run / "float_ckpt"), include_last_layer=True)
    anchors = {key: tf.expand_dims(value, 0) for key, value in preprocessor.Preprocessor(spec).anchor_boxes.items()}
    policy = model._model.detection_generator.get_config()
    report["training_postprocessing"] = policy
    for mode in ("regular", "fast"):
        config = postprocessing_config(policy, mode == "regular")
        folder = args.output / mode
        folder.mkdir()
        module = export_module(model._model, anchors, config)
        saved = folder / "saved-model"
        tf.saved_model.save(module, str(saved), signatures={"serving_default": module.detect.get_concrete_function()})
        converter = tf.lite.TFLiteConverter.from_saved_model(str(saved))
        converter.target_spec.supported_ops = (tf.lite.OpsSet.TFLITE_BUILTINS,)
        buffer = converter.convert()
        validate_postprocessed_model(buffer, config)
        writer = object_detector.MetadataWriter.create_for_models_with_nms(
            bytearray(buffer), spec.mean_rgb, spec.stddev_rgb,
            labels=metadata_writer.Labels().add(list(CLASSES.values())),
        )
        populated, metadata = writer.populate()
        validate_model_labels(MetadataDisplayer.with_model_buffer(populated))
        path = folder / "waste-detector.tflite"
        with path.open("xb") as destination:
            destination.write(populated)
        write_json_report(folder / "metadata.json", json.loads(metadata))
        report["models"][mode] = {"file": path.relative_to(args.output).as_posix(), "sha256": file_hash(path),
                                  "bytes": path.stat().st_size, "postprocessing": config}
        write_json_report(args.output / "export.json", report, replace=True)
        print(f"Exported {mode} NMS control: {path}", flush=True)
    if (file_hash(args.run / "waste-detector.tflite") != source["model_sha256"]
            or file_hash(args.run / "metrics.json") != source["metrics_sha256"]
            or {name: file_hash(args.run / name) for name in source["checkpoint_sha256"]} != source["checkpoint_sha256"]
            or run_configuration(SimpleNamespace(dataset=args.dataset, epochs=0, batch_size=1), versions)["dataset_sha256"] != source["dataset_sha256"]):
        raise TrainingError("Source artifacts or dataset changed during export.")
    report.update(status="exported", limitations=[
        "Experimental export only; browser and held-out detection performance must be evaluated separately.",
        "Registered TFLite NMS is not bitwise equivalent to Keras pre-NMS top-k filtering and image-boundary clipping.",
        "Background logits are suppressed without changing foreground logits or source checkpoint files.",
        "Class-aware NMS can retain competing labels for the same object; extra detections are not proof of correct classification.",
        "Float32 browser/computer artifact, not an ESP32 deployment model; leaves, wood, and rock remain untrained.",
    ])
    return report


def main(argv: list[str] | None = None) -> int:
    parser = ArgumentParser(description=__doc__)
    parser.add_argument("--run", type=Path, required=True)
    parser.add_argument("--dataset", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = None
    try:
        args = parser.parse_args(argv)
        args.run = args.run.resolve(strict=True)
        args.dataset = args.dataset.resolve(strict=True)
        args.output = args.output.resolve()
        result = export(args)
        write_json_report(args.output / "export.json", result, replace=True)
        print("Experimental exports completed; no training or promotion was performed.")
        return 0
    except (Exception, KeyboardInterrupt) as error:
        if args is not None and getattr(args, "output_owned", False):
            report = read_json(args.output, args.output / "export.json")
            report.update(status="interrupted" if isinstance(error, KeyboardInterrupt) else "failed", error=str(error) or type(error).__name__)
            write_json_report(args.output / "export.json", report, replace=True)
        print(f"Experimental export failed: {error}; source artifacts were not modified.", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
