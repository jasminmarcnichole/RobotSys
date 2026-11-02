"""Audit a reviewed learning diagnostic without updating or exporting its weights."""

from __future__ import annotations

import argparse
from dataclasses import replace
from datetime import datetime, timezone
from pathlib import Path
import sys
import tempfile
from types import SimpleNamespace
from typing import Any

from train_waste import CLASSES, TrainingError, check_environment, read_json, validate_output, write_json_report

PIPELINES = ("pinned", "cardinality", "full_shuffle")


def controlled_dataset(api: Any, tf: Any, dataset: Any, mode: str) -> Any:
    if mode not in PIPELINES:
        raise TrainingError("Unknown diagnostic input pipeline.")
    if mode == "pinned":
        return dataset
    if (type(dataset.size) is not int or not 20 <= dataset.size <= 40 or dataset.size % 2
            or list(dataset.label_names) != list(CLASSES.values())):
        raise TrainingError("Pipeline controls require a balanced diagnostic-size dataset and its label mapping.")
    records = dataset.gen_tf_dataset(batch_size=1, is_training=False).unbatch().apply(
        tf.data.experimental.assert_cardinality(dataset.size)
    )

    class ControlledDataset(api.Dataset):
        def gen_tf_dataset(self, batch_size: int = 1, is_training: bool = False, shuffle: bool = False,
                           preprocess: Any = None, drop_remainder: bool = False) -> Any:
            if mode == "full_shuffle" and is_training and not shuffle:
                raise TrainingError("Full-shuffle diagnostics require shuffle=True in detector hyperparameters.")
            if mode == "full_shuffle" and is_training and shuffle:
                shuffled = self._dataset.shuffle(self.size, seed=42, reshuffle_each_iteration=True)
                return api.Dataset(dataset=shuffled, label_names=list(self.label_names), size=self.size).gen_tf_dataset(
                    batch_size=batch_size, is_training=is_training, shuffle=False,
                    preprocess=preprocess, drop_remainder=drop_remainder,
                )
            return super().gen_tf_dataset(batch_size=batch_size, is_training=is_training, shuffle=shuffle,
                                          preprocess=preprocess, drop_remainder=drop_remainder)

    return ControlledDataset(dataset=records, label_names=list(dataset.label_names), size=dataset.size)


def batch_accounting(tf: Any, steps: int, output: Path | None = None, *, strict: bool = True) -> Any:
    if type(steps) is not int or not 1 <= steps <= 20 or type(strict) is not bool:
        raise TrainingError("Diagnostic accounting requires 1-20 batches per epoch.")

    class BatchAccounting(tf.keras.callbacks.Callback):
        def __init__(self) -> None:
            super().__init__()
            self.epochs: list[dict[str, Any]] = []

        def on_epoch_begin(self, epoch: int, logs: Any = None) -> None:
            self.started = self.completed = 0
            self.start_iteration = int(self.model.optimizer.iterations.numpy())
            if strict and self.start_iteration != epoch * steps:
                raise TrainingError("Optimizer counter does not match the completed diagnostic epoch budget.")

        def on_train_batch_begin(self, batch: int, logs: Any = None) -> None:
            self.started += 1

        def on_train_batch_end(self, batch: int, logs: Any = None) -> None:
            self.completed += 1

        def on_epoch_end(self, epoch: int, logs: Any = None) -> None:
            end = int(self.model.optimizer.iterations.numpy())
            passed = self.started == self.completed == steps and end - self.start_iteration == steps
            record = {"epoch": epoch, "expected_batches": steps, "started_batches": self.started,
                      "completed_batches": self.completed, "optimizer_iterations_before": self.start_iteration,
                      "optimizer_iterations_after": end, "passed": passed,
                      "scope": "callback_completion_and_counter_not_independent_gradient_verification"}
            self.epochs.append(record)
            if output is not None:
                (output / "batch-accounting").mkdir(parents=True, exist_ok=True)
                write_json_report(output / "batch-accounting" / f"epoch-{epoch + 1:05d}.json", record)
            if strict and not passed:
                raise TrainingError("Diagnostic batch completion and optimizer increments disagree; no epoch may be committed.")

    return BatchAccounting()


