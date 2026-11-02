"""Prepare a private, training-only material-ambiguity exclusion experiment."""

from __future__ import annotations

import argparse
from collections import Counter
from datetime import datetime, timezone
import json
from pathlib import Path
import shutil
import sys
from types import SimpleNamespace
from typing import Any

from prepare_hitl import COLLECTION, digest, load_provenance, parse_annotation
from train_waste import (
    CLASSES, SPLITS, ArgumentParser, TrainingError, contained_file, integer,
    read_json, run_configuration, validate_dataset, validate_output, write_json_report,
)


def dataset_hash(dataset: Path) -> str:
    return run_configuration(SimpleNamespace(dataset=dataset, epochs=30, batch_size=2), {})["dataset_sha256"]


def prepare(args: argparse.Namespace) -> dict[str, Any]:
    validate_output(args.output, args.base)
    validate_dataset(args.base)
    for path in (args.assessment, args.review_index):
        if path.is_relative_to(args.output):
            raise TrainingError("Review inputs must be outside the experiment output.")
    assessment = read_json(args.assessment.parent, args.assessment)
    index = read_json(args.review_index.parent, args.review_index)
    proof = load_provenance(args.base)
    if proof is None:
        raise TrainingError("Material review exclusions require a provenanced HITL base.")
    proof_hash = digest(args.base / "hitl-provenance.json")
    context = assessment.get("base_provenance_sha256")
    queue, rows = assessment.get("material_confirmation_queue"), index.get("objects")
    if (assessment.get("status") != "visually_reviewed" or assessment.get("split") != "train"
            or assessment.get("existing_annotations_changed") is not False or assessment.get("promoted") is not False
            or not isinstance(assessment.get("reviewer"), str) or not assessment["reviewer"].strip()
            or not isinstance(queue, list) or not 1 <= len(queue) <= 100
            or not isinstance(rows, list) or not rows
            or index.get("training_only") is not True or index.get("prepared_only") is not True
            or index.get("base_provenance_sha256") != context
            or context not in (proof_hash, proof.get("parent_provenance_sha256"))
            or not isinstance(context, str) or len(context) != 64
            or assessment.get("wrapper_review_index_sha256") != digest(args.review_index)):
        raise TrainingError("Assessment, review bytes, or prepared training context do not match.")
    indexed = {}
    for row in rows:
        if not isinstance(row, dict) or not isinstance(row.get("image"), str) or not integer(row.get("source_object_id")):
            raise TrainingError("Invalid review object identity.")
        key = (row["image"], row["source_object_id"])
        if key in indexed:
            raise TrainingError("Duplicate review object identity.")
        indexed[key] = row
    datasets = {split: read_json(args.base, args.base / split / "labels.json") for split in SPLITS}
    training = {image["id"]: image for image in datasets["train"]["images"]}
    excluded, seen = {}, set()
    for decision in queue:
        if (not isinstance(decision, dict) or not isinstance(decision.get("image"), str)
                or not integer(decision.get("source_object_id")) or not integer(decision.get("prepared_image_id"))
                or decision.get("split") != "train" or decision.get("status") != "needs_material_confirmation"
                or decision.get("existing_training_label_changed") is not False
                or not isinstance(decision.get("reason"), str) or not decision["reason"].strip()):
            raise TrainingError("Only explicit, unchanged training-material review flags may be excluded.")
        key = (decision["image"], decision["source_object_id"])
        row = indexed.get(key)
        if row is None or key in seen or any(row.get(field) != decision.get(field) for field in (
                "source_sha256", "annotation_sha256", "source_bbox", "source_tags", "category",
                "prepared_image_id", "split", "sheet")):
            raise TrainingError("Exclusion is duplicated or differs from the hash-bound review object.")
        seen.add(key)
        source_id = f"{COLLECTION}:{decision['image']}"
        entry = proof["images"].get(source_id)
        image = training.get(decision["prepared_image_id"])
        if (not isinstance(entry, dict) or image is None or image.get("source_image_id") != source_id
                or entry.get("split") != "train" or entry.get("image_id") != image["id"]
                or any(entry.get(field) != decision.get(field) for field in ("source_sha256", "annotation_sha256"))):
            raise TrainingError("Exclusion must identify the exact retained training source, never a holdout.")
        document = json.loads(entry["source_annotation_json"])
        boxes, _ = parse_annotation(document)
        box = next((box for box in boxes if box["source_object_id"] == decision["source_object_id"]), None)
        original = next((obj for obj in document["objects"] if obj["id"] == decision["source_object_id"]), None)
        tags = {} if original is None else {tag["name"]: tag["value"] for tag in original["tags"]
                                          if tag["name"] in ("Material", "Object")}
        if (box is None or box["bbox"] != decision["source_bbox"]
                or CLASSES[box["category_id"]] != decision["category"] or tags != decision["source_tags"]):
            raise TrainingError("Flagged source object, geometry, or category changed.")
        if image["id"] not in excluded:
            excluded[image["id"]] = {
                "image": image, "prepared_sha256": entry["prepared_sha256"], "reviewed_objects": [],
                "annotations": [a for a in datasets["train"]["annotations"] if a["image_id"] == image["id"]],
            }
        excluded[image["id"]]["reviewed_objects"].append(decision)
    datasets["train"]["images"] = [image for image in datasets["train"]["images"] if image["id"] not in excluded]
    datasets["train"]["annotations"] = [a for a in datasets["train"]["annotations"] if a["image_id"] not in excluded]
    counts = {}
    for split, labels in datasets.items():
        classes = Counter(a["category_id"] for a in labels["annotations"])
        if not labels["images"] or any(not classes[key] for key in (1, 2, 3, 4)):
            raise TrainingError("Exclusions would remove required waste-class coverage.")
        counts[split] = {"images": len(labels["images"]), "annotations": len(labels["annotations"]),
                         "annotations_by_class": {CLASSES[key]: classes[key] for key in (1, 2, 3, 4)}}
    base_hash = dataset_hash(args.base)
    source_hashes = {"assessment": digest(args.assessment), "review_index": digest(args.review_index)}
    protected, retained = {}, {}
    for split, labels in datasets.items():
        for image in labels["images"]:
            relative = f"{split}/data/{image['file_name']}"
            retained[relative] = digest(contained_file(args.base, args.base / relative))
            if split != "train":
                protected[relative] = retained[relative]
        if split != "train":
            protected[f"{split}/labels.json"] = digest(args.base / split / "labels.json")
    args.output.mkdir(parents=True, exist_ok=True)
    for relative in retained:
        destination = args.output / relative
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(args.base / relative, destination)
    for split, labels in datasets.items():
        if split == "train":
            write_json_report(args.output / split / "labels.json", labels)
        else:
            shutil.copy2(args.base / split / "labels.json", args.output / split / "labels.json")
    proof["images"] = {key: entry for key, entry in proof["images"].items()
                       if entry.get("image_id") not in excluded}
    proof["parent_provenance_sha256"] = proof_hash
    write_json_report(args.output / "hitl-provenance.json", proof)
    if (args.base / "realdirt-provenance.json").exists():
        shutil.copy2(args.base / "realdirt-provenance.json", args.output / "realdirt-provenance.json")
    summary = {
        "status": "prepared", "full_dataset": True, "created_at": datetime.now(timezone.utc).isoformat(),
        "experiment": "training_material_ambiguity_exclusion", "base": str(args.base), "base_dataset_sha256": base_hash,
        "assessment": str(args.assessment), "review_index": str(args.review_index), "review_source_sha256": source_hashes,
        "license_verified_images": sum(count["images"] for count in counts.values()), "counts": counts,
        "excluded_training_images": list(excluded.values()), "protected_holdout_file_sha256": protected,
        "retained_image_sha256": retained,
        "split_policy": "Whole flagged training photos excluded; remaining IDs and annotations unchanged; holdout photos and labels byte-identical.",
        "retrained": False, "promoted": False, "accuracy_gain_measured": False, "missing_classes": ["leaves", "wood", "rock"],
        "limitations": [
            "Material ambiguity is not proof of a wrong label or a training root cause; no object was relabeled.",
            "Whole-photo exclusion also removes any other annotations in that photo, avoiding newly unlabeled objects.",
            "This changes training composition, not independent validation or camera-domain coverage.",
            "Compare with the parent run using identical training settings, protected validation photos, and browser worker.",
        ],
    }
    write_json_report(args.output / "summary.json", summary)
    try:
        validate_dataset(args.output)
        if (dataset_hash(args.base) != base_hash or digest(args.base / "hitl-provenance.json") != proof_hash
                or digest(args.assessment) != source_hashes["assessment"] or digest(args.review_index) != source_hashes["review_index"]
                or any(digest(args.output / relative) != expected for relative, expected in {**retained, **protected}.items())):
            raise TrainingError("Experiment inputs or preserved dataset bytes changed during preparation.")
        summary["dataset_sha256"] = dataset_hash(args.output)
        write_json_report(args.output / "summary.json", summary, replace=True)
    except (Exception, KeyboardInterrupt) as error:
        summary.update(status="failed", full_dataset=False, error=str(error) or type(error).__name__)
        write_json_report(args.output / "summary.json", summary, replace=True)
        raise
    return summary


def main(argv: list[str] | None = None) -> int:
    parser = ArgumentParser(description=__doc__)
    for name in ("base", "assessment", "review-index", "output"):
        parser.add_argument(f"--{name}", type=Path, required=True)
    try:
        args = parser.parse_args(argv)
        for name in ("base", "assessment", "review_index"):
            setattr(args, name, getattr(args, name).resolve(strict=True))
        args.output = args.output.resolve()
        summary = prepare(args)
        print(f"Prepared exclusion experiment: {len(summary['excluded_training_images'])} whole training photos omitted; "
              f"{summary['counts']['train']['images']} retained. Holdouts unchanged; no retraining or promotion.")
        return 0
    except (Exception, KeyboardInterrupt) as error:
        print(f"Ablation preparation failed: {error}; partial output is not a completed experiment.", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
