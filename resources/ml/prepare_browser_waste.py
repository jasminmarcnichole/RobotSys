"""Prepare private validation RGB frames with the pinned training preprocessing."""

from __future__ import annotations

import argparse
from datetime import datetime, timezone
import math
from pathlib import Path
import re
import sys
from typing import Any

from compare_waste import file_hash
from train_waste import (
    ArgumentParser, TrainingError, check_environment, integer, load_training_api,
    read_json, validate_dataset, validate_output, write_json_report,
)


RECIPE = {
    "decoder": "tf.io.decode_jpeg(channels=3)",
    "resize": "official.vision.ops.preprocess_ops.resize_and_crop_image",
    "input_size": 256, "mean_rgb": [127.5], "std_rgb": [127.5],
    "padding": "top_left_normalized_zero", "augmentation": False,
    "rgb_conversion": "clip_rint_uint8", "format": "RGB_PNG",
}


def validate_manifest(report: dict[str, Any], labels: dict[str, Any], labels_hash: str,
                      image_hashes: dict[str, str]) -> None:
    if (not integer(report.get("schema_version")) or report["schema_version"] != 1 or report.get("status") != "prepared"
            or report.get("split") != "validation" or report.get("architecture") != "MOBILENET_V2"
            or report.get("recipe") != RECIPE or report.get("promoted") is not False
            or report.get("labels_sha256") != labels_hash or report.get("image_sha256") != image_hashes
            or not re.fullmatch(r"[a-f0-9]{64}", report.get("generator_sha256", ""))):
        raise TrainingError("Prepared browser frames must match the validation sources and training recipe.")
    frames = report.get("frames")
    images = {image["id"]: image for image in labels["images"]}
    if (not isinstance(frames, list) or len(frames) != len(images)
            or any(not isinstance(frame, dict) or not integer(frame.get("image_id")) for frame in frames)
            or {frame["image_id"] for frame in frames} != set(images)):
        raise TrainingError("Every validation photo must have exactly one prepared frame.")
    for frame in frames:
        image = images[frame["image_id"]]
        content = [frame.get("content_width"), frame.get("content_height")]
        scale = 256 / max(image["width"], image["height"])
        if (frame.get("file") != f'{image["id"]}.png'
                or frame.get("original_sha256") != image_hashes[str(image["id"])]
                or (frame.get("width"), frame.get("height")) != (256, 256)
                or not all(integer(frame.get(axis)) for axis in ("width", "height"))
                or not all(integer(value) and 1 <= value <= 256 for value in content)
                or max(content) != 256
                or any(abs(value - image[axis] * scale) > 0.5001 for value, axis in zip(content, ("width", "height")))
                or not re.fullmatch(r"[a-f0-9]{64}", frame.get("png_sha256", ""))
                or not isinstance(frame.get("max_normalized_roundtrip_error"), (float, int))
                or isinstance(frame["max_normalized_roundtrip_error"], bool)
                or not math.isfinite(frame["max_normalized_roundtrip_error"])
                or not 0 <= frame["max_normalized_roundtrip_error"] <= 0.5 / 127.5 + 1e-6):
            raise TrainingError("Invalid prepared PNG geometry, source identity, or rounding error.")


def prepare(args: argparse.Namespace) -> dict[str, Any]:
    audit = validate_dataset(args.dataset)
    validate_output(args.output, args.dataset)
    versions = check_environment()
    api = load_training_api()
    import numpy as np
    import tensorflow as tf
    from official.vision.ops import preprocess_ops

    spec = api.SupportedModels.get(api.SupportedModels.MOBILENET_V2)
    if list(spec.mean_rgb) != RECIPE["mean_rgb"] or list(spec.stddev_rgb) != RECIPE["std_rgb"]:
        raise TrainingError("The pinned model normalization changed.")
    labels_path = args.dataset / "validation/labels.json"
    labels = read_json(args.dataset, labels_path)
    labels_hash = file_hash(labels_path)
    image_hashes = {str(image["id"]): file_hash(args.dataset / "validation/data" / image["file_name"])
                    for image in labels["images"]}
    generator_hash = file_hash(Path(__file__))
    frames = []
    args.output.mkdir(parents=True, exist_ok=True)
    for image in labels["images"]:
        source = args.dataset / "validation/data" / image["file_name"]
        pixels = tf.io.decode_jpeg(source.read_bytes(), channels=3)
        if pixels.shape.as_list() != [image["height"], image["width"], 3]:
            raise TrainingError("Decoded dimensions disagree with validation labels.")
        normalized = preprocess_ops.normalize_image(pixels, spec.mean_norm, spec.stddev_norm)
        padded, info = preprocess_ops.resize_and_crop_image(
            normalized, [256, 256], padded_size=[256, 256], aug_scale_min=1.0, aug_scale_max=1.0,
        )
        floats = padded.numpy()
        rgb = np.clip(np.rint((floats + 1) * 127.5), 0, 255).astype(np.uint8)
        path = args.output / f'{image["id"]}.png'
        with path.open("xb") as stream:
            stream.write(tf.io.encode_png(rgb).numpy())
        scale_y, scale_x = info[2].numpy().tolist()
        frames.append({"image_id": image["id"], "file": path.name, "original_sha256": image_hashes[str(image["id"])],
                       "png_sha256": file_hash(path), "width": 256, "height": 256,
                       "content_width": round(image["width"] * scale_x),
                       "content_height": round(image["height"] * scale_y),
                       "max_normalized_roundtrip_error": float(np.max(np.abs(rgb.astype(np.float32) / 127.5 - 1 - floats)))})
        print(f'Prepared TensorFlow RGB validation frame {image["id"]}', flush=True)
    report = {"schema_version": 1, "status": "prepared", "created_at": datetime.now(timezone.utc).isoformat(),
              "split": "validation", "architecture": "MOBILENET_V2", "recipe": RECIPE,
              "generator_sha256": generator_hash, "versions": versions, "dataset_audit": audit,
              "labels_sha256": labels_hash, "image_sha256": image_hashes, "frames": frames, "promoted": False,
              "limitations": ["Private validation probe, not a live camera preprocessing change.",
                  "RGB PNG rounding is not exact float tensor parity; normalized error is measured per frame.",
                  "No training, model promotion or test-split inference is performed."]}
    validate_manifest(report, labels, labels_hash, image_hashes)
    if (file_hash(labels_path) != labels_hash or file_hash(Path(__file__)) != generator_hash
            or any(file_hash(args.dataset / "validation/data" / image["file_name"]) != image_hashes[str(image["id"])]
                   for image in labels["images"])
            or any(file_hash(args.output / frame["file"]) != frame["png_sha256"] for frame in frames)):
        raise TrainingError("Preprocessing inputs or outputs changed.")
    write_json_report(args.output / "manifest.json", report)
    return report


def main() -> int:
    parser = ArgumentParser(description=__doc__)
    parser.add_argument("--dataset", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    try:
        args = parser.parse_args()
        args.dataset = args.dataset.resolve(strict=True)
        args.output = args.output.resolve()
        prepare(args)
        print("Prepared private browser frames; no model or live setting was changed.")
        return 0
    except (Exception, KeyboardInterrupt) as error:
        print(f"Browser frame preparation failed: {error}; no completed manifest is guaranteed.", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