def fit_counter_probe(tf: Any, count: int) -> dict[str, Any]:
    results = {}
    for mode in ("unknown", "asserted"):
        tf.keras.utils.set_random_seed(42)
        values = tf.cast(tf.range(count), tf.float32)[:, None] / count
        records = tf.data.Dataset.from_tensor_slices((values, values)).filter(lambda x, y: tf.constant(True))
        before = int(records.cardinality().numpy())
        if mode == "asserted":
            records = records.apply(tf.data.experimental.assert_cardinality(count))
        batches = records.batch(2).take(count // 2)
        model = tf.keras.Sequential([tf.keras.layers.Dense(1, input_shape=(1,))])
        model.compile(optimizer=tf.keras.optimizers.SGD(.01), loss="mse")
        accounting = batch_accounting(tf, count // 2, strict=mode == "asserted")
        model.fit(batches, epochs=3, verbose=0, callbacks=[accounting])
        results[mode] = {"record_cardinality_before": before, "batch_cardinality": int(batches.cardinality().numpy()),
                         "epochs": accounting.epochs, "final_optimizer_iterations": int(model.optimizer.iterations.numpy()),
                         "expected_completed_batches": 3 * count // 2}
    return {"scope": "tiny_dense_model_runtime_control_not_detector_training", "results": results}


def validate_batch_journal(output: Path, epochs: int, steps: int, final_iteration: int) -> dict[str, Any]:
    if (type(epochs) is not int or not 1 <= epochs <= 80 or type(steps) is not int or not 1 <= steps <= 20
            or type(final_iteration) is not int or final_iteration != epochs * steps):
        raise TrainingError("Final optimizer counter does not match the controlled epoch budget.")
    for epoch in range(epochs):
        report = read_json(output, output / "batch-accounting" / f"epoch-{epoch + 1:05d}.json")
        if (report.get("passed") is not True or report.get("epoch") != epoch
                or any(type(report.get(key)) is not int or report[key] != expected for key, expected in (
                    ("expected_batches", steps), ("started_batches", steps), ("completed_batches", steps),
                    ("optimizer_iterations_before", epoch * steps), ("optimizer_iterations_after", (epoch + 1) * steps)))):
            raise TrainingError("Controlled export requires complete, consistent batch-accounting journals.")
    return {"completed_epochs": epochs, "completed_batches": epochs * steps,
            "final_optimizer_iterations": final_iteration, "all_epoch_journals_passed": True,
            "scope": "callback_completion_and_counter_not_independent_gradient_verification"}


def normalization_probe(model: Any, batch: Any) -> dict[str, Any]:
    import numpy as np
    variables = list(model.variables)
    saved = [value.numpy().copy() for value in variables]
    layers = [layer for layer in model.submodules if hasattr(layer, "moving_mean") and hasattr(layer, "moving_variance")]
    try:
        inference = model(batch, training=False)
        training = model(batch, training=True)
        changes = [{"name": variable.name, "max_abs_change": float(np.max(np.abs(variable.numpy() - previous)))}
                   for variable, previous in zip(variables, saved) if not np.array_equal(variable.numpy(), previous)]
        differences = {}
        for field in ("cls_outputs", "box_outputs"):
            differences[field] = max(float(np.max(np.abs(inference[field][key].numpy() - training[field][key].numpy())))
                                     for key in inference[field])
    finally:
        for variable, previous in zip(variables, saved):
            variable.assign(previous)
    restored = all(np.array_equal(variable.numpy(), previous) for variable, previous in zip(variables, saved))
    if not restored:
        raise TrainingError("Normalization probe did not restore the checkpoint's in-memory state.")
    return {"scope": "one_batch_mode_probe_not_causal_attribution", "batch_size": int(batch.shape[0]),
            "batchnorm_layers": len(layers), "trainable_batchnorm_layers": sum(bool(layer.trainable) for layer in layers),
            "changed_variables": changes, "raw_output_max_abs_differences": differences,
            "in_memory_variables_restored": restored, "gradient_updates": 0}


def audit(args: argparse.Namespace) -> dict[str, Any]:
    from diagnose_waste import file_hash, training_preprocessor, validate_snapshot
    reviewed = validate_snapshot(args.dataset)
    validate_output(args.output, args.dataset)
    if args.output == args.run or args.run in args.output.parents or args.output in args.run.parents:
        raise TrainingError("Keep audit output separate from the frozen diagnostic run.")
    metrics = read_json(args.run, args.run / "metrics.json")
    metrics_hash = file_hash(args.run / "metrics.json")
    if (metrics.get("status") != "diagnostic_trained" or metrics.get("diagnostic_only") is not True
            or metrics.get("promoted") is not False or metrics.get("deployment_ready") is not False
            or metrics.get("snapshot_sha256") != reviewed["summary_sha256"]
            or metrics.get("review_sha256") != file_hash(args.dataset / "review.json")):
        raise TrainingError("Audit requires the completed diagnostic checkpoint for this exact reviewed snapshot.")
    expected = metrics.get("models_sha256", {})
    required = ("float_ckpt.index", "float_ckpt.data-00000-of-00001")
    if any(file_hash(args.run / name) != expected.get(name) for name in required):
        raise TrainingError("Diagnostic checkpoint bytes changed.")
    versions = check_environment()
    if versions != metrics["versions"]:
        raise TrainingError("Audit must use the diagnostic's pinned dependencies.")
    import numpy as np
    import tensorflow as tf
    from train_waste import load_training_api
    from mediapipe_model_maker.python.vision.object_detector import model as model_lib, model_options, preprocessor
    from official.vision.ops import preprocess_ops
    tf.keras.utils.set_random_seed(42)
    api = load_training_api()
    spec = replace(api.SupportedModels.get(api.SupportedModels.MOBILENET_V2),
                   downloaded_files=SimpleNamespace(get_path=lambda: str(args.run)), checkpoint_name="float_ckpt")
    processor = training_preprocessor(preprocessor.Preprocessor(spec))
    orders, preprocessing = {}, []
    first_batch = None
    with tempfile.TemporaryDirectory(prefix="learning-audit-") as temporary:
        staging = Path(temporary)
        (staging / "images").symlink_to(args.dataset / "train/data", target_is_directory=True)
        (staging / "labels.json").symlink_to(args.dataset / "train/labels.json")
        data = api.Dataset.from_coco_folder(str(staging), cache_dir=str(staging / "cache"))
        if data.size != len(reviewed["labels"]["images"]):
            raise TrainingError("Audit data membership changed.")
        expected_ids = sorted(str(image["id"]) for image in reviewed["labels"]["images"])
        for mode in PIPELINES:
            controlled = controlled_dataset(api, tf, data, mode)
            shuffle_enabled = mode == "full_shuffle"
            batches = controlled.gen_tf_dataset(batch_size=2, is_training=True, shuffle=shuffle_enabled,
                                                 preprocess=lambda record, is_training: {"source_id": record["source_id"]})
            epochs = []
            for _ in range(3):
                ids = [value.decode() for batch in batches for value in batch["source_id"].numpy().tolist()]
                if sorted(ids) != expected_ids:
                    raise TrainingError("Pipeline dropped, duplicated, or added diagnostic photos.")
                epochs.append(ids)
            orders[mode] = {"batch_cardinality": int(batches.cardinality().numpy()), "epochs": epochs,
                            "shuffle_enabled": shuffle_enabled, "exact_membership_each_epoch": True}
        images = {str(image["id"]): image for image in reviewed["labels"]["images"]}
        for batch in data.gen_tf_dataset(batch_size=1, is_training=False):
            record = tf.nest.map_structure(lambda value: value[0], batch)
            image_id = record["source_id"].numpy().decode()
            processed, _ = processor(record, is_training=True)
            jpeg = tf.io.decode_jpeg((args.dataset / "train/data" / images[image_id]["file_name"]).read_bytes(), channels=3)
            normalized = preprocess_ops.normalize_image(jpeg, spec.mean_norm, spec.stddev_norm)
            evaluated, _ = preprocess_ops.resize_and_crop_image(normalized, [256, 256], padded_size=[256, 256],
                                                               aug_scale_min=1., aug_scale_max=1.)
            preprocessing.append({"image_id": image_id, "max_abs_pixel_error": float(np.max(np.abs(processed.numpy() - evaluated.numpy())))})
            if first_batch is None:
                first_batch = []
            if len(first_batch) < 2:
                first_batch.append(processed)
        counter = fit_counter_probe(tf, data.size)
    model = model_lib.ObjectDetectorModel(spec, model_options.ObjectDetectorModelOptions(), len(CLASSES))
    model.load_checkpoint(str(args.run / "float_ckpt"), include_last_layer=True)
    normalization = normalization_probe(model, tf.stack(first_batch))
    validate_snapshot(args.dataset)
    if (file_hash(args.dataset / "summary.json") != reviewed["summary_sha256"]
            or file_hash(args.dataset / "review.json") != metrics["review_sha256"]
            or file_hash(args.run / "metrics.json") != metrics_hash
            or any(file_hash(args.run / name) != expected[name] for name in required)):
        raise TrainingError("Frozen audit inputs changed during the probe.")
    report = {"status": "learning_pipeline_audited", "created_at": datetime.now(timezone.utc).isoformat(),
              "diagnostic_only": True, "evaluation_scope": "training_resubstitution",
              "snapshot_sha256": reviewed["summary_sha256"], "review_sha256": metrics["review_sha256"],
              "source_metrics_sha256": metrics_hash,
              "checkpoint_sha256": {name: expected[name] for name in required}, "versions": versions,
              "auditor_sha256": file_hash(Path(__file__)), "diagnostic_runner_sha256": file_hash(Path(__file__).with_name("diagnose_waste.py")),
              "pipeline_orders": orders, "preprocessing": preprocessing,
              "preprocessing_exact_match": all(row["max_abs_pixel_error"] == 0 for row in preprocessing),
              "counter_probe": counter, "normalization_probe": normalization,
              "held_out_photos_loaded": False, "promoted": False, "deployment_ready": False,
              "limitations": ["Training photos and a tiny runtime control only; no detector was retrained.",
                              "Train/inference output differences alone do not establish BatchNorm as the accuracy cause.",
                              "Full shuffling preserves membership but does not establish improved detector quality."]}
    args.output.mkdir(parents=True, exist_ok=False)
    write_json_report(args.output / "audit.json", report)
    return report


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ("dataset", "run", "output"):
        parser.add_argument(f"--{name}", type=Path, required=True)
    try:
        args = parser.parse_args()
        args.dataset, args.run = args.dataset.resolve(strict=True), args.run.resolve(strict=True)
        args.output = args.output.resolve()
        result = audit(args)
        print(f"{result['status']}; exact preprocessing match: {result['preprocessing_exact_match']}; no deployment", flush=True)
        return 0
    except (Exception, KeyboardInterrupt) as error:
        print(f"Learning audit failed: {error}; no model was changed or promoted.", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
