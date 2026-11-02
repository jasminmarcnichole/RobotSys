"""Add a bounded, publisher-licensed RealDirt training subset to prepared waste data."""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import math
import re
import shutil
import sys
import zipfile
from collections import Counter
from pathlib import Path, PurePosixPath
from typing import Any


COLLECTION = "zenodo:12517610"
SOURCE_URL = "https://zenodo.org/records/12517610"
LICENSE_URL = "https://creativecommons.org/licenses/by/4.0/"
ARCHIVE_MD5 = "bd8ddd318398188579dce65ce9adbdd5"
ARCHIVE_SIZE = 247758950
AUTHORS = ["Ershov, Egor", "Lysenko, Maksim", "Karasev, Oleg", "Startsev, Aleksandr"]
IMAGE_PATTERN = re.compile(r"RealDirt/train/images/([a-f0-9]+)\.jpg")


def digest(path: Path, algorithm: str = "sha256") -> str:
    with path.open("rb") as source:
        return hashlib.file_digest(source, algorithm).hexdigest()


def validate_publisher(record: dict[str, Any]) -> None:
    metadata = record.get("metadata", {})
    files = record.get("files", [])
    if (record.get("id") != 12517610
            or metadata.get("doi") != "10.5281/zenodo.12517610"
            or metadata.get("license", {}).get("id") != "cc-by-4.0"
            or metadata.get("access_right") != "open"
            or [item.get("name") for item in metadata.get("creators", [])] != AUTHORS
            or not any(item.get("key") == "RealDirt.zip"
                       and item.get("checksum") == f"md5:{ARCHIVE_MD5}"
                       and item.get("size") == ARCHIVE_SIZE for item in files)):
        raise ValueError("RealDirt publisher identity, license, authors, or archive checksum changed.")


def validate_archive(archive: zipfile.ZipFile) -> None:
    names = set()
    total = 0
    for member in archive.infolist():
        path = PurePosixPath(member.filename)
        total += member.file_size
        if (path.is_absolute() or ".." in path.parts or "\\" in member.filename
                or ":" in member.filename or member.filename in names
                or (member.external_attr >> 16) & 0o170000 == 0o120000
                or member.file_size > 32 * 1024 * 1024 or total > 2 * 1024**3):
            raise ValueError("Unsafe or oversized RealDirt archive member.")
        names.add(member.filename)


def yolo_boxes(text: str, width: int, height: int) -> list[dict[str, Any]]:
    boxes = []
    for line in text.splitlines():
        if not line.strip():
            continue
        fields = line.split()
        if len(fields) != 5 or fields[0] not in ("0", "1", "2"):
            raise ValueError("Unexpected RealDirt YOLO label.")
        cx, cy, bw, bh = map(float, fields[1:])
        if (not all(math.isfinite(value) for value in (cx, cy, bw, bh))
                or bw <= 0 or bh <= 0 or cx - bw / 2 < -1e-6
                or cy - bh / 2 < -1e-6 or cx + bw / 2 > 1 + 1e-6
                or cy + bh / 2 > 1 + 1e-6):
            raise ValueError("Invalid RealDirt bounding box.")
        if fields[0] == "2":
            continue
        x1, y1 = max(0, cx - bw / 2) * width, max(0, cy - bh / 2) * height
        x2, y2 = min(1, cx + bw / 2) * width, min(1, cy + bh / 2) * height
        boxes.append({"category_id": int(fields[0]) + 1, "bbox": [x1, y1, x2 - x1, y2 - y1], "iscrowd": 0})
    return boxes


def load_provenance(root: Path) -> dict[str, Any] | None:
    path = root / "realdirt-provenance.json"
    if not path.exists():
        return None
    if not path.resolve(strict=True).is_relative_to(root):
        raise ValueError("RealDirt provenance must be inside the dataset.")
    proof = json.loads(path.read_text(encoding="utf-8"))
    validate_publisher(proof["publisher"])
    if (proof.get("collection") != COLLECTION or proof.get("archive_md5") != ARCHIVE_MD5
            or not re.fullmatch(r"[a-f0-9]{64}", proof.get("archive_sha256", ""))
            or not isinstance(proof.get("images"), dict)):
        raise ValueError("Invalid RealDirt provenance record.")
    return proof


