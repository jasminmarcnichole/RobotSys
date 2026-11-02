"""Train an official MediaPipe Model Maker detector from prepared waste data."""

from __future__ import annotations

import argparse
import csv
import hashlib
import importlib
import importlib.metadata
import json
import math
import os
import platform
import re
import subprocess
import sys
import tempfile
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path
from typing import Any
from urllib.parse import urlparse

from prepare_waste import approved_license
from prepare_realdirt import approved_image, load_provenance
from prepare_hitl import (
    COLLECTION as HITL_COLLECTION,
    approved_image as approved_hitl_image,
    load_provenance as load_hitl_provenance,
)


CLASSES = {
    0: "background",
    1: "plastic_bottle",
    2: "other_plastic",
    3: "plastic_wrapper",
    4: "paper",
    5: "metal_can",
    6: "aluminum_can",
}
SPLITS = ("train", "validation", "test")
MODULES = (
    "tensorflow", "tensorflow_text", "official", "tensorflow_hub",
    "tensorflow_datasets", "tensorflow_metadata", "mediapipe", "numpy",
    "tensorflow_model_optimization", "tensorflow_addons", "mediapipe_model_maker",
    "keras",
)


class TrainingError(Exception):
    """An actionable preflight or training failure."""


class TFLiteEvaluationError(TrainingError):
    def __init__(self, message: str, diagnostics: dict[str, Any]) -> None:
        super().__init__(message)
        self.diagnostics = diagnostics.copy()


class ArgumentParser(argparse.ArgumentParser):
    def error(self, message: str) -> None:
        raise TrainingError(message)


def positive_int(value: str) -> int:
    number = int(value)
    if number < 1:
        raise argparse.ArgumentTypeError("must be a positive integer")
    return number


def nonnegative_float(value: str) -> float:
    number = float(value)
    if not math.isfinite(number) or number < 0:
        raise argparse.ArgumentTypeError("must be a finite nonnegative number")
    return number


def positive_float(value: str) -> float:
    number = nonnegative_float(value)
    if number == 0:
        raise argparse.ArgumentTypeError("must be a finite positive number")
    return number


def unit_interval_float(value: str) -> float:
    number = nonnegative_float(value)
    if number > 1:
        raise argparse.ArgumentTypeError("must be between 0 and 1")
    return number


def class_repeat(value: str) -> tuple[str, int]:
    name, separator, factor = value.partition("=")
    if not separator or name not in tuple(CLASSES.values())[1:]:
        raise argparse.ArgumentTypeError("use a foreground class and factor, for example paper=2")
    try:
        repeat = int(factor)
    except ValueError as error:
        raise argparse.ArgumentTypeError("repeat factor must be an integer from 1 to 3") from error
    if not 1 <= repeat <= 3:
        raise argparse.ArgumentTypeError("repeat factor must be an integer from 1 to 3")
    return name, repeat


def image_repeat(value: str) -> tuple[int, int]:
    identifier, separator, factor = value.partition("=")
    try:
        image_id, repeat = int(identifier), int(factor)
    except ValueError as error:
        raise argparse.ArgumentTypeError("use an image ID and integer factor, for example 123=2") from error
    if not separator or image_id < 0 or not 1 <= repeat <= 3:
        raise argparse.ArgumentTypeError("image ID must be nonnegative and repeat factor must be from 1 to 3")
    return image_id, repeat


def check_environment() -> dict[str, str]:
    if platform.system() != "Linux":
        raise TrainingError(
            "Training requires Linux with CPython 3.11. Windows requires Linux "
            "(a Linux machine, container, or configured WSL distribution); "
            "tensorflow-text 2.15.0 has no compatible native Windows wheel."
        )
    if sys.version_info[:2] != (3, 11) or platform.python_implementation() != "CPython":
        raise TrainingError("This pinned training environment requires CPython 3.11 exactly.")

    versions = {}
    problems = []
    requirements = Path(__file__).with_name("requirements-train.txt")
    for line in requirements.read_text(encoding="utf-8").splitlines():
        if not line.strip() or line.startswith("#"):
            continue
        package, expected = line.split("==", 1)
        try:
            actual = importlib.metadata.version(package)
        except importlib.metadata.PackageNotFoundError:
            problems.append(f"{package}=={expected} is missing")
            continue
        versions[package] = actual
        if actual != expected:
            problems.append(f"{package}=={expected} is required, found {actual}")
    if problems:
        raise TrainingError(
            "Training dependency preflight failed: " + "; ".join(problems)
            + ". Use an isolated Linux Python 3.11 environment with requirements-train.txt."
        )
    return versions


def load_training_api() -> Any:
    try:
        for module in MODULES:
            importlib.import_module(module)
        from mediapipe_model_maker import object_detector

        return object_detector
    except Exception as error:
        raise TrainingError(f"Training dependency import failed: {error}") from error


def contained_file(root: Path, path: Path) -> Path:
    resolved = path.resolve(strict=True)
    if not resolved.is_relative_to(root) or not resolved.is_file():
        raise TrainingError(f"Dataset file must resolve inside {root}: {path}")
    return resolved


def read_json(root: Path, path: Path) -> dict[str, Any]:
    with contained_file(root, path).open(encoding="utf-8") as source:
        data = json.load(source)
    if not isinstance(data, dict):
        raise TrainingError(f"Expected a JSON object in {path}")
    return data


def validate_training_use(dataset: Path) -> None:
    root = dataset.resolve(strict=True)
    for name in ("summary.json", "hitl-provenance.json"):
        if (root / name).exists() and read_json(root, root / name).get("evaluation_only") is True:
            raise TrainingError("Evaluation-only dataset cannot be used for training or checkpoint selection; use the original training base.")


def integer(value: Any) -> bool:
    return type(value) is int and value >= 0


def finite_number(value: Any) -> bool:
    return type(value) in (int, float) and math.isfinite(value)


def image_identities(image: dict[str, Any], path: Path) -> set[tuple[str, str]]:
    with path.open("rb") as source:
        digest = hashlib.file_digest(source, "sha256").hexdigest()
    identities = {("sha256", digest), ("image_id", str(image["id"]))}
    for field in ("group_id", "source_image_id", "flickr_id", "flickr_photo_id", "source_photo_id"):
        value = image.get(field)
        if value is not None:
            if type(value) not in (str, int) or not str(value).strip():
                raise TrainingError(f"Invalid {field} on image {image['id']}")
            key = "flickr_photo_id" if field.startswith("flickr_") or field == "source_photo_id" else field
            identities.add((key, str(value)))
    for field in ("flickr_url", "flickr_640_url", "flickr_1024_url", "source_url", "coco_url", "download_url"):
        value = image.get(field)
        if not isinstance(value, str) or not value:
            continue
        parsed = urlparse(value)
        hostname = (parsed.hostname or "").lower()
        photo_id = None
        if hostname == "staticflickr.com" or hostname.endswith(".staticflickr.com"):
            photo_id = re.match(r"(\d+)_", Path(parsed.path).name)
        elif hostname in ("flickr.com", "www.flickr.com"):
            photo_id = re.search(r"/photos/[^/]+/(\d+)", parsed.path)
        if photo_id:
            identities.add(("flickr_photo_id", photo_id.group(1)))
        identities.add(("source_url", value))
    return identities


