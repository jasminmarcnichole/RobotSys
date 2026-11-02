"""Collect and prepare a bounded publisher-licensed HITL recycling subset."""

from __future__ import annotations

import argparse
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
import hashlib
import io
import json
from pathlib import Path
import shutil
import sys
from typing import Any
from urllib.parse import quote, urlparse
import zipfile

from prepare_waste import fetch, write_json

COLLECTION = "kaggle:humansintheloop/recycling-dataset:v2"
SOURCE_URL = "https://www.kaggle.com/datasets/humansintheloop/recycling-dataset/versions/2"
PUBLISHER_URL = "https://humansintheloop.org/resources/datasets/recycling-dataset/"
API = "https://www.kaggle.com/api/v1/datasets/"
LICENSE_URL = "https://creativecommons.org/publicdomain/zero/1.0/"
OTHER_PLASTIC = {"bag", "binbag", "bottlecap", "candywrapper", "carrierbag", "cellophanewrapper", "container",
                 "cup", "cutlery", "drinkpouch", "gloves", "lid", "smallpiece", "largepiece", "piece",
                 "stirrer", "straw", "strawwrapper", "tiewrap", "wrapper", "ziplock"}
PAPER = {"bag", "carrierbag", "cup", "flyer", "label", "newspaper", "receipt", "straw", "strawwrapper", "tissue", "wrapper"}

# HITL annotations were prepared with the old 4-class taxonomy; translate to new 7-class taxonomy
OLD_TO_NEW = {1: 1, 2: 2, 3: 4, 4: 5}


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def candidate_paths(root: Path, candidate: dict[str, Any]) -> tuple[Path, Path]:
    from train_waste import contained_file

    name = candidate.get("image", "")
    annotation = candidate.get("annotation", "")
    if (not isinstance(name, str) or not name.startswith("ds0/img/")
            or name != "ds0/img/" + Path(name).name or "\\" in name or ".." in name
            or annotation != Path(name).name + ".json"):
        raise ValueError("Unexpected candidate source paths.")
    return (contained_file(root, root / "images" / Path(name).name),
            contained_file(root, root / "annotations" / annotation))


def read_collection(root: Path) -> dict[str, Any]:
    from train_waste import contained_file

    record = json.loads(contained_file(root, root / "collection.json").read_text(encoding="utf-8"))
    publisher_path = contained_file(root, root / "publisher.json")
    validate_publisher(json.loads(publisher_path.read_text(encoding="utf-8")))
    if (record.get("status") != "collected" or record.get("collection") != COLLECTION
            or digest(publisher_path) != record.get("publisher_sha256")
            or digest(contained_file(root, root / "files.json")) != record.get("file_index_sha256")):
        raise ValueError("Source collection or publisher bytes changed.")
    return record


def source_image(path: Path, document: dict[str, Any]) -> Any:
    from PIL import Image, ImageOps

    with Image.open(path) as original:
        if original.width * original.height > 20_000_000 or original.getexif().get(274, 1) not in range(9):
            raise ValueError("Unexpected image size or EXIF orientation.")
        oriented = ImageOps.exif_transpose(original).convert("RGB")
        if oriented.size != (document["size"]["width"], document["size"]["height"]):
            raise ValueError("Oriented image dimensions disagree with source labels.")
        return oriented


def allowed_url(url: str) -> bool:
    parsed = urlparse(url)
    return (parsed.scheme == "https" and parsed.username is None and parsed.port in (None, 443)
            and ((parsed.hostname == "www.kaggle.com" and parsed.path.startswith("/api/v1/datasets/"))
                 or (parsed.hostname == "storage.googleapis.com" and parsed.path.startswith((
                     "/kagglesdsdata/datasets/3380384/5884426/", "/kaggle-data-sets/3380384/5884426/")))))


def download(name: str) -> bytes:
    if name != "meta.json" and (not name.startswith(("ds0/ann/", "ds0/img/")) or ".." in name or "\\" in name
                               or len(name.split("/")) != 3):
        raise ValueError("Unexpected publisher file path.")
    content = fetch(API + "download/humansintheloop/recycling-dataset/" + quote(name, safe="") + "?datasetVersionNumber=2",
                    allowed_url, maximum=32_000_000)
    if content.startswith(b"PK"):
        with zipfile.ZipFile(io.BytesIO(content)) as archive:
            members = archive.infolist()
            if len(members) != 1 or members[0].filename != Path(name).name or members[0].file_size > 32_000_000:
                raise ValueError("Unexpected single-file download wrapper.")
            content = archive.read(members[0])
    return content


