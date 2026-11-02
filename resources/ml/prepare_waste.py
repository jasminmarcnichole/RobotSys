"""Prepare a license-checked TACO subset without changing the deployed detector."""

import argparse
from collections import Counter, defaultdict
from datetime import datetime, timezone
import hashlib
from html.parser import HTMLParser
import io
import json
import math
from pathlib import Path
import re
import sys
from urllib.parse import urljoin, urlparse


SOURCE = 'https://raw.githubusercontent.com/pedropro/TACO/master/data/annotations.json'
CLASSES = ['background', 'plastic_bottle', 'other_plastic', 'plastic_wrapper', 'paper', 'metal_can', 'aluminum_can']
CLASS_SOURCES = {
    'plastic_wrapper': [
        'Other plastic wrapper', 'Wrapper', 'Candy wrapper', 'Cellophane wrapper',
        'Drink pouch', 'Crisp packet', 'Chocolate wrapper', 'Snack wrapper',
        'Flexible plastic packaging', 'Drinkpouch', 'CandyWrapper', 'CellophaneWrapper',
    ],
    'plastic_bottle': ['Clear plastic bottle', 'Other plastic bottle'],
    'other_plastic': [
        'Plastic bottle cap', 'Disposable plastic cup', 'Other plastic cup',
        'Plastic lid', 'Other plastic', 'Plastic film', 'Six pack rings',
        'Garbage bag', 'Single-use carrier bag',
        'Polypropylene bag', 'Spread tub', 'Tupperware',
        'Disposable food container', 'Other plastic container',
        'Plastic glooves', 'Plastic utensils', 'Plastic straw',
    ],
    'paper': ['Magazine paper', 'Tissues', 'Wrapping paper', 'Normal paper', 'Paper bag', 'Paper cup', 'Paper straw'],
    'metal_can': ['Food Can'],
    'aluminum_can': ['Drink can', 'Aluminum can', 'Soda can'],
}