def validate_dataset(dataset: Path) -> dict[str, Any]:
    root = dataset.resolve(strict=True)
    summary = read_json(root, root / "summary.json")
    if summary.get("status") != "prepared" or summary.get("full_dataset") is not True:
        raise TrainingError("Dataset is incomplete: summary.json must declare status 'prepared' and full_dataset true.")

    seen: dict[tuple[str, str], str] = {}
    realdirt_provenance = load_provenance(root)
    hitl_provenance = load_hitl_provenance(root)
    counts = {}
    grouped_images = 0
    for split in SPLITS:
        data = read_json(root, root / split / "labels.json")
        categories = data.get("categories")
        if (
            not isinstance(categories, list)
            or len(categories) != len(CLASSES)
            or any(not isinstance(item, dict) or not integer(item.get("id")) for item in categories)
            or {item["id"]: item.get("name") for item in categories} != CLASSES
        ):
            raise TrainingError(f"{split}: categories must match background (0) and the six waste classes (1-6).")
        images = data.get("images")
        annotations = data.get("annotations")
        if not isinstance(images, list) or not images or not isinstance(annotations, list) or not annotations:
            raise TrainingError(f"{split}: nonempty images and annotations are required.")
        image_map = {}
        for image in images:
            if not isinstance(image, dict) or not integer(image.get("id")):
                raise TrainingError(f"{split}: image IDs must be nonnegative integers.")
            image_id = image["id"]
            photo_id = image.get("source_photo_id")
            attribution = image.get("attribution")
            if image_id in image_map:
                raise TrainingError(f"{split}: duplicate image ID {image_id}.")
            if image.get("file_name") != f"{image_id}.jpg":
                raise TrainingError(f"{split}: image {image_id} file_name must be '{image_id}.jpg' inside data/.")
            if any(not integer(image.get(axis)) or image[axis] == 0 for axis in ("width", "height")):
                raise TrainingError(f"{split}: image {image_id} requires positive integer dimensions.")
            path = contained_file(root, root / split / "data" / image["file_name"])
            flickr_approved = (isinstance(photo_id, str) and photo_id.isdigit()
                               and isinstance(attribution, dict) and approved_license(photo_id, attribution))
            if image.get("source_collection") == HITL_COLLECTION:
                license_approved = approved_hitl_image(image, path, hitl_provenance, split, annotations)
            elif image.get("source_collection") is not None:
                license_approved = approved_image(image, path, realdirt_provenance, split)
            else:
                license_approved = flickr_approved
            if not license_approved:
                raise TrainingError(f"{split}: image {image_id} has no verified acceptable license and attribution.")
            with path.open("rb") as source:
                if source.read(3) != b"\xff\xd8\xff":
                    raise TrainingError(f"{split}: image {image_id} is not a JPEG.")
            identities = image_identities(image, path)
            if any(kind in ("group_id", "flickr_photo_id") for kind, _ in identities):
                grouped_images += 1
            for identity in identities:
                previous = seen.get(identity)
                if previous is not None and previous != split:
                    raise TrainingError(f"Split leakage: {identity[0]} {identity[1]} appears in {previous} and {split}.")
                seen[identity] = split
            image_map[image_id] = image

        annotation_ids = set()
        annotated_images = set()
        class_counts: Counter[int] = Counter()
        for annotation in annotations:
            if not isinstance(annotation, dict) or not integer(annotation.get("id")):
                raise TrainingError(f"{split}: annotation IDs must be nonnegative integers.")
            if annotation["id"] in annotation_ids:
                raise TrainingError(f"{split}: duplicate annotation ID {annotation['id']}.")
            annotation_ids.add(annotation["id"])
            image_id = annotation.get("image_id")
            category_id = annotation.get("category_id")
            if not integer(image_id) or image_id not in image_map:
                raise TrainingError(f"{split}: annotation references an unknown image.")
            if not integer(category_id) or category_id not in (1, 2, 3, 4, 5, 6):
                raise TrainingError(f"{split}: annotations must reference waste categories 1-6.")
            bbox = annotation.get("bbox")
            if not isinstance(bbox, list) or len(bbox) != 4 or not all(finite_number(value) for value in bbox):
                raise TrainingError(f"{split}: annotation {annotation['id']} has an invalid bbox.")
            x, y, width, height = bbox
            image = image_map[image_id]
            if x < 0 or y < 0 or width <= 0 or height <= 0 or x + width > image["width"] + 1e-6 or y + height > image["height"] + 1e-6:
                raise TrainingError(f"{split}: annotation {annotation['id']} bbox is outside its image.")
            if annotation.get("iscrowd", 0) != 0:
                raise TrainingError(f"{split}: crowd annotations cannot establish individual waste class coverage.")
            class_counts[category_id] += 1
            annotated_images.add(image_id)
        missing = [CLASSES[key] for key in (1, 2, 3, 4, 5, 6) if not class_counts[key]]
        if missing:
            raise TrainingError(f"{split}: missing annotations for {', '.join(missing)}.")
        if annotated_images != set(image_map):
            raise TrainingError(f"{split}: images without annotations would be silently skipped by Model Maker.")
        counts[split] = {
            "images": len(images), "annotations": len(annotations),
            "annotations_by_class": {CLASSES[key]: class_counts[key] for key in (1, 2, 3, 4, 5, 6)},
        }

    total_images = sum(split["images"] for split in counts.values())
    licensed = summary.get("license_verified_images")
    if not integer(licensed) or licensed != total_images:
        raise TrainingError("Dataset is incomplete: license_verified_images must equal the total prepared image count.")
    return {
        "counts": counts,
        "license_verified_images": licensed,
        "license_verification_source": "per-photo Flickr records or pinned RealDirt/HITL publisher provenance; not re-fetched during training",
        "images_with_group_or_flickr_identity": grouped_images,
        "split_leakage_checks": ["image ID", "SHA-256", "available group/source/Flickr identities"],
        "split_leakage_limitations": "Re-encoded duplicates cannot be ruled out when group or Flickr identity is absent.",
    }


def validate_output(output: Path, dataset: Path, resume: bool = False) -> None:
    public = (Path(__file__).resolve().parents[2] / "public").resolve()
    if output.is_relative_to(dataset) or dataset.is_relative_to(output):
        raise TrainingError("Output and dataset directories must be separate, without nesting.")
    if output.is_relative_to(public):
        raise TrainingError("Training output must be outside public/; promotion requires separate validation.")
    if resume:
        if not output.is_dir() or not (output / "run.json").is_file():
            raise TrainingError("Resume requires an existing run.json from a checkpoint-enabled run.")
        if (output / "metrics.json").exists():
            raise TrainingError("This run has already completed; use a new output directory.")
    elif output.exists() and (not output.is_dir() or any(output.iterdir())):
        raise TrainingError("Output must be a new or empty directory to avoid mixing training runs.")


def training_hyperparameters(args: argparse.Namespace) -> dict[str, Any]:
    settings = {
        "learning_rate": getattr(args, "learning_rate", 0.3),
        "cosine_decay_epochs": getattr(args, "cosine_decay_epochs", None),
        "cosine_decay_alpha": getattr(args, "cosine_decay_alpha", 1.0),
    }
    if not finite_number(settings["learning_rate"]) or settings["learning_rate"] <= 0:
        raise TrainingError("Learning rate must be finite and positive.")
    if not finite_number(settings["cosine_decay_alpha"]) or not 0 <= settings["cosine_decay_alpha"] <= 1:
        raise TrainingError("Cosine decay alpha must be finite and between 0 and 1.")
    decay_epochs = settings["cosine_decay_epochs"]
    if decay_epochs is not None and (type(decay_epochs) is not int or decay_epochs < 1):
        raise TrainingError("Cosine decay epochs must be a positive integer or unspecified.")
    return settings


def focal_loss_gamma(value: str) -> float:
    gamma = float(value)
    if not math.isfinite(gamma) or not 1 <= gamma <= 3:
        raise argparse.ArgumentTypeError("Focal loss gamma must be finite and between 1 and 3.")
    return gamma


def training_objective(args: argparse.Namespace) -> dict[str, Any]:
    gamma = getattr(args, "focal_loss_gamma", 1.5)
    if not finite_number(gamma) or not 1 <= gamma <= 3:
        raise TrainingError("Focal loss gamma must be finite and between 1 and 3.")
    return {
        "classification": "official.vision.losses.focal_loss.FocalLoss",
        "gamma": gamma, "alpha": 0.25,
        "box_loss": "Huber", "box_delta": 0.1, "box_multiplier": 50,
        "normalization": "sum_positive_box_weights_plus_one",
        "implementation": "pinned_original" if gamma == 1.5 else "scoped_gamma_override",
    }