def validate_publisher(publisher: dict[str, Any]) -> None:
    if (publisher.get("id") != 3380384 or publisher.get("ref") != "humansintheloop/recycling-dataset"
            or publisher.get("ownerRef") != "humansintheloop" or publisher.get("licenseName") != "CC0: Public Domain"
            or publisher.get("isPrivate") is not False
            or not any(v.get("versionNumber") == 2 and v.get("status") == "Ready" for v in publisher.get("versions", []))
            or "CC0 1.0" not in publisher.get("description", "")):
        raise ValueError("Publisher identity, pinned version, or CC0 license could not be confirmed.")


def source_split(actor: str) -> str:
    bucket = int(hashlib.sha256(("robotsys-hitl-v1:" + actor).encode()).hexdigest()[:8], 16) % 100
    return "train" if bucket < 70 else "validation" if bucket < 85 else "test"


def parse_annotation(document: dict[str, Any]) -> tuple[list[dict[str, Any]], str]:
    from train_waste import finite_number, integer

    size, objects = document.get("size", {}), document.get("objects")
    if not isinstance(objects, list) or any(not integer(size.get(k)) or size[k] <= 0 for k in ("width", "height")):
        raise ValueError("Invalid Supervisely image size or object list.")
    actors = {obj.get("labelerLogin") for obj in objects}
    if len(actors) != 1 or not all(isinstance(actor, str) and actor.startswith("portugal") for actor in actors):
        raise ValueError("Missing or mixed annotator identity; cannot preserve conservative group splits.")
    mapped = []
    ids = set()
    for obj in objects:
        if not integer(obj.get("id")) or obj["id"] in ids or obj.get("geometryType") != "rectangle" or obj.get("classTitle") != "litter":
            raise ValueError("Unexpected source object identity or geometry.")
        ids.add(obj["id"])
        points = obj.get("points", {})
        exterior = points.get("exterior")
        if (points.get("interior") != [] or not isinstance(exterior, list) or len(exterior) != 2
                or any(not isinstance(p, list) or len(p) != 2 or not all(finite_number(v) for v in p) for p in exterior)):
            raise ValueError("Expected a finite rectangle with two exterior points.")
        (x, y), (right, bottom) = exterior
        if not (0 <= x < right <= size["width"] and 0 <= y < bottom <= size["height"]):
            raise ValueError("Source rectangle is outside the image.")
        tags = {}
        for tag in obj.get("tags", []):
            if tag.get("name") in ("Material", "Object"):
                if tag["name"] in tags or not isinstance(tag.get("value"), str):
                    raise ValueError("Missing or ambiguous material/object tags.")
                tags[tag["name"]] = tag["value"]
        material, kind = tags.get("Material"), tags.get("Object")
        category = None
        if material == "plastic":
            category = 1 if kind in ("bottle", "largebottle") else 2 if kind in OTHER_PLASTIC else None
        elif material == "paper" and kind in PAPER:
            category = 3
        elif material in ("metal", "aluminum") and kind == "can":
            category = 4
        if category is not None:
            mapped.append({"source_object_id": obj["id"], "category_id": category,
                           "bbox": [x, y, right-x, bottom-y], "requires_can_review": category == 4})
    return mapped, next(iter(actors))