def approved_image(image: dict[str, Any], path: Path, proof: dict[str, Any] | None, split: str) -> bool:
    if proof is None or split != "train":
        return False
    entry = proof["images"].get(image.get("source_image_id"))
    attribution = image.get("attribution", {})
    if not isinstance(entry, dict) or not isinstance(attribution, dict):
        return False
    return (image.get("source_collection") == COLLECTION
            and IMAGE_PATTERN.fullmatch(entry.get("archive_member", "")) is not None
            and image.get("source_image_id") == f"{COLLECTION}:{entry['archive_member']}"
            and entry.get("image_id") == image.get("id")
            and attribution.get("verified") is True
            and attribution.get("source_url") == SOURCE_URL
            and attribution.get("license_url") == LICENSE_URL
            and attribution.get("author") == "; ".join(AUTHORS)
            and entry.get("prepared_sha256") == digest(path))


def prepare(args: argparse.Namespace) -> dict[str, Any]:
    from PIL import Image, ImageChops, ImageOps, ImageStat
    from train_waste import CLASSES, SPLITS, validate_dataset, validate_output

    base = args.base.resolve(strict=True)
    output = args.output.resolve()
    validate_output(output, base)
    validate_dataset(base)
    if (base / "realdirt-provenance.json").exists():
        raise ValueError("Base dataset already contains RealDirt; start from the original prepared dataset.")
    publisher = json.loads(args.publisher.read_text(encoding="utf-8-sig"))
    validate_publisher(publisher)
    if args.archive.stat().st_size != ARCHIVE_SIZE or digest(args.archive, "md5") != ARCHIVE_MD5:
        raise ValueError("RealDirt download does not match the publisher's archive checksum.")
    proof = {"collection": COLLECTION, "publisher": publisher, "archive_md5": ARCHIVE_MD5,
             "archive_sha256": digest(args.archive), "images": {}}
    with zipfile.ZipFile(args.archive) as archive:
        validate_archive(archive)
        yaml = archive.read("RealDirt/data.yaml").decode("utf-8")
        if yaml.splitlines()[:5] != ["names:", "- plast_bot", "- plast_bag", "- carton", "nc: 3"]:
            raise ValueError("Unexpected RealDirt class order.")
        members = [name for name in archive.namelist() if IMAGE_PATTERN.fullmatch(name)]
        members.sort(key=lambda name: hashlib.sha256(f"robotsys-realdirt-v1:{name}".encode()).hexdigest())
        output.mkdir(parents=True, exist_ok=True)
        datasets = {}
        existing_hashes = set()
        for split in SPLITS:
            datasets[split] = json.loads((base / split / "labels.json").read_text(encoding="utf-8"))
            shutil.copytree(base / split / "data", output / split / "data")
            existing_hashes.update(digest(path) for path in (output / split / "data").glob("*.jpg"))
        next_id = max(image["id"] for data in datasets.values() for image in data["images"]) + 1
        annotation_id = max(item["id"] for data in datasets.values() for item in data["annotations"]) + 1
        excluded = []
        thumbnails = []
        for name in members:
            if len(proof["images"]) >= args.limit:
                break
            label_name = name.replace("/images/", "/labels/").removesuffix(".jpg") + ".txt"
            raw = archive.read(name)
            with Image.open(io.BytesIO(raw)) as original:
                if original.width * original.height > 20_000_000 or original.getexif().get(274, 1) != 1:
                    raise ValueError(f"Unsupported dimensions or EXIF orientation: {name}")
                original.load()
                image = ImageOps.contain(original.convert("RGB"), (640, 640))
            boxes = yolo_boxes(archive.read(label_name).decode("utf-8"), image.width, image.height)
            if not any(box["category_id"] == 1 for box in boxes):
                excluded.append({"image": name, "reason": "No annotated plastic bottle; bottle-focused selection."})
                continue
            thumbnail = image.convert("L").resize((32, 32))
            duplicate = next((previous_name for previous_name, previous in thumbnails
                              if ImageStat.Stat(ImageChops.difference(thumbnail, previous)).mean[0] <= 6), None)
            if duplicate is not None:
                excluded.append({"image": name, "reason": "Near-duplicate training frame (32x32 grayscale MAE <= 6).",
                                 "retained_source": duplicate})
                continue
            encoded = io.BytesIO()
            image.save(encoded, format="JPEG", quality=90)
            prepared = encoded.getvalue()
            prepared_hash = hashlib.sha256(prepared).hexdigest()
            if prepared_hash in existing_hashes:
                excluded.append({"image": name, "reason": "Exact prepared-image duplicate."})
                continue
            source_id = f"{COLLECTION}:{name}"
            (output / "train" / "data" / f"{next_id}.jpg").write_bytes(prepared)
            datasets["train"]["images"].append({
                "id": next_id, "file_name": f"{next_id}.jpg", "width": image.width, "height": image.height,
                "source_collection": COLLECTION, "source_image_id": source_id,
                "attribution": {"verified": True, "author": "; ".join(AUTHORS),
                                "source_url": SOURCE_URL, "license_url": LICENSE_URL},
            })
            proof["images"][source_id] = {"image_id": next_id, "archive_member": name,
                "source_sha256": hashlib.sha256(raw).hexdigest(), "prepared_sha256": prepared_hash,
                "labels_sha256": hashlib.sha256(archive.read(label_name)).hexdigest()}
            for box in boxes:
                datasets["train"]["annotations"].append({"id": annotation_id, "image_id": next_id, **box})
                annotation_id += 1
            existing_hashes.add(prepared_hash)
            thumbnails.append((name, thumbnail))
            next_id += 1
    if not proof["images"]:
        raise ValueError("No usable RealDirt bottle images were found.")
    summary = {"status": "prepared", "full_dataset": True,
        "license_verified_images": sum(len(data["images"]) for data in datasets.values()),
        "source": SOURCE_URL, "base_dataset": str(base), "added_training_images": len(proof["images"]),
        "requested_limit": args.limit, "available_source_images": len(members), "excluded": excluded,
        "selection": "Deterministic bottle-containing subset, filtered at grayscale 32x32 MAE <= 6; original archive train images remain training-only.",
        "split_policy": "Base splits preserved, no RealDirt validation/test split invented.",
        "limitations": ["Publisher license checked, not independent verification of image ownership.",
            "Recycling-plant domain; no held-out RealDirt accuracy claim.",
            "Near-duplicate filtering is heuristic; no scene metadata, so remaining similar frames are possible.",
            "Published description claims 2000 images; this pinned archive contains 500 image/label pairs.",
            "Carton is ignored, not relabeled as paper."],
        "missing_classes": ["leaves", "wood", "rock"], "counts": {}}
    for split, data in datasets.items():
        (output / split / "labels.json").write_text(json.dumps(data, indent=2) + "\n", encoding="utf-8")
        counts = Counter(item["category_id"] for item in data["annotations"])
        summary["counts"][split] = {"images": len(data["images"]), "annotations": len(data["annotations"]),
            "annotations_by_class": {CLASSES[key]: counts[key] for key in (1, 2, 3, 4)}}
    (output / "realdirt-provenance.json").write_text(json.dumps(proof, indent=2) + "\n", encoding="utf-8")
    (output / "summary.json").write_text(json.dumps(summary, indent=2) + "\n", encoding="utf-8")
    validate_dataset(output)
    return summary


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ("base", "archive", "publisher", "output"):
        parser.add_argument(f"--{name}", type=Path, required=True)
    parser.add_argument("--limit", type=int, default=150)
    args = parser.parse_args()
    try:
        if args.limit < 1:
            raise ValueError("--limit must be positive.")
        print(json.dumps(prepare(args), indent=2))
        return 0
    except Exception as error:
        print(f"RealDirt preparation failed: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