def focal_loss_model(tensorflow: Any, objective: dict[str, Any]) -> Any:
    from mediapipe_model_maker.python.vision.object_detector import model as model_lib
    from official.vision.losses import focal_loss, loss_utils

    expected = training_objective(argparse.Namespace(focal_loss_gamma=objective.get("gamma")))
    if objective != expected:
        raise TrainingError("Unsupported training objective; only focal loss gamma may change.")
    if objective["gamma"] == 1.5:
        return model_lib.ObjectDetectorModel

    class FocalLossObjectDetectorModel(model_lib.ObjectDetectorModel):
        def compute_loss(self, x: Any = None, y: Any = None, y_pred: Any = None, sample_weight: Any = None) -> Any:
            # Preserve Model Maker 0.2.1.4's objective, changing only focal gamma.
            cls_loss_fn = focal_loss.FocalLoss(
                alpha=objective["alpha"], gamma=objective["gamma"],
                reduction=tensorflow.keras.losses.Reduction.SUM,
            )
            box_loss_fn = tensorflow.keras.losses.Huber(
                objective["box_delta"], reduction=tensorflow.keras.losses.Reduction.SUM,
            )
            positives = tensorflow.reduce_sum(y["box_weights"]) + 1.0
            cls_targets = loss_utils.multi_level_flatten(y["cls_targets"], last_dim=None)
            cls_targets = tensorflow.one_hot(cls_targets, self._num_classes)
            cls_outputs = loss_utils.multi_level_flatten(y_pred["cls_outputs"], last_dim=self._num_classes)
            box_targets = loss_utils.multi_level_flatten(y["box_targets"], last_dim=4)
            box_outputs = loss_utils.multi_level_flatten(y_pred["box_outputs"], last_dim=4)
            cls_loss = cls_loss_fn(cls_targets, cls_outputs, sample_weight=y["cls_weights"] / positives)
            box_loss = box_loss_fn(box_targets, box_outputs, sample_weight=y["box_weights"] / positives)
            model_loss = cls_loss + objective["box_multiplier"] * box_loss
            total_loss = model_loss
            if self._model.losses:
                total_loss = model_loss + tensorflow.reduce_sum(self._model.losses)
            losses = {"total_loss": total_loss, "cls_loss": cls_loss, "box_loss": box_loss, "model_loss": model_loss}
            for metric in self.metrics:
                metric.update_state(losses[metric.name])
            return total_loss

    return FocalLossObjectDetectorModel