def collect(args: argparse.Namespace) -> None:
    from train_waste import validate_dataset, validate_output

    refresh_validation = getattr(args, "refresh_validation", False)
    previous = None
    if refresh_validation:
        validate_dataset(args.base)
        previous = load_provenance(args.base)
        if previous is None:
            raise ValueError("Fresh validation collection requires a provenanced HITL base.")
    if args.source.exists() and any(args.source.iterdir()):
        marker = args.source / "publisher.json"
        if not marker.is_file() or any(path.name not in {"publisher.json", "files.json", "annotations", "images", "collection.json"} or path.is_symlink() for path in args.source.iterdir()):
            raise ValueError("Refusing to mix an unrelated source directory with this collection.")
        validate_publisher(json.loads(marker.read_text(encoding="utf-8")))
        public = (Path(__file__).resolve().parents[2] / "public").resolve()
        if args.source.is_relative_to(public) or args.source.is_relative_to(args.base) or args.base.is_relative_to(args.source):
            raise ValueError("Source cache must be private and separate from the base dataset.")
    else:
        validate_output(args.source, args.base)
    args.source.mkdir(parents=True, exist_ok=True)
    publisher_path = args.source / "publisher.json"
    if not publisher_path.exists():
        publisher = json.loads(fetch(API + "view/humansintheloop/recycling-dataset", allowed_url))
        validate_publisher(publisher)
        write_json(publisher_path, publisher)
    validate_publisher(json.loads(publisher_path.read_text(encoding="utf-8")))
    index_path = args.source / "files.json"
    if not index_path.exists():
        files, token = [], None
        for _ in range(40):
            url = API + "list/humansintheloop/recycling-dataset?datasetVersionNumber=2&pageSize=200"
            if token:
                url += "&pageToken=" + quote(token, safe="")
            page = json.loads(fetch(url, allowed_url))
            files.extend(item["name"] for item in page["datasetFiles"])
            token = page.get("nextPageToken")
            if not token:
                break
        if token or len(files) != len(set(files)):
            raise ValueError("Incomplete or duplicated publisher file index.")
        write_json(index_path, files)
    files = set(json.loads(index_path.read_text(encoding="utf-8")))
    names = sorted((name for name in files if name.startswith("ds0/ann/")),
                   key=lambda name: hashlib.sha256(("robotsys-hitl-v1:"+name).encode()).hexdigest())[:args.scan_limit]
    annotations = args.source / "annotations"
    annotations.mkdir(exist_ok=True)
    def cache_path(directory: Path, name: str) -> Path:
        path = directory / Path(name).name
        if path.is_symlink() or not path.resolve().is_relative_to(args.source):
            raise ValueError("Source cache path escapes its private collection.")
        return path

    def get_annotation(name: str) -> tuple[str, dict[str, Any] | None, str | None]:
        try:
            path = cache_path(annotations, name)
            if not path.exists():
                path.write_bytes(download(name))
            return name, json.loads(path.read_text(encoding="utf-8")), None
        except Exception as error:
            return name, None, str(error)
    candidates, excluded = [], []
    counts: Counter[tuple[str, int]] = Counter()
    with ThreadPoolExecutor(max_workers=4) as pool:
        for index, (name, document, error) in enumerate(pool.map(get_annotation, names), 1):
            try:
                if error:
                    raise ValueError(error)
                boxes, actor = parse_annotation(document)
                image_name = "ds0/img/" + Path(name).name.removesuffix(".json")
                if image_name not in files:
                    raise ValueError("No matching image in the publisher index.")
                split = source_split(actor)
                if refresh_validation and (split != "validation" or f"{COLLECTION}:{image_name}" in previous["images"]
                                           or f"{COLLECTION}:{image_name}" in previous.get("previous_evaluation_source_ids", [])):
                    continue
                classes = {box["category_id"] for box in boxes}
                limit = args.train_per_class if split == "train" else args.holdout_per_class
                if not classes or all(counts[split, category] >= limit for category in classes):
                    continue
                candidates.append({"annotation": Path(name).name, "image": image_name,
                                   "split": split, "actor": actor, "categories": sorted(classes)})
                for category in classes:
                    counts[split, category] += 1
            except Exception as error:
                excluded.append({"annotation": name, "reason": str(error)})
            if index % 50 == 0:
                print(f"Checked {index}/{len(names)} annotations; selected {len(candidates)} images.", flush=True)
    images = args.source / "images"
    images.mkdir(exist_ok=True)
    def get_image(candidate: dict[str, Any]) -> dict[str, Any]:
        path = cache_path(images, candidate["image"])
        if not path.exists():
            path.write_bytes(download(candidate["image"]))
        return {**candidate, "source_sha256": digest(path), "annotation_sha256": digest(annotations / candidate["annotation"])}
    with ThreadPoolExecutor(max_workers=4) as pool:
        candidates = list(pool.map(get_image, candidates))
    if not candidates:
        raise ValueError(f"No usable source images were collected; sample failures: {excluded[:3]}")
    write_json(args.source / "collection.json", {"status": "collected", "collection": COLLECTION,
        "publisher_sha256": digest(publisher_path), "file_index_sha256": digest(index_path),
        "scan_limit": args.scan_limit, "candidates": candidates, "excluded": excluded,
        "counts": {f"{split}/{category}": count for (split, category), count in sorted(counts.items())},
        "can_policy": "Every can requires explicit visual drink/food review; aerosol and unknown cans are excluded.",
        "group_policy": "Deterministic annotator-group split, a conservative proxy rather than proven scene independence.",
        "validation_only_refresh": refresh_validation,
        "excluded_base_provenance_sha256": digest(args.base / "hitl-provenance.json") if refresh_validation else None,
        "missing_classes": ["leaves", "wood", "rock"], "promoted": False})
    print(f"Collected {len(candidates)} publisher-annotated photos; can review and duplicate checks are still required.")