def write_json(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(path.suffix + '.tmp')
    temporary.write_text(json.dumps(value, indent=2, allow_nan=False), encoding='utf-8')
    temporary.replace(path)


def read_json(path):
    return json.loads(path.read_text(encoding='utf-8-sig'))


def image_url(url):
    parsed = urlparse(url or '')
    host = parsed.hostname or ''
    if (parsed.scheme != 'https' or parsed.username or parsed.port not in (None, 443)
            or not (host == 'live.staticflickr.com' or re.fullmatch(r'farm\d+\.staticflickr\.com', host))):
        raise ValueError('Expected an HTTPS Flickr image URL.')
    photo_id = parsed.path.rsplit('/', 1)[-1].split('_', 1)[0]
    if not photo_id.isdigit():
        raise ValueError('Flickr image URL has no numeric photo ID.')
    return photo_id


def normalized_license(url):
    parsed = urlparse(url or '')
    if parsed.scheme != 'https' or parsed.hostname != 'creativecommons.org' or parsed.query or parsed.fragment:
        return None
    match = re.fullmatch(r'/(licenses/by/(?:2\.0|2\.5|3\.0|4\.0)|publicdomain/(?:zero|mark)/1\.0)/(?:deed\.[a-z_-]+)?', parsed.path)
    return 'https://creativecommons.org/' + match[1] + '/' if match else None


def approved_license(photo_id, entry):
    source = urlparse(entry.get('source_url', ''))
    return (entry.get('verified') is True and bool(entry.get('author'))
            and normalized_license(entry.get('license_url')) is not None
            and source.scheme == 'https' and source.hostname == 'www.flickr.com'
            and source.path.rstrip('/').split('/')[-1] == photo_id)


class LicensePage(HTMLParser):
    def __init__(self):
        super().__init__()
        self.scripts = []
        self.links = []
        self.current = None

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if tag == 'script' and attrs.get('type') == 'application/ld+json':
            self.current = ''
        if tag == 'a' and 'license' in attrs.get('rel', '').split() and 'license-text' in attrs.get('class', '').split():
            self.links.append(attrs.get('href', ''))

    def handle_data(self, data):
        if self.current is not None:
            self.current += data

    def handle_endtag(self, tag):
        if tag == 'script' and self.current is not None:
            self.scripts.append(self.current)
            self.current = None


def fetch(url, allowed, maximum=16_000_000):
    import requests

    for _ in range(4):
        if not allowed(url):
            raise ValueError('Download redirected outside the permitted source hosts.')
        with requests.get(url, timeout=(8, 25), stream=True, allow_redirects=False,
                          headers={'User-Agent': 'RobotSys-dataset-preparation/1.0'}) as response:
            if response.is_redirect:
                url = urljoin(url, response.headers['Location'])
                continue
            response.raise_for_status()
            parts, size = [], 0
            for chunk in response.iter_content(65536):
                size += len(chunk)
                if size > maximum:
                    raise ValueError('Source response exceeded the download size limit.')
                parts.append(chunk)
            return b''.join(parts)
    raise ValueError('Too many redirects from the source.')


def verify_photo(photo_id):
    source = 'https://www.flickr.com/photo.gne?id=' + photo_id
    entry = {'verified': False, 'source_url': source, 'checked_at': datetime.now(timezone.utc).isoformat()}
    try:
        content = fetch(source, lambda url: urlparse(url).scheme == 'https' and urlparse(url).hostname == 'www.flickr.com')
        parser = LicensePage()
        parser.feed(content.decode('utf-8'))
        visible = {normalized_license(url) for url in parser.links} - {None}
        for script in parser.scripts:
            document = json.loads(script)
            graph = document.get('@graph', [document]) if isinstance(document, dict) else document
            for item in graph:
                if item.get('@type') != 'ImageObject':
                    continue
                page = item.get('acquireLicensePage', '')
                if urlparse(page).path.rstrip('/').split('/')[-1] != photo_id:
                    continue
                license_url = normalized_license(item.get('license'))
                entry.update({'license_url': item.get('license'), 'source_url': page,
                              'author': item.get('creator', {}).get('name'),
                              'author_url': item.get('creator', {}).get('url'),
                              'evidence_sha256': hashlib.sha256(content).hexdigest()})
                entry['verified'] = bool(license_url and license_url in visible and entry['author'])
                entry['reason'] = 'CC BY or public-domain license confirmed' if entry['verified'] else 'License is restricted or could not be cross-checked'
                return entry
        entry['reason'] = 'No matching structured image-license evidence'
    except Exception as error:
        entry['reason'] = str(error)
    return entry


def map_annotations(data, skip_invalid_images=False):
    mapping = {name: CLASSES.index(target) for target, names in CLASS_SOURCES.items() for name in names}
    category_ids = {category['id']: mapping.get(category['name']) for category in data['categories']}
    images = {image['id']: image for image in data['images']}
    if len(images) != len(data['images']):
        raise ValueError('Duplicate source image IDs.')
    mapped = defaultdict(list)
    invalid = {}
    for annotation in data['annotations']:
        category = category_ids.get(annotation['category_id'])
        if category is None:
            continue
        image = images[annotation['image_id']]
        box = annotation.get('bbox', [])
        if (len(box) != 4 or not all(isinstance(value, (int, float)) and math.isfinite(value) for value in box)
                or box[0] < 0 or box[1] < 0 or box[2] <= 0 or box[3] <= 0
                or box[0] + box[2] > image['width'] + 1 or box[1] + box[3] > image['height'] + 1):
            reason = f"Invalid bounding box in annotation {annotation['id']}."
            if not skip_invalid_images:
                raise ValueError(reason)
            invalid[image['id']] = {'image_id': image['id'], 'reason': reason}
            continue
        mapped[image['id']].append({'id': annotation['id'], 'image_id': image['id'],
                                   'category_id': category, 'bbox': box, 'area': box[2] * box[3], 'iscrowd': 0})
    for image_id in invalid:
        mapped.pop(image_id, None)
    for image_id in list(mapped):
        image = images[image_id]
        if not isinstance(image_id, int) or image_id < 0 or image['width'] <= 0 or image['height'] <= 0:
            raise ValueError('Invalid source image ID or dimensions.')
        try:
            image_url(image['flickr_url'])
        except ValueError as error:
            if not skip_invalid_images:
                raise
            invalid[image_id] = {'image_id': image_id, 'reason': str(error)}
            del mapped[image_id]
    return images, mapped, list(invalid.values())


def source_split(photo_id):
    bucket = int(hashlib.sha256(('robotsys-v1:' + photo_id).encode()).hexdigest()[:8], 16) % 100
    return 'train' if bucket < 80 else 'validation' if bucket < 90 else 'test'


def decode_image(content, preserve=False):
    from PIL import Image

    Image.MAX_IMAGE_PIXELS = 20_000_000
    with Image.open(io.BytesIO(content)) as image:
        if image.width * image.height > Image.MAX_IMAGE_PIXELS:
            raise ValueError('Image exceeds the pixel limit.')
        image.load()
        if preserve:
            if image.format != 'JPEG' or image.mode != 'RGB' or max(image.size) > 640:
                raise ValueError('Cached image must be a normalized RGB JPEG no larger than 640 pixels.')
            return content, image.width, image.height
        image = image.convert('RGB')
        image.thumbnail((640, 640))
        output = io.BytesIO()
        image.save(output, format='JPEG', quality=95)
        return output.getvalue(), image.width, image.height


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--annotations-file', type=Path)
    parser.add_argument('--licenses-file', type=Path, help='Previously verified per-photo license and attribution records')
    parser.add_argument('--metadata-only', action='store_true', help='Prepare candidates without downloading images or creating a trainable dataset')
    parser.add_argument('--skip-invalid-images', action='store_true', help='Exclude and record images with malformed target boxes or unsupported sources')
    parser.add_argument('--skip-unavailable-images', action='store_true', help='Exclude failed downloads from the prepared subset and retain their failure records')
    parser.add_argument('--limit', type=int, default=150, help='Maximum candidate images to audit (0 means all)')
    args = parser.parse_args()
    if args.limit < 0:
        parser.error('--limit must be nonnegative')
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=True)
    source_path = args.annotations_file or output / 'source_annotations.json'
    if not source_path.exists():
        if args.annotations_file:
            raise ValueError('The specified annotations file does not exist.')
        content = fetch(SOURCE, lambda url: url == SOURCE, maximum=32_000_000)
        write_json(source_path, json.loads(content))
    data = read_json(source_path)
    images, mapped, excluded = map_annotations(data, args.skip_invalid_images)
    cache_path = args.licenses_file or output / 'license_audit.json'
    licenses = read_json(cache_path) if cache_path.exists() else {}
    totals = Counter(annotation['category_id'] for annotations in mapped.values() for annotation in annotations)
    # Audit rare classes first. Splits stay fixed by source photo, not by this order.
    candidates = sorted(mapped, key=lambda image_id: (
        min(totals[item['category_id']] for item in mapped[image_id]), image_id))
    if args.limit:
        candidates = candidates[:args.limit]
    categories = [{'id': index, 'name': name} for index, name in enumerate(CLASSES)]
    approved, annotations = [], []
    for position, image_id in enumerate(candidates, 1):
        original = images[image_id]
        photo_id = image_url(original['flickr_url'])
        entry = licenses.get(photo_id, {})
        if not args.licenses_file and not entry:
            entry = verify_photo(photo_id)
            licenses[photo_id] = entry
            write_json(cache_path, licenses)
        if not approved_license(photo_id, entry):
            excluded.append({'image_id': image_id, 'photo_id': photo_id, 'reason': entry.get('reason', 'No verified acceptable image license')})
        else:
            approved.append({'id': image_id, 'file_name': f'{image_id}.jpg',
                             'width': original['width'], 'height': original['height'],
                             'source_photo_id': photo_id, 'source_file_name': original['file_name'],
                             'attribution': entry, 'split': source_split(photo_id),
                             'download_url': original.get('flickr_640_url') or original['flickr_url']})
            annotations.extend(mapped[image_id])
        print(f'License audit {position}/{len(candidates)}: {len(approved)} approved', flush=True)
    write_json(output / 'candidate_annotations.json', {'images': approved, 'annotations': annotations, 'categories': categories})
    write_json(output / 'excluded_images.json', excluded)
    summary = {'status': 'metadata_only', 'full_dataset': False,
               'source': SOURCE, 'source_sha256': hashlib.sha256(source_path.read_bytes()).hexdigest(),
               'audited_images': len(candidates), 'license_verified_images': len(approved),
               'license_approved_candidates': len(approved),
               'excluded_images': len(excluded), 'downloaded_images': 0,
               'classes': CLASSES[1:], 'missing_classes': ['leaves', 'wood', 'rock'],
               'created_at': datetime.now(timezone.utc).isoformat()}
    write_json(output / 'summary.json', summary)
    if args.metadata_only:
        print(json.dumps(summary), flush=True)
        return 0
    splits = {name: {'images': [], 'annotations': [], 'categories': categories} for name in ('train', 'validation', 'test')}
    failures, content_splits = [], {}
    for position, item in enumerate(approved, 1):
        try:
            photo_id = image_url(item['download_url'])
            if photo_id != item['source_photo_id']:
                raise ValueError('Preview and original photo IDs differ.')
            cached = output / 'images' / item['file_name']
            if cached.exists():
                content, width, height = decode_image(cached.read_bytes(), preserve=True)
            else:
                raw = fetch(item['download_url'], lambda url: image_url(url) == photo_id)
                content, width, height = decode_image(raw)
                cached.parent.mkdir(parents=True, exist_ok=True)
                cached.write_bytes(content)
            digest = hashlib.sha256(content).hexdigest()
            split = content_splits.setdefault(digest, item['split'])
            destination = output / split / 'data' / item['file_name']
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_bytes(content)
            record = {**item, 'width': width, 'height': height, 'split': split, 'sha256': digest}
            splits[split]['images'].append(record)
            sx, sy = width / item['width'], height / item['height']
            for annotation in mapped[item['id']]:
                x, y, w, h = annotation['bbox']
                box = [x * sx, y * sy, min(w * sx, width - x * sx), min(h * sy, height - y * sy)]
                splits[split]['annotations'].append({**annotation, 'bbox': box, 'area': box[2] * box[3]})
        except Exception as error:
            failures.append({'image_id': item['id'], 'reason': str(error)})
        print(f'Image download {position}/{len(approved)}: {len(failures)} failures', flush=True)
    for name, dataset in splits.items():
        write_json(output / name / 'labels.json', dataset)
    downloaded = sum(len(dataset['images']) for dataset in splits.values())
    complete = downloaded > 0 and (not failures or args.skip_unavailable_images)
    if args.skip_unavailable_images:
        excluded.extend(failures)
        write_json(output / 'excluded_images.json', excluded)
        summary['license_verified_images'] = downloaded
    summary.update({'status': 'prepared' if complete else 'incomplete',
                    'full_dataset': complete,
                    'unavailable_images_excluded': args.skip_unavailable_images,
                    'excluded_images': len(excluded),
                    'downloaded_images': downloaded,
                    'download_failures': failures,
                    'splits': {name: {'images': len(dataset['images']), 'objects': dict(Counter(CLASSES[a['category_id']] for a in dataset['annotations']))}
                               for name, dataset in splits.items()}})
    write_json(output / 'summary.json', summary)
    print(json.dumps(summary), flush=True)
    return 0 if summary['full_dataset'] else 1


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (ValueError, KeyError, OSError, json.JSONDecodeError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