def sampling_configuration(args: argparse.Namespace) -> dict[str, Any]:
    repeats = {name: 1 for name in tuple(CLASSES.values())[1:]}
    seen = set()
    for name, factor in getattr(args, "class_repeat", None) or []:
        if name not in repeats or name in seen or type(factor) is not int or not 1 <= factor <= 3:
            raise TrainingError("Class repeats require unique foreground classes and integer factors from 1 to 3.")
        repeats[name] = factor
        seen.add(name)
    labels = json.loads((args.dataset / "train" / "labels.json").read_text(encoding="utf-8"))
    categories_by_image = {image["id"]: set() for image in labels["images"]}
    for annotation in labels["annotations"]:
        categories_by_image[annotation["image_id"]].add(annotation["category_id"])
    targeted_repeats = {}
    for image_id, factor in getattr(args, "image_repeat", None) or []:
        if (not integer(image_id) or image_id not in categories_by_image or image_id in targeted_repeats
                or type(factor) is not int or not 1 <= factor <= 3):
            raise TrainingError("Image repeats require unique training image IDs and integer factors from 1 to 3; held-out IDs are forbidden.")
        targeted_repeats[image_id] = factor
    image_repeats = {
        image_id: max(targeted_repeats.get(image_id, 1), *(repeats[CLASSES[category]] for category in categories))
        for image_id, categories in categories_by_image.items()
    }
    original_counts: Counter[str] = Counter()
    effective_counts: Counter[str] = Counter()
    coverage: Counter[str] = Counter()
    for categories in categories_by_image.values():
        coverage.update(CLASSES[category] for category in categories)
    for annotation in labels["annotations"]:
        name = CLASSES[annotation["category_id"]]
        original_counts[name] += 1
        effective_counts[name] += image_repeats[annotation["image_id"]]
    class_enabled = any(factor > 1 for factor in repeats.values())
    targeted_enabled = any(factor > 1 for factor in targeted_repeats.values())
    enabled = class_enabled or targeted_enabled
    method = ("bounded_class_and_image_repeat" if class_enabled else "bounded_targeted_image_repeat") if targeted_enabled else (
        "bounded_whole_image_repeat" if class_enabled else "none"
    )
    effective_images = sum(image_repeats.values())
    return {
        "method": method,
        "split": "train", "class_repeats": repeats, "maximum_repeat": 3,
        "image_repeats": {str(image_id): factor for image_id, factor in sorted(targeted_repeats.items())},
        "multi_class_policy": "maximum, not product; preserve every object and box",
        "unique_images": len(image_repeats), "images_per_epoch": effective_images,
        "steps_per_epoch": (math.ceil(effective_images / args.batch_size) if enabled
                            else effective_images // args.batch_size),
        "partial_final_batch": enabled and effective_images % args.batch_size != 0,
        "image_coverage_by_class": {name: coverage[name] for name in repeats},
        "original_annotations_by_class": {name: original_counts[name] for name in repeats},
        "annotations_per_epoch_by_class": {name: effective_counts[name] for name in repeats},
        "repeated_image_ids": sorted(image_id for image_id, factor in image_repeats.items() if factor > 1),
        "validation_and_test_sampling": "unchanged",
    }


def apply_training_sampling(api: Any, tensorflow: Any, dataset: Any, sampling: dict[str, Any]) -> Any:
    if sampling["method"] == "none":
        return dataset
    if dataset.size != sampling["unique_images"] or list(dataset.label_names) != list(CLASSES.values()):
        raise TrainingError("Training sampler requires the audited image count and foreground label mapping.")
    repeat_table = tensorflow.constant(
        [1, *(sampling["class_repeats"][CLASSES[key]] for key in (1, 2, 3, 4, 5, 6))], dtype=tensorflow.int64,
    )
    targeted = sampling.get("image_repeats", {})
    image_repeat_table = None
    if targeted:
        image_repeat_table = tensorflow.lookup.StaticHashTable(
            tensorflow.lookup.KeyValueTensorInitializer(
                tensorflow.constant(list(targeted), dtype=tensorflow.string),
                tensorflow.constant(list(targeted.values()), dtype=tensorflow.int64),
            ), default_value=tensorflow.constant(1, dtype=tensorflow.int64),
        )

    def repeat_image(record: Any) -> Any:
        factor = tensorflow.reduce_max(tensorflow.gather(repeat_table, record["groundtruth_classes"]))
        if image_repeat_table is not None:
            factor = tensorflow.maximum(factor, image_repeat_table.lookup(record["source_id"]))
        return tensorflow.data.Dataset.from_tensors(record).repeat(factor)

    # Repeat before augmentation so each occurrence gets its own training transform.
    records = dataset.gen_tf_dataset(batch_size=1, is_training=False).unbatch()
    records = records.flat_map(repeat_image).apply(
        tensorflow.data.experimental.assert_cardinality(sampling["images_per_epoch"])
    )
    return api.Dataset(dataset=records, label_names=list(dataset.label_names), size=sampling["images_per_epoch"])


def training_geometry_configuration(args: argparse.Namespace) -> dict[str, Any]:
    mode = getattr(args, "training_geometry", "native")
    if mode not in ("native", "camera_square"):
        raise TrainingError("Training geometry must be native or camera_square.")
    return {
        "mode": mode, "split": "train",
        "input_size": 256 if mode == "camera_square" else None,
        "resize": "bilinear_clip_round_uint8" if mode == "camera_square" else "none",
        "boxes": "normalized_coordinates_unchanged",
        "areas": "scale_by_resized_pixel_area" if mode == "camera_square" else "unchanged",
        "augmentation": "pinned_original", "validation_and_test": "unchanged",
    }


def apply_training_geometry(api: Any, tensorflow: Any, dataset: Any, geometry: dict[str, Any]) -> Any:
    expected = training_geometry_configuration(argparse.Namespace(training_geometry=geometry.get("mode")))
    if geometry != expected:
        raise TrainingError("Unsupported training geometry recipe.")
    if geometry["mode"] == "native":
        return dataset
    if list(dataset.label_names) != list(CLASSES.values()):
        raise TrainingError("Training geometry requires the audited foreground label mapping.")
    size = geometry["input_size"]

    def resize_record(record: Any) -> Any:
        image = record["image"]
        if image.dtype != tensorflow.uint8:
            raise TrainingError("Training geometry requires decoded uint8 RGB images.")
        shape = tensorflow.shape(image)
        tensorflow.debugging.assert_rank(image, 3)
        tensorflow.debugging.assert_equal(shape[2], 3)
        tensorflow.debugging.assert_positive(shape[:2])
        tensorflow.debugging.assert_equal(tensorflow.cast(record["height"], tensorflow.int32), shape[0])
        tensorflow.debugging.assert_equal(tensorflow.cast(record["width"], tensorflow.int32), shape[1])
        resized = tensorflow.image.resize(image, [size, size], method="bilinear", antialias=False)
        # Model Maker scales uint8 to [0, 1]; float pixels here would bypass that scaling.
        resized = tensorflow.cast(tensorflow.clip_by_value(tensorflow.round(resized), 0, 255), tensorflow.uint8)
        area_scale = (size / tensorflow.cast(shape[0], tensorflow.float32)
                      * (size / tensorflow.cast(shape[1], tensorflow.float32)))
        return {
            **record, "image": resized,
            "height": tensorflow.ones_like(record["height"]) * size,
            "width": tensorflow.ones_like(record["width"]) * size,
            "groundtruth_area": record["groundtruth_area"] * tensorflow.cast(area_scale, record["groundtruth_area"].dtype),
        }

    records = dataset.gen_tf_dataset(batch_size=1, is_training=False).unbatch().map(resize_record)
    records = records.apply(tensorflow.data.experimental.assert_cardinality(dataset.size))
    return api.Dataset(dataset=records, label_names=list(dataset.label_names), size=dataset.size)


def primary_label_recipe() -> dict[str, Any]:
    try:
        backend_version = importlib.metadata.version("pycocotools")
    except importlib.metadata.PackageNotFoundError:
        backend_version = None
    return {
        "scope": "native_primary_label_proxy_not_browser_or_camera_accuracy",
        "category_selection": "highest_score_per_identical_rounded_model_pixel_box",
        "tie_break": "first_post_nms_candidate", "score_threshold": 0.2, "max_results": 10,
        "coordinates": "round_xywh_then_clip_and_project", "group_before_projection": True,
        "preprocessing": "pinned_validation_without_augmentation", "split": "validation",
        "metric": "COCO_AP_0.50_0.95", "secondary_metric": "AP50",
        "validation_labels_fingerprint": "canonical_json_sort_keys",
        "scorer_sha256": hashlib.sha256(Path(__file__).with_name("evaluate_waste.py").read_bytes()).hexdigest(),
        "pycocotools_version": backend_version,
    }


def selection_configuration(args: argparse.Namespace) -> dict[str, Any]:
    metric = getattr(args, "selection_metric", "coco_ap")
    if metric not in ("coco_ap", "primary_ap"):
        raise TrainingError("Selection metric must be coco_ap or primary_ap.")
    policy = {"monitor": "val_primary_AP" if metric == "primary_ap" else "val_coco_AP", "mode": "max",
              "patience": getattr(args, "patience", 5), "min_delta": getattr(args, "min_delta", 0.0)}
    if metric == "primary_ap":
        policy["primary_label"] = primary_label_recipe()
    return policy


def run_configuration(args: argparse.Namespace, versions: dict[str, str]) -> dict[str, Any]:
    hyperparameters = training_hyperparameters(args)
    digest = hashlib.sha256()
    for split in SPLITS:
        labels = args.dataset / split / "labels.json"
        digest.update(split.encode("ascii"))
        digest.update(labels.read_bytes())
        for image in sorted((args.dataset / split / "data").glob("*.jpg")):
            digest.update(image.name.encode("ascii"))
            with image.open("rb") as source:
                digest.update(hashlib.file_digest(source, "sha256").digest())
    return {
        "schema_version": 10, "architecture": "MOBILENET_V2",
        "dataset_sha256": digest.hexdigest(), "versions": versions,
        "epochs": args.epochs, "batch_size": args.batch_size, "seed": 42,
        "runner_sha256": hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
        "optimizer": {
            "name": "SGD", "momentum": 0.9, "warmup_fraction": 0.1,
            "cosine_step_origin": "global_step",
            **hyperparameters,
            "effective_initial_learning_rate": hyperparameters["learning_rate"] * args.batch_size / 256,
        },
        "evaluate_test": getattr(args, "evaluate_test", False),
        "training_objective": training_objective(args),
        "training_geometry": training_geometry_configuration(args),
        "training_sampling": sampling_configuration(args),
        "training_pipeline": training_pipeline_configuration(args),
        "selection": selection_configuration(args),
    }


def training_pipeline_configuration(args: argparse.Namespace) -> dict[str, Any]:
    mode = getattr(args, "training_pipeline", "pinned")
    if mode not in ("pinned", "bounded_shuffle"):
        raise TrainingError("Training pipeline must be pinned or bounded_shuffle.")
    return {
        "mode": mode, "split": "train", "shuffle": mode == "bounded_shuffle",
        "seed": 42, "asserted_cardinality": mode == "bounded_shuffle",
        "shuffle_stage": "after_preprocessing_before_batch" if mode == "bounded_shuffle" else "disabled",
        "maximum_buffer_records": 256 if mode == "bounded_shuffle" else None,
        "maximum_buffer_tensor_bytes": 128 * 1024 * 1024 if mode == "bounded_shuffle" else None,
        "memory_scope": "shuffle_tensor_payload_only_not_total_process_memory",
        "partial_final_batch": "preserved" if mode == "bounded_shuffle" else "pinned_original",
        "resume_order": "seeded_iterator_restarts_not_exact_epoch_replay",
        "validation_and_test": "unchanged",
    }


def apply_training_pipeline(api: Any, tensorflow: Any, dataset: Any, policy: dict[str, Any]) -> Any:
    expected = training_pipeline_configuration(argparse.Namespace(training_pipeline=policy.get("mode")))
    if policy != expected:
        raise TrainingError("Unsupported training input pipeline recipe.")
    if policy["mode"] == "pinned":
        return dataset
    if (type(dataset.size) is not int or dataset.size < 2
            or list(dataset.label_names) != list(CLASSES.values())):
        raise TrainingError("Shuffled training requires at least two images and the audited label mapping.")
    records = dataset.gen_tf_dataset(batch_size=1, is_training=False).unbatch().apply(
        tensorflow.data.experimental.assert_cardinality(dataset.size)
    )

    class ShuffledDataset(api.Dataset):
        def gen_tf_dataset(self, batch_size: int = 1, is_training: bool = False, shuffle: bool = False,
                           preprocess: Any = None, drop_remainder: bool = False) -> Any:
            if not is_training:
                return super().gen_tf_dataset(batch_size=batch_size, is_training=False, shuffle=False,
                                              preprocess=preprocess, drop_remainder=drop_remainder)
            if shuffle is not True or preprocess is None or drop_remainder:
                raise TrainingError("Bounded shuffled training requires shuffle=True, preprocessing, and every final image.")
            processed = self._dataset.map(lambda record: preprocess(record, is_training=True), num_parallel_calls=1)
            payload_bytes = 0
            for spec in tensorflow.nest.flatten(processed.element_spec):
                if (not isinstance(spec, tensorflow.TensorSpec) or not spec.shape.is_fully_defined()
                        or spec.dtype == tensorflow.string or spec.dtype.size <= 0):
                    raise TrainingError("Bounded shuffle requires fixed-size numeric preprocessed tensors.")
                payload_bytes += spec.shape.num_elements() * spec.dtype.size
            if payload_bytes <= 0:
                raise TrainingError("Bounded shuffle cannot buffer an empty preprocessed example.")
            buffer_size = min(self.size, policy["maximum_buffer_records"],
                              policy["maximum_buffer_tensor_bytes"] // payload_bytes)
            if buffer_size < min(self.size, batch_size + 1):
                raise TrainingError("Shuffle payload budget is too small to mix examples beyond one batch.")
            self.shuffle_buffer = {"records": buffer_size, "tensor_bytes_per_record": payload_bytes,
                                   "maximum_tensor_bytes": buffer_size * payload_bytes,
                                   "full_epoch": buffer_size == self.size}
            processed = processed.shuffle(buffer_size, seed=policy["seed"], reshuffle_each_iteration=True)
            return processed.batch(batch_size, drop_remainder=False).prefetch(1)

    return ShuffledDataset(dataset=records, label_names=list(dataset.label_names), size=dataset.size)


def validate_resume(output: Path, configuration: dict[str, Any]) -> None:
    previous = read_json(output, output / "run.json")
    if previous != configuration:
        raise TrainingError("Cannot resume: dataset, dependencies, runner, or training settings changed.")


def checkpoint_hashes(output: Path, best_epoch: int) -> dict[str, str]:
    prefix = output / "selection" / f"weights-{best_epoch:05d}"
    files = sorted(prefix.parent.glob(prefix.name + ".*"))
    shards = [match for path in files if (match := re.fullmatch(re.escape(prefix.name) + r"\.data-(\d{5})-of-(\d{5})", path.name))]
    if (not prefix.with_suffix(".index").is_file() or not shards
            or len({int(match[2]) for match in shards}) != 1
            or {int(match[1]) for match in shards} != set(range(int(shards[0][2])))):
        raise TrainingError("Best validation checkpoint is missing or incomplete.")
    hashes = {}
    for path in files:
        if not re.fullmatch(re.escape(prefix.name) + r"\.(index|data-\d{5}-of-\d{5})", path.name):
            raise TrainingError("Unexpected file in the best validation checkpoint.")
        if path.stat().st_size == 0:
            raise TrainingError("Best validation checkpoint contains an empty file.")
        with contained_file(output, path).open("rb") as source:
            hashes[path.name] = hashlib.file_digest(source, "sha256").hexdigest()
    return hashes


def selection_metric_field(policy: dict[str, Any]) -> str:
    if policy.get("monitor") == "val_primary_AP" and policy.get("mode") == "max":
        if policy.get("primary_label") != primary_label_recipe():
            raise TrainingError("Primary-label selection requires the fixed, versioned validation recipe.")
        return "best_primary_validation_ap"
    if policy.get("monitor") == "val_coco_AP" and policy.get("mode") == "max":
        return "best_validation_ap"
    if policy.get("monitor") == "val_total_loss" and policy.get("mode") == "min":
        return "best_validation_loss"
    raise TrainingError("Unsupported validation checkpoint selection policy.")


def validate_selection_metric(value: Any, policy: dict[str, Any]) -> None:
    field = selection_metric_field(policy)
    if not finite_number(value) or value < 0 or (field != "best_validation_loss" and value > 1):
        raise TrainingError("Checkpoint selection requires finite validation AP in [0, 1] or a nonnegative validation loss.")


def read_selection(output: Path, completed_epochs: int, policy: dict[str, Any]) -> dict[str, Any]:
    state = read_json(output, output / "selection" / f"state-{completed_epochs:05d}.json")
    field = selection_metric_field(policy)
    legacy = state.get("schema_version") == 1 and field == "best_validation_loss"
    if ((state.get("schema_version") != 2 and not legacy) or not integer(state.get("completed_epochs")) or state["completed_epochs"] != completed_epochs
            or state.get("policy") != policy or not integer(state.get("best_epoch"))
            or not 1 <= state["best_epoch"] <= completed_epochs or not integer(state.get("wait"))
            or type(state.get("stopped_early")) is not bool
            or not finite_number(state.get(field))
            or (not legacy and (not finite_number(state.get("best_metric_value")) or state.get("best_metric_value") != state[field]))):
        raise TrainingError("Validation selection state does not match the recovered training epoch and settings.")
    validate_selection_metric(state[field], policy)
    if state.get("checkpoint_sha256") != checkpoint_hashes(output, state["best_epoch"]):
        raise TrainingError("Best validation checkpoint bytes no longer match the selection state.")
    return state


def recover_history(output: Path, completed_epochs: int, policy: dict[str, Any]) -> None:
    rows = []
    fields = None
    for completed in range(1, completed_epochs + 1):
        state = read_json(output, output / "selection" / f"state-{completed:05d}.json")
        logs = state.get("logs")
        if (state.get("completed_epochs") != completed or state.get("policy") != policy
                or not isinstance(logs, dict) or not logs or "epoch" in logs
                or any(not isinstance(key, str) or not finite_number(value) for key, value in logs.items())):
            raise TrainingError("Cannot reconcile CSV history with the committed epoch journal.")
        validate_selection_metric(logs.get(policy["monitor"]), policy)
        if fields is None:
            fields = sorted(logs)
        elif fields != sorted(logs):
            raise TrainingError("Training history fields changed between committed epochs.")
        rows.append({"epoch": completed - 1, **logs})
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8", newline="", dir=output, delete=False) as destination:
            temporary = Path(destination.name)
            writer = csv.DictWriter(destination, fieldnames=["epoch", *(fields or [])])
            writer.writeheader()
            writer.writerows(rows)
        history = output / "history.csv"
        if history.exists() and history.read_bytes() != temporary.read_bytes():
            with tempfile.NamedTemporaryFile(prefix="history-before-resume-", suffix=".csv", dir=output, delete=False) as preserved:
                preserved.write(history.read_bytes())
        temporary.replace(history)
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)


def validation_ap_callback(tensorflow: Any, detector: Any, validation_data: Any, batch_size: int) -> Any:
    if validation_data is None:
        raise TrainingError("Validation data is required for AP checkpoint selection.")

    class ValidationAveragePrecision(tensorflow.keras.callbacks.Callback):
        def on_epoch_end(self, epoch: int, logs: Any = None) -> None:
            if logs is None:
                raise TrainingError("Validation AP needs the shared epoch logs before checkpointing.")
            _, metrics = detector.evaluate(validation_data, batch_size=batch_size)
            metrics = json_value(metrics)
            if not isinstance(metrics, dict):
                raise TrainingError("Model Maker must return a COCO metrics object for AP selection.")
            value = metrics.get("AP")
            validate_selection_metric(value, {"monitor": "val_coco_AP", "mode": "max"})
            logs["val_coco_AP"] = value
            print(f"Epoch {epoch + 1}: validation COCO AP = {value:.6f}", flush=True)

    return ValidationAveragePrecision()


def primary_label_predictions(predictions: list[dict[str, Any]], image: dict[str, Any], scale_yx: list[float]) -> list[dict[str, Any]]:
    if (not integer(image.get("id")) or image["id"] < 0
            or any(not integer(image.get(key)) or image[key] < 1 for key in ("width", "height"))
            or len(scale_yx) != 2 or any(not finite_number(value) or value <= 0 for value in scale_yx)):
        raise TrainingError("Primary-label projection requires a valid image and two positive scales.")
    grouped = {}
    for prediction in predictions:
        box, score, category = prediction.get("bbox"), prediction.get("score"), prediction.get("category_id")
        if (prediction.get("image_id") != image["id"] or not finite_number(score) or not 0 <= score <= 1
                or not integer(category) or category not in (1, 2, 3, 4, 5, 6)
                or not isinstance(box, list) or len(box) != 4 or not all(finite_number(value) for value in box)):
            raise TrainingError("Invalid native primary-label candidate.")
        if score < 0.2 or box[2] <= 0 or box[3] <= 0:
            continue
        coordinates = tuple(math.floor(value + 0.5) for value in box)
        if coordinates[2] < 1 or coordinates[3] < 1:
            continue
        if coordinates not in grouped or score > grouped[coordinates]["score"]:
            grouped[coordinates] = {**prediction, "bbox": list(coordinates)}
    # Group and limit in model space; clipping must not merge distinct candidate boxes.
    selected = sorted(grouped.values(), key=lambda item: item["score"], reverse=True)[:10]
    result = []
    for prediction in selected:
        x, y, width, height = prediction["bbox"]
        left = max(0, min(image["width"], x / scale_yx[1]))
        top = max(0, min(image["height"], y / scale_yx[0]))
        right = max(0, min(image["width"], (x + width) / scale_yx[1]))
        bottom = max(0, min(image["height"], (y + height) / scale_yx[0]))
        if right > left and bottom > top:
            result.append({**prediction, "bbox": [left, top, right - left, bottom - top]})
    return result


def primary_validation_callback(tensorflow: Any, detector: Any, validation_data: Any, batch_size: int,
                                labels: dict[str, Any], policy: dict[str, Any], output: Path) -> Any:
    if selection_metric_field(policy) != "best_primary_validation_ap":
        raise TrainingError("Primary-label validation requires its own selection policy.")
    if (validation_data is None or not isinstance(labels, dict) or not isinstance(labels.get("images"), list)
            or not labels["images"] or not isinstance(labels.get("annotations"), list)
            or list(validation_data.label_names) != list(CLASSES.values())
            or validation_data.size != len(labels["images"])):
        raise TrainingError("Primary-label validation requires the audited validation dataset and labels.")
    images = {}
    for image in labels["images"]:
        primary_label_predictions([], image, [1, 1])
        if image["id"] in images:
            raise TrainingError("Duplicate primary validation image ID.")
        images[image["id"]] = image
    categories = labels.get("categories")
    if (not isinstance(categories, list) or any(not isinstance(item, dict) or not integer(item.get("id")) for item in categories)
            or len(categories) != len(CLASSES) or {item["id"]: item.get("name") for item in categories} != CLASSES
            or any(not isinstance(item, dict) or item.get("image_id") not in images
                   or item.get("category_id") not in (1, 2, 3, 4, 5, 6) for item in labels["annotations"])):
        raise TrainingError("Primary validation labels must retain the audited foreground categories and image IDs.")
    labels_hash = hashlib.sha256(json.dumps(labels, sort_keys=True).encode("utf-8")).hexdigest()

    class PrimaryValidationAveragePrecision(tensorflow.keras.callbacks.Callback):
        def on_epoch_end(self, epoch: int, logs: Any = None) -> None:
            if logs is None:
                raise TrainingError("Primary-label validation needs shared epoch logs before checkpointing.")
            if hashlib.sha256(json.dumps(labels, sort_keys=True).encode("utf-8")).hexdigest() != labels_hash:
                raise TrainingError("Primary validation labels changed after callback construction.")
            from evaluate_waste import score_predictions

            predictions, seen = [], set()
            batches = validation_data.gen_tf_dataset(batch_size=batch_size, is_training=False,
                                                      shuffle=False, preprocess=detector._preprocessor)
            for x, y in batches:
                result = detector._model(x, anchor_boxes=y["anchor_boxes"], image_shape=y["image_info"][:, 1, :], training=False)
                sources = y["groundtruths"]["source_id"].numpy().reshape(-1).tolist()
                heights = y["groundtruths"]["height"].numpy().reshape(-1).tolist()
                widths = y["groundtruths"]["width"].numpy().reshape(-1).tolist()
                boxes = result["detection_boxes"].numpy().tolist()
                categories = result["detection_classes"].numpy().tolist()
                scores = result["detection_scores"].numpy().tolist()
                counts = result["num_detections"].numpy().reshape(-1).tolist()
                if not all(len(values) == len(sources) for values in (heights, widths, boxes, categories, scores, counts)):
                    raise TrainingError("Primary validation output batch dimensions disagree.")
                for index, identifier in enumerate(sources):
                    if not integer(identifier) or identifier not in images or identifier in seen:
                        raise TrainingError("Primary validation must cover each audited image exactly once.")
                    image = images[identifier]
                    if heights[index] != image["height"] or widths[index] != image["width"]:
                        raise TrainingError("Primary validation source dimensions disagree with audited labels.")
                    seen.add(identifier)
                    count = counts[index]
                    if (not finite_number(count) or int(count) != count or not 0 <= count <= 100
                            or any(len(values[index]) < count for values in (boxes, categories, scores))):
                        raise TrainingError("Invalid native primary validation detection count.")
                    candidates = []
                    for box, category, score in zip(boxes[index][:int(count)], categories[index][:int(count)], scores[index][:int(count)]):
                        if (not isinstance(box, list) or len(box) != 4 or not all(finite_number(value) for value in box)
                                or not finite_number(category) or int(category) != category):
                            raise TrainingError("Invalid native primary validation outputs.")
                        top, left, bottom, right = box
                        candidates.append({"image_id": identifier, "category_id": int(category), "score": score,
                                           "bbox": [left, top, right - left, bottom - top]})
                    predictions.extend(primary_label_predictions(candidates, image, y["image_info"][index, 2, :].numpy().tolist()))
            if seen != set(images):
                raise TrainingError("Primary validation did not cover every audited validation image.")
            metrics = json_value(score_predictions(labels, predictions))
            for key in ("AP", "AP50"):
                validate_selection_metric(metrics.get(key), policy)
            (output / "primary-validation").mkdir(parents=True, exist_ok=True)
            write_json_report(output / "primary-validation" / f"epoch-{epoch + 1:05d}.json", {
                "epoch": epoch + 1, "recipe": policy["primary_label"], "validation_labels_sha256": labels_hash,
                "image_ids": sorted(seen), "metrics": metrics, "predictions": predictions, "promoted": False,
            }, replace=True)
            logs["val_primary_AP"], logs["val_primary_AP50"] = metrics["AP"], metrics["AP50"]
            print(f'Epoch {epoch + 1}: native primary-label proxy AP = {metrics["AP"]:.6f}', flush=True)

    return PrimaryValidationAveragePrecision()


def selection_callback(tensorflow: Any, output: Path, policy: dict[str, Any], recovered: dict[str, Any] | None = None) -> Any:
    field = selection_metric_field(policy)

    class PersistentEarlyStopping(tensorflow.keras.callbacks.EarlyStopping):
        def __init__(self) -> None:
            super().__init__(monitor=policy["monitor"], mode=policy["mode"], patience=policy["patience"],
                             min_delta=policy["min_delta"], restore_best_weights=False, verbose=1)
            self.state = recovered

        def on_train_begin(self, logs: Any = None) -> None:
            super().on_train_begin(logs)
            if self.state is not None:
                self.best = self.state[field]
                self.best_epoch = self.state["best_epoch"] - 1
                self.wait = self.state["wait"]
                self.stopped_epoch = self.state["completed_epochs"] - 1 if self.state["stopped_early"] else 0

        def on_epoch_end(self, epoch: int, logs: Any = None) -> None:
            values = json_value(logs or {})
            current = values.get(policy["monitor"])
            validate_selection_metric(current, policy)
            expected = self.state["completed_epochs"] if self.state is not None else 0
            if epoch != expected:
                raise TrainingError("Training epoch does not match the committed selection journal.")
            previous_best = self.best
            super().on_epoch_end(epoch, values)
            if self.best != previous_best:
                prefix = output / "selection" / f"weights-{epoch + 1:05d}"
                prefix.parent.mkdir(parents=True, exist_ok=True)
                self.model.save_weights(str(prefix))
            self.state = {
                "schema_version": 2, "policy": policy, "completed_epochs": epoch + 1,
                "best_epoch": self.best_epoch + 1, "best_metric_value": float(self.best), field: float(self.best),
                "wait": int(self.wait), "stopped_early": bool(self.model.stop_training),
                "checkpoint_sha256": checkpoint_hashes(output, self.best_epoch + 1), "logs": values,
            }
            write_json_report(output / "selection" / f"state-{epoch + 1:05d}.json", self.state, replace=True)

        def on_train_end(self, logs: Any = None) -> None:
            super().on_train_end(logs)
            if self.state is None:
                raise TrainingError("Training did not produce a selectable validation checkpoint.")
            state = read_selection(output, self.state["completed_epochs"], policy)
            status = self.model.load_weights(str(output / "selection" / f'weights-{state["best_epoch"]:05d}'))
            status.assert_existing_objects_matched()
            status.expect_partial()
            print(f'Restored {policy["monitor"]} checkpoint from epoch {state["best_epoch"]}.', flush=True)

    return PersistentEarlyStopping()


def checkpoint_detector(
    api: Any, tensorflow: Any, output: Path, resume: bool,
    policy: dict[str, Any] | None = None, recovered: dict[str, Any] | None = None,
    validation_data: Any = None, batch_size: int = 1,
    objective: dict[str, Any] | None = None,
    validation_labels: dict[str, Any] | None = None,
) -> Any:
    objective = objective if objective is not None else training_objective(argparse.Namespace())
    if objective != training_objective(argparse.Namespace(focal_loss_gamma=objective.get("gamma"))):
        raise TrainingError("Unsupported training objective; only focal loss gamma may change.")
    # Model Maker 0.2.1.4 exposes callbacks only through its classifier base class.
    class CheckpointObjectDetector(api.ObjectDetector):
        def _create_model(self) -> None:
            if objective["gamma"] == 1.5:
                super()._create_model()
                return
            model_class = focal_loss_model(tensorflow, objective)
            self._model = model_class(
                model_spec=self._model_spec, model_options=self._model_options, num_classes=self._num_classes,
            )

        def __init__(self, *args: Any, **kwargs: Any) -> None:
            super().__init__(*args, **kwargs)
            backup = tensorflow.keras.callbacks.BackupAndRestore(
                backup_dir=str(output / "backup"), save_freq="epoch", delete_checkpoint=False,
            )
            logger = tensorflow.keras.callbacks.CSVLogger(str(output / "history.csv"), append=resume)
            self._selection = selection_callback(tensorflow, output, policy, recovered) if policy is not None else None
            # Commit the epoch journal before its recovery backup; CSV is reconciled on resume.
            self._callbacks = [logger, self._selection, backup] if self._selection is not None else [backup, logger]
            if policy is not None and policy["monitor"] in ("val_coco_AP", "val_primary_AP"):
                self._callbacks.insert(0, validation_ap_callback(tensorflow, self, validation_data, batch_size))
            if policy is not None and policy["monitor"] == "val_primary_AP":
                self._callbacks.insert(1, primary_validation_callback(
                    tensorflow, self, validation_data, batch_size, validation_labels, policy, output,
                ))

        @property
        def training_selection(self) -> dict[str, Any]:
            if self._selection is None or self._selection.state is None:
                raise TrainingError("No validation checkpoint was selected for export.")
            return {key: value for key, value in self._selection.state.items() if key != "logs"}

    return CheckpointObjectDetector


def json_value(value: Any) -> Any:
    if isinstance(value, dict):
        return {str(key): json_value(item) for key, item in value.items()}
    if isinstance(value, (list, tuple)):
        return [json_value(item) for item in value]
    if hasattr(value, "tolist"):
        return json_value(value.tolist())
    if hasattr(value, "numpy"):
        return json_value(value.numpy())
    if type(value) is float and not math.isfinite(value):
        raise TrainingError("Evaluation returned non-finite metrics; no metrics.json was written.")
    if value is None or type(value) in (str, int, float, bool):
        return value
    raise TrainingError(f"Unsupported evaluation result type: {type(value).__name__}")


def write_json_report(path: Path, value: dict[str, Any], *, replace: bool = False) -> None:
    serialized = json.dumps(json_value(value), indent=2, allow_nan=False) + "\n"
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(
            mode="w", encoding="utf-8", dir=path.parent,
            prefix=f".{path.name}.", suffix=".tmp", delete=False,
        ) as destination:
            temporary = Path(destination.name)
            destination.write(serialized)
        if replace:
            temporary.replace(path)
        else:
            # Publish the complete report atomically without overwriting an existing report.
            path.hardlink_to(temporary)
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)