def load_provenance(root: Path) -> dict[str, Any] | None:
    from train_waste import contained_file

    path = root / "hitl-provenance.json"
    if not path.exists():
        return None
    proof = json.loads(contained_file(root, path).read_text(encoding="utf-8"))
    validate_publisher(proof["publisher"])
    if proof.get("collection") != COLLECTION or not isinstance(proof.get("images"), dict):
        raise ValueError("Invalid HITL provenance.")
    return proof


def review_sheets(args: argparse.Namespace) -> None:
    from PIL import Image, ImageDraw, ImageFont, ImageOps
    from train_waste import CLASSES, validate_dataset, validate_output

    validate_output(args.output, args.source)
    validate_output(args.output, args.base)
    classes = getattr(args, "review_class", None) or ["metal_can"]
    if not isinstance(classes, list) or any(name not in tuple(CLASSES.values())[1:] for name in classes):
        raise ValueError("Review classes must be canonical waste classes.")
    categories = {key for key, name in CLASSES.items() if name in classes}
    training_only = getattr(args, "training_only", False)
    prepared_only = getattr(args, "prepared_only", False)
    refresh_validation = getattr(args, "refresh_validation", False)
    if refresh_validation and (training_only or prepared_only):
        raise ValueError("Fresh validation review cannot select training or already-prepared objects.")
    proof = None
    if prepared_only or refresh_validation:
        validate_dataset(args.base)
        proof = load_provenance(args.base)
        if proof is None:
            raise ValueError("Prepared-only review requires a provenanced HITL base.")
    record = read_collection(args.source)
    rows = []
    args.output.mkdir(parents=True, exist_ok=True)
    font = ImageFont.load_default(size=14)
    canvas = None
    for candidate in record["candidates"]:
        source, annotation = candidate_paths(args.source, candidate)
        if digest(source) != candidate["source_sha256"] or digest(annotation) != candidate["annotation_sha256"]:
            raise ValueError("Source changed before visual review.")
        document = json.loads(annotation.read_text(encoding="utf-8"))
        boxes, actor = parse_annotation(document)
        if actor != candidate["actor"] or source_split(actor) != candidate["split"]:
            raise ValueError("Source group or split changed before visual review.")
        if training_only and candidate["split"] != "train":
            continue
        entry = None if proof is None else proof["images"].get(f"{COLLECTION}:{candidate['image']}")
        if refresh_validation and (candidate["split"] != "validation" or entry is not None
                                   or f"{COLLECTION}:{candidate['image']}" in proof.get("previous_evaluation_source_ids", [])):
            continue
        if prepared_only:
            if entry is None:
                continue
            if any(entry.get(key) != candidate.get(key) for key in ("source_sha256", "annotation_sha256", "split", "actor")):
                raise ValueError("Prepared source identity changed before visual review.")
        for box in boxes:
            if box["category_id"] not in categories:
                continue
            original = next(obj for obj in document["objects"] if obj["id"] == box["source_object_id"])
            tags = {tag["name"]: tag["value"] for tag in original["tags"] if tag["name"] in ("Material", "Object")}
            index = len(rows)
            prefix = "cans" if categories == {4} else "objects"
            if index % 12 == 0:
                if canvas is not None:
                    canvas.save(args.output / f"{prefix}-{(index-1)//12+1}.png")
                canvas = Image.new("RGB", (1400, 1020), "white")
            x, y, width, height = box["bbox"]
            with source_image(source, document) as image:
                crop = image.crop((max(0, x-width*.15), max(0, y-height*.15),
                                   min(image.width, x+width*1.15), min(image.height, y+height*1.15)))
                thumb = ImageOps.contain(crop.convert("RGB"), (330, 245))
            left, top = (index % 4)*350+10, ((index % 12)//4)*340+10
            canvas.paste(thumb, (left, top+75))
            drawing = ImageDraw.Draw(canvas)
            drawing.text((left, top), f"{box['source_object_id']} / {candidate['split']}", fill="black", font=font)
            drawing.text((left, top+22), Path(candidate["image"]).name, fill="black", font=font)
            drawing.text((left, top+44), f"{tags['Material']} / {tags['Object']} / {CLASSES[box['category_id']]}", fill="black", font=font)
            rows.append({"source_object_id": box["source_object_id"], "source_sha256": candidate["source_sha256"],
                         "annotation_sha256": candidate["annotation_sha256"], "source_bbox": box["bbox"], "source_tags": tags,
                         "category": CLASSES[box["category_id"]], "prepared_image_id": entry["image_id"] if entry else None,
                         "image": candidate["image"], "split": candidate["split"], "sheet": f"{prefix}-{index//12+1}.png"})
    if canvas is not None:
        canvas.save(args.output / f"{prefix}-{(len(rows)-1)//12+1}.png")
    write_json(args.output / "review-index.json", {"status": "pending_visual_review", "objects": rows,
        "collection_sha256": digest(args.source / "collection.json"), "accepted_labels": ["drink_can", "food_can"],
        "classes": sorted(classes), "training_only": training_only, "prepared_only": prepared_only,
        "validation_only_refresh": refresh_validation,
        "base_provenance_sha256": digest(args.base / "hitl-provenance.json") if proof is not None else None,
        "instruction": "Aerosol or unidentifiable objects must not be approved as drink/food cans. Source tags are unreviewed material claims."})
    print(f"Rendered {len(rows)} object crops for visual review; no labels changed.")


def approved_image(image: dict[str, Any], path: Path, proof: dict[str, Any] | None, split: str,
                   annotations: list[dict[str, Any]]) -> bool:
    if proof is None:
        return False
    entry = proof["images"].get(image.get("source_image_id"), {})
    attribution = image.get("attribution", {})
    raw = entry.get("source_annotation_json", "")
    if hashlib.sha256(raw.encode("utf-8")).hexdigest() != entry.get("annotation_sha256"):
        return False
    document = json.loads(raw)
    boxes, actor = parse_annotation(document)
    if actor != entry.get("actor") or not boxes:
        return False
    for box in boxes:
        if box["requires_can_review"]:
            review = entry.get("can_reviews", {}).get(str(box["source_object_id"]), {})
            if (review.get("label") not in ("drink_can", "food_can")
                    or review.get("source_sha256") != entry.get("source_sha256") or not review.get("reviewer")):
                return False
    expected = sorted((OLD_TO_NEW[box["category_id"]], tuple(v * image["width" if i % 2 == 0 else "height"] /
        document["size"]["width" if i % 2 == 0 else "height"] for i, v in enumerate(box["bbox"]))) for box in boxes)
    actual = sorted((a["category_id"], tuple(a["bbox"])) for a in annotations if a.get("image_id") == image["id"])
    if expected != actual:
        return False
    return (image.get("source_collection") == COLLECTION and entry.get("split") == split
            and image.get("id") == entry.get("image_id") and image.get("group_id") == f"{COLLECTION}:annotator:{entry.get('actor')}"
            and image.get("source_image_id") == f"{COLLECTION}:{entry.get('image')}"
            and source_split(entry.get("actor", "")) == split and digest(path) == entry.get("prepared_sha256")
            and attribution.get("verified") is True and attribution.get("license_url") == LICENSE_URL
            and attribution.get("source_url") == SOURCE_URL and attribution.get("author") == "Humans in the Loop / Inovinter contributors")


def prepare(args: argparse.Namespace) -> dict[str, Any]:
    from PIL import Image, ImageChops, ImageOps, ImageStat
    from train_waste import CLASSES, validate_dataset, validate_output

    validate_dataset(args.base)
    extend_training = getattr(args, "extend_training", False)
    refresh_validation = getattr(args, "refresh_validation", False)
    if extend_training and refresh_validation:
        raise ValueError("Training expansion and fresh validation preparation are mutually exclusive.")
    minimum = getattr(args, "min_validation_per_class", 5)
    if refresh_validation and (type(minimum) is not int or not 1 <= minimum <= 10):
        raise ValueError("Minimum validation coverage must be an integer from 1 to 10.")
    previous_proof = load_provenance(args.base)
    if extend_training and (previous_proof or {}).get("evaluation_only") is True:
        raise ValueError("Evaluation-only datasets cannot be expanded for training; use the original training base.")
    if previous_proof is not None and not (extend_training or refresh_validation):
        raise ValueError("A HITL base is not supported; use the original non-HITL base.")
    if (extend_training or refresh_validation) and previous_proof is None:
        raise ValueError("Training expansion or fresh validation requires a prepared HITL base with protected holdouts.")
    validate_output(args.output, args.base)
    if args.output.is_relative_to(args.source) or args.source.is_relative_to(args.output):
        raise ValueError("Prepared output and source cache must be separate.")
    record = read_collection(args.source)
    publisher = json.loads((args.source / "publisher.json").read_text(encoding="utf-8"))
    validate_publisher(publisher)
    if record.get("status") != "collected" or record.get("collection") != COLLECTION or digest(args.source / "publisher.json") != record["publisher_sha256"]:
        raise ValueError("Source collection or publisher bytes changed.")
    reviews = json.loads(args.reviews.read_text(encoding="utf-8")) if args.reviews is not None else {}
    base_bytes = {split: (args.base / split / "labels.json").read_bytes() for split in ("train", "validation", "test")}
    base_labels = {split: json.loads(content) for split, content in base_bytes.items()}
    base_hashes = {f"{split}/labels.json": hashlib.sha256(content).hexdigest() for split, content in base_bytes.items()}
    thumbnails, hashes = [], set()
    for split, labels in base_labels.items():
        for image in labels["images"]:
            path = args.base / split / "data" / image["file_name"]
            image_hash = digest(path)
            hashes.add(image_hash)
            base_hashes[f"{split}/data/{image['file_name']}"] = image_hash
            with Image.open(path) as decoded:
                thumbnails.append((f"base/{split}/{image['id']}", decoded.convert("L").resize((32, 32))))
    datasets = dict(base_labels) if extend_training or refresh_validation else {"train": base_labels["train"], **{
        split: {"categories": base_labels[split]["categories"], "images": [], "annotations": []}
        for split in ("validation", "test")}}
    if refresh_validation:
        datasets["validation"] = {"categories": base_labels["validation"]["categories"], "images": [], "annotations": []}
    next_id = max(image["id"] for labels in base_labels.values() for image in labels["images"]) + 1
    next_annotation = max(annotation["id"] for labels in base_labels.values() for annotation in labels["annotations"]) + 1
    proof = ({**previous_proof, "images": dict(previous_proof["images"])} if extend_training or refresh_validation
             else {"collection": COLLECTION, "publisher": publisher, "images": {}})
    if extend_training or refresh_validation:
        if publisher != proof["publisher"]:
            raise ValueError("Expansion publisher differs from the protected base dataset.")
        proof["parent_provenance_sha256"] = digest(args.base / "hitl-provenance.json")
    proof["collection_sha256"] = digest(args.source / "collection.json")
    pending, excluded, prepared = [], [], []
    seen_sources = set()
    for candidate in record["candidates"]:
        source, annotation = candidate_paths(args.source, candidate)
        if digest(source) != candidate["source_sha256"] or digest(annotation) != candidate["annotation_sha256"]:
            raise ValueError("Downloaded source image or annotation bytes changed.")
        annotation_json = annotation.read_bytes().decode("utf-8")
        document = json.loads(annotation_json)
        boxes, actor = parse_annotation(document)
        if actor != candidate["actor"] or source_split(actor) != candidate["split"]:
            raise ValueError("Source group or split changed.")
        source_id = f"{COLLECTION}:{candidate['image']}"
        if source_id in seen_sources:
            raise ValueError("Repeated source identity in candidate collection.")
        seen_sources.add(source_id)
        if (extend_training or refresh_validation) and source_id in previous_proof["images"]:
            existing = previous_proof["images"][source_id]
            if any(existing.get(key) != candidate.get(key) for key in ("source_sha256", "annotation_sha256", "split", "actor")):
                raise ValueError("Previously prepared source identity or bytes changed.")
            excluded.append({"image": candidate["image"], "reason": "Already present in protected base"})
            continue
        if refresh_validation and source_id in previous_proof.get("previous_evaluation_source_ids", []):
            excluded.append({"image": candidate["image"], "reason": "Previously used evaluation photo"})
            continue
        if extend_training and candidate["split"] != "train":
            excluded.append({"image": candidate["image"], "reason": "Holdout split protected; not added to training"})
            continue
        if refresh_validation and candidate["split"] != "validation":
            excluded.append({"image": candidate["image"], "reason": "Only unused validation-group photos may enter fresh validation"})
            continue
        can_boxes = [box for box in boxes if box["requires_can_review"]]
        for box in can_boxes:
            review = reviews.get(str(box["source_object_id"]), {})
            if review.get("source_sha256") != candidate["source_sha256"]:
                raise ValueError(f"Can review source mismatch for object {box['source_object_id']}")
            if review.get("label") not in ("drink_can", "food_can"):
                raise ValueError(f"Invalid can review label for object {box['source_object_id']}: {review.get('label')}")
            if not review.get("reviewer"):
                raise ValueError(f"Missing can reviewer for object {box['source_object_id']}")
        with source_image(source, document) as original:
            image = ImageOps.contain(original, (640, 640))
        thumbnail = image.convert("L").resize((32, 32))
        duplicate = next((name for name, previous in thumbnails if ImageStat.Stat(ImageChops.difference(thumbnail, previous)).mean[0] <= 6), None)
        if duplicate is not None or candidate["source_sha256"] in hashes:
            excluded.append({"image": candidate["image"], "reason": "Exact or heuristic near duplicate", "matches": duplicate})
            continue
        encoded = io.BytesIO()
        image.save(encoded, format="JPEG", quality=90)
        content = encoded.getvalue()
        split = candidate["split"]
        target = {"id": next_id, "file_name": f"{next_id}.jpg", "width": image.width, "height": image.height,
                  "source_collection": COLLECTION, "source_image_id": f"{COLLECTION}:{candidate['image']}",
                  "group_id": f"{COLLECTION}:annotator:{actor}", "attribution": {"verified": True,
                  "author": "Humans in the Loop / Inovinter contributors", "license_url": LICENSE_URL, "source_url": SOURCE_URL}}
        datasets[split]["images"].append(target)
        for box in boxes:
            x, y, width, height = box["bbox"]
            datasets[split]["annotations"].append({"id": next_annotation, "image_id": next_id, "category_id": box["category_id"],
                "bbox": [x*image.width/document["size"]["width"], y*image.height/document["size"]["height"],
                         width*image.width/document["size"]["width"], height*image.height/document["size"]["height"]], "iscrowd": 0})
            next_annotation += 1
        proof["images"][target["source_image_id"]] = {**candidate, "image_id": next_id, "prepared_sha256": hashlib.sha256(content).hexdigest(),
            "source_annotation_json": annotation_json, "can_reviews": {str(box["source_object_id"]): reviews[str(box["source_object_id"])] for box in can_boxes}}
        prepared.append((split, target["file_name"], content))
        thumbnails.append((candidate["image"], thumbnail))
        hashes.add(hashlib.sha256(content).hexdigest())
        next_id += 1
    counts = {split: {"images": len(labels["images"]), "annotations_by_class": {
        CLASSES[key]: sum(a["category_id"] == key for a in labels["annotations"]) for key in (1, 2, 3, 4, 5, 6)}} for split, labels in datasets.items()}
    if not all(counts["train"]["annotations_by_class"].values()):
        raise ValueError(f"Insufficient class coverage after review and duplicate filtering: {counts}")
    if extend_training and not prepared:
        raise ValueError("No new reviewed, nonduplicate training images were available.")
    validation_coverage = {CLASSES[key]: len({a["image_id"] for a in datasets["validation"]["annotations"] if a["category_id"] == key})
                           for key in (1, 2, 3, 4)}
    if refresh_validation and any(count < minimum for count in validation_coverage.values()):
        raise ValueError(f"Fresh validation needs at least {minimum} distinct photos per class after review/filtering: {validation_coverage}")
    protected_splits = ("train", "test") if refresh_validation else ("validation", "test") if extend_training else ()
    protected = {relative: value for relative, value in base_hashes.items() if relative.split("/")[0] in protected_splits}
    if refresh_validation:
        proof["evaluation_only"] = True
        proof["previous_evaluation_source_ids"] = sorted(set(previous_proof.get("previous_evaluation_source_ids", [])) | {
            image["source_image_id"] for image in base_labels["validation"]["images"]})
        retained = {image.get("source_image_id") for labels in datasets.values() for image in labels["images"]}
        proof["images"] = {key: entry for key, entry in proof["images"].items() if key in retained}
    args.output.mkdir(parents=True, exist_ok=True)
    for split in ("train", "test") if refresh_validation else ("train", "validation", "test") if extend_training else ("train",):
        shutil.copytree(args.base / split / "data", args.output / split / "data")
    if (args.base / "realdirt-provenance.json").exists():
        shutil.copy2(args.base / "realdirt-provenance.json", args.output / "realdirt-provenance.json")
    for split, filename, content in prepared:
        directory = args.output / split / "data"
        directory.mkdir(parents=True, exist_ok=True)
        (directory / filename).write_bytes(content)
    for split, labels in datasets.items():
        if split in protected_splits:
            shutil.copy2(args.base / split / "labels.json", args.output / split / "labels.json")
        else:
            write_json(args.output / split / "labels.json", labels)
    write_json(args.output / "hitl-provenance.json", proof)
    summary = {"status": "prepared", "full_dataset": True, "license_verified_images": sum(c["images"] for c in counts.values()),
        "counts": counts, "added_images": len(prepared), "base": str(args.base), "pending_can_review": [], "excluded": excluded,
        "split_policy": ("Fresh unused validation-group photos only; train/test files preserved byte-for-byte; prior validation excluded."
                         if refresh_validation else "Existing train preserved and extended; validation/test files preserved byte-for-byte; only train annotators added."
                         if extend_training else "Base train preserved; fresh HITL validation/test only; annotator groups stay in one split."),
        "training_only_expansion": extend_training,
        "validation_only_refresh": refresh_validation, "evaluation_only": refresh_validation,
        "validation_image_coverage_by_class": validation_coverage,
        "minimum_validation_photos_per_class": minimum if refresh_validation else None,
        "protected_split_file_sha256": protected,
        "protected_holdout_labels_sha256": {split: digest(args.base / split / "labels.json") for split in ("validation", "test")} if extend_training else None,
        "missing_classes": ["leaves", "wood", "rock"], "promoted": False,
        "limitations": ["Publisher CC0 dedication checked, not independent ownership verification.",
            "Annotator identity is only a grouping proxy; scene and photographer independence are not established.",
            "32x32 grayscale MAE <= 6 duplicate filtering is heuristic and can both miss and overexclude similar scenes.",
            "Public phone photos are not an ESP32-CAM field test. No empty-ground or negative training images were added.",
            "Can reviews are visual assessments, not independent expert ground truth; unsupported or ambiguous material/object tags are ignored."]}
    if refresh_validation:
        previous_groups = {image.get("group_id") for image in base_labels["validation"]["images"]}
        current_groups = {image.get("group_id") for image in datasets["validation"]["images"]}
        summary["previous_validation_group_overlap"] = sorted(previous_groups & current_groups)
        summary["limitations"].append("New photos can share contributors with prior validation; this is not independent scene-group or live-camera validation.")
    write_json(args.output / "summary.json", summary)
    try:
        validate_dataset(args.output)
        if any(digest(args.base / relative) != expected or digest(args.output / relative) != expected for relative, expected in protected.items()):
            raise ValueError("Protected source or prepared split files changed.")
        if refresh_validation and digest(args.base / "hitl-provenance.json") != proof["parent_provenance_sha256"]:
            raise ValueError("Base provenance changed during validation preparation.")
    except (Exception, KeyboardInterrupt) as error:
        summary.update(status="failed", full_dataset=False, error=str(error) or type(error).__name__)
        write_json(args.output / "summary.json", summary)
        raise
    return summary


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", type=Path, required=True)
    parser.add_argument("--source", type=Path, required=True)
    modes = parser.add_mutually_exclusive_group()
    modes.add_argument("--collect", action="store_true")
    modes.add_argument("--review-sheets", action="store_true")
    parser.add_argument("--scan-limit", type=int, default=400)
    parser.add_argument("--train-per-class", type=int, default=20)
    parser.add_argument("--holdout-per-class", type=int, default=5)
    parser.add_argument("--reviews", type=Path)
    parser.add_argument("--review-class", action="append", choices=("plastic_bottle", "other_plastic", "plastic_wrapper", "paper", "metal_can", "aluminum_can"),
                        help="Class to include in review sheets; repeat for multiple classes (default: metal_can).")
    parser.add_argument("--training-only", action="store_true", help="Review training-group candidates only.")
    parser.add_argument("--prepared-only", action="store_true", help="Review only candidates retained in the prepared HITL base.")
    parser.add_argument("--extend-training", action="store_true", help="Add only new train-group photos to a HITL base; preserve validation/test files exactly.")
    parser.add_argument("--refresh-validation", action="store_true", help="Collect or prepare unused validation photos only; preserve train/test and mark prepared output evaluation-only.")
    parser.add_argument("--min-validation-per-class", type=int, default=5,
                        help="Minimum distinct photos per class after filtering for --refresh-validation (1-10; default 5).")
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    try:
        args.base = args.base.resolve(strict=True)
        args.source = args.source.resolve()
        if not 1 <= args.scan_limit <= 1000 or not 1 <= args.train_per_class <= 50 or not 1 <= args.holdout_per_class <= 10:
            raise ValueError("Collection limits are outside the bounded ranges.")
        if args.refresh_validation and (args.extend_training or args.training_only or args.prepared_only):
            raise ValueError("Fresh validation cannot be combined with training or already-prepared scopes.")
        if not 1 <= args.min_validation_per_class <= 10:
            raise ValueError("Minimum validation coverage must be between 1 and 10 photos per class.")
        if not args.review_sheets and (args.review_class is not None or args.training_only or args.prepared_only):
            raise ValueError("Review class and scope options require --review-sheets.")
        if args.collect:
            collect(args)
        else:
            if args.output is None:
                raise ValueError("--output is required for preparation.")
            args.output = args.output.resolve()
            if args.review_sheets:
                review_sheets(args)
            else:
                print(json.dumps(prepare(args), indent=2))
        return 0
    except Exception as error:
        print(f"HITL preparation failed: {error}; partial cache is not a prepared training dataset.", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