def run_tflite_evaluation(request: argparse.Namespace, timeout_seconds: int = 600) -> dict[str, Any]:
    validate_output(request.output, request.dataset)
    log_path = request.output.parent / "tflite-validation.log"
    diagnostics = {"log": log_path.name, "exit_code": None, "timeout_seconds": timeout_seconds, "timed_out": False}
    environment = os.environ.copy()
    environment["PYTHONFAULTHANDLER"] = "1"
    for key, value in {"TF_NUM_INTRAOP_THREADS": "2", "TF_NUM_INTEROP_THREADS": "1",
                       "OMP_NUM_THREADS": "2", "OPENBLAS_NUM_THREADS": "2"}.items():
        environment.setdefault(key, value)
    try:
        # Keep native inference outside the trainer and publish only a verified child report.
        with log_path.open("xb") as log, tempfile.TemporaryDirectory(prefix=".tflite-evaluation-", dir=request.output.parent) as temporary:
            staging = Path(temporary).resolve()
            command = [
                sys.executable, "-u", str(Path(__file__).with_name("evaluate_waste.py")),
                "--model", str(request.model), "--dataset", str(request.dataset),
                "--output", str(staging), "--split", request.split,
            ]
            completed = subprocess.run(command, stdout=log, stderr=subprocess.STDOUT,
                                       stdin=subprocess.DEVNULL, env=environment, timeout=timeout_seconds, check=False)
            diagnostics["exit_code"] = completed.returncode
            if completed.returncode != 0:
                raise TrainingError(f"TFLite evaluation process exited with code {completed.returncode}; see {log_path}.")
            result = read_json(staging, staging / "tflite-evaluation.json")
            result["process"] = diagnostics
            return result
    except subprocess.TimeoutExpired as error:
        diagnostics["timed_out"] = True
        raise TFLiteEvaluationError(f"TFLite evaluation exceeded {timeout_seconds} seconds; see {log_path}.", diagnostics) from error
    except KeyboardInterrupt as error:
        error.diagnostics = diagnostics
        raise
    except Exception as error:
        raise TFLiteEvaluationError(f"TFLite evaluation failed: {error}", diagnostics) from error


def evaluate_export(args: argparse.Namespace, metrics: dict[str, Any]) -> None:
    output = args.output / "tflite-validation"
    report = output / "tflite-evaluation.json"
    metrics_path = args.output / "metrics.json"
    diagnostics = None
    metrics["tflite_evaluation"] = {"status": "running", "split": "validation"}
    try:
        write_json_report(metrics_path, metrics, replace=True)
        validate_output(output, args.dataset)
        result = run_tflite_evaluation(argparse.Namespace(
            model=args.output / metrics["model"]["file"], dataset=args.dataset,
            output=output, split="validation",
        ))
        diagnostics = result.get("process")
        if (result["status"] != "evaluated" or result["split"] != "validation"
                or result["model_sha256"] != metrics["model"]["sha256"] or result["promoted"] is not False):
            raise TrainingError("TFLite validation report does not match this exported model and validation split.")
        summary = {
            key: result[key]
            for key in ("status", "split", "backend", "model_sha256", "settings", "metrics")
        }
        summary["report"] = report.relative_to(args.output).as_posix()
        if "process" in result:
            summary["process"] = result["process"]
        output.mkdir(parents=True, exist_ok=True)
        write_json_report(report, result)
        metrics["tflite_evaluation"] = summary
        write_json_report(metrics_path, metrics, replace=True)
    except (Exception, KeyboardInterrupt) as error:
        metrics["tflite_evaluation"] = {
            "status": "interrupted" if isinstance(error, KeyboardInterrupt) else "failed",
            "split": "validation", "error": str(error) or type(error).__name__,
        }
        diagnostics = getattr(error, "diagnostics", diagnostics)
        if diagnostics is not None:
            metrics["tflite_evaluation"]["process"] = diagnostics
        write_json_report(metrics_path, metrics, replace=True)
        if isinstance(error, KeyboardInterrupt):
            raise
        raise TrainingError(
            f"Training and export completed, but TFLite validation failed: {error}. "
            "Model, checkpoints, and Keras metrics were preserved. "
            "Retry with evaluate_waste.py --split validation and a new private output directory."
        ) from error
    print(f"TFLite validation completed: {report}; model has not been promoted.")


def train(args: argparse.Namespace, api: Any, versions: dict[str, str], audit: dict[str, Any]) -> None:
    validate_training_use(args.dataset)
    import tensorflow as tf

    configuration = run_configuration(args, versions)
    recovered = None
    if args.resume:
        validate_resume(args.output, configuration)
        checkpoint = tf.train.latest_checkpoint(str(args.output / "backup" / "chief"))
        if not checkpoint:
            raise TrainingError("No recoverable epoch checkpoint was found; keep this run and use a new output directory.")
        completed = int(tf.train.load_variable(checkpoint, "ckpt_saved_epoch/.ATTRIBUTES/VARIABLE_VALUE")) + 1
        if not 1 <= completed <= args.epochs:
            raise TrainingError("Recovered epoch is outside this run's requested training range.")
        recovered = read_selection(args.output, completed, configuration["selection"])
        recover_history(args.output, completed, configuration["selection"])
    args.output.mkdir(parents=True, exist_ok=True)
    if not args.resume:
        (args.output / "run.json").write_text(json.dumps(configuration, indent=2) + "\n", encoding="utf-8")
    tf.keras.utils.set_random_seed(configuration["seed"])
    # The official 0.2.1.4 loader hardcodes images/, while preparation emits data/.
    with tempfile.TemporaryDirectory(prefix="model-maker-", dir=args.output) as temporary:
        staging = Path(temporary)
        datasets = {}
        loaded_splits = SPLITS if args.evaluate_test else SPLITS[:2]
        for split in loaded_splits:
            folder = staging / split
            folder.mkdir()
            (folder / "images").symlink_to(args.dataset / split / "data", target_is_directory=True)
            (folder / "labels.json").symlink_to(args.dataset / split / "labels.json")
            datasets[split] = api.Dataset.from_coco_folder(str(folder), cache_dir=str(staging / f"cache-{split}"))
            if datasets[split].size != audit["counts"][split]["images"]:
                raise TrainingError(f"{split}: Model Maker loaded an unexpected number of images.")
            if list(datasets[split].label_names) != list(CLASSES.values()):
                raise TrainingError(f"{split}: Model Maker loaded an unexpected label mapping.")
        sampling = configuration["training_sampling"]
        datasets["train"] = apply_training_geometry(api, tf, datasets["train"], configuration["training_geometry"])
        datasets["train"] = apply_training_sampling(api, tf, datasets["train"], sampling)
        pipeline = configuration["training_pipeline"]
        datasets["train"] = apply_training_pipeline(api, tf, datasets["train"], pipeline)
        sampling_hparams = {"steps_per_epoch": sampling["steps_per_epoch"]} if sampling["method"] != "none" else {}
        if pipeline["shuffle"]:
            sampling_hparams["steps_per_epoch"] = math.ceil(datasets["train"].size / args.batch_size)
        options = api.ObjectDetectorOptions(
            supported_model=api.SupportedModels.MOBILENET_V2,
            hparams=api.HParams(epochs=recovered["completed_epochs"] if recovered and recovered["stopped_early"] else args.epochs,
                               batch_size=args.batch_size, export_dir=str(args.output),
                               shuffle=pipeline["shuffle"],
                               **training_hyperparameters(args), **sampling_hparams),
        )
        detector = checkpoint_detector(api, tf, args.output, args.resume, configuration["selection"], recovered,
                                       validation_data=datasets["validation"], batch_size=args.batch_size,
                                       objective=configuration["training_objective"],
                                       validation_labels=(read_json(args.dataset, args.dataset / "validation" / "labels.json")
                                                          if configuration["selection"]["monitor"] == "val_primary_AP" else None))
        model = detector.create(
            train_data=datasets["train"], validation_data=datasets["validation"], options=options,
        )
        if pipeline["shuffle"]:
            write_json_report(args.output / "input-pipeline.json", {
                "policy": pipeline, "effective_shuffle": datasets["train"].shuffle_buffer,
                "images_per_epoch": datasets["train"].size, "steps_per_epoch": sampling_hparams["steps_per_epoch"],
            })
        selection = model.training_selection
        evaluations = {}
        for split in loaded_splits[1:]:
            losses, coco_metrics = model.evaluate(datasets[split], batch_size=args.batch_size)
            evaluations[split] = json_value({"losses": losses, "coco_metrics": coco_metrics})
        model.export_model(model_name="waste-detector.tflite")

    model_path = args.output / "waste-detector.tflite"
    metadata_path = args.output / "metadata.json"
    if not model_path.is_file() or model_path.stat().st_size == 0 or not metadata_path.is_file():
        raise TrainingError("Model Maker did not produce the expected TFLite model and metadata.")
    with model_path.open("rb") as source:
        model_hash = hashlib.file_digest(source, "sha256").hexdigest()
    metrics = {
        "status": "trained", "created_at": datetime.now(timezone.utc).isoformat(),
        "engine": "official mediapipe-model-maker", "architecture": "MOBILENET_V2",
        "versions": versions, "epochs": args.epochs, "batch_size": args.batch_size,
        "completed_epochs": selection["completed_epochs"], "selection": selection,
        "optimizer": configuration["optimizer"],
        "training_objective": configuration["training_objective"],
        "training_geometry": configuration["training_geometry"],
        "training_sampling": configuration["training_sampling"],
        "training_pipeline": configuration["training_pipeline"],
        "test_evaluation": {"status": "evaluated" if args.evaluate_test else "not_evaluated"},
        "dataset": str(args.dataset), "dataset_audit": audit,
        "evaluation_backend": "Keras (before TFLite export)",
        "evaluation": evaluations,
        "tflite_evaluation": {"status": "not_evaluated", "accuracy": None},
        "model": {"file": model_path.name, "sha256": model_hash, "format": "float32 TFLite with Model Maker metadata"},
        "promoted": False,
    }
    write_json_report(args.output / "metrics.json", metrics)
    print(f"Training and export completed: {model_path}")
    print("Evaluating the exported TFLite model on validation images with MediaPipe...")
    evaluate_export(args, metrics)


def main(argv: list[str] | None = None) -> int:
    parser = ArgumentParser(description=__doc__)
    parser.add_argument("--dataset", type=Path, help="Prepared dataset root (required except for --check-environment).")
    parser.add_argument("--output", type=Path, help="New or empty private output directory for training or --dry-run.")
    parser.add_argument("--epochs", type=positive_int, default=30)
    parser.add_argument("--batch-size", type=positive_int, default=2)
    parser.add_argument("--learning-rate", type=positive_float, default=0.3,
                        help="Base SGD rate; Model Maker scales this by batch_size / 256.")
    parser.add_argument("--cosine-decay-epochs", type=positive_int,
                        help="Cosine decay horizon; defaults to the requested epoch count.")
    parser.add_argument("--cosine-decay-alpha", type=unit_interval_float, default=1.0,
                        help="Final learning-rate fraction. The legacy default 1 keeps the rate constant after warmup.")
    parser.add_argument("--evaluate-test", action="store_true",
                        help="Opt in to final held-out test evaluation; training experiments evaluate validation only by default.")
    parser.add_argument("--focal-loss-gamma", type=focal_loss_gamma, default=1.5,
                        help="Classification focal gamma from 1 to 3; 1.5 preserves the pinned Model Maker objective.")
    parser.add_argument("--training-geometry", choices=("native", "camera_square"), default="native",
                        help="Opt-in square-stretch training experiment; native preserves pinned preprocessing. Validation and test stay unchanged.")
    parser.add_argument("--training-pipeline", choices=("pinned", "bounded_shuffle"), default="pinned",
                        help="Opt in to seeded, memory-bounded training shuffle and known batch cardinality. Held-out splits stay unchanged.")
    parser.add_argument("--class-repeat", type=class_repeat, action="append",
                        help="Repeat whole training images containing CLASS, with a factor from 1 to 3 (e.g. paper=2). May be specified for multiple classes.")
    parser.add_argument("--image-repeat", type=image_repeat, action="append",
                        help="Repeat a selected training image by ID (e.g. 123=2), bounded from 1 to 3. Combines with class repeats by maximum, never multiplication.")
    parser.add_argument("--selection-metric", choices=("coco_ap", "primary_ap"), default="coco_ap",
                        help="Opt in to native primary-label proxy AP selection. Adds a validation pass; does not measure browser or camera accuracy.")
    parser.add_argument("--patience", type=positive_int, default=5, help="Stop after this many epochs without a qualifying selected validation AP improvement.")
    parser.add_argument("--min-delta", type=nonnegative_float, default=0.0, help="Minimum selected validation AP increase (0-1 scale) required to select a new best checkpoint.")
    parser.add_argument("--resume", action="store_true", help="Restore the last completed epoch with identical data, dependencies, and settings.")
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--check-environment", action="store_true", help="Check platform, pinned versions, and imports without training.")
    mode.add_argument("--check-dataset", action="store_true", help="Check dataset records and split leakage without TensorFlow or writing files.")
    mode.add_argument("--dry-run", action="store_true", help="Validate dataset, output, and environment without training or writing files.")
    try:
        args = parser.parse_args(argv)
        objective = training_objective(args)
        geometry = training_geometry_configuration(args)
        pipeline = training_pipeline_configuration(args)
        selection = selection_configuration(args)
        if args.check_dataset:
            if args.dataset is None:
                raise TrainingError("--dataset is required for --check-dataset.")
            audit = validate_dataset(args.dataset)
            audit["training_sampling"] = sampling_configuration(args)
            audit["training_objective"] = objective
            audit["training_geometry"] = geometry
            audit["training_pipeline"] = pipeline
            audit["selection"] = selection
            print("Dataset check passed. Image decoding and training environment have not been tested.")
            print(json.dumps(audit, indent=2))
            return 0
        if args.check_environment:
            check_environment()
            load_training_api()
            print("Environment check passed: Linux CPython 3.11, pinned dependencies, and training imports are available.")
            return 0
        if args.dataset is None or args.output is None:
            raise TrainingError("--dataset and --output are required for training or --dry-run.")
        args.dataset = args.dataset.resolve(strict=True)
        validate_training_use(args.dataset)
        versions = check_environment()
        args.output = args.output.resolve()
        validate_output(args.output, args.dataset, args.resume)
        audit = validate_dataset(args.dataset)
        sampling = sampling_configuration(args)
        if audit["counts"]["train"]["images"] < args.batch_size:
            raise TrainingError("Training split must contain at least one complete batch.")
        api = load_training_api()
        if args.dry_run:
            if args.resume:
                validate_resume(args.output, run_configuration(args, versions))
            print("Preflight passed. No training, export, or output files were produced.")
            print(json.dumps(sampling, indent=2))
            print(json.dumps(objective, indent=2))
            print(json.dumps(geometry, indent=2))
            print(json.dumps(pipeline, indent=2))
            print(json.dumps(selection, indent=2))
            return 0
        train(args, api, versions, audit)
        return 0
    except KeyboardInterrupt:
        print("Interrupted; inspect metrics.json for completed export and TFLite validation status.", file=sys.stderr)
        return 1
    except Exception as error:
        print(f"Training failed: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())

