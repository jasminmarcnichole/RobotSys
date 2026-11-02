<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WasteDatasetPreparationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/waste-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_preparation_maps_only_supported_waste_classes_and_preserves_provenance(): void
    {
        $result = $this->prepare($this->annotations(), $this->licenses());
        $this->assertSame(0, $result->getExitCode(), $result->getErrorOutput());
        $candidates = json_decode(File::get($this->directory.'/output/candidate_annotations.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['background', 'plastic_bottle', 'other_plastic', 'paper', 'metal_can'], array_column($candidates['categories'], 'name'));
        $this->assertSame([1, 2, 3, 4], array_column($candidates['annotations'], 'category_id'));
        $this->assertCount(1, $candidates['images']);
        $this->assertSame('12345', $candidates['images'][0]['source_photo_id']);
        $this->assertSame('Fixture photographer', $candidates['images'][0]['attribution']['author']);
        $this->assertSame([10, 10, 20, 30], $candidates['annotations'][0]['bbox']);
        $summary = json_decode(File::get($this->directory.'/output/summary.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('metadata_only', $summary['status']);
        $this->assertFalse($summary['full_dataset']);
        $this->assertSame(['leaves', 'wood', 'rock'], $summary['missing_classes']);
        $this->assertFileDoesNotExist($this->directory.'/output/train/labels.json');
    }

    public function test_missing_and_noncommercial_licenses_are_not_approved(): void
    {
        foreach ([[], ['12345' => [...$this->licenses()['12345'], 'license_url' => 'https://creativecommons.org/licenses/by-nc/4.0/']]] as $licenses) {
            $result = $this->prepare($this->annotations(), $licenses);
            $this->assertSame(0, $result->getExitCode(), $result->getErrorOutput());
            $summary = json_decode(File::get($this->directory.'/output/summary.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(0, $summary['license_verified_images']);
            $this->assertSame(1, $summary['excluded_images']);
        }
    }

    public function test_license_source_must_match_the_image_and_not_just_a_cc_link(): void
    {
        $licenses = $this->licenses();
        $licenses['12345']['source_url'] = 'https://www.flickr.com/photos/owner/99999/';
        $result = $this->prepare($this->annotations(), $licenses);
        $this->assertSame(0, $result->getExitCode());
        $summary = json_decode(File::get($this->directory.'/output/summary.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $summary['license_verified_images']);
    }

    public function test_invalid_geometry_is_rejected_before_creating_training_output(): void
    {
        $annotations = $this->annotations();
        $annotations['annotations'][0]['bbox'] = [90, 10, 50, 30];
        $result = $this->prepare($annotations, $this->licenses());
        $this->assertNotSame(0, $result->getExitCode());
        $this->assertStringContainsString('bounding box', $result->getErrorOutput());
        $this->assertFileDoesNotExist($this->directory.'/output/train/labels.json');
    }

    public function test_source_filenames_cannot_escape_the_dataset_directory(): void
    {
        $annotations = $this->annotations();
        $annotations['images'][0]['file_name'] = '../../outside.jpg';
        $result = $this->prepare($annotations, $this->licenses());
        $this->assertSame(0, $result->getExitCode(), $result->getErrorOutput());
        $candidates = json_decode(File::get($this->directory.'/output/candidate_annotations.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('1.jpg', $candidates['images'][0]['file_name']);
        $this->assertFileDoesNotExist($this->directory.'/outside.jpg');
    }

    public function test_optional_geometry_exclusion_removes_the_whole_image_and_records_why(): void
    {
        $annotations = $this->annotations();
        $annotations['annotations'][1]['bbox'] = [10, -1, 20, 30];
        $annotations['images'][] = [...$annotations['images'][0], 'id' => 2];
        $annotations['annotations'][] = ['id' => 20, 'image_id' => 2, 'category_id' => 6, 'bbox' => [10, 10, 20, 30]];
        $result = $this->prepare($annotations, $this->licenses(), ['--skip-invalid-images']);
        $this->assertSame(0, $result->getExitCode(), $result->getErrorOutput());
        $candidates = json_decode(File::get($this->directory.'/output/candidate_annotations.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([2], array_column($candidates['images'], 'id'));
        $this->assertSame([20], array_column($candidates['annotations'], 'id'));
        $excluded = json_decode(File::get($this->directory.'/output/excluded_images.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $excluded[0]['image_id']);
        $this->assertStringContainsString('annotation 5', $excluded[0]['reason']);
    }

    public function test_image_downloads_cannot_target_arbitrary_hosts(): void
    {
        $annotations = $this->annotations();
        $annotations['images'][0]['flickr_url'] = 'https://example.invalid/12345_image.jpg';
        $result = $this->prepare($annotations, $this->licenses());
        $this->assertNotSame(0, $result->getExitCode());
        $this->assertStringContainsString('Flickr image URL', $result->getErrorOutput());
    }

    public function test_optional_exclusion_records_unsupported_image_sources(): void
    {
        $annotations = $this->annotations();
        $annotations['images'][0]['flickr_url'] = 'https://example.invalid/12345_image.jpg';
        $result = $this->prepare($annotations, $this->licenses(), ['--skip-invalid-images']);
        $this->assertSame(0, $result->getExitCode(), $result->getErrorOutput());
        $excluded = json_decode(File::get($this->directory.'/output/excluded_images.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $excluded[0]['image_id']);
        $this->assertStringContainsString('Flickr image URL', $excluded[0]['reason']);
    }

    private function prepare(array $annotations, array $licenses, array $options = []): Process
    {
        File::put($this->directory.'/annotations.json', json_encode($annotations, JSON_THROW_ON_ERROR));
        File::put($this->directory.'/licenses.json', json_encode((object) $licenses, JSON_THROW_ON_ERROR));
        $finder = new ExecutableFinder;
        $python = $finder->find(PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');
        if ($python === null) {
            $this->markTestSkipped('Python is required for dataset preparation tests.');
        }
        $command = [$python];
        if (PHP_OS_FAMILY === 'Windows') {
            $command[] = '-3.11';
        }
        $process = new Process([...$command, resource_path('ml/prepare_waste.py'),
            '--annotations-file', $this->directory.'/annotations.json',
            '--licenses-file', $this->directory.'/licenses.json',
            '--output', $this->directory.'/output', '--metadata-only',
            ...$options,
        ]);
        $process->setTimeout(30)->run();

        return $process;
    }

    public function test_training_dataset_preflight_can_run_without_tensorflow(): void
    {
        $this->trainingFixture();
        $result = $this->checkDataset();
        $this->assertSame(0, $result->getExitCode(), $result->getErrorOutput());
        $this->assertStringContainsString('Dataset check passed', $result->getOutput());
        $this->assertFileDoesNotExist($this->directory.'/dataset/waste-detector.tflite');
    }

    public function test_prepared_image_cache_preserves_bytes_and_does_not_recompress(): void
    {
        $executable = PHP_OS_FAMILY === 'Windows' ? 'Scripts/python.exe' : 'bin/python';
        $python = storage_path('app/private/waste-training/.venv/'.$executable);
        if (! is_file($python)) {
            $this->markTestSkipped('The isolated image preparation environment is required.');
        }
        $code = <<<'PYTHON'
import io, sys
sys.path.insert(0, sys.argv[1])
from PIL import Image
from prepare_waste import decode_image
source = io.BytesIO()
Image.effect_noise((100, 80), 100).convert('RGB').save(source, format='PNG')
prepared, width, height = decode_image(source.getvalue())
cached, cached_width, cached_height = decode_image(prepared, preserve=True)
assert (width, height) == (cached_width, cached_height)
assert cached == prepared, 'Cache must not be recompressed'
try:
    decode_image(source.getvalue(), preserve=True)
except ValueError:
    pass
else:
    raise AssertionError('Cache must contain normalized JPEG data')
print('Cache preserved')
PYTHON;
        $process = new Process([$python, '-c', $code, resource_path('ml')]);
        $process->setTimeout(30)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Cache preserved', $process->getOutput());
    }

    public function test_unavailable_images_can_be_explicitly_excluded_without_discarding_good_images(): void
    {
        $executable = PHP_OS_FAMILY === 'Windows' ? 'Scripts/python.exe' : 'bin/python';
        $python = storage_path('app/private/waste-training/.venv/'.$executable);
        if (! is_file($python)) {
            $this->markTestSkipped('The isolated image preparation environment is required.');
        }
        $annotations = $this->annotations();
        $annotations['images'][] = [...$annotations['images'][0], 'id' => 2, 'flickr_640_url' => 'https://live.staticflickr.com/1/99999_image.jpg'];
        $annotations['annotations'][] = ['id' => 20, 'image_id' => 2, 'category_id' => 6, 'bbox' => [10, 10, 20, 30]];
        $this->prepare($annotations, $this->licenses());
        File::ensureDirectoryExists($this->directory.'/output/images');
        $fixture = new Process([$python, '-c', 'from PIL import Image; import sys; Image.new("RGB", (100,80)).save(sys.argv[1], format="JPEG")', $this->directory.'/output/images/1.jpg']);
        $fixture->setTimeout(30)->run();
        $this->assertSame(0, $fixture->getExitCode(), $fixture->getErrorOutput());
        $command = [$python, resource_path('ml/prepare_waste.py'),
            '--annotations-file', $this->directory.'/annotations.json', '--licenses-file', $this->directory.'/licenses.json',
            '--output', $this->directory.'/output',
        ];
        $strict = new Process($command);
        $strict->setTimeout(30)->run();
        $this->assertNotSame(0, $strict->getExitCode());
        $result = new Process([...$command, '--skip-unavailable-images']);
        $result->setTimeout(30)->run();
        $this->assertSame(0, $result->getExitCode(), $result->getErrorOutput());
        $summary = json_decode(File::get($this->directory.'/output/summary.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('prepared', $summary['status']);
        $this->assertSame(2, $summary['license_approved_candidates']);
        $this->assertSame(1, $summary['license_verified_images']);
        $this->assertSame(1, $summary['downloaded_images']);
        $excluded = json_decode(File::get($this->directory.'/output/excluded_images.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(2, $excluded[0]['image_id']);
        $this->assertStringContainsString('photo IDs differ', $excluded[0]['reason']);
    }

    public function test_training_preflight_rejects_the_same_source_photo_in_different_splits(): void
    {
        $this->trainingFixture();
        $path = $this->directory.'/dataset/test/labels.json';
        $data = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        $data['images'][0]['source_photo_id'] = '123450';
        $data['images'][0]['attribution']['source_url'] = 'https://www.flickr.com/photos/owner/123450/';
        File::put($path, json_encode($data, JSON_THROW_ON_ERROR));
        $result = $this->checkDataset();
        $this->assertNotSame(0, $result->getExitCode());
        $this->assertStringContainsString('Split leakage', $result->getErrorOutput());
    }

    public function test_training_preflight_does_not_trust_only_the_license_summary(): void
    {
        $this->trainingFixture();
        $path = $this->directory.'/dataset/test/labels.json';
        $data = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        $data['images'][0]['attribution']['verified'] = false;
        File::put($path, json_encode($data, JSON_THROW_ON_ERROR));
        $result = $this->checkDataset();
        $this->assertNotSame(0, $result->getExitCode());
        $this->assertStringContainsString('license', $result->getErrorOutput());
    }

    public function test_training_preflight_rejects_missing_class_coverage(): void
    {
        $this->trainingFixture();
        $path = $this->directory.'/dataset/validation/labels.json';
        $data = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        array_pop($data['annotations']);
        File::put($path, json_encode($data, JSON_THROW_ON_ERROR));
        $result = $this->checkDataset();
        $this->assertNotSame(0, $result->getExitCode());
        $this->assertStringContainsString('metal_can', $result->getErrorOutput());
    }

    private function trainingFixture(): void
    {
        $root = $this->directory.'/dataset';
        File::ensureDirectoryExists($root);
        File::put($root.'/summary.json', json_encode(['status' => 'prepared', 'full_dataset' => true, 'license_verified_images' => 3], JSON_THROW_ON_ERROR));
        foreach (['train', 'validation', 'test'] as $index => $split) {
            $imageId = $index + 1;
            $photoId = '12345'.$index;
            File::ensureDirectoryExists($root.'/'.$split.'/data');
            File::put($root.'/'.$split.'/data/'.$imageId.'.jpg', "\xff\xd8\xff".'unique-header-fixture-'.$index);
            $data = [
                'categories' => array_map(fn (string $name, int $id): array => ['id' => $id, 'name' => $name], ['background', 'plastic_bottle', 'other_plastic', 'paper', 'metal_can'], range(0, 4)),
                'images' => [[
                    'id' => $imageId, 'file_name' => $imageId.'.jpg', 'width' => 100, 'height' => 80,
                    'source_photo_id' => $photoId,
                    'attribution' => [...$this->licenses()['12345'], 'source_url' => 'https://www.flickr.com/photos/owner/'.$photoId.'/'],
                ]],
                'annotations' => array_map(fn (int $id): array => ['id' => $id, 'image_id' => $imageId, 'category_id' => $id, 'bbox' => [10, 10, 20, 30]], range(1, 4)),
            ];
            File::put($root.'/'.$split.'/labels.json', json_encode($data, JSON_THROW_ON_ERROR));
        }
    }

    public function test_browser_benchmark_preserves_application_boxes_and_does_not_invent_materials(): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null || ! is_file(public_path('build/manifest.json')) || ! is_file(base_path('node_modules/rollup/dist/es/parseAst.js'))) {
            $this->markTestSkipped('Node, the existing Rollup parser, and current built browser assets are required.');
        }
        $this->trainingFixture();
        $code = <<<'JAVASCRIPT'
import assert from 'node:assert/strict';
import { writeFile, readFile, mkdir } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { parseAst } from 'rollup/parseAst';
const { normalizeDetections, frameGeometry, loadInputs, loadPreparedFrames, createAssetServer, sha256 } = await import(pathToFileURL(resolve(process.argv[2], 'benchmark_waste.mjs')));
const root = resolve(process.argv[3]);
const image = {id:1, width:100, height:80};
const frame = {width:50, height:40};
const detection = {boundingBox:{originX:-2.5, originY:10.5, width:20.5, height:60},
    categories:[{categoryName:'bottle', index:39, score:.25}, {categoryName:'cup', index:41, score:.9}]};
const [generic] = normalizeDetections([detection], image, frame, 'generic');
assert.equal(generic.category_id, null);
assert.equal(generic.label, 'bottle');
assert.equal(generic.score, .25, 'The first category must match the app, not the highest score');
assert.deepEqual(generic.bbox, [0,22,42,58]);
const source = await readFile(resolve(process.argv[2],'../js/robot-camera.js'),'utf8');
function findNormalizer(node) {
    if (!node || typeof node !== 'object') return null;
    if (node.type === 'FunctionDeclaration' && node.id?.name === 'normalizeDetections') return node;
    for (const value of Object.values(node)) {
        const found = findNormalizer(value);
        if (found) return found;
    }
    return null;
}
const normalizer = findNormalizer(parseAst(source));
assert.ok(normalizer, 'The actual application normalizer must be covered');
const applicationNormalize = new Function('result','canvas',source.slice(normalizer.body.start+1,normalizer.body.end-1));
const [application] = applicationNormalize({detections:[detection]},frame);
assert.equal(generic.label,application.label);
assert.equal(generic.score,application.score);
assert.deepEqual(generic.bbox,[application.bbox.x*2,application.bbox.y*2,application.bbox.width*2,application.bbox.height*2]);
assert.throws(() => normalizeDetections([detection], image, frame, 'waste'));
const waste = {...detection, categories:[{categoryName:'plastic_bottle', index:-1, score:.7}]};
assert.equal(normalizeDetections([waste], image, frame, 'waste')[0].category_id, 1);
assert.throws(() => normalizeDetections([{...waste, categories:[{...waste.categories[0], index:4}]}], image, frame, 'waste'));
assert.throws(() => normalizeDetections([{...waste, categories:[{categoryName:'background', index:0, score:.7}]}], image, frame, 'waste'));
assert.throws(() => normalizeDetections([{...waste, boundingBox:{...waste.boundingBox, originX:NaN}}], image, frame, 'waste'));
assert.throws(() => normalizeDetections([], image, {...frame, width:0}, 'generic'));
assert.deepEqual(normalizeDetections([{...detection, boundingBox:{...detection.boundingBox, width:0}}], image, frame, 'generic'), []);
assert.deepEqual(frameGeometry(image, 256), {width:100, height:80, content_width:100, content_height:80});
const padded = frameGeometry(image, 256, 'training_pad');
assert.deepEqual(padded, {width:256, height:256, content_width:256, content_height:205});
assert.deepEqual(frameGeometry({width:80,height:100},256,'training_pad'), {width:256,height:256,content_width:205,content_height:256});
assert.deepEqual(frameGeometry({width:2560,height:1280},256), {width:1280,height:640,content_width:1280,content_height:640});
assert.deepEqual(frameGeometry({width:1,height:10000},256,'training_pad'), {width:256,height:256,content_width:1,content_height:256});
assert.throws(() => frameGeometry(image,256,'centered'));
assert.throws(() => frameGeometry({...image,width:0},256));
assert.throws(() => frameGeometry(image,255));
const paddedDetection = {...waste, boundingBox:{originX:64,originY:41,width:128,height:82}};
assert.deepEqual(normalizeDetections([paddedDetection],image,padded,'waste')[0].bbox,[25,16,50,32]);
assert.deepEqual(normalizeDetections([{...paddedDetection,boundingBox:{originX:0,originY:205,width:20,height:30}}],image,padded,'waste'),[]);
assert.deepEqual(normalizeDetections([{...paddedDetection,boundingBox:{originX:0,originY:200,width:256,height:30}}],image,padded,'waste')[0].bbox,[0,200*80/205,100,5*80/205]);
assert.throws(() => normalizeDetections([],image,{...padded,content_width:300},'waste'));
assert.throws(() => normalizeDetections([],image,{...frame,content_width:50},'waste'));
assert.throws(() => normalizeDetections([],image,{...padded,content_height:null},'waste'));
const model = resolve(root,'fixture.tflite');
await writeFile(model, Buffer.from([0,0,0,0,84,70,76,51]));
const configs = resolve(root,'models.json');
await writeFile(configs, JSON.stringify([{id:'fixture', path:model, input_size:448, label_space:'generic'}]));
const dataset = resolve(root,'dataset');
const output = resolve(root,'benchmark/result.json');
const inputs = await loadInputs(dataset, configs, output);
assert.equal(inputs.images.size, 1, 'Only validation frames may enter this benchmark');
assert.equal(inputs.models.get('fixture').sha256, sha256(await readFile(model)));
assert.equal(inputs.models.get('fixture').input_geometry,'application');
for (const config of [
    {id:'fixture',path:model,input_size:448,label_space:'generic',input_geometry:'training_pad'},
    {id:'fixture',path:model,input_size:320,label_space:'waste',input_geometry:'training_pad'},
    {id:'fixture',path:model,input_size:256,label_space:'waste',input_geometry:null},
    {id:'fixture',path:model,input_size:256,label_space:'waste',input_geometry:'centered'},
    {id:'fixture',path:model,input_size:256,label_space:'waste',input_geometry:'training_pad',input_preprocessing:'unknown'},
    {id:'fixture',path:model,input_size:256,label_space:'waste',input_preprocessing:'tensorflow_rgb',input_manifest:'unused'},
    {id:'fixture',path:model,input_size:256,label_space:'waste',input_geometry:'training_pad',input_preprocessing:'tensorflow_rgb'},
    {id:'fixture',path:model,input_size:256,label_space:'waste',input_manifest:'unused'},
]) {
    await writeFile(configs,JSON.stringify([config]));
    await assert.rejects(() => loadInputs(dataset,configs,output),/model identity/);
}
await writeFile(configs,JSON.stringify([{id:'padded',path:model,input_size:256,label_space:'waste',input_geometry:'training_pad'}]));
assert.equal((await loadInputs(dataset,configs,output)).models.get('padded').input_geometry,'training_pad');
const preparedFolder = resolve(root,'prepared');
await mkdir(preparedFolder);
const png = Buffer.alloc(33);
Buffer.from('89504e470d0a1a0a','hex').copy(png);
png.write('IHDR',12);
png.writeUInt32BE(256,16);
png.writeUInt32BE(256,20);
png[24]=8; png[25]=2;
await writeFile(resolve(preparedFolder,'2.png'),png);
const preparedManifest = {schema_version:1,status:'prepared',split:'validation',architecture:'MOBILENET_V2',promoted:false,
    labels_sha256:inputs.labels_sha256,image_sha256:{'2':inputs.images.get(2).sha256},generator_sha256:'a'.repeat(64),
    recipe:{decoder:'tf.io.decode_jpeg(channels=3)',resize:'official.vision.ops.preprocess_ops.resize_and_crop_image',
        input_size:256,mean_rgb:[127.5],std_rgb:[127.5],padding:'top_left_normalized_zero',augmentation:false,rgb_conversion:'clip_rint_uint8',format:'RGB_PNG'},
    frames:[{image_id:2,file:'2.png',original_sha256:inputs.images.get(2).sha256,png_sha256:sha256(png),
        width:256,height:256,content_width:256,content_height:205,max_normalized_roundtrip_error:0.003}]};
const preparedPath = resolve(preparedFolder,'manifest.json');
await writeFile(preparedPath,JSON.stringify(preparedManifest));
const prepared = await loadPreparedFrames(preparedPath,inputs);
assert.equal(prepared.frames.size,1);
assert.equal(prepared.frames.get(2).sha256,sha256(png));
await writeFile(resolve(dataset,'prepared-manifest.json'),JSON.stringify(preparedManifest));
await assert.rejects(()=>loadPreparedFrames(resolve(dataset,'prepared-manifest.json'),inputs),/private/);
for(const change of ['test','labels','image','duplicate','id','path','geometry','zero','hash','rounding','recipe']) {
    const invalid = structuredClone(preparedManifest);
    if(change==='test') invalid.split='test';
    if(change==='labels') invalid.labels_sha256='b'.repeat(64);
    if(change==='image') invalid.image_sha256['2']='b'.repeat(64);
    if(change==='duplicate') invalid.frames.push(invalid.frames[0]);
    if(change==='id') invalid.frames[0].image_id=1;
    if(change==='path') invalid.frames[0].file='../2.png';
    if(change==='geometry') invalid.frames[0].content_height=200;
    if(change==='zero') invalid.frames[0].content_width=0;
    if(change==='hash') invalid.frames[0].png_sha256='b'.repeat(64);
    if(change==='rounding') invalid.frames[0].max_normalized_roundtrip_error=0.1;
    if(change==='recipe') invalid.recipe.augmentation=true;
    await writeFile(preparedPath,JSON.stringify(invalid));
    await assert.rejects(()=>loadPreparedFrames(preparedPath,inputs));
}
await writeFile(preparedPath,JSON.stringify(preparedManifest));
const tfConfig={id:'tf',path:model,input_size:256,label_space:'waste',input_geometry:'training_pad',
    input_preprocessing:'tensorflow_rgb',input_manifest:preparedPath};
await writeFile(configs,JSON.stringify([tfConfig]));
const preparedInputs=await loadInputs(dataset,configs,output);
assert.equal(preparedInputs.models.get('tf').input_preprocessing,'tensorflow_rgb');
inputs.models.set('tf',preparedInputs.models.get('tf'));
const wrongSize=Buffer.from(png);
wrongSize.writeUInt32BE(255,16);
await writeFile(resolve(preparedFolder,'2.png'),wrongSize);
await assert.rejects(()=>loadPreparedFrames(preparedPath,inputs),/PNG/);
await writeFile(resolve(preparedFolder,'2.png'),png);
await writeFile(configs,JSON.stringify([{id:'fixture',path:model,input_size:448,label_space:'generic'}]));
const server = createAssetServer(inputs);
await new Promise((accept) => server.listen(0,'127.0.0.1',accept));
try {
    const origin = `http://127.0.0.1:${server.address().port}`;
    assert.equal((await fetch(origin+'/models/fixture')).status, 200);
    assert.equal((await fetch(origin+'/frames/2')).status, 200);
    assert.equal((await fetch(origin+'/frames/1')).status, 404, 'Training photos are not served');
    assert.equal((await fetch(origin+'/publisher.json')).status, 404);
    assert.equal((await fetch(origin+'/prepared/tf/2')).status,200);
    assert.equal((await fetch(origin+'/prepared/tf/1')).status,404,'Training photos cannot be substituted');
    assert.equal((await fetch(origin+'/prepared/fixture/2')).status,404);
    await writeFile(resolve(preparedFolder,'2.png'),wrongSize);
    assert.equal((await fetch(origin+'/prepared/tf/2')).status,500,'Prepared frame mutation must fail');
    await writeFile(resolve(preparedFolder,'2.png'),png);
    assert.equal((await fetch(origin+'/models/fixture', {method:'POST'})).status, 405);
    assert.equal((await fetch(origin+'/build/assets/'+inputs.worker)).status, 200);
    await writeFile(model, 'changed bytes');
    assert.equal((await fetch(origin+'/models/fixture')).status, 500);
} finally {
    server.closeAllConnections();
    await new Promise((accept) => server.close(accept));
}
await writeFile(output, 'preserve');
await assert.rejects(() => loadInputs(dataset, configs, output), /overwrite/);
assert.equal(await readFile(output,'utf8'), 'preserve');
await assert.rejects(() => loadInputs(dataset, configs, resolve(dataset,'report.json')), /private/);
console.log('Browser normalization, private serving, and no-overwrite checks passed');
JAVASCRIPT;
        $process = new Process([$node, '--input-type=module', '-', resource_path('ml'), $this->directory]);
        $process->setInput($code)->setTimeout(60)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Browser normalization, private serving, and no-overwrite checks passed', $process->getOutput());
    }

    public function test_browser_scoring_separates_localization_from_material_accuracy_and_checks_fresh_inputs(): void
    {
        $this->trainingFixture();
        $python = (new ExecutableFinder)->find(PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');
        if ($python === null) {
            $this->markTestSkipped('Python is required for browser scoring tests.');
        }
        $command = PHP_OS_FAMILY === 'Windows' ? [$python, '-3.11'] : [$python];
        $code = <<<'PYTHON'
import copy, json, sys
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
sys.path.insert(0, sys.argv[1])
import score_browser_waste as browser
from train_waste import TrainingError
root = Path(sys.argv[2]).resolve()
dataset = root/'dataset'
labels = json.loads((dataset/'validation/labels.json').read_text())
image_hashes = {'2':browser.file_hash(dataset/'validation/data/2.jpg')}
labels_hash = browser.file_hash(dataset/'validation/labels.json')
digest = 'a'*64
model = {'model_sha256':digest, 'label_space':'generic', 'input_size':448,
    'frames':[{'image_id':2, 'width':100, 'height':80, 'inference_ms':10, 'detections':[{}]}],
    'predictions':[{'image_id':2, 'label':'bottle', 'category_id':None, 'score':.6, 'bbox':[10,10,20,30]}]}
report = {'status':'inferred', 'split':'validation', 'promoted':False, 'settings':browser.SETTINGS,
    'labels_sha256':labels_hash, 'image_sha256':image_hashes, 'worker_sha256':digest,
    'benchmarker_sha256':digest, 'models_config_sha256':digest, 'browser_version':'fixture',
    'assets_sha256':{'worker.js':digest}, 'application_sources_sha256':{'robot-camera.js':digest},
    'models':{'generic':model}}
browser.validate_benchmark(report, labels, labels_hash, image_hashes)

def rejected(action):
    try:
        action()
    except (TrainingError, ValueError):
        return
    raise AssertionError('Invalid input accepted')

for change in ('test', 'labels', 'image', 'frames', 'generic_mapping', 'bbox', 'limit', 'score'):
    invalid = copy.deepcopy(report)
    if change == 'test': invalid['split'] = 'test'
    elif change == 'labels': invalid['labels_sha256'] = 'b'*64
    elif change == 'image': invalid['image_sha256']['2'] = 'b'*64
    elif change == 'frames': invalid['models']['generic']['frames'] *= 2
    elif change == 'generic_mapping': invalid['models']['generic']['predictions'][0]['category_id'] = 1
    elif change == 'bbox': invalid['models']['generic']['predictions'][0]['bbox'][2] = 1000
    elif change == 'limit': invalid['models']['generic']['frames'][0]['detections'] *= 11
    elif change == 'score': invalid['models']['generic']['predictions'][0]['score'] = float('nan')
    rejected(lambda: browser.validate_benchmark(invalid, labels, labels_hash, image_hashes))
summary = browser.summarize_model(labels, model)
assert summary['thresholds']['0.5']['class_aware_iou_05'] is None
assert summary['thresholds']['0.5']['waste_coco_AP'] is None
assert summary['thresholds']['0.5']['localization_only_iou_05']['true_positives'] == 1, 'Duplicate overlapping annotations cannot receive duplicate credit'
assert summary['thresholds']['0.5']['localization_only_iou_05']['false_negatives'] == 3
waste = copy.deepcopy(model)
waste['label_space'] = 'waste'
waste['input_size'] = 256
waste['predictions'][0].update(label='paper', category_id=3)
with patch.object(browser, 'score_predictions', return_value={'AP':.25}) as ap:
    summary = browser.summarize_model(labels, waste)
    assert ap.call_count == 2
assert summary['thresholds']['0.5']['class_aware_iou_05']['true_positives'] == 1
assert summary['thresholds']['0.5']['class_aware_iou_05']['by_class']['paper'] == {'matched':1}
assert summary['thresholds']['0.5']['waste_coco_AP'] == {'AP':.25}
padded = copy.deepcopy(report)
padded['models'] = {'padded':copy.deepcopy(waste)}
padded_model = padded['models']['padded']
padded_model['input_geometry'] = 'training_pad'
padded_model['frames'][0].update(width=256,height=256,content_width=256,content_height=205)
browser.validate_benchmark(padded,labels,labels_hash,image_hashes)
with patch.object(browser,'score_predictions',return_value={'AP':.25}):
    assert browser.summarize_model(labels,padded_model)['input_geometry'] == 'training_pad'
for change in ('geometry','size','label_space','missing_content','wrong_content','frame_size','boolean_content'):
    invalid = copy.deepcopy(padded)
    candidate = invalid['models']['padded']
    if change == 'geometry': candidate['input_geometry'] = 'centered'
    elif change == 'size': candidate['input_size'] = 320
    elif change == 'label_space': candidate['label_space'] = 'generic'
    elif change == 'missing_content': candidate['frames'][0].pop('content_width')
    elif change == 'wrong_content': candidate['frames'][0]['content_height'] = 204
    elif change == 'frame_size': candidate['frames'][0]['width'] = 255
    elif change == 'boolean_content': candidate['frames'][0]['content_width'] = True
    rejected(lambda: browser.validate_benchmark(invalid,labels,labels_hash,image_hashes))
explicit = copy.deepcopy(report)
explicit['models']['generic']['input_geometry'] = 'application'
explicit['models']['generic']['frames'][0].update(content_width=100,content_height=80)
browser.validate_benchmark(explicit,labels,labels_hash,image_hashes)
explicit['models']['generic']['frames'][0]['content_height'] = 79
rejected(lambda: browser.validate_benchmark(explicit,labels,labels_hash,image_hashes))
from prepare_browser_waste import RECIPE
prepared_report = copy.deepcopy(padded)
prepared_model = prepared_report['models']['padded']
prepared_model['input_preprocessing'] = 'tensorflow_rgb'
prepared_model['input_manifest_sha256'] = 'e'*64
prepared_manifest = {'schema_version':1,'status':'prepared','split':'validation','architecture':'MOBILENET_V2',
    'recipe':RECIPE,'promoted':False,'generator_sha256':'f'*64,'labels_sha256':labels_hash,'image_sha256':image_hashes,
    'frames':[{'image_id':2,'file':'2.png','original_sha256':image_hashes['2'],'png_sha256':'c'*64,
        'width':256,'height':256,'content_width':256,'content_height':205,'max_normalized_roundtrip_error':0.003}]}
prepared_model['input_manifest'] = copy.deepcopy(prepared_manifest)
prepared_model['frames'][0]['input_png_sha256'] = 'c'*64
browser.validate_benchmark(prepared_report,labels,labels_hash,image_hashes)
with patch.object(browser,'score_predictions',return_value={'AP':.25}):
    assert browser.summarize_model(labels,prepared_model)['input_preprocessing'] == 'tensorflow_rgb'
for change in ('missing','geometry','png','labels','image','test','duplicate','path','content','rounding','recipe','boolean_schema','canvas'):
    invalid = copy.deepcopy(prepared_report)
    model = invalid['models']['padded']
    manifest = model['input_manifest']
    if change == 'missing': model.pop('input_manifest_sha256')
    elif change == 'geometry': model['input_geometry'] = 'application'
    elif change == 'png': model['frames'][0]['input_png_sha256'] = 'b'*64
    elif change == 'labels': manifest['labels_sha256'] = 'b'*64
    elif change == 'image': manifest['image_sha256']['2'] = 'b'*64
    elif change == 'test': manifest['split'] = 'test'
    elif change == 'duplicate': manifest['frames'].append(manifest['frames'][0])
    elif change == 'path': manifest['frames'][0]['file'] = '../2.png'
    elif change == 'content': manifest['frames'][0]['content_height'] = 200
    elif change == 'rounding': manifest['frames'][0]['max_normalized_roundtrip_error'] = float('inf')
    elif change == 'recipe': manifest['recipe']['augmentation'] = True
    elif change == 'boolean_schema': manifest['schema_version'] = True
    elif change == 'canvas': model['input_preprocessing'] = 'browser_canvas'
    rejected(lambda: browser.validate_benchmark(invalid,labels,labels_hash,image_hashes))
source = root/'benchmark.json'
source.write_text(json.dumps(report), encoding='utf-8')
args = SimpleNamespace(dataset=dataset, benchmark=[source], output=root/'scored')
result = browser.score(args)
assert not result['promoted'] and not result['deployment_ready'] and result['images'] == 1
assert result['sources'][0]['sha256'] == browser.file_hash(source)
assert result['dataset_use'] == {'evaluation_only':False,'validation_only_refresh':False,'previous_validation_group_overlap':[]}
summary_path = dataset/'summary.json'
summary_bytes = summary_path.read_bytes()
fresh_summary = {**json.loads(summary_bytes),'evaluation_only':True,'validation_only_refresh':True,
    'previous_validation_group_overlap':['heldout-contributor']}
summary_path.write_text(json.dumps(fresh_summary))
fresh_result = browser.score(SimpleNamespace(**{**vars(args),'output':root/'fresh-scored'}))
assert fresh_result['dataset_use']['evaluation_only'] and fresh_result['dataset_use']['validation_only_refresh']
assert fresh_result['dataset_use']['previous_validation_group_overlap'] == ['heldout-contributor']
assert 'independent scenes are not established' in fresh_result['limitations'][-1]
assert 'reused-for-selection' not in fresh_result['limitations'][-1]
summary_path.write_bytes(summary_bytes)
original = (args.output/'comparison.json').read_bytes()
rejected(lambda: browser.score(args))
assert (args.output/'comparison.json').read_bytes() == original
args.output = root/'mismatched'
other = copy.deepcopy(report)
other['models'] = {'other':other['models']['generic']}
other['browser_version'] = 'changed'
second = root/'second.json'
second.write_text(json.dumps(other))
args.benchmark = [source, second]
rejected(lambda: browser.score(args))
assert not args.output.exists()
args.output = root/'changed-report'
args.benchmark = [source]
original_summary = browser.summarize_model
def change_report(*values):
    result = original_summary(*values)
    source.write_bytes(source.read_bytes()+b' ')
    return result
with patch.object(browser, 'summarize_model', side_effect=change_report):
    rejected(lambda: browser.score(args))
assert not args.output.exists()
print('Browser scoring, label policy, provenance, and no-overwrite checks passed')
PYTHON;
        $process = new Process([...$command, '-', resource_path('ml'), $this->directory]);
        $process->setInput($code)->setTimeout(30)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Browser scoring, label policy, provenance, and no-overwrite checks passed', $process->getOutput());
    }

    public function test_hitl_import_preserves_fresh_grouped_holdouts_and_requires_can_reviews(): void
    {
        $this->trainingFixture();
        $executable = PHP_OS_FAMILY === 'Windows' ? 'Scripts/python.exe' : 'bin/python';
        $python = storage_path('app/private/waste-training/.venv/'.$executable);
        if (! is_file($python)) {
            $this->markTestSkipped('The preparation environment with Pillow is required.');
        }
        $code = <<<'PYTHON'
import copy, json, sys
from pathlib import Path
from types import SimpleNamespace
from PIL import Image
sys.path.insert(0, sys.argv[1])
import prepare_hitl as hitl
from train_waste import TrainingError, validate_dataset
root = Path(sys.argv[2]).resolve()

def rejected(action):
    try:
        action()
    except (ValueError, RuntimeError, TrainingError):
        return
    raise AssertionError('Invalid input accepted')

publisher = {'id': 3380384, 'ref': 'humansintheloop/recycling-dataset', 'ownerRef': 'humansintheloop',
    'licenseName': 'CC0: Public Domain', 'isPrivate': False, 'description': 'CC0 1.0',
    'versions': [{'versionNumber': 2, 'status': 'Ready'}]}
hitl.validate_publisher(publisher)
rejected(lambda: hitl.validate_publisher({**publisher, 'ownerRef': 'mirror'}))
rejected(lambda: hitl.validate_publisher({**publisher, 'licenseName': 'CC BY-NC'}))
assert hitl.allowed_url('https://storage.googleapis.com/kagglesdsdata/datasets/3380384/5884426/ds0/img/a.jpeg')
assert not hitl.allowed_url('https://storage.googleapis.com/another-dataset/a.jpeg')
assert not hitl.allowed_url('https://example.invalid/a.jpeg')
oriented_path = root/'oriented.jpeg'
exif = Image.Exif()
exif[274] = 6
Image.new('RGB', (100,80), 'red').save(oriented_path, exif=exif)
assert hitl.source_image(oriented_path, {'size': {'width':80, 'height':100}}).size == (80,100)
rejected(lambda: hitl.source_image(oriented_path, {'size': {'width':100, 'height':80}}))

source = root / 'source'
(source / 'images').mkdir(parents=True)
(source / 'annotations').mkdir()
hitl.write_json(source / 'publisher.json', publisher)
hitl.write_json(source / 'files.json', [])
actors = {split: next(f'portugal{i}' for i in range(1000) if hitl.source_split(f'portugal{i}') == split)
          for split in ('train', 'validation', 'test')}
candidates, reviews = [], {}
for index, split in enumerate(('train', 'validation', 'test'), 1):
    base_path = root / 'dataset' / split / 'data' / f'{index}.jpg'
    Image.new('RGB', (100, 80), index*25).save(base_path)
    document = {'size': {'width': 100, 'height': 80}, 'objects': []}
    for category, (material, kind) in enumerate((('plastic','bottle'), ('plastic','wrapper'), ('paper','newspaper'), ('aluminum','can')), 1):
        document['objects'].append({'id': index*10+category, 'classTitle': 'litter', 'geometryType': 'rectangle',
            'labelerLogin': actors[split], 'points': {'exterior': [[10, 10], [30, 40]], 'interior': []},
            'tags': [{'name': 'Material', 'value': material}, {'name': 'Object', 'value': kind}]})
    assert [b['category_id'] for b in hitl.parse_annotation(document)[0]] == [1,2,3,4]
    invalid = copy.deepcopy(document)
    invalid['objects'][0]['points']['exterior'][1][0] = 101
    rejected(lambda: hitl.parse_annotation(invalid))
    invalid = copy.deepcopy(document)
    invalid['objects'][0]['labelerLogin'] = 'other'
    rejected(lambda: hitl.parse_annotation(invalid))
    ignored = copy.deepcopy(document)
    ignored['objects'] = [ignored['objects'][2]]
    ignored['objects'][0]['tags'][1]['value'] = 'box'
    assert hitl.parse_annotation(ignored)[0] == []
    name = f'{index}.jpeg'
    Image.new('RGB', (100,80), 120+index*35).save(source / 'images' / name)
    hitl.write_json(source / 'annotations' / (name+'.json'), document)
    candidate = {'image': 'ds0/img/'+name, 'annotation': name+'.json', 'actor': actors[split], 'split': split,
        'source_sha256': hitl.digest(source/'images'/name), 'annotation_sha256': hitl.digest(source/'annotations'/(name+'.json'))}
    candidates.append(candidate)
    reviews[str(index*10+4)] = {'source_sha256': candidate['source_sha256'], 'label': 'drink_can', 'reviewer': 'fixture'}
duplicate = {**candidates[1], 'image': 'ds0/img/duplicate.jpeg', 'annotation': 'duplicate.jpeg.json'}
(source/'images/duplicate.jpeg').write_bytes((source/'images/2.jpeg').read_bytes())
(source/'annotations/duplicate.jpeg.json').write_bytes((source/'annotations/2.jpeg.json').read_bytes())
candidates.append(duplicate)
hitl.write_json(source/'collection.json', {'status':'collected', 'collection':hitl.COLLECTION, 'candidates':candidates,
    'publisher_sha256':hitl.digest(source/'publisher.json'), 'file_index_sha256':hitl.digest(source/'files.json')})
args = SimpleNamespace(base=root/'dataset', source=source, output=root/'prepared', reviews=None)
rejected(lambda: hitl.prepare(args))
assert not args.output.exists()
bad_reviews = copy.deepcopy(reviews)
bad_reviews['24']['label'] = 'aerosol'
hitl.write_json(root/'reviews.json', bad_reviews)
args.reviews = root/'reviews.json'
rejected(lambda: hitl.prepare(args))
assert not args.output.exists()
hitl.write_json(args.reviews, reviews)
args.output = root/'review-sheets'
hitl.review_sheets(args)
assert (args.output/'cans-1.png').is_file()
args.output = root/'prepared'
summary = hitl.prepare(args)
assert summary['added_images'] == 3 and len(summary['excluded']) == 1
assert summary['counts']['train']['images'] == 2
assert summary['counts']['validation']['images'] == 1
assert summary['counts']['test']['images'] == 1
assert not summary['promoted'] and summary['missing_classes'] == ['leaves','wood','rock']
assert (args.output/'train/data/1.jpg').read_bytes() == (args.base/'train/data/1.jpg').read_bytes()
validate_dataset(args.output)
rejected(lambda: hitl.prepare(args))
rejected(lambda: hitl.candidate_paths(source, {**candidates[0], 'annotation':'../../outside.json'}))
protected_base = args.output
protected_files = {str(path.relative_to(protected_base)): path.read_bytes()
                   for split in ('validation', 'test') for path in (protected_base/split).rglob('*') if path.is_file()}
targeted = SimpleNamespace(base=protected_base, source=source, output=root/'targeted-review',
    review_class=['other_plastic','paper'], training_only=True, prepared_only=True)
hitl.review_sheets(targeted)
review_index = json.loads((targeted.output/'review-index.json').read_text())
assert review_index['training_only'] and review_index['prepared_only']
assert review_index['base_provenance_sha256'] == hitl.digest(protected_base/'hitl-provenance.json')
assert {item['source_object_id'] for item in review_index['objects']} == {12,13}
assert all(item['split'] == 'train' and item['prepared_image_id'] is not None for item in review_index['objects'])
assert review_index['objects'][0]['source_tags'] == {'Material':'plastic','Object':'wrapper'}
assert review_index['objects'][0]['source_bbox'] == [10,10,20,30]
assert (targeted.output/'objects-1.png').is_file() and not (targeted.output/'cans-1.png').exists()
rejected(lambda: hitl.review_sheets(targeted))
rejected(lambda: hitl.review_sheets(SimpleNamespace(**{**vars(targeted),'output':root/'bad-review','review_class':['unknown']})))
assert not (root/'bad-review').exists()
rejected(lambda: hitl.review_sheets(SimpleNamespace(**{**vars(targeted),'output':root/'non-hitl-review','base':root/'dataset'})))
assert not (root/'non-hitl-review').exists()
import prepare_ablation as ablation
base_snapshot = {str(path.relative_to(protected_base)):path.read_bytes()
                 for path in protected_base.rglob('*') if path.is_file()}
flag = {**review_index['objects'][0], 'reason':'Material uncertain in fixture',
        'status':'needs_material_confirmation', 'existing_training_label_changed':False}
assessment = {'status':'visually_reviewed', 'split':'train', 'reviewer':'fixture',
    'existing_annotations_changed':False, 'promoted':False,
    'base_provenance_sha256':review_index['base_provenance_sha256'],
    'wrapper_review_index_sha256':hitl.digest(targeted.output/'review-index.json'),
    'material_confirmation_queue':[flag]}
assessment_path = root/'assessment.json'
hitl.write_json(assessment_path, assessment)
exclusions = SimpleNamespace(base=protected_base, assessment=assessment_path,
    review_index=targeted.output/'review-index.json', output=root/'ablation')
experiment = ablation.prepare(exclusions)
assert experiment['counts']['train']['images'] == 1
assert experiment['counts']['train']['annotations'] == 4
assert len(experiment['excluded_training_images']) == 1
assert len(experiment['excluded_training_images'][0]['annotations']) == 4
assert not experiment['retrained'] and not experiment['promoted'] and not experiment['accuracy_gain_measured']
assert experiment['base_dataset_sha256'] == ablation.dataset_hash(protected_base)
assert experiment['dataset_sha256'] == ablation.dataset_hash(exclusions.output)
remaining = json.loads((exclusions.output/'train/labels.json').read_text())
assert {image['id'] for image in remaining['images']} == {1}
assert not (exclusions.output/'train/data'/f"{flag['prepared_image_id']}.jpg").exists()
original_train = json.loads((protected_base/'train/labels.json').read_text())
assert remaining['annotations'] == [a for a in original_train['annotations'] if a['image_id'] == 1]
assert all((exclusions.output/name).read_bytes() == content for name,content in protected_files.items())
assert all((protected_base/name).read_bytes() == content for name,content in base_snapshot.items())
assert (exclusions.output/'train/data/1.jpg').read_bytes() == (protected_base/'train/data/1.jpg').read_bytes()
experiment_proof = json.loads((exclusions.output/'hitl-provenance.json').read_text())
assert experiment_proof['parent_provenance_sha256'] == hitl.digest(protected_base/'hitl-provenance.json')
assert all(entry['image_id'] != flag['prepared_image_id'] for entry in experiment_proof['images'].values())
validate_dataset(exclusions.output)
rejected(lambda: ablation.prepare(exclusions))

def reject_assessment(value, index_value=None):
    trial = SimpleNamespace(**{**vars(exclusions),'output':root/'invalid-ablation'})
    if index_value is not None:
        trial.review_index = root/'invalid-index.json'
        hitl.write_json(trial.review_index, index_value)
        value = {**value,'wrapper_review_index_sha256':hitl.digest(trial.review_index)}
    hitl.write_json(assessment_path, value)
    rejected(lambda: ablation.prepare(trial))
    assert not trial.output.exists()
    hitl.write_json(assessment_path, assessment)

reject_assessment({**assessment,'material_confirmation_queue':[]})
reject_assessment({**assessment,'material_confirmation_queue':[flag,flag]})
reject_assessment({**assessment,'base_provenance_sha256':'0'*64})
reject_assessment({**assessment,'wrapper_review_index_sha256':'0'*64})
reject_assessment({**assessment,'existing_annotations_changed':True})
reject_assessment({**assessment,'material_confirmation_queue':[{**flag,'split':'test'}]})
reject_assessment({**assessment,'material_confirmation_queue':[{**flag,'prepared_image_id':True}]})
reject_assessment({**assessment,'material_confirmation_queue':[{**flag,'reason':' '}]})
for field,value in [('source_sha256','0'*64),('source_bbox',[0,0,20,30]),('category','paper'),
                    ('source_tags',{'Material':'paper','Object':'wrapper'})]:
    changed = {**flag,field:value}
    changed_index = {**review_index,'objects':[{**review_index['objects'][0],field:value}]}
    reject_assessment({**assessment,'material_confirmation_queue':[changed]}, changed_index)
heldout = {**flag,'image':candidates[1]['image'],'source_object_id':22,'prepared_image_id':5,
           'source_sha256':candidates[1]['source_sha256'],'annotation_sha256':candidates[1]['annotation_sha256']}
reject_assessment({**assessment,'material_confirmation_queue':[heldout]}, {**review_index,'objects':[heldout]})
train_path = protected_base/'train/labels.json'
train_bytes = train_path.read_bytes()
without_base_can = copy.deepcopy(original_train)
without_base_can['annotations'] = [a for a in without_base_can['annotations'] if not (a['image_id']==1 and a['category_id']==4)]
hitl.write_json(train_path, without_base_can)
validate_dataset(protected_base)
reject_assessment(assessment)
train_path.write_bytes(train_bytes)
original_copy = ablation.shutil.copy2
def damaged_copy(source_path, destination):
    result = original_copy(source_path, destination)
    if Path(destination).as_posix().endswith('/train/data/1.jpg'):
        Path(destination).write_bytes(Path(destination).read_bytes()+b'changed')
    return result
try:
    ablation.shutil.copy2 = damaged_copy
    damaged = SimpleNamespace(**{**vars(exclusions),'output':root/'damaged-ablation'})
    rejected(lambda: ablation.prepare(damaged))
    failed = json.loads((damaged.output/'summary.json').read_text())
    assert failed['status'] == 'failed' and not failed['full_dataset']
    rejected(lambda: validate_dataset(damaged.output))
finally:
    ablation.shutil.copy2 = original_copy
assert all((protected_base/name).read_bytes() == content for name,content in base_snapshot.items())
old_arguments = sys.argv
try:
    sys.argv = ['prepare_ablation.py','--base',str(protected_base),'--assessment',str(assessment_path),
        '--review-index',str(exclusions.review_index),'--output',str(root/'cli-ablation')]
    assert ablation.main() == 0
    assert ablation.main() == 1
    sys.argv = ['prepare_hitl.py','--base',str(protected_base),'--source',str(source),
        '--review-sheets','--review-class','other_plastic','--training-only','--prepared-only','--output',str(root/'cli-review')]
    assert hitl.main() == 0
    cli_index = json.loads((root/'cli-review/review-index.json').read_text())
    assert len(cli_index['objects']) == 1 and cli_index['objects'][0]['source_object_id'] == 12
    sys.argv = ['prepare_hitl.py','--base',str(protected_base),'--source',str(source),'--training-only','--collect']
    assert hitl.main() == 1
finally:
    sys.argv = old_arguments
original_collection = (source/'collection.json').read_bytes()
expanded_candidates = copy.deepcopy(candidates)
for name, split, color in [('fresh.jpeg', 'train', (10,180,70)),
                          ('heldout.jpeg', 'validation', (180,180,20)),
                          ('near-heldout.jpeg', 'train', None),
                          ('unreviewed-can.jpeg', 'train', (0,0,255))]:
    document = json.loads((source/'annotations/1.jpeg.json').read_text())
    document['objects'] = [document['objects'][3 if name == 'unreviewed-can.jpeg' else 1]]
    document['objects'][0]['labelerLogin'] = actors[split]
    document['objects'][0]['id'] = 100 + len(expanded_candidates)
    if color is None:
        (source/'images'/name).write_bytes((source/'images/2.jpeg').read_bytes())
    else:
        Image.new('RGB', (100,80), color).save(source/'images'/name)
    hitl.write_json(source/'annotations'/(name+'.json'), document)
    expanded_candidates.append({'image':'ds0/img/'+name, 'annotation':name+'.json', 'actor':actors[split], 'split':split,
        'source_sha256':hitl.digest(source/'images'/name), 'annotation_sha256':hitl.digest(source/'annotations'/(name+'.json'))})
collection = json.loads(original_collection)
collection['candidates'] = expanded_candidates
hitl.write_json(source/'collection.json', collection)
expanded = SimpleNamespace(base=protected_base, source=source, output=root/'expanded', reviews=None, extend_training=True)
rejected(lambda: hitl.prepare(SimpleNamespace(**{**vars(expanded), 'extend_training':False})))
rejected(lambda: hitl.prepare(SimpleNamespace(**{**vars(expanded), 'base':root/'dataset'})))
expansion = hitl.prepare(expanded)
assert expansion['training_only_expansion'] and expansion['added_images'] == 1
assert expansion['counts']['train']['images'] == summary['counts']['train']['images'] + 1
assert expansion['counts']['validation'] == summary['counts']['validation']
assert expansion['counts']['test'] == summary['counts']['test']
assert len(expansion['pending_can_review']) == 1 and not expansion['promoted']
assert any(item['image'].endswith('near-heldout.jpeg') and 'duplicate' in item['reason'] for item in expansion['excluded'])
assert any(item['image'].endswith('heldout.jpeg') and 'Holdout split protected' in item['reason'] for item in expansion['excluded'])
assert all((expanded.output/name).read_bytes() == content for name, content in protected_files.items())
assert all((protected_base/name).read_bytes() == content for name, content in protected_files.items())
expanded_proof = json.loads((expanded.output/'hitl-provenance.json').read_text())
assert expanded_proof['parent_provenance_sha256'] == hitl.digest(protected_base/'hitl-provenance.json')
assert expanded_proof['collection_sha256'] == hitl.digest(source/'collection.json')
validate_dataset(expanded.output)
child = ablation.prepare(SimpleNamespace(**{**vars(exclusions),'base':expanded.output,'output':root/'expanded-ablation'}))
assert child['counts']['train']['images'] == expansion['counts']['train']['images']-1
assert all((root/'expanded-ablation'/name).read_bytes() == content for name,content in protected_files.items())
rejected(lambda: hitl.prepare(expanded))
hitl.write_json(source/'collection.json', json.loads(original_collection))
expanded.output = root/'no-new-training'
rejected(lambda: hitl.prepare(expanded))
assert not expanded.output.exists()
labels_path = args.output/'validation/labels.json'
labels = json.loads(labels_path.read_text())
assert all(i['source_collection'] == hitl.COLLECTION for i in labels['images'])
original = labels_path.read_bytes()
labels['annotations'][0]['category_id'] = 4
hitl.write_json(labels_path, labels)
rejected(lambda: validate_dataset(args.output))
labels_path.write_bytes(original)
proof_path = args.output/'hitl-provenance.json'
proof = json.loads(proof_path.read_text())
entry = next(v for v in proof['images'].values() if v['split'] == 'validation')
entry['can_reviews']['24']['label'] = 'aerosol'
hitl.write_json(proof_path, proof)
rejected(lambda: validate_dataset(args.output))
print('HITL import and rejection checks passed')
PYTHON;
        $process = new Process([$python, '-', resource_path('ml'), $this->directory]);
        $process->setInput($code)->setTimeout(60)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('HITL import and rejection checks passed', $process->getOutput());
    }

    public function test_fresh_validation_excludes_used_photos_preserves_protected_files_and_cannot_train(): void
    {
        $this->trainingFixture();
        $executable = PHP_OS_FAMILY === 'Windows' ? 'Scripts/python.exe' : 'bin/python';
        $python = storage_path('app/private/waste-training/.venv/'.$executable);
        if (! is_file($python)) {
            $this->markTestSkipped('The preparation environment with Pillow is required.');
        }
        $code = <<<'PYTHON'
import copy, json, sys
from pathlib import Path
from types import SimpleNamespace
from PIL import Image
sys.path.insert(0, sys.argv[1])
import prepare_hitl as hitl
import train_waste as trainer
root = Path(sys.argv[2]).resolve()

def rejected(action, message=None):
    try:
        action()
    except (ValueError, RuntimeError, trainer.TrainingError) as error:
        assert message is None or message in str(error), str(error)
        return
    raise AssertionError('Invalid input accepted')

source = root/'source'
(source/'images').mkdir(parents=True)
(source/'annotations').mkdir()
publisher = {'id':3380384, 'ref':'humansintheloop/recycling-dataset', 'ownerRef':'humansintheloop',
    'licenseName':'CC0: Public Domain', 'isPrivate':False, 'description':'CC0 1.0',
    'versions':[{'versionNumber':2, 'status':'Ready'}]}
hitl.write_json(source/'publisher.json', publisher)
hitl.write_json(source/'files.json', [])
actors = {split:next(f'portugal{i}' for i in range(1000) if hitl.source_split(f'portugal{i}') == split)
          for split in ('train','validation','test')}
candidates, reviews = [], {}

def add_photo(index, split, color, duplicate=None):
    name = f'{index}.jpeg'
    image_path = source/'images'/name
    if duplicate is None:
        Image.new('RGB', (100,80), color).save(image_path)
    else:
        image_path.write_bytes((source/'images'/duplicate).read_bytes())
    document = {'size':{'width':100,'height':80}, 'objects':[
        {'id':index*10+category, 'classTitle':'litter', 'geometryType':'rectangle', 'labelerLogin':actors[split],
         'points':{'exterior':[[10,10],[30,40]], 'interior':[]},
         'tags':[{'name':'Material','value':material}, {'name':'Object','value':kind}]}
        for category,(material,kind) in enumerate((('plastic','bottle'),('plastic','wrapper'),('paper','newspaper'),('aluminum','can')),1)]}
    hitl.write_json(source/'annotations'/(name+'.json'), document)
    candidate = {'image':'ds0/img/'+name, 'annotation':name+'.json', 'actor':actors[split], 'split':split,
        'source_sha256':hitl.digest(image_path), 'annotation_sha256':hitl.digest(source/'annotations'/(name+'.json'))}
    candidates.append(candidate)
    reviews[str(index*10+4)] = {'source_sha256':candidate['source_sha256'], 'label':'food_can', 'reviewer':'fixture'}

def collection():
    hitl.write_json(source/'collection.json', {'status':'collected', 'collection':hitl.COLLECTION,
        'publisher_sha256':hitl.digest(source/'publisher.json'), 'file_index_sha256':hitl.digest(source/'files.json'),
        'candidates':candidates})
    hitl.write_json(root/'reviews.json', reviews)

for index,split in enumerate(('train','validation','test'),1):
    Image.new('RGB', (100,80), index*25).save(root/'dataset'/split/'data'/f'{index}.jpg')
    add_photo(index, split, 120+index*35)
collection()
args = SimpleNamespace(base=root/'dataset', source=source, output=root/'prepared', reviews=root/'reviews.json')
hitl.prepare(args)
base = args.output
base_snapshot = {str(p.relative_to(base)):p.read_bytes() for p in base.rglob('*') if p.is_file()}
old_labels = json.loads((base/'validation/labels.json').read_text())
old_source = old_labels['images'][0]['source_image_id']
add_photo(4, 'validation', 100)
add_photo(5, 'train', 10)
add_photo(6, 'test', 240)
add_photo(7, 'validation', None, duplicate='2.jpeg')
collection()
fresh = SimpleNamespace(base=base, source=source, output=root/'fresh', reviews=args.reviews,
    refresh_validation=True, min_validation_per_class=1)
for changes in ({'min_validation_per_class':2}, {'min_validation_per_class':True},
                {'extend_training':True}, {'base':root/'dataset'}, {'reviews':None}):
    rejected(lambda:hitl.prepare(SimpleNamespace(**{**vars(fresh), **changes})))
    assert not fresh.output.exists()
reviews['44']['label'] = 'aerosol'
collection()
rejected(lambda:hitl.prepare(fresh))
reviews['44']['label'] = 'food_can'
collection()
bad_image = source/'images/4.jpeg'
original = bad_image.read_bytes()
bad_image.write_bytes(original+b'changed')
rejected(lambda:hitl.prepare(fresh), 'bytes changed')
bad_image.write_bytes(original)
summary = hitl.prepare(fresh)
assert summary['evaluation_only'] and summary['validation_only_refresh'] and not summary['promoted']
assert summary['added_images'] == 1 and set(summary['validation_image_coverage_by_class'].values()) == {1}
assert summary['previous_validation_group_overlap'] == [old_labels['images'][0]['group_id']]
assert any('duplicate' in row['reason'] for row in summary['excluded'])
assert all((fresh.output/name).read_bytes() == content for name,content in base_snapshot.items() if name.startswith(('train/','test/')))
assert all((base/name).read_bytes() == content for name,content in base_snapshot.items())
new_labels = json.loads((fresh.output/'validation/labels.json').read_text())
assert [image['source_image_id'] for image in new_labels['images']] == [hitl.COLLECTION+':ds0/img/4.jpeg']
assert new_labels['images'][0]['id'] > max(image['id'] for split in ('train','validation','test')
    for image in json.loads((base/split/'labels.json').read_text())['images'])
proof = json.loads((fresh.output/'hitl-provenance.json').read_text())
assert old_source not in proof['images'] and old_source in proof['previous_evaluation_source_ids']
assert proof['evaluation_only'] and proof['parent_provenance_sha256'] == hitl.digest(base/'hitl-provenance.json')
trainer.validate_dataset(fresh.output)
trainer.validate_training_use(base)
rejected(lambda:trainer.validate_training_use(fresh.output), 'Evaluation-only')
rejected(lambda:trainer.train(SimpleNamespace(dataset=fresh.output),None,{},{}), 'Evaluation-only')
for dry in ([],['--dry-run']):
    assert trainer.main(['--dataset',str(fresh.output),'--output',str(root/'forbidden-training'),*dry]) == 1
assert not (root/'forbidden-training').exists()
assert trainer.main(['--dataset',str(fresh.output),'--check-dataset']) == 0
hitl.write_json(fresh.output/'summary.json', {**summary,'evaluation_only':False})
rejected(lambda:trainer.validate_training_use(fresh.output), 'Evaluation-only')
hitl.write_json(fresh.output/'summary.json', summary)
rejected(lambda:hitl.prepare(SimpleNamespace(**{**vars(fresh),'base':fresh.output,'output':root/'bad-expansion',
    'refresh_validation':False,'extend_training':True})), 'Evaluation-only')

review = SimpleNamespace(**{**vars(fresh),'output':root/'fresh-review'})
hitl.review_sheets(review)
index = json.loads((review.output/'review-index.json').read_text())
assert index['validation_only_refresh'] and {row['source_object_id'] for row in index['objects']} == {44,74}
assert all(row['split'] == 'validation' and row['prepared_image_id'] is None for row in index['objects'])
rejected(lambda:hitl.review_sheets(SimpleNamespace(**{**vars(review),'output':root/'bad-review','training_only':True})))

original_copytree = hitl.shutil.copytree
def damaged_copytree(source_path, destination, *args, **kwargs):
    result = original_copytree(source_path, destination, *args, **kwargs)
    if Path(destination).parts[-2:] == ('train','data'):
        target = Path(destination)/'1.jpg'
        target.write_bytes(target.read_bytes()+b'changed')
    return result
try:
    hitl.shutil.copytree = damaged_copytree
    damaged = SimpleNamespace(**{**vars(fresh),'output':root/'damaged'})
    rejected(lambda:hitl.prepare(damaged), 'Protected')
    failed = json.loads((damaged.output/'summary.json').read_text())
    assert failed['status'] == 'failed' and not failed['full_dataset']
finally:
    hitl.shutil.copytree = original_copytree

cache = root/'collection-cache'
(cache/'annotations').mkdir(parents=True)
hitl.write_json(cache/'publisher.json', publisher)
names = [candidate['image'] for candidate in candidates]+['ds0/ann/'+candidate['annotation'] for candidate in candidates]
hitl.write_json(cache/'files.json', names)
for candidate in candidates:
    (cache/'annotations'/candidate['annotation']).write_bytes((source/'annotations'/candidate['annotation']).read_bytes())
downloaded = []
def cached_download(name):
    assert name.startswith('ds0/img/')
    downloaded.append(name)
    return (source/'images'/Path(name).name).read_bytes()
original_download, old_arguments = hitl.download, sys.argv
try:
    hitl.download = cached_download
    sys.argv = ['prepare_hitl.py','--base',str(base),'--source',str(cache),'--collect','--refresh-validation',
        '--holdout-per-class','3']
    assert hitl.main() == 0
    assert set(downloaded) == {'ds0/img/4.jpeg','ds0/img/7.jpeg'}
    collected = json.loads((cache/'collection.json').read_text())
    assert collected['validation_only_refresh'] and collected['excluded_base_provenance_sha256'] == hitl.digest(base/'hitl-provenance.json')
    for invalid in ('--training-only','--prepared-only','--extend-training'):
        sys.argv = ['prepare_hitl.py','--base',str(base),'--source',str(cache),'--collect','--refresh-validation',invalid]
        assert hitl.main() == 1
    downloaded.clear()
    sys.argv = ['prepare_hitl.py','--base',str(fresh.output),'--source',str(cache),'--collect','--refresh-validation']
    assert hitl.main() == 0
    record = json.loads((cache/'collection.json').read_text())
    assert all(candidate['image'] not in {'ds0/img/2.jpeg','ds0/img/4.jpeg'} for candidate in record['candidates'])
finally:
    hitl.download, sys.argv = original_download, old_arguments
assert all((base/name).read_bytes() == content for name,content in base_snapshot.items())
print('Fresh validation protection, minimum coverage, and training rejection checks passed')
PYTHON;
        $process = new Process([$python, '-', resource_path('ml'), $this->directory]);
        $process->setInput($code)->setTimeout(60)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertStringContainsString('Fresh validation protection, minimum coverage, and training rejection checks passed', $process->getOutput());
    }

    public function test_learning_diagnostic_requires_reviewed_training_only_sources_and_preserves_original_data(): void
    {
        $this->trainingFixture();
        $executable = PHP_OS_FAMILY === 'Windows' ? 'Scripts/python.exe' : 'bin/python';
        $python = storage_path('app/private/waste-training/.venv/'.$executable);
        if (! is_file($python)) {
            $this->markTestSkipped('The preparation environment with Pillow is required.');
        }
        $code = <<<'PYTHON'
import copy, json, random, sys
from pathlib import Path
from types import SimpleNamespace as Args
from PIL import Image, ImageDraw
sys.path.insert(0,sys.argv[1])
import prepare_hitl as hitl
import diagnose_waste as diagnostic
from train_waste import TrainingError, validate_dataset, validate_training_use
root=Path(sys.argv[2]).resolve()

def rejected(action):
    try: action()
    except (ValueError, RuntimeError, TrainingError, FileNotFoundError): return
    raise AssertionError('Invalid diagnostic accepted')

source=root/'source'
(source/'images').mkdir(parents=True)
(source/'annotations').mkdir()
publisher={'id':3380384,'ref':'humansintheloop/recycling-dataset','ownerRef':'humansintheloop',
    'licenseName':'CC0: Public Domain','isPrivate':False,'description':'CC0 1.0',
    'versions':[{'versionNumber':2,'status':'Ready'}]}
hitl.write_json(source/'publisher.json',publisher)
hitl.write_json(source/'files.json',[])
actors={split:next(f'portugal{i}' for i in range(1000) if hitl.source_split(f'portugal{i}')==split)
    for split in ('train','validation','test')}
for index,split in enumerate(('train','validation','test'),1):
    Image.new('RGB',(100,80),index*25).save(root/'dataset'/split/'data'/f'{index}.jpg')
candidates,reviews=[],{}
for split in ('train','validation','test'):
    for category,(material,kind) in enumerate((('plastic','bottle'),('plastic','wrapper'),('paper','newspaper'),('aluminum','can')),1):
        for occurrence in range(5 if split=='train' else 1):
            index=len(candidates)+1
            name=f'{index}.jpeg'
            rng=random.Random(index)
            image=Image.new('RGB',(100,80))
            drawing=ImageDraw.Draw(image)
            for y in range(0,80,10):
                for x in range(0,100,10):
                    drawing.rectangle((x,y,x+9,y+9),fill=tuple(rng.randrange(256) for _ in range(3)))
            image.save(source/'images'/name)
            document={'size':{'width':100,'height':80},'objects':[{'id':100+index,'classTitle':'litter',
                'geometryType':'rectangle','labelerLogin':actors[split],
                'points':{'exterior':[[10,10],[80,70]],'interior':[]},
                'tags':[{'name':'Material','value':material},{'name':'Object','value':kind}]}]}
            hitl.write_json(source/'annotations'/(name+'.json'),document)
            candidate={'image':'ds0/img/'+name,'annotation':name+'.json','actor':actors[split],'split':split,
                'source_sha256':hitl.digest(source/'images'/name),'annotation_sha256':hitl.digest(source/'annotations'/(name+'.json'))}
            candidates.append(candidate)
            if category==4:
                reviews[str(100+index)]={'source_sha256':candidate['source_sha256'],'label':'drink_can','reviewer':'fixture'}
hitl.write_json(source/'collection.json',{'status':'collected','collection':hitl.COLLECTION,'candidates':candidates,
    'publisher_sha256':hitl.digest(source/'publisher.json'),'file_index_sha256':hitl.digest(source/'files.json')})
hitl.write_json(root/'can-reviews.json',reviews)
base=root/'prepared'
hitl.prepare(Args(base=root/'dataset',source=source,output=base,reviews=root/'can-reviews.json'))
snapshot={str(path.relative_to(base)):path.read_bytes() for path in base.rglob('*') if path.is_file()}
args=Args(dataset=base,output=root/'diagnostic',photos_per_class=5)
summary=diagnostic.prepare(args)
assert summary['diagnostic_only'] and summary['evaluation_only'] and not summary['full_dataset']
assert summary['evaluation_scope']=='training_resubstitution' and not summary['held_out_photos_used']
audit=diagnostic.validate_snapshot(args.output,require_review=False)
assert audit['counts']['train']['images']==20
assert set(audit['counts']['train']['annotations_by_class'].values())=={5}
assert not (args.output/'validation').exists() and not (args.output/'test').exists()
assert (args.output/'review-1.png').is_file() and (args.output/'review-2.png').is_file()
rejected(lambda:validate_dataset(args.output))
rejected(lambda:validate_training_use(args.output))
rejected(lambda:diagnostic.validate_snapshot(args.output))
rejected(lambda:diagnostic.train(Args(dataset=args.output,output=root/'forbidden',epochs=1,resume=False)))
assert not (root/'forbidden').exists()
ids=[image['id'] for image in audit['labels']['images']]
review={'status':'accepted','summary_sha256':audit['summary_sha256'],'reviewer':'fixture','accepted_image_ids':ids}
hitl.write_json(args.output/'review.json',review)
diagnostic.validate_snapshot(args.output)
import subprocess
node = r'''
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
const modulePath = process.argv[1];
process.argv[1] = 'diagnostic-fixture.mjs';
const {loadInputs} = await import(pathToFileURL(modulePath).href);
const root = process.argv[2], dataset = `${root}/diagnostic`;
const model = `${root}/fixture.tflite`, models = `${root}/models.json`;
await writeFile(model, Buffer.from('0000000054464c3300000000', 'hex'));
await writeFile(models, JSON.stringify([{id:'fixture',path:model,input_size:256,label_space:'waste'}]));
const input = await loadInputs(dataset,models,`${root}/browser.json`,{diagnosticTraining:true});
assert.equal(input.split,'train');
assert.equal(input.diagnostic.evaluation_scope,'training_resubstitution');
assert.equal(input.images.size,20);
assert.equal(input.diagnosticFiles.size,3);
await assert.rejects(loadInputs(dataset,models,`${root}/not-validation.json`));
await assert.rejects(loadInputs(dataset,models,`${root}/bad-flag.json`,{diagnosticTraining:'yes'}));
const reviewPath = `${dataset}/review.json`, original = await readFile(reviewPath);
try {
  const review = JSON.parse(original);
  await writeFile(reviewPath,JSON.stringify({...review,status:'pending'}));
  await assert.rejects(loadInputs(dataset,models,`${root}/not-reviewed.json`,{diagnosticTraining:true}));
} finally { await writeFile(reviewPath,original); }
console.log('Explicit browser diagnostic scope checks passed');
'''
checked=subprocess.run(['node','--input-type=module','-e',node,str(Path(sys.argv[1])/'benchmark_waste.mjs'),str(root)],
    capture_output=True,text=True,timeout=30)
assert checked.returncode==0,checked.stdout+checked.stderr
assert 'Explicit browser diagnostic scope checks passed' in checked.stdout
import score_browser_waste as browser
from unittest.mock import patch
labels=audit['labels']
benchmark={'status':'inferred','split':'train','diagnostic_only':True,'evaluation_scope':'training_resubstitution',
    'snapshot_sha256':audit['summary_sha256'],'review_sha256':diagnostic.file_hash(args.output/'review.json'),
    'deployment_ready':False,'promoted':False,'settings':browser.SETTINGS,
    'worker_sha256':'a'*64,'benchmarker_sha256':'b'*64,'models_config_sha256':'c'*64,
    'assets_sha256':{'worker':'a'*64},'application_sources_sha256':{'camera':'d'*64},'browser_version':'fixture',
    'labels_sha256':summary['labels_sha256'],'image_sha256':summary['image_sha256'],
    'models':{'fixture':{'model_sha256':'e'*64,'input_size':256,'label_space':'generic','predictions':[],
        'frames':[{'image_id':image['id'],'width':image['width'],'height':image['height'],'inference_ms':1,'detections':[]} for image in labels['images']]}}}
benchmark_path=root/'benchmark.json'
hitl.write_json(benchmark_path,benchmark)
scoring=Args(dataset=args.output,benchmark=[benchmark_path],output=root/'scored',diagnostic_training=True)
scored=browser.score(scoring)
assert scored['split']=='train' and scored['diagnostic_only'] and scored['evaluation_scope']=='training_resubstitution'
assert scored['snapshot_sha256']==audit['summary_sha256'] and not scored['deployment_ready'] and not scored['promoted']
assert 'not validation' in scored['limitations'][0]
rejected(lambda:browser.score(Args(**{**vars(scoring),'output':root/'bad-scope','diagnostic_training':False})))
assert not (root/'bad-scope').exists()
for change in ({'diagnostic_only':False},{'split':'validation'},{'snapshot_sha256':'0'*64},{'review_sha256':'0'*64}):
    hitl.write_json(benchmark_path,{**benchmark,**change})
    rejected(lambda:browser.score(Args(**{**vars(scoring),'output':root/'bad-report'})))
    assert not (root/'bad-report').exists()
hitl.write_json(benchmark_path,benchmark)
original_summary=browser.summarize_model
def changed_review(*values):
    result=original_summary(*values)
    hitl.write_json(args.output/'review.json',{**review,'status':'pending'})
    return result
with patch.object(browser,'summarize_model',side_effect=changed_review):
    rejected(lambda:browser.score(Args(**{**vars(scoring),'output':root/'changed-review'})))
assert not (root/'changed-review').exists()
hitl.write_json(args.output/'review.json',review)
for changes in ({'status':'pending'},{'summary_sha256':'0'*64},{'accepted_image_ids':ids[:-1]},
                {'accepted_image_ids':ids[:-1]+[ids[0]]},{'reviewer':' '}):
    hitl.write_json(args.output/'review.json',{**review,**changes})
    rejected(lambda:diagnostic.validate_snapshot(args.output))
hitl.write_json(args.output/'review.json',review)
for changes in ({'epochs':0},{'epochs':81},{'epochs':True},{'epochs':1,'input_pipeline':'unsupported'}):
    rejected(lambda:diagnostic.train(Args(dataset=args.output,output=root/'bad-training',resume=False,**changes)))
    assert not (root/'bad-training').exists()
for changes in ({'photos_per_class':6},{'photos_per_class':True},{'exclude_image':[999999]},
                {'exclude_image':[ids[0],ids[0]]},{'exclude_image':[ids[0]]}):
    trial=Args(**{**vars(args),**changes,'output':root/'bad-selection'})
    rejected(lambda:diagnostic.prepare(trial))
    assert not trial.output.exists()
image_path=args.output/'train/data'/f'{ids[0]}.jpg'
original=image_path.read_bytes()
image_path.write_bytes(original+b'changed')
rejected(lambda:diagnostic.validate_snapshot(args.output))
image_path.write_bytes(original)
labels_path=args.output/'train/labels.json'
original=labels_path.read_bytes()
labels=json.loads(original)
labels['annotations'][0]['category_id']=4
hitl.write_json(labels_path,labels)
rejected(lambda:diagnostic.validate_snapshot(args.output))
labels_path.write_bytes(original)
calls=[]
class Processor:
    anchor_boxes={'3':'anchors'}
    def __call__(self,data,is_training):
        calls.append(is_training)
        return data
processor=diagnostic.training_preprocessor(Processor())
assert processor.anchor_boxes=={'3':'anchors'}
assert processor('train',is_training=True)=='train'
assert processor('evaluation',is_training=False)=='evaluation'
assert calls==[False,False]
import audit_learning as learning
rejected(lambda:learning.audit(Args(dataset=args.output,run=root/'missing-run',output=root/'bad-audit')))
assert not (root/'bad-audit').exists()
run=root/'audit-source'
run.mkdir()
for name in ('float_ckpt.index','float_ckpt.data-00000-of-00001'):
    (run/name).write_bytes(b'fixture checkpoint')
metadata={'status':'diagnostic_trained','diagnostic_only':True,'promoted':False,'deployment_ready':False,
    'snapshot_sha256':audit['summary_sha256'],'review_sha256':diagnostic.file_hash(args.output/'review.json'),
    'models_sha256':{name:diagnostic.file_hash(run/name) for name in ('float_ckpt.index','float_ckpt.data-00000-of-00001')}}
with patch.object(learning,'check_environment',side_effect=AssertionError('Invalid input reached runtime preflight')):
    for change in ({'status':'trained'},{'diagnostic_only':False},{'promoted':True},{'deployment_ready':True},
                   {'snapshot_sha256':'0'*64},{'review_sha256':'0'*64},{'models_sha256':{}}):
        hitl.write_json(run/'metrics.json',{**metadata,**change})
        rejected(lambda:learning.audit(Args(dataset=args.output,run=run,output=root/'bad-audit')))
        assert not (root/'bad-audit').exists()
    hitl.write_json(run/'metrics.json',metadata)
    (run/'float_ckpt.index').write_bytes(b'changed checkpoint')
    rejected(lambda:learning.audit(Args(dataset=args.output,run=run,output=root/'bad-audit')))
    rejected(lambda:learning.audit(Args(dataset=args.output,run=run,output=run/'nested')))
accounting=root/'accounting'
(accounting/'batch-accounting').mkdir(parents=True)
record={'epoch':0,'expected_batches':10,'started_batches':10,'completed_batches':10,
    'optimizer_iterations_before':0,'optimizer_iterations_after':10,'passed':True}
hitl.write_json(accounting/'batch-accounting/epoch-00001.json',record)
assert learning.validate_batch_journal(accounting,1,10,10)['all_epoch_journals_passed']
for values in ((1,10,11),(2,10,20),(True,10,10),(1,True,1)):
    rejected(lambda:learning.validate_batch_journal(accounting,*values))
for changes in ({'passed':False},{'completed_batches':9},{'started_batches':11},
                {'optimizer_iterations_before':1},{'optimizer_iterations_after':11},{'expected_batches':True}):
    hitl.write_json(accounting/'batch-accounting/epoch-00001.json',{**record,**changes})
    rejected(lambda:learning.validate_batch_journal(accounting,1,10,10))
assert all((base/name).read_bytes()==content for name,content in snapshot.items())
rejected(lambda:diagnostic.prepare(args))
print('Training-only diagnostic review, isolation, augmentation, bounds and integrity checks passed')
PYTHON;
        $process = new Process([$python, '-', resource_path('ml'), $this->directory]);
        $process->setInput($code)->setTimeout(60)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertStringContainsString('Training-only diagnostic review, isolation, augmentation, bounds and integrity checks passed', $process->getOutput());
    }

    public function test_realdirt_import_checks_publisher_geometry_archive_and_image_provenance(): void
    {
        $this->trainingFixture();
        $executable = PHP_OS_FAMILY === 'Windows' ? 'Scripts/python.exe' : 'bin/python';
        $python = storage_path('app/private/waste-training/.venv/'.$executable);
        if (! is_file($python)) {
            $this->markTestSkipped('The preparation environment with Pillow is required.');
        }
        $code = <<<'PYTHON'
import copy, hashlib, io, json, sys, zipfile
from pathlib import Path
from types import SimpleNamespace
from PIL import Image
sys.path.insert(0, sys.argv[1])
import prepare_realdirt as realdirt
from train_waste import validate_dataset
root = Path(sys.argv[2]).resolve()

def rejected(action):
    try:
        action()
    except (ValueError, RuntimeError):
        return
    raise AssertionError('Invalid input was accepted')

assert realdirt.yolo_boxes('0 0.5 0.5 0.4 0.6\n1 0.2 0.2 0.2 0.2\n2 0.5 0.5 0.3 0.3', 100, 80)[0]['bbox'] == [30.0, 16.0, 40.0, 48.0]
assert [b['category_id'] for b in realdirt.yolo_boxes('0 0.5 0.5 0.2 0.2\n1 0.5 0.5 0.2 0.2\n2 0.5 0.5 0.2 0.2', 100, 80)] == [1, 2]
for invalid in ('0 nan 0.5 0.2 0.2', '0 0 0 1 1', '3 0.5 0.5 0.2 0.2', '0 0.5 0.5 0 0.2'):
    rejected(lambda: realdirt.yolo_boxes(invalid, 100, 80))
bad = io.BytesIO()
with zipfile.ZipFile(bad, 'w') as archive:
    archive.writestr('../outside.jpg', b'bad')
with zipfile.ZipFile(bad) as archive:
    rejected(lambda: realdirt.validate_archive(archive))

image = io.BytesIO()
Image.new('RGB', (100, 80), 'green').save(image, format='JPEG')
archive_path = root / 'fixture.zip'
with zipfile.ZipFile(archive_path, 'w') as archive:
    archive.writestr('RealDirt/data.yaml', 'names:\n- plast_bot\n- plast_bag\n- carton\nnc: 3\n')
    archive.writestr('RealDirt/train/images/abc123.jpg', image.getvalue())
    archive.writestr('RealDirt/train/labels/abc123.txt', '0 0.5 0.5 0.4 0.6\n1 0.2 0.2 0.2 0.2')
    similar = io.BytesIO()
    Image.new('RGB', (100, 80), (0, 130, 0)).save(similar, format='JPEG')
    archive.writestr('RealDirt/train/images/def456.jpg', similar.getvalue())
    archive.writestr('RealDirt/train/labels/def456.txt', '0 0.5 0.5 0.4 0.6\n1 0.2 0.2 0.2 0.2')
realdirt.ARCHIVE_MD5 = realdirt.digest(archive_path, 'md5')
realdirt.ARCHIVE_SIZE = archive_path.stat().st_size
publisher = {'id': 12517610, 'metadata': {'doi': '10.5281/zenodo.12517610',
    'license': {'id': 'cc-by-4.0'}, 'access_right': 'open',
    'creators': [{'name': name} for name in realdirt.AUTHORS]},
    'files': [{'key': 'RealDirt.zip', 'checksum': 'md5:' + realdirt.ARCHIVE_MD5, 'size': realdirt.ARCHIVE_SIZE}]}
realdirt.validate_publisher(publisher)
invalid_publisher = copy.deepcopy(publisher)
invalid_publisher['metadata']['license']['id'] = 'cc-by-nc-4.0'
rejected(lambda: realdirt.validate_publisher(invalid_publisher))
publisher_path = root / 'publisher.json'
publisher_path.write_text(json.dumps(publisher))
args = SimpleNamespace(base=root / 'dataset', output=root / 'mixed', archive=archive_path, publisher=publisher_path, limit=2)
summary = realdirt.prepare(args)
assert summary['added_training_images'] == 1
assert summary['license_verified_images'] == 4
assert len(summary['excluded']) == 1 and 'Near-duplicate' in summary['excluded'][0]['reason']
for split in ('validation', 'test'):
    assert json.loads((args.base / split / 'labels.json').read_text()) == json.loads((args.output / split / 'labels.json').read_text())
    for path in (args.base / split / 'data').glob('*.jpg'):
        assert path.read_bytes() == (args.output / split / 'data' / path.name).read_bytes()
data = json.loads((args.output / 'train/labels.json').read_text())
image = data['images'][-1]
proof = realdirt.load_provenance(args.output)
path = args.output / 'train/data' / image['file_name']
assert realdirt.approved_image(image, path, proof, 'train')
assert not realdirt.approved_image(image, path, proof, 'validation')
assert not realdirt.approved_image(image, path, None, 'train')
invalid = copy.deepcopy(image)
invalid['attribution']['source_url'] = 'https://example.invalid'
assert not realdirt.approved_image(invalid, path, proof, 'train')
invalid['source_collection'] = 'unknown'
assert not realdirt.approved_image(invalid, path, proof, 'train')
path.write_bytes(path.read_bytes() + b'tampered')
assert not realdirt.approved_image(image, path, proof, 'train')
try:
    validate_dataset(args.output)
except Exception as error:
    assert 'license' in str(error)
else:
    raise AssertionError('Tampered RealDirt image accepted')
args.output = root / 'corrupt-output'
archive_path.write_bytes(b'bad')
rejected(lambda: realdirt.prepare(args))
assert not args.output.exists()
print('RealDirt import and rejection checks passed')
PYTHON;
        $process = new Process([$python, '-c', $code, resource_path('ml'), $this->directory]);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('RealDirt import and rejection checks passed', $process->getOutput());
    }

    public function test_training_resume_requires_matching_data_and_settings_and_preserves_completed_runs(): void
    {
        $this->trainingFixture();
        $python = (new ExecutableFinder)->find(PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');
        if ($python === null) {
            $this->markTestSkipped('Python is required for training resume tests.');
        }
        $command = PHP_OS_FAMILY === 'Windows' ? [$python, '-3.11'] : [$python];
        $code = <<<'PYTHON'
import json, sys
from pathlib import Path
from types import SimpleNamespace
sys.path.insert(0, sys.argv[1])
from train_waste import (TrainingError, checkpoint_detector, run_configuration, sampling_configuration,
                         apply_training_geometry, training_geometry_configuration,
                         apply_training_pipeline, training_pipeline_configuration,
                         training_hyperparameters, training_objective, validate_output, validate_resume)
root = Path(sys.argv[2]).resolve()
args = SimpleNamespace(dataset=root / 'dataset', epochs=30, batch_size=2)
output = root / 'run'
configuration = run_configuration(args, {'tensorflow': '2.15.1'})
assert configuration['selection']['monitor'] == 'val_coco_AP'
assert configuration['selection']['mode'] == 'max'
assert configuration['schema_version'] == 10
assert configuration['training_pipeline'] == training_pipeline_configuration(args)
assert configuration['training_pipeline']['shuffle'] is False
assert configuration['training_objective'] == training_objective(args)
assert configuration['training_objective']['gamma'] == 1.5
assert configuration['training_objective']['implementation'] == 'pinned_original'
assert configuration['training_geometry'] == training_geometry_configuration(args)
assert configuration['training_geometry']['mode'] == 'native'
assert configuration['training_geometry']['validation_and_test'] == 'unchanged'
assert configuration['training_sampling']['image_repeats'] == {}
assert configuration['training_sampling']['method'] == 'none'
assert configuration['training_sampling']['images_per_epoch'] == 1
assert configuration['evaluate_test'] is False
assert configuration['optimizer']['learning_rate'] == .3
assert configuration['optimizer']['effective_initial_learning_rate'] == .3 * 2 / 256
assert configuration['optimizer']['cosine_decay_alpha'] == 1.0
assert configuration['optimizer']['cosine_decay_epochs'] is None
def rejected(action):
    try:
        action()
    except TrainingError:
        return
    raise AssertionError('Unsafe resume was accepted')
original_labels = (args.dataset / 'train/labels.json').read_bytes()
held_out = {split: (args.dataset / split / 'labels.json').read_bytes() for split in ('validation', 'test')}
args.class_repeat = [('paper', 2), ('metal_can', 3)]
sampling = sampling_configuration(args)
assert sampling['method'] == 'bounded_whole_image_repeat'
assert sampling['unique_images'] == 1 and sampling['images_per_epoch'] == 3
assert sampling['steps_per_epoch'] == 2 and sampling['partial_final_batch']
assert sampling['repeated_image_ids'] == [1]
assert sampling['image_coverage_by_class'] == dict.fromkeys(('plastic_bottle', 'other_plastic', 'paper', 'metal_can'), 1)
assert sampling['annotations_per_epoch_by_class'] == dict.fromkeys(('plastic_bottle', 'other_plastic', 'paper', 'metal_can'), 3)
assert (args.dataset / 'train/labels.json').read_bytes() == original_labels
assert all((args.dataset / split / 'labels.json').read_bytes() == contents for split, contents in held_out.items())
for settings in ([('paper', 0)], [('paper', 4)], [('paper', True)], [('paper', 1.5)],
                 [('background', 2)], [('aerosol', 2)], [('paper', 2), ('paper', 3)]):
    args.class_repeat = settings
    rejected(lambda: sampling_configuration(args))
del args.class_repeat
args.image_repeat = [(1, 2)]
targeted = sampling_configuration(args)
assert targeted['method'] == 'bounded_targeted_image_repeat'
assert targeted['image_repeats'] == {'1':2} and targeted['images_per_epoch'] == 2
assert targeted['steps_per_epoch'] == 1 and not targeted['partial_final_batch']
assert targeted['annotations_per_epoch_by_class'] == dict.fromkeys(('plastic_bottle', 'other_plastic', 'paper', 'metal_can'), 2)
args.class_repeat = [('paper', 3)]
combined = sampling_configuration(args)
assert combined['method'] == 'bounded_class_and_image_repeat'
assert combined['images_per_epoch'] == 3, 'Class and image factors use maximum, not multiplication'
del args.class_repeat
for settings in ([(1, 0)], [(1, 4)], [(1, True)], [(1, 1.5)], [(True, 2)],
                 [(-1, 2)], [(2, 2)], [(3, 2)], [(999, 2)], [(1, 2), (1, 3)]):
    args.image_repeat = settings
    rejected(lambda: sampling_configuration(args))
assert (args.dataset / 'train/labels.json').read_bytes() == original_labels
assert all((args.dataset / split / 'labels.json').read_bytes() == contents for split, contents in held_out.items())
del args.image_repeat
rejected(lambda: validate_output(output, args.dataset, True))
validate_output(output, args.dataset)
output.mkdir()
(output / 'run.json').write_text(json.dumps(configuration))
validate_output(output, args.dataset, True)
validate_resume(output, configuration)
args.image_repeat = [(1, 2)]
rejected(lambda: validate_resume(output, run_configuration(args, {'tensorflow': '2.15.1'})))
del args.image_repeat
args.class_repeat = [('paper', 2)]
rejected(lambda: validate_resume(output, run_configuration(args, {'tensorflow': '2.15.1'})))
del args.class_repeat
for key, value in [('learning_rate', .15), ('cosine_decay_alpha', .1), ('cosine_decay_epochs', 8), ('evaluate_test', True), ('focal_loss_gamma', 1.0), ('training_geometry', 'camera_square'), ('training_pipeline', 'bounded_shuffle'), ('selection_metric', 'primary_ap')]:
    setattr(args, key, value)
    rejected(lambda: validate_resume(output, run_configuration(args, {'tensorflow': '2.15.1'})))
    delattr(args, key)
for key, values in {
    'learning_rate': (0, -1, float('nan'), float('inf'), True),
    'cosine_decay_alpha': (-.1, 1.1, float('nan'), float('inf'), True),
    'cosine_decay_epochs': (0, -1, 1.5, True),
}.items():
    for value in values:
        rejected(lambda: training_hyperparameters(SimpleNamespace(**{key: value})))
assert training_hyperparameters(SimpleNamespace(cosine_decay_alpha=0))['cosine_decay_alpha'] == 0
for value in (0, .5, 3.1, float('nan'), float('inf'), True, '1.0'):
    rejected(lambda: training_objective(SimpleNamespace(focal_loss_gamma=value)))
for gamma in (1.0, 1.5, 3.0):
    assert training_objective(SimpleNamespace(focal_loss_gamma=gamma))['gamma'] == gamma
for mode in (None, True, 256, 'stretch', 'training_pad', [], {}):
    rejected(lambda: training_geometry_configuration(SimpleNamespace(training_geometry=mode)))
geometry = training_geometry_configuration(SimpleNamespace(training_geometry='camera_square'))
for mode in (None, True, 256, 'full_shuffle', [], {}):
    rejected(lambda: training_pipeline_configuration(SimpleNamespace(training_pipeline=mode)))
pipeline = training_pipeline_configuration(SimpleNamespace(training_pipeline='bounded_shuffle'))
assert pipeline['validation_and_test'] == 'unchanged' and pipeline['maximum_buffer_tensor_bytes'] == 128 * 1024 * 1024
for key, value in [('split', 'validation'), ('seed', 7), ('maximum_buffer_records', 1024), ('maximum_buffer_tensor_bytes', 1)]:
    rejected(lambda: apply_training_pipeline(None, None, None, {**pipeline, key: value}))
assert geometry['input_size'] == 256 and geometry['augmentation'] == 'pinned_original'
for key, value in [('split', 'validation'), ('input_size', 320), ('resize', 'nearest'), ('augmentation', False)]:
    rejected(lambda: apply_training_geometry(None, None, None, {**geometry, key: value}))
assert training_hyperparameters(SimpleNamespace(learning_rate=.15, cosine_decay_alpha=.1, cosine_decay_epochs=8)) == {
    'learning_rate': .15, 'cosine_decay_alpha': .1, 'cosine_decay_epochs': 8,
}
rejected(lambda: validate_output(output, args.dataset))
args.batch_size = 4
rejected(lambda: validate_resume(output, run_configuration(args, {'tensorflow': '2.15.1'})))
args.batch_size = 2
args.patience = 6
rejected(lambda: validate_resume(output, run_configuration(args, {'tensorflow': '2.15.1'})))
args.patience = 5
args.min_delta = .001
rejected(lambda: validate_resume(output, run_configuration(args, {'tensorflow': '2.15.1'})))
args.min_delta = 0.0
rejected(lambda: validate_resume(output, run_configuration(args, {'tensorflow': '2.15.0'})))
from unittest.mock import patch
primary_args=SimpleNamespace(dataset=args.dataset,epochs=30,batch_size=2,selection_metric='primary_ap')
primary_output=root/'primary-resume'
primary_output.mkdir()
primary_configuration=run_configuration(primary_args,{'tensorflow':'2.15.1'})
(primary_output/'run.json').write_text(json.dumps(primary_configuration))
validate_resume(primary_output,primary_configuration)
with patch('train_waste.importlib.metadata.version',return_value='changed-backend'):
    rejected(lambda:validate_resume(primary_output,run_configuration(primary_args,{'tensorflow':'2.15.1'})))
original_read=Path.read_bytes
def changed_scorer(path):
    content=original_read(path)
    return content+b'\n# scorer changed' if path.name=='evaluate_waste.py' else content
with patch.object(Path,'read_bytes',changed_scorer):
    rejected(lambda:validate_resume(primary_output,run_configuration(primary_args,{'tensorflow':'2.15.1'})))
validate_resume(primary_output,run_configuration(primary_args,{'tensorflow':'2.15.1'}))
image = args.dataset / 'train/data/1.jpg'
image.write_bytes(image.read_bytes() + b'changed')
rejected(lambda: validate_resume(output, run_configuration(args, {'tensorflow': '2.15.1'})))
(output / 'metrics.json').write_text('{"status":"trained"}')
rejected(lambda: validate_output(output, args.dataset, True))
rejected(lambda: validate_output(args.dataset / 'nested', args.dataset, True))
calls = []
def backup(**kwargs):
    calls.append(('backup', kwargs))
def logger(path, **kwargs):
    calls.append(('logger', path, kwargs))
api = SimpleNamespace(ObjectDetector=object)
tensorflow = SimpleNamespace(keras=SimpleNamespace(callbacks=SimpleNamespace(BackupAndRestore=backup, CSVLogger=logger)))
checkpoint_detector(api, tensorflow, output, True)()
assert calls[0][1] == {'backup_dir': str(output / 'backup'), 'save_freq': 'epoch', 'delete_checkpoint': False}
assert calls[1][2] == {'append': True}
print('Resume safeguards passed')
PYTHON;
        $process = new Process([...$command, '-', resource_path('ml'), $this->directory]);
        $process->setInput($code)->setTimeout(30)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Resume safeguards passed', $process->getOutput());
    }

    public function test_primary_label_proxy_groups_competing_labels_before_limits_without_using_ground_truth(): void
    {
        $python = (new ExecutableFinder)->find(PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');
        if ($python === null) {
            $this->markTestSkipped('Python is required for primary-label validation tests.');
        }
        $command = PHP_OS_FAMILY === 'Windows' ? [$python, '-3.11'] : [$python];
        $code = <<<'PYTHON'
import copy, hashlib, sys
from pathlib import Path
from types import SimpleNamespace as Args
from unittest.mock import patch
sys.path.insert(0,sys.argv[1])
from train_waste import TrainingError, primary_label_predictions, primary_label_recipe, selection_configuration, selection_metric_field

def rejected(action):
    try: action()
    except TrainingError: return
    raise AssertionError('Invalid primary-label contract accepted')

image={'id':7,'width':200,'height':100}
def candidate(category=3,score=.9,box=None):
    return {'image_id':7,'category_id':category,'score':score,'bbox':box if box is not None else [10.2,5.2,20.2,10.2]}
values=[candidate(2,.7),candidate(3,.9),candidate(1,.9)]
original=copy.deepcopy(values)
actual=primary_label_predictions(values,image,[.5,.5])
assert actual==[{'image_id':7,'category_id':3,'score':.9,'bbox':[20.,10.,40.,20.]}]
assert values==original, 'Do not mutate candidates or choose a lower-ranked ground-truth class'
assert primary_label_predictions([candidate(score=.199)],image,[1,1])==[]
assert len(primary_label_predictions([candidate(score=.2)],image,[1,1]))==1
assert primary_label_predictions([candidate(box=[-5,-5,10,10])],image,[1,1])[0]['bbox']==[0,0,5,5]
assert primary_label_predictions([candidate(box=[250,0,10,10])],image,[1,1])==[]
assert primary_label_predictions([candidate(box=[0,0,.1,.1])],image,[1,1])==[]
clipped=primary_label_predictions([candidate(1,.8,[-1,0,11,10]),candidate(2,.7,[-2,0,12,10])],image,[1,1])
assert len(clipped)==2 and clipped[0]['bbox']==clipped[1]['bbox'], 'Group before clipping'
groups=[candidate(1,.8-i*.01,[i*5,0,4,10]) for i in range(11)]
assert len(primary_label_predictions(groups,image,[1,1]))==10
outside=[candidate(1,.9+i*.001,[250+i*5,0,4,10]) for i in range(10)]
assert primary_label_predictions(outside+[candidate(2,.3)],image,[1,1])==[], 'Limit before projection, as the runtime does'
for change in ({'category_id':0},{'category_id':True},{'score':True},{'score':float('nan')},{'score':1.1},
               {'bbox':[0,0,float('inf'),1]},{'bbox':[1,2,3]},{'image_id':8}):
    rejected(lambda:primary_label_predictions([{**candidate(),**change}],image,[1,1]))
for scales in ([0,1],[float('nan'),1],[True,1],[1]):
    rejected(lambda:primary_label_predictions([],image,scales))
for changed in ({**image,'id':True},{**image,'width':0},{**image,'height':False}):
    rejected(lambda:primary_label_predictions([],changed,[1,1]))
policy=selection_configuration(Args(selection_metric='primary_ap'))
assert policy['primary_label']==primary_label_recipe()
assert policy['primary_label']['scorer_sha256']==hashlib.sha256((Path(sys.argv[1])/'evaluate_waste.py').read_bytes()).hexdigest()
with patch('train_waste.importlib.metadata.version',return_value='changed-backend'):
    rejected(lambda:selection_metric_field(policy))
with patch.object(Path,'read_bytes',return_value=b'changed-scorer'):
    rejected(lambda:selection_metric_field(policy))
assert selection_metric_field(policy)=='best_primary_validation_ap'
assert selection_configuration(Args())['monitor']=='val_coco_AP'
for mode in (None,True,'primary','ap50',[]):
    rejected(lambda:selection_configuration(Args(selection_metric=mode)))
for change in ({'score_threshold':.3},{'max_results':20},{'split':'test'},{'tie_break':'ground_truth'}):
    rejected(lambda:selection_metric_field({**policy,'primary_label':{**policy['primary_label'],**change}}))
print('Primary-label proxy grouping and validation safeguards passed')
PYTHON;
        $process = new Process([...$command, '-', resource_path('ml')]);
        $process->setInput($code)->setTimeout(30)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Primary-label proxy grouping and validation safeguards passed', $process->getOutput());
    }

    public function test_validation_checkpoint_selection_restores_best_weights_and_preserves_patience_on_resume(): void
    {
        $python = (new ExecutableFinder)->find(PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');
        if ($python === null) {
            $this->markTestSkipped('Python is required for checkpoint selection tests.');
        }
        $command = PHP_OS_FAMILY === 'Windows' ? [$python, '-3.11'] : [$python];
        $code = <<<'PYTHON'
import argparse, csv, json, math, sys
from pathlib import Path
from types import SimpleNamespace as Value
sys.path.insert(0, sys.argv[1])
from train_waste import (TrainingError, checkpoint_hashes, nonnegative_float, read_selection,
                         recover_history, run_configuration, selection_callback, validate_resume,
                         checkpoint_detector, validation_ap_callback, selection_configuration)
root = Path(sys.argv[2]).resolve()
output = root / 'selection-run'
output.mkdir()
class EarlyStopping:
    def __init__(self, monitor, mode, patience, min_delta, **kwargs):
        self.monitor, self.patience, self.min_delta, self.mode = monitor, patience, min_delta, mode
    def on_train_begin(self, logs=None):
        self.best, self.best_epoch, self.wait, self.stopped_epoch = math.inf if self.mode == 'min' else -math.inf, 0, 0, 0
    def on_epoch_end(self, epoch, logs=None):
        self.wait += 1
        improved = (logs[self.monitor] < self.best - self.min_delta if self.mode == 'min'
                    else logs[self.monitor] > self.best + self.min_delta)
        if improved:
            self.best, self.best_epoch, self.wait = logs[self.monitor], epoch, 0
        elif self.wait >= self.patience and epoch > 0:
            self.stopped_epoch, self.model.stop_training = epoch, True
    def on_train_end(self, logs=None):
        pass
class Model:
    def __init__(self):
        self.stop_training, self.weight, self.loaded = False, 0, []
    def save_weights(self, prefix):
        Path(prefix + '.index').write_bytes(b'index')
        Path(prefix + '.data-00000-of-00001').write_text(str(self.weight))
    def load_weights(self, prefix):
        self.weight = int(Path(prefix + '.data-00000-of-00001').read_text())
        self.loaded.append(prefix)
        return Value(assert_existing_objects_matched=lambda: None, expect_partial=lambda: None)
tf = Value(keras=Value(callbacks=Value(EarlyStopping=EarlyStopping)))
policy = {'monitor': 'val_total_loss', 'mode': 'min', 'patience': 2, 'min_delta': 0.0}
callback = selection_callback(tf, output, policy)
callback.model = Model()
callback.on_train_begin()
for epoch, loss in enumerate([1.0, .8, .9]):
    callback.model.weight = epoch + 1
    callback.on_epoch_end(epoch, {'val_total_loss': loss, 'total_loss': 1/(epoch+1)})
assert callback.state['best_epoch'] == 2 and callback.state['wait'] == 1
assert not callback.model.stop_training
callback.on_train_end()
assert callback.model.weight == 2, 'Restore best even if training reaches its epoch limit without early stopping'
assert callback.state['completed_epochs'] == 3
state = read_selection(output, 3, policy)
legacy = {key: value for key, value in state.items() if key != 'best_metric_value'}
legacy['schema_version'] = 1
snapshot = output / 'selection/state-00003.json'
snapshot.write_text(json.dumps(legacy))
assert read_selection(output, 3, policy)['best_validation_loss'] == .8
snapshot.write_text(json.dumps(state))
ahead = {**state, 'completed_epochs': 4, 'wait': 999}
(output / 'selection/state-00004.json').write_text(json.dumps(ahead))
(output / 'history.csv').write_text('epoch,total_loss,val_total_loss\n0,1,1\n1,.5,.8\n2,.3,.9\n3,.2,2\n')
previous = (output / 'history.csv').read_bytes()
recover_history(output, 3, policy)
with (output / 'history.csv').open(newline='') as source:
    rows = list(csv.DictReader(source))
assert [row['epoch'] for row in rows] == ['0', '1', '2']
preserved = list(output.glob('history-before-resume-*.csv'))
assert len(preserved) == 1 and preserved[0].read_bytes() == previous
callback = selection_callback(tf, output, policy, read_selection(output, 3, policy))
callback.model = Model()
callback.on_train_begin()
assert callback.wait == 1 and callback.best == .8
callback.model.weight = 4
callback.on_epoch_end(3, {'val_total_loss': 1.0, 'total_loss': .25})
assert callback.model.stop_training and callback.state['stopped_early']
assert callback.state['wait'] == 2, 'Resume must not reset patience or use an uncommitted later snapshot'
callback.on_train_end()
assert callback.model.weight == 2
# A finalized early-stopping backup can restore its best weights without another epoch.
completed = selection_callback(tf, output, policy, read_selection(output, 4, policy))
completed.model = Model()
completed.on_train_begin()
completed.on_train_end()
assert completed.model.weight == 2
def rejected(action):
    try:
        action()
    except (TrainingError, argparse.ArgumentTypeError):
        return
    raise AssertionError('Unsafe checkpoint selection was accepted')
rejected(lambda: read_selection(output, 4, {**policy, 'patience': 9}))
for value in ['nan', 'inf', '-0.1']:
    rejected(lambda: nonnegative_float(value))
assert nonnegative_float('0') == 0
prefix = output / 'selection/weights-00002'
data = Path(str(prefix) + '.data-00000-of-00001')
data.write_text('tampered')
rejected(lambda: read_selection(output, 4, policy))
data.unlink()
Path(str(prefix) + '.data-00000-of-00002').write_text('partial')
rejected(lambda: checkpoint_hashes(output, 2))
for loss in [float('nan'), float('inf'), -1, None]:
    invalid = selection_callback(tf, output, policy)
    invalid.model = Model()
    invalid.on_train_begin()
    rejected(lambda: invalid.on_epoch_end(0, {'val_total_loss': loss}))
first = json.loads((output / 'selection/state-00001.json').read_text())
first['logs']['val_total_loss'] = None
(output / 'selection/state-00001.json').write_text(json.dumps(first))
before = (output / 'history.csv').read_bytes()
rejected(lambda: recover_history(output, 3, policy))
assert (output / 'history.csv').read_bytes() == before

ap_output = root / 'ap-selection-run'
ap_output.mkdir()
ap_policy = {'monitor': 'val_coco_AP', 'mode': 'max', 'patience': 2, 'min_delta': .01}
ap = selection_callback(tf, ap_output, ap_policy)
ap.model = Model()
ap.on_train_begin()
for epoch, score in enumerate([0.0, .2, .205]):
    ap.model.weight = epoch + 1
    ap.on_epoch_end(epoch, {'val_coco_AP': score, 'val_total_loss': epoch + 1.0})
assert ap.state['best_epoch'] == 2 and ap.state['best_validation_ap'] == .2
assert ap.state['best_metric_value'] == .2 and ap.state['wait'] == 1
assert 'best_validation_loss' not in ap.state, 'Do not mislabel AP as loss'
recover_history(ap_output, 3, ap_policy)
ap = selection_callback(tf, ap_output, ap_policy, read_selection(ap_output, 3, ap_policy))
ap.model = Model()
ap.on_train_begin()
ap.model.weight = 4
ap.on_epoch_end(3, {'val_coco_AP': .2, 'val_total_loss': .01})
assert ap.model.stop_training and ap.state['wait'] == 2, 'Loss improvements and tied AP must not reset patience'
ap.on_train_end()
assert ap.model.weight == 2, 'Restore highest qualifying AP, even when its validation loss is worse'
for score in [float('nan'), float('inf'), -1, 1.01, None, True]:
    invalid = selection_callback(tf, ap_output, ap_policy)
    invalid.model = Model()
    invalid.on_train_begin()
    rejected(lambda: invalid.on_epoch_end(0, {'val_coco_AP': score}))
state = json.loads((ap_output / 'selection/state-00004.json').read_text())
state['best_validation_ap'] = state['best_metric_value'] = 1.1
(ap_output / 'selection/state-00004.json').write_text(json.dumps(state))
rejected(lambda: read_selection(ap_output, 4, ap_policy))

primary_output = root / 'primary-selection-run'
primary_output.mkdir()
primary_policy = selection_configuration(Value(selection_metric='primary_ap',patience=2,min_delta=.01))
primary = selection_callback(tf,primary_output,primary_policy)
primary.model = Model()
primary.on_train_begin()
for epoch,score in enumerate([.1,.3,.2]):
    primary.model.weight=epoch+1
    primary.on_epoch_end(epoch,{'val_primary_AP':score,'val_primary_AP50':score,'val_coco_AP':.4+epoch*.1})
assert primary.state['best_epoch']==2 and primary.state['best_primary_validation_ap']==.3
assert 'best_validation_ap' not in primary.state and primary.state['wait']==1
recover_history(primary_output,3,primary_policy)
primary = selection_callback(tf,primary_output,primary_policy,read_selection(primary_output,3,primary_policy))
primary.model=Model()
primary.on_train_begin()
primary.on_epoch_end(3,{'val_primary_AP':.3,'val_primary_AP50':.4,'val_coco_AP':.9})
assert primary.model.stop_training and primary.state['wait']==2
primary.on_train_end()
assert primary.model.weight==2, 'Better native AP must not override the primary-label checkpoint'
for value in (None,True,-.1,1.1,float('nan')):
    rejected(lambda: primary.on_epoch_end(4,{'val_primary_AP':value}))
rejected(lambda: read_selection(primary_output,4,ap_policy))

tf.keras.callbacks.Callback = object
tf.keras.callbacks.BackupAndRestore = lambda **kwargs: 'backup'
tf.keras.callbacks.CSVLogger = lambda *args, **kwargs: 'csv'
validation = object()
calls = []
class Detector:
    def evaluate(self, dataset, batch_size):
        assert dataset is validation, 'Only validation data may influence checkpoint selection'
        calls.append(batch_size)
        return [123], {'AP': .3}
detector = checkpoint_detector(Value(ObjectDetector=Detector), tf, ap_output, False, ap_policy,
                               validation_data=validation, batch_size=2)()
logs = {'val_total_loss': 9.0}
detector._callbacks[0].on_epoch_end(0, logs)
assert logs == {'val_total_loss': 9.0, 'val_coco_AP': .3} and calls == [2]
assert detector._callbacks[1] == 'csv' and detector._callbacks[-1] == 'backup'
assert detector._callbacks[2] is detector._selection
rejected(lambda: checkpoint_detector(Value(ObjectDetector=Detector), tf, ap_output, False, ap_policy)())
rejected(lambda: detector._callbacks[0].on_epoch_end(0, None))
for metrics in [{}, [], {'AP': None}, {'AP': float('nan')}, {'AP': -1}, {'AP': 1.1}]:
    detector.evaluate = lambda *args, **kwargs: ([], metrics)
    unchanged = {'val_total_loss': .1}
    rejected(lambda: detector._callbacks[0].on_epoch_end(0, unchanged))
    assert unchanged == {'val_total_loss': .1}, 'An invalid evaluation must not enter epoch logs'
from unittest.mock import patch
with patch('train_waste.primary_validation_callback',return_value='primary') as primary_factory:
    primary_detector=checkpoint_detector(Value(ObjectDetector=Detector),tf,primary_output,False,primary_policy,
        validation_data=validation,batch_size=2,validation_labels={'fixture':True})()
    primary_factory.assert_called_once_with(tf,primary_detector,validation,2,{'fixture':True},primary_policy,primary_output)
    assert primary_detector._callbacks[1:3]==['primary','csv']
    assert primary_detector._callbacks[3] is primary_detector._selection and primary_detector._callbacks[-1]=='backup'
print('Persistent validation selection checks passed')
PYTHON;
        $process = new Process([...$command, '-', resource_path('ml'), $this->directory]);
        $process->setInput($code)->setTimeout(30)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Persistent validation selection checks passed', $process->getOutput());
    }

    public function test_checkpoint_selection_with_real_pinned_keras_survives_an_interrupted_fit(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('This optional integration test targets the configured RobotSys WSL training environment.');
        }
        $wsl = (new ExecutableFinder)->find('wsl');
        if ($wsl === null) {
            $this->markTestSkipped('WSL is required for the pinned Keras integration test.');
        }
        $python = '/home/robotsys/waste-training/.venv/bin/python';
        $launcher = [$wsl, '-d', 'RobotSys-Ubuntu', '-u', 'robotsys', '--exec'];
        $probe = new Process([...$launcher, '/usr/bin/test', '-x', $python]);
        $probe->setTimeout(60)->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('The configured RobotSys pinned Linux Python environment is unavailable.');
        }
        $this->trainingFixture();
        $source = str_replace('\\', '/', resource_path('ml'));
        $source = '/mnt/'.strtolower($source[0]).substr($source, 2);
        $fixture = str_replace('\\', '/', $this->directory.'/dataset');
        $fixture = '/mnt/'.strtolower($fixture[0]).substr($fixture, 2);
        $code = <<<'PYTHON'
import csv, sys, tempfile
from pathlib import Path
from types import SimpleNamespace as Value
import numpy as np
import tensorflow as tf
assert tf.__version__ == '2.15.1', 'Use the pinned training environment'
sys.path.insert(0, sys.argv[1])
from train_waste import checkpoint_detector, read_selection, recover_history
tf.keras.utils.set_random_seed(42)
policy = {'monitor': 'val_total_loss', 'mode': 'min', 'patience': 2, 'min_delta': 0.0}
def model():
    result = tf.keras.Sequential([tf.keras.layers.Dense(1, input_shape=(1,))])
    result.compile(optimizer=tf.keras.optimizers.SGD(.01), loss='mse')
    return result
class ControlledLoss(tf.keras.callbacks.Callback):
    def on_epoch_end(self, epoch, logs=None):
        logs['val_total_loss'] = [1.0, .8, .9, 1.0][epoch]
        logs['total_loss'] = float(logs['loss'])
class Interruption(tf.keras.callbacks.Callback):
    def on_epoch_end(self, epoch, logs=None):
        if epoch == 1:
            raise RuntimeError('Simulated interruption after committed backup')
x = np.arange(8, dtype=np.float32).reshape(-1, 1) / 8
with tempfile.TemporaryDirectory(prefix='selection-integration-') as directory:
    output = Path(directory).resolve()
    detector = checkpoint_detector(Value(ObjectDetector=object), tf, output, False, policy)()
    original = model()
    try:
        original.fit(x, x, validation_data=(x, x), epochs=4, batch_size=4, verbose=0,
                     callbacks=[ControlledLoss(), *detector._callbacks, Interruption()])
    except RuntimeError as error:
        assert 'Simulated interruption' in str(error)
    else:
        raise AssertionError('Training did not simulate interruption')
    best_weights = original.get_weights()
    backup = tf.train.latest_checkpoint(str(output / 'backup/chief'))
    completed = int(tf.train.load_variable(backup, 'ckpt_saved_epoch/.ATTRIBUTES/VARIABLE_VALUE')) + 1
    assert completed == 2
    state = read_selection(output, completed, policy)
    recover_history(output, completed, policy)
    resumed_detector = checkpoint_detector(Value(ObjectDetector=object), tf, output, True, policy, state)()
    resumed = model()
    resumed.fit(x, x, validation_data=(x, x), epochs=4, batch_size=4, verbose=0,
                callbacks=[ControlledLoss(), *resumed_detector._callbacks])
    selection = resumed_detector.training_selection
    assert selection['best_epoch'] == 2 and selection['completed_epochs'] == 4
    assert selection['stopped_early'] and selection['wait'] == 2
    for actual, expected in zip(resumed.get_weights(), best_weights):
        np.testing.assert_array_equal(actual, expected)
    with (output / 'history.csv').open(newline='') as source:
        assert [row['epoch'] for row in csv.DictReader(source)] == ['0', '1', '2', '3']
    # Finalization after an interrupted export must not require another training epoch.
    final = checkpoint_detector(Value(ObjectDetector=object), tf, output, True, policy,
                                read_selection(output, 4, policy))()
    finalized = model()
    finalized.fit(x, x, validation_data=(x, x), epochs=4, batch_size=4, verbose=0,
                  callbacks=[ControlledLoss(), *final._callbacks])
    assert final.training_selection['completed_epochs'] == 4
    for actual, expected in zip(finalized.get_weights(), best_weights):
        np.testing.assert_array_equal(actual, expected)

class ValidationDetector:
    def evaluate(self, dataset, batch_size):
        assert dataset is validation, 'Only validation data is evaluated during fit'
        self._model.evaluate(dataset[0], dataset[1], batch_size=batch_size, verbose=0)
        score = [.1, .3, .2, .1][self.evaluations]
        self.evaluations += 1
        return [], {'AP': np.float32(score)}
class ControlledAPLoss(tf.keras.callbacks.Callback):
    def on_epoch_end(self, epoch, logs=None):
        logs['val_total_loss'] = [.1, .2, .3, .01][epoch]
validation = (x, x)
policy = {'monitor': 'val_coco_AP', 'mode': 'max', 'patience': 2, 'min_delta': 0.0}
with tempfile.TemporaryDirectory(prefix='ap-selection-integration-') as directory:
    output = Path(directory).resolve()
    detector = checkpoint_detector(Value(ObjectDetector=ValidationDetector), tf, output, False, policy,
                                   validation_data=validation, batch_size=4)()
    detector._model, detector.evaluations = model(), 0
    try:
        detector._model.fit(x, x, validation_data=validation, epochs=4, batch_size=4, verbose=0,
                            callbacks=[ControlledAPLoss(), *detector._callbacks, Interruption()])
    except RuntimeError as error:
        assert 'Simulated interruption' in str(error)
    else:
        raise AssertionError('AP training did not simulate interruption')
    best_weights = detector._model.get_weights()
    state = read_selection(output, 2, policy)
    recover_history(output, 2, policy)
    resumed = checkpoint_detector(Value(ObjectDetector=ValidationDetector), tf, output, True, policy, state,
                                  validation_data=validation, batch_size=4)()
    resumed._model, resumed.evaluations = model(), 2
    resumed._model.fit(x, x, validation_data=validation, epochs=4, batch_size=4, verbose=0,
                      callbacks=[ControlledAPLoss(), *resumed._callbacks])
    selected = resumed.training_selection
    assert selected['completed_epochs'] == 4 and selected['best_epoch'] == 2
    assert selected['stopped_early'] and selected['wait'] == 2
    np.testing.assert_allclose(selected['best_validation_ap'], .3)
    for actual, expected in zip(resumed._model.get_weights(), best_weights):
        np.testing.assert_array_equal(actual, expected)
    with (output / 'history.csv').open(newline='') as source:
        rows = list(csv.DictReader(source))
    assert [row['epoch'] for row in rows] == ['0', '1', '2', '3']
    np.testing.assert_allclose([float(row['val_coco_AP']) for row in rows], [.1, .3, .2, .1])
    assert [float(row['val_total_loss']) for row in rows] == [.1, .2, .3, .01]

# Exercise the actual pinned detector, optimizer, COCO evaluator, and fit callback order.
import json
from dataclasses import replace
from unittest.mock import patch
from mediapipe_model_maker import object_detector as api
from mediapipe_model_maker.python.vision.object_detector import model as model_lib
from mediapipe_model_maker.python.vision.object_detector import model_options
from train_waste import (CLASSES, TrainingError, apply_training_geometry, apply_training_sampling,
                         focal_loss_model, training_geometry_configuration, training_hyperparameters, training_objective,
                         selection_configuration, primary_validation_callback)
from mediapipe_model_maker.python.vision.object_detector import preprocessor as preprocessor_lib
assert focal_loss_model(tf, training_objective(Value())) is model_lib.ObjectDetectorModel
# Compare the scoped objective and gradients with an independent sigmoid focal formula.
for gamma in (1.0, 2.0, 3.0):
    objective = training_objective(Value(focal_loss_gamma=gamma))
    model_class = focal_loss_model(tf, objective)
    for regularization in ([], [tf.constant(.125), tf.constant(.25)]):
        trackers = [tf.keras.metrics.Mean(name=name) for name in ('total_loss','cls_loss','box_loss','model_loss')]
        holder = Value(_num_classes=5, _model=Value(losses=regularization), metrics=trackers)
        logits = tf.Variable([[[[.2,-.4,.7,-.8,.1],[.5,.2,-.3,.1,-.5],[.1,.6,-.2,.3,-.7]]]])
        boxes = tf.Variable(tf.ones([1,1,3,4]) * .2)
        labels = {'cls_targets':{'3':tf.constant([[[1,-1,4]]])},
                  'box_targets':{'3':tf.zeros([1,1,3,4])},
                  'cls_weights':tf.constant([[1.,0.,1.]]), 'box_weights':tf.constant([[1.,0.,1.]])}
        with tf.GradientTape() as actual_tape:
            actual = model_class.compute_loss(holder, y=labels, y_pred={'cls_outputs':{'3':logits},'box_outputs':{'3':boxes}})
        actual_gradients = actual_tape.gradient(actual,[logits,boxes])
        with tf.GradientTape() as reference_tape:
            targets = tf.one_hot(tf.constant([[1,-1,4]]),5)
            flat_logits = tf.reshape(logits,[1,3,5])
            probabilities = tf.sigmoid(flat_logits)
            correct_probability = targets*probabilities+(1-targets)*(1-probabilities)
            alpha = targets*.25+(1-targets)*.75
            cls = tf.reduce_sum(tf.nn.sigmoid_cross_entropy_with_logits(labels=targets,logits=flat_logits)
                                * alpha * tf.pow(1-correct_probability,gamma) * (labels['cls_weights']/3.)[:,:,None])
            box = tf.reduce_sum(tf.keras.losses.huber(tf.zeros([1,3,4]),tf.reshape(boxes,[1,3,4]),delta=.1)
                                * labels['box_weights']/3.)
            expected = cls + 50*box + sum(regularization)
        np.testing.assert_allclose(actual.numpy(),expected.numpy(),rtol=1e-6)
        for actual_gradient, expected_gradient in zip(actual_gradients,reference_tape.gradient(expected,[logits,boxes])):
            assert np.isfinite(actual_gradient.numpy()).all()
            np.testing.assert_allclose(actual_gradient.numpy(),expected_gradient.numpy(),rtol=1e-5,atol=1e-7)
        for tracker, expected_metric in zip(trackers,(expected,cls,box,cls+50*box)):
            np.testing.assert_allclose(tracker.result().numpy(),expected_metric.numpy(),rtol=1e-6)
try:
    focal_loss_model(tf,{**training_objective(Value(focal_loss_gamma=1.0)),'alpha':.5})
except TrainingError:
    pass
else:
    raise AssertionError('Uncontrolled objective change accepted')
for alpha in (1.0, .1):
    settings = training_hyperparameters(Value(cosine_decay_alpha=alpha, cosine_decay_epochs=8))
    hparams = api.HParams(epochs=30, batch_size=2, **settings)
    optimizer = api.ObjectDetector._create_optimizer(Value(_hparams=hparams), steps_per_epoch=10)
    schedule = optimizer._learning_rate
    assert float(schedule(tf.constant(0))) == 0
    assert np.isclose(float(schedule(tf.constant(29))), .3 * 2 / 256 * 29 / 30)
    assert np.isclose(float(schedule(tf.constant(30))), .3 * 2 / 256 * (alpha + (1 - alpha) * .5 * (1 + np.cos(np.pi * 30 / 80))))
    assert np.isclose(float(schedule(tf.constant(80))), .3 * 2 / 256 * alpha)
    assert float(schedule(tf.constant(70))) <= float(schedule(tf.constant(30)))
geometry = training_geometry_configuration(Value(training_geometry='camera_square'))
preprocessor = preprocessor_lib.Preprocessor(api.SupportedModels.get(api.SupportedModels.MOBILENET_V2))
for height, width in ((32, 64), (64, 32), (48, 48)):
    pixels = np.arange(height * width * 3, dtype=np.uint8).reshape(height, width, 3)
    record = {
        'image':tf.constant(pixels), 'height':tf.constant(height, tf.int64), 'width':tf.constant(width, tf.int64),
        'source_id':tf.constant('123'), 'groundtruth_boxes':tf.constant([[0.,0.,1.,1.],[.25,.125,.75,.875]]),
        'groundtruth_classes':tf.constant([3,4],tf.int64), 'groundtruth_is_crowd':tf.constant([False,False]),
        'groundtruth_area':tf.constant([height*width*.9, height*width*.3]),
    }
    original = api.Dataset(dataset=tf.data.Dataset.from_tensors(record), label_names=list(CLASSES.values()), size=1)
    assert apply_training_geometry(api,tf,original,training_geometry_configuration(Value())) is original
    resized = apply_training_geometry(api,tf,original,geometry)
    assert resized.size == original.size == 1 and list(resized.label_names) == list(original.label_names)
    actual = next(resized.gen_tf_dataset(batch_size=1).unbatch().as_numpy_iterator())
    assert set(actual) == set(record) and actual['image'].dtype == np.uint8
    assert actual['image'].shape == (256,256,3) and actual['height'] == actual['width'] == 256
    assert actual['source_id'] == b'123'
    for key in ('groundtruth_boxes','groundtruth_classes','groundtruth_is_crowd'):
        np.testing.assert_array_equal(actual[key],record[key].numpy())
    np.testing.assert_allclose(actual['groundtruth_area'],[256*256*.9,256*256*.3],rtol=1e-6)
    expected_pixels = np.clip(np.rint(tf.image.resize(pixels,[256,256]).numpy()),0,255).astype(np.uint8)
    np.testing.assert_array_equal(actual['image'],expected_pixels)
    official_pixels, official_labels = preprocessor({key:tf.constant(value) for key,value in actual.items()},is_training=False)
    np.testing.assert_allclose(official_pixels.numpy(),expected_pixels.astype(np.float32)/127.5-1,atol=2e-7)
    assert float(tf.reduce_min(official_pixels)) >= -1 and float(tf.reduce_max(official_pixels)) <= 1
    np.testing.assert_allclose(official_labels['groundtruths']['boxes'][:2].numpy(),[[0,0,256,256],[64,32,192,224]])
    np.testing.assert_allclose(official_labels['image_info'][2].numpy(),[1,1])
    # Source records stay rectangular; repeated occurrences retain all labels and identities.
    untouched = next(original.gen_tf_dataset(batch_size=1).unbatch().as_numpy_iterator())
    np.testing.assert_array_equal(untouched['image'],pixels)
    assert untouched['height'] == height and untouched['width'] == width
    repeated = apply_training_sampling(api,tf,resized,{
        'method':'bounded_targeted_image_repeat','unique_images':1,'images_per_epoch':2,
        'class_repeats':dict.fromkeys(tuple(CLASSES.values())[1:],1),'image_repeats':{'123':2},
    })
    assert repeated.size == 2
    for occurrence in repeated.gen_tf_dataset(batch_size=1).unbatch().as_numpy_iterator():
        for key in actual:
            np.testing.assert_array_equal(occurrence[key],actual[key])
invalid_record = {**record,'image':tf.cast(record['image'],tf.float32)}
try:
    apply_training_geometry(api,tf,api.Dataset(dataset=tf.data.Dataset.from_tensors(invalid_record),
        label_names=list(CLASSES.values()),size=1),geometry)
except TrainingError:
    pass
else:
    raise AssertionError('Unscaled float pixels accepted before official normalization')
invalid_record = {**record,'width':tf.constant(width+1,tf.int64)}
try:
    invalid = apply_training_geometry(api,tf,api.Dataset(dataset=tf.data.Dataset.from_tensors(invalid_record),
        label_names=list(CLASSES.values()),size=1),geometry)
    list(invalid.gen_tf_dataset(batch_size=1).unbatch().as_numpy_iterator())
except tf.errors.InvalidArgumentError:
    pass
else:
    raise AssertionError('Inconsistent source geometry accepted')
print('Pinned training camera geometry integration passed')
with tempfile.TemporaryDirectory(prefix='model-maker-ap-integration-') as directory:
    root = Path(directory)
    images = root / 'images'
    images.mkdir()
    labels = {'images': [], 'annotations': [], 'categories': [
        {'id': key, 'name': value} for key, value in CLASSES.items()
    ]}
    for image_id in range(1, 5):
        pixels = np.full((64, 96, 3), image_id * 40, dtype=np.uint8)
        (images / f'{image_id}.jpg').write_bytes(tf.io.encode_jpeg(pixels).numpy())
        labels['images'].append({'id': image_id, 'file_name': f'{image_id}.jpg', 'width': 96, 'height': 64})
        labels['annotations'].append({'id': image_id, 'image_id': image_id, 'category_id': image_id,
                                      'bbox': [8, 8, 32, 32], 'area': 1024, 'iscrowd': 0})
    (root / 'labels.json').write_text(json.dumps(labels))
    validation = api.Dataset.from_coco_folder(str(root), cache_dir=str(root / 'cache'))
    sampling = {
        'method': 'bounded_whole_image_repeat', 'unique_images': 4, 'images_per_epoch': 7,
        'class_repeats': {'plastic_bottle': 1, 'other_plastic': 1, 'paper': 2, 'metal_can': 3},
    }
    assert apply_training_sampling(api, tf, validation, {'method': 'none'}) is validation
    balanced = apply_training_sampling(api, tf, validation, sampling)
    assert balanced.size == 7 and validation.size == 4
    original_records = list(validation.gen_tf_dataset(batch_size=1).unbatch().as_numpy_iterator())
    balanced_records = list(balanced.gen_tf_dataset(batch_size=1).unbatch().as_numpy_iterator())
    assert [int(record['groundtruth_classes'][0]) for record in balanced_records] == [1, 2, 3, 3, 4, 4, 4]
    for actual, original in zip(balanced_records, [original_records[i] for i in (0, 1, 2, 2, 3, 3, 3)]):
        assert set(actual) == set(original)
        for key in original:
            np.testing.assert_array_equal(actual[key], original[key])
    assert [record['source_id'] for record in original_records] == [b'1', b'2', b'3', b'4']
    targeted = apply_training_sampling(api, tf, validation, {
        **sampling, 'method':'bounded_class_and_image_repeat', 'images_per_epoch':9, 'image_repeats':{'2':3, '3':2},
    })
    targeted_records = list(targeted.gen_tf_dataset(batch_size=1).unbatch().as_numpy_iterator())
    expected_indices = (0,1,1,1,2,2,3,3,3)
    assert targeted.size == 9 and len(targeted_records) == 9
    for actual, original in zip(targeted_records, [original_records[i] for i in expected_indices]):
        for key in original:
            np.testing.assert_array_equal(actual[key], original[key])
    selected_only = apply_training_sampling(api, tf, validation, {
        **sampling, 'method':'bounded_targeted_image_repeat', 'images_per_epoch':5,
        'class_repeats':dict.fromkeys(CLASSES.values(),1), 'image_repeats':{'2':2},
    })
    assert [record['source_id'] for record in selected_only.gen_tf_dataset(batch_size=1).unbatch().as_numpy_iterator()] == [b'1',b'2',b'2',b'3',b'4']
    # A co-occurring plastic label stays on both repeats; no label or box is discarded.
    mixed = dict(original_records[2])
    mixed['groundtruth_classes'] = tf.constant([3, 2], dtype=tf.int64)
    mixed['groundtruth_boxes'] = tf.constant([[.1, .1, .5, .5], [.2, .2, .8, .8]])
    mixed['groundtruth_area'] = tf.constant([655.36, 1474.56])
    mixed['groundtruth_is_crowd'] = tf.constant([False, False])
    mixed_dataset = api.Dataset(dataset=tf.data.Dataset.from_tensors(mixed), label_names=list(CLASSES.values()), size=1)
    mixed_balanced = apply_training_sampling(api, tf, mixed_dataset, {**sampling, 'unique_images': 1, 'images_per_epoch': 2})
    assert mixed_balanced.size == 2
    for record in mixed_balanced.gen_tf_dataset(batch_size=1).unbatch().as_numpy_iterator():
        np.testing.assert_array_equal(record['groundtruth_classes'], [3, 2])
        np.testing.assert_array_equal(record['groundtruth_boxes'], mixed['groundtruth_boxes'].numpy())
    invalid = apply_training_sampling(api, tf, validation, {**sampling, 'images_per_epoch': 8})
    try:
        list(invalid.gen_tf_dataset(batch_size=1).unbatch().as_numpy_iterator())
    except tf.errors.FailedPreconditionError:
        pass
    else:
        raise AssertionError('Incorrect repeated cardinality accepted')
    output = root / 'run'
    output.mkdir()
    spec = replace(api.SupportedModels.get(api.SupportedModels.MOBILENET_V2),
                   downloaded_files=Value(get_path=lambda: str(root)))
    policy = selection_configuration(Value(selection_metric='primary_ap',patience=2))
    detector = checkpoint_detector(api, tf, output, False, policy,
                                   validation_data=validation, batch_size=2,
                                   validation_labels=labels,
                                   objective=training_objective(Value(focal_loss_gamma=1.0)))(
        spec, list(CLASSES.values()), api.HParams(epochs=2, batch_size=2, steps_per_epoch=5, export_dir=str(output)),
        model_options.ObjectDetectorModelOptions(),
    )
    # Random initialization keeps this integration test offline; no trained assets are modified.
    def initialize(self, *args, **kwargs):
        self._model(tf.zeros([1] + self._model_spec.input_image_shape), training=False)
    with patch.object(model_lib.ObjectDetectorModel, 'load_checkpoint', initialize):
        detector._create_and_train_model(apply_training_geometry(api,tf,targeted,geometry), validation)
    assert type(detector._model).__name__ == 'FocalLossObjectDetectorModel'
    selection = detector.training_selection
    assert selection['completed_epochs'] == 2
    assert 0 <= selection['best_primary_validation_ap'] <= 1
    assert 'best_validation_ap' not in selection
    with (output / 'history.csv').open(newline='') as source:
        rows = list(csv.DictReader(source))
    assert len(rows) == 2 and all(0 <= float(row['val_coco_AP']) <= 1 for row in rows)
    assert all(0 <= float(row['val_primary_AP']) <= 1 and 0 <= float(row['val_primary_AP50']) <= 1 for row in rows)
    for epoch, row in enumerate(rows, 1):
        state = read_selection(output, epoch, policy)
        assert state['logs']['val_coco_AP'] == float(row['val_coco_AP'])
        assert state['logs']['val_primary_AP'] == float(row['val_primary_AP'])
        journal=json.loads((output/'primary-validation'/f'epoch-{epoch:05d}.json').read_text())
        assert journal['image_ids']==[1,2,3,4] and journal['recipe']==policy['primary_label']
        assert journal['metrics']['AP']==float(row['val_primary_AP'])
    def rejected_primary(action):
        try: action()
        except TrainingError: return
        raise AssertionError('Unsafe primary validation accepted')
    class BrokenValidation:
        size=4
        label_names=list(CLASSES.values())
        def __init__(self,duplicate): self.duplicate=duplicate
        def gen_tf_dataset(self,**kwargs):
            batch=validation.gen_tf_dataset(**kwargs).take(1)
            return batch.concatenate(batch) if self.duplicate else batch
    for duplicate in (False,True):
        broken=primary_validation_callback(tf,detector,BrokenValidation(duplicate),2,labels,policy,output)
        unchanged={}
        counter=int(detector._model.optimizer.iterations)
        weights=[v.numpy().copy() for v in detector._model.variables]
        rejected_primary(lambda:broken.on_epoch_end(2,unchanged))
        assert unchanged=={} and int(detector._model.optimizer.iterations)==counter
        for value,expected in zip(detector._model.variables,weights):np.testing.assert_array_equal(value.numpy(),expected)
        assert not (output/'primary-validation/epoch-00003.json').exists()
    valid_callback=primary_validation_callback(tf,detector,validation,2,labels,policy,output)
    rejected_primary(lambda:valid_callback.on_epoch_end(2,None))
    with patch('evaluate_waste.score_predictions',return_value={'AP':float('nan'),'AP50':.5}):
        unchanged={}
        rejected_primary(lambda:valid_callback.on_epoch_end(2,unchanged))
        assert unchanged=={}
    print('Pinned primary-label checkpoint and validation integration passed')
    assert (output / 'float_ckpt.index').is_file()
    detector.export_model(model_name='waste-detector.tflite')
    import hashlib
    import train_waste
    fixture = Path(sys.argv[2]).resolve()
    for index, split in enumerate(('train', 'validation', 'test'), 1):
        pixels = np.full((80, 100, 3), index * 50, dtype=np.uint8)
        (fixture / split / 'data' / f'{index}.jpg').write_bytes(tf.io.encode_jpeg(pixels).numpy())
    model_hash = hashlib.sha256((output / 'waste-detector.tflite').read_bytes()).hexdigest()
    metrics = {'status': 'trained', 'model': {'file': 'waste-detector.tflite', 'sha256': model_hash},
               'evaluation': {'validation': {'coco_metrics': {'AP': float(rows[selection['best_epoch']-1]['val_coco_AP'])}}},
               'promoted': False}
    train_waste.write_json_report(output / 'metrics.json', metrics)
    run_evaluation = train_waste.run_tflite_evaluation
    with patch('train_waste.run_tflite_evaluation', side_effect=lambda request: run_evaluation(request, timeout_seconds=60)):
        try:
            train_waste.evaluate_export(Value(output=output, dataset=fixture), metrics)
        except train_waste.TrainingError as error:
            log = output / 'tflite-validation.log'
            raise AssertionError(f'{error}\n{log.read_text() if log.exists() else "No child log"}') from error
    evaluation = json.loads((output / 'metrics.json').read_text())['tflite_evaluation']
    assert evaluation['status'] == 'evaluated' and evaluation['process']['exit_code'] == 0
    assert evaluation['model_sha256'] == model_hash and not evaluation['process']['timed_out']
    report = json.loads((output / evaluation['report']).read_text())
    assert report['split'] == 'validation' and len(report['image_sha256']) == 1
    assert report['promoted'] is False and (output / 'tflite-validation.log').is_file()

print('Pinned isolated TFLite export integration passed')
print('Pinned Model Maker AP integration passed')
print('Pinned Keras checkpoint integration passed')
PYTHON;
        $process = new Process([...$launcher, '/usr/bin/env', 'TF_NUM_INTRAOP_THREADS=2', 'TF_NUM_INTEROP_THREADS=1',
            'OMP_NUM_THREADS=2', 'OPENBLAS_NUM_THREADS=2', $python, '-u', '-', $source, $fixture]);
        $process->setInput($code)->setTimeout(480)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertStringContainsString('Pinned Keras checkpoint integration passed', $process->getOutput());
        $this->assertStringContainsString('Pinned Model Maker AP integration passed', $process->getOutput());
        $this->assertStringContainsString('Pinned isolated TFLite export integration passed', $process->getOutput());
        $this->assertStringContainsString('Pinned training camera geometry integration passed', $process->getOutput());
        $this->assertStringContainsString('Pinned primary-label checkpoint and validation integration passed', $process->getOutput());
    }

    public function test_diagnostic_pipeline_controls_preserve_coverage_and_enforce_real_keras_batch_accounting(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('This integration test targets the configured RobotSys WSL environment.');
        }
        $wsl = (new ExecutableFinder)->find('wsl');
        if ($wsl === null) {
            $this->markTestSkipped('WSL is required for diagnostic pipeline integration.');
        }
        $python = '/home/robotsys/waste-training/.venv/bin/python';
        $launcher = [$wsl, '-d', 'RobotSys-Ubuntu', '-u', 'robotsys', '--exec'];
        $probe = new Process([...$launcher, '/usr/bin/test', '-x', $python]);
        $probe->setTimeout(60)->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('The configured pinned Linux Python environment is unavailable.');
        }
        $source = str_replace('\\', '/', resource_path('ml'));
        $source = '/mnt/'.strtolower($source[0]).substr($source, 2);
        $code = <<<'PYTHON'
import json, sys, tempfile
from pathlib import Path
import numpy as np
import tensorflow as tf
assert tf.__version__ == '2.15.1'
sys.path.insert(0,sys.argv[1])
from mediapipe_model_maker import object_detector as api
from train_waste import CLASSES, TrainingError, apply_training_pipeline, training_pipeline_configuration
from audit_learning import PIPELINES, batch_accounting, controlled_dataset, fit_counter_probe, normalization_probe

def rejected(action):
    try: action()
    except (TrainingError, tf.errors.FailedPreconditionError): return
    raise AssertionError('Invalid pipeline control accepted')

tf.keras.utils.set_random_seed(42)
raw=tf.data.Dataset.from_tensor_slices({'source_id':tf.strings.as_string(tf.range(20)),
    'feature':tf.cast(tf.range(20),tf.float32)[:,None]}).filter(lambda row:tf.constant(True))
data=api.Dataset(dataset=raw,label_names=list(CLASSES.values()),size=20)
from types import SimpleNamespace as Args
policy=training_pipeline_configuration(Args(training_pipeline='bounded_shuffle'))
assert apply_training_pipeline(api,tf,data,training_pipeline_configuration(Args())) is data
shuffled=apply_training_pipeline(api,tf,data,policy)
preprocess=lambda row,is_training:(row['feature']/20, row['feature']/20)
batches=shuffled.gen_tf_dataset(batch_size=3,is_training=True,shuffle=True,preprocess=preprocess)
assert int(batches.cardinality())==7
orders=[]
for _ in range(3):
    order=[int(round(float(value[0])*20)) for features,labels in batches for value in features.numpy()]
    assert sorted(order)==list(range(20)), 'Partial batches must retain every source image'
    orders.append(order)
assert orders[0]!=orders[1] and orders[0]!=list(range(20))
assert shuffled.shuffle_buffer['records']==20 and shuffled.shuffle_buffer['full_epoch']
assert shuffled.shuffle_buffer['maximum_tensor_bytes'] <= policy['maximum_buffer_tensor_bytes']
large=api.Dataset(dataset=tf.data.Dataset.from_tensors({'feature':tf.zeros([1])}).repeat(300),
                  label_names=list(CLASSES.values()),size=300)
large=apply_training_pipeline(api,tf,large,policy)
large.gen_tf_dataset(batch_size=2,is_training=True,shuffle=True,preprocess=lambda row,is_training:row['feature'])
assert large.shuffle_buffer['records']==256 and not large.shuffle_buffer['full_epoch']
large.gen_tf_dataset(batch_size=2,is_training=True,shuffle=True,preprocess=lambda row,is_training:tf.zeros([1024,1024]))
assert large.shuffle_buffer['records']==32
assert large.shuffle_buffer['maximum_tensor_bytes']==policy['maximum_buffer_tensor_bytes']
evaluation=shuffled.gen_tf_dataset(batch_size=2,is_training=False,shuffle=True)
assert [int(value) for batch in evaluation for value in batch['source_id'].numpy()]==list(range(20))
for kwargs in ({'shuffle':False,'preprocess':preprocess}, {'shuffle':True},
               {'shuffle':True,'preprocess':preprocess,'drop_remainder':True},
               {'shuffle':True,'preprocess':lambda row,is_training:row},
               {'shuffle':True,'preprocess':lambda row,is_training:tf.zeros([4096,4096])}):
    rejected(lambda: shuffled.gen_tf_dataset(batch_size=3,is_training=True,**kwargs))
for count in (19,21):
    wrong=apply_training_pipeline(api,tf,api.Dataset(dataset=raw,label_names=list(CLASSES.values()),size=count),policy)
    rejected(lambda:list(wrong.gen_tf_dataset(batch_size=3,is_training=True,shuffle=True,preprocess=preprocess)))
rejected(lambda:apply_training_pipeline(api,tf,api.Dataset(dataset=raw.take(1),label_names=list(CLASSES.values()),size=1),policy))
rejected(lambda:apply_training_pipeline(api,tf,api.Dataset(dataset=raw,label_names=['wrong'],size=20),policy))
tf.keras.utils.set_random_seed(42)
dense=tf.keras.Sequential([tf.keras.layers.Dense(1,input_shape=(1,))])
dense.compile(optimizer=tf.keras.optimizers.SGD(.01),loss='mse')
dense.fit(batches,epochs=3,verbose=0)
assert int(dense.optimizer.iterations)==21, 'Known cardinality must prevent end-of-epoch phantom steps'
# Exercise the actual pinned object-detector preprocessor, not just a scalar stand-in.
from mediapipe_model_maker.python.vision.object_detector.preprocessor import Preprocessor
processor=Preprocessor(api.SupportedModels.get(api.SupportedModels.MOBILENET_V2))
record={'image':tf.zeros([32,48,3],tf.uint8),'source_id':tf.constant('1'),
    'height':tf.constant(32,tf.int64),'width':tf.constant(48,tf.int64),
    'groundtruth_boxes':tf.constant([[.1,.1,.8,.8]]),'groundtruth_classes':tf.constant([1],tf.int64),
    'groundtruth_area':tf.constant([752.64]),'groundtruth_is_crowd':tf.constant([False])}
detector_data=api.Dataset(dataset=tf.data.Dataset.from_tensors(record).repeat(20),label_names=list(CLASSES.values()),size=20)
detector_data=apply_training_pipeline(api,tf,detector_data,policy)
detector_batches=detector_data.gen_tf_dataset(batch_size=3,is_training=True,shuffle=True,preprocess=processor)
assert int(detector_batches.cardinality())==7
assert sum(int(images.shape[0]) for images,labels in detector_batches)==20
assert detector_data.shuffle_buffer['tensor_bytes_per_record']>256*256*3*4
assert detector_data.shuffle_buffer['maximum_tensor_bytes']<=policy['maximum_buffer_tensor_bytes']
assert int(raw.cardinality().numpy())==-2
assert controlled_dataset(api,tf,data,'pinned') is data
for mode in PIPELINES:
    controlled=controlled_dataset(api,tf,data,mode)
    batches=controlled.gen_tf_dataset(batch_size=2,is_training=True,shuffle=True)
    assert int(batches.cardinality().numpy())==(-2 if mode=='pinned' else 10)
    orders=[]
    for _ in range(3):
        order=[int(value) for batch in batches for value in batch['source_id'].numpy()]
        assert sorted(order)==list(range(20))
        orders.append(order)
    if mode=='full_shuffle':
        assert orders[0]!=orders[1], 'Each epoch must reshuffle'
    evaluation=controlled.gen_tf_dataset(batch_size=2,is_training=False,shuffle=False)
    assert [int(value) for batch in evaluation for value in batch['source_id'].numpy()]==list(range(20))
    transformed=controlled.gen_tf_dataset(batch_size=2,is_training=True,shuffle=mode=='full_shuffle',
        preprocess=lambda row,is_training:{**row,'feature':row['feature']+tf.cast(is_training,tf.float32)})
    for batch in transformed:
        expected=np.asarray([float(value)+1 for value in batch['source_id'].numpy()])[:,None]
        np.testing.assert_array_equal(batch['feature'].numpy(),expected)
rejected(lambda:controlled_dataset(api,tf,data,'full_shuffle').gen_tf_dataset(is_training=True,shuffle=False))
rejected(lambda:controlled_dataset(api,tf,data,'unknown'))
for count in (19,42):
    invalid=api.Dataset(dataset=raw,label_names=list(CLASSES.values()),size=count)
    rejected(lambda:controlled_dataset(api,tf,invalid,'cardinality'))
invalid=api.Dataset(dataset=raw,label_names=['wrong'],size=20)
rejected(lambda:controlled_dataset(api,tf,invalid,'full_shuffle'))
short=api.Dataset(dataset=raw.take(19),label_names=list(CLASSES.values()),size=20)
rejected(lambda:list(controlled_dataset(api,tf,short,'cardinality').gen_tf_dataset()))
long=api.Dataset(dataset=raw.concatenate(raw.take(1)),label_names=list(CLASSES.values()),size=20)
rejected(lambda:list(controlled_dataset(api,tf,long,'cardinality').gen_tf_dataset()))
mixed=tf.data.Dataset.from_generator(lambda:({'source_id':str(i),'image':np.zeros((2+i%3,4,3),np.uint8)} for i in range(20)),
    output_signature={'source_id':tf.TensorSpec([],tf.string),'image':tf.TensorSpec([None,None,3],tf.uint8)})
mixed=api.Dataset(dataset=mixed,label_names=list(CLASSES.values()),size=20)
for mode in PIPELINES:
    batches=controlled_dataset(api,tf,mixed,mode).gen_tf_dataset(batch_size=2,is_training=True,shuffle=True,
        preprocess=lambda record,is_training:{'source_id':record['source_id']})
    assert sorted(int(value) for batch in batches for value in batch['source_id'].numpy())==list(range(20))

# Exercise the options actually passed by train(), not just a manually shuffled dataset.
from types import SimpleNamespace as Args
from unittest.mock import patch
import diagnose_waste as diagnostic
class CapturedOptions(Exception): pass
assert api.HParams().shuffle is False, 'Record the pinned default that previously disabled shuffling'
with tempfile.TemporaryDirectory() as directory:
    root=Path(directory)
    snapshot=root/'snapshot'
    snapshot.mkdir()
    (snapshot/'review.json').write_text('{}')
    audit={'summary_sha256':'a'*64,'labels':{'images':[{'id':i} for i in range(20)]}}
    for mode in PIPELINES:
        captured=[]
        def capture(cls,train_data,validation_data,options):
            assert options.hparams.shuffle is (mode=='full_shuffle')
            assert validation_data is train_data
            batches=train_data.gen_tf_dataset(batch_size=2,is_training=True,shuffle=options.hparams.shuffle,
                preprocess=lambda row,is_training:{'source_id':row['source_id']})
            order=[int(value) for batch in batches for value in batch['source_id'].numpy()]
            assert sorted(order)==list(range(20))
            assert (order!=list(range(20))) is (mode=='full_shuffle')
            captured.append(True)
            raise CapturedOptions()
        with patch.object(diagnostic,'validate_snapshot',return_value=audit), \
             patch.object(api.Dataset,'from_coco_folder',return_value=data), \
             patch.object(api.ObjectDetector,'create',classmethod(capture)):
            try: diagnostic.train(Args(dataset=snapshot,output=root/mode,epochs=2,resume=False,input_pipeline=mode))
            except CapturedOptions: pass
            else: raise AssertionError('Expected to stop immediately before model fitting')
        assert captured==[True]
        configuration=json.loads((root/mode/'run.json').read_text())
        if mode!='pinned':
            assert configuration['input_pipeline']['shuffle'] is (mode=='full_shuffle')
            assert configuration['input_pipeline']['shuffle_buffer']==(20 if mode=='full_shuffle' else None)

result=fit_counter_probe(tf,20)
assert result['scope']=='tiny_dense_model_runtime_control_not_detector_training'
assert result['results']['asserted']['final_optimizer_iterations']==30
assert all(epoch['passed'] and epoch['started_batches']==epoch['completed_batches']==10
    for epoch in result['results']['asserted']['epochs'])
assert result['results']['unknown']['batch_cardinality']==-2
assert all(epoch['completed_batches']==10 for epoch in result['results']['unknown']['epochs'])
for steps in (0,21,True):
    rejected(lambda:batch_accounting(tf,steps))
model=tf.keras.Sequential([tf.keras.layers.Dense(1,input_shape=(1,))])
model.compile(optimizer=tf.keras.optimizers.SGD(.01),loss='mse')
with tempfile.TemporaryDirectory() as directory:
    accounting=batch_accounting(tf,1,Path(directory))
    accounting.set_model(model)
    accounting.on_epoch_begin(0)
    accounting.on_train_batch_begin(0)
    model.optimizer.iterations.assign_add(2)
    accounting.on_train_batch_end(0)
    rejected(lambda:accounting.on_epoch_end(0))
    assert json.loads((Path(directory)/'batch-accounting/epoch-00001.json').read_text())['passed'] is False
    rejected(lambda:accounting.on_epoch_begin(1))

class Normalized(tf.keras.Model):
    def __init__(self):
        super().__init__()
        self.norm=tf.keras.layers.BatchNormalization()
        self.fail=False
    def call(self,x,training=False):
        output=self.norm(x,training=training)
        if training and self.fail:
            raise RuntimeError('probe interrupted')
        return {'cls_outputs':{'3':output},'box_outputs':{'3':output}}
normalized=Normalized()
batch=tf.constant([[1.,2.],[3.,4.]])
normalized(batch,training=False)
before=[value.numpy().copy() for value in normalized.variables]
probe=normalization_probe(normalized,batch)
assert probe['batchnorm_layers']==1 and probe['trainable_batchnorm_layers']==1
assert probe['in_memory_variables_restored'] and probe['gradient_updates']==0
assert probe['changed_variables'] and probe['raw_output_max_abs_differences']['cls_outputs']>0
for actual,expected in zip(normalized.variables,before):
    np.testing.assert_array_equal(actual.numpy(),expected)
normalized.fail=True
try: normalization_probe(normalized,batch)
except RuntimeError as error: assert 'probe interrupted' in str(error)
else: raise AssertionError('Probe failure was hidden')
for actual,expected in zip(normalized.variables,before):
    np.testing.assert_array_equal(actual.numpy(),expected)
print('Pinned diagnostic pipeline coverage, cardinality, batch accounting and normalization restoration passed')
PYTHON;
        $process = new Process([...$launcher, '/usr/bin/env', 'TF_NUM_INTRAOP_THREADS=2', 'TF_NUM_INTEROP_THREADS=1',
            'OMP_NUM_THREADS=2', 'OPENBLAS_NUM_THREADS=2', $python, '-u', '-', $source]);
        $process->setInput($code)->setTimeout(180)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertStringContainsString('Pinned diagnostic pipeline coverage, cardinality, batch accounting and normalization restoration passed', $process->getOutput());
    }

    public function test_prepared_browser_frames_match_pinned_tensorflow_without_changing_source_photos(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('This integration test targets the configured RobotSys WSL environment.');
        }
        $wsl = (new ExecutableFinder)->find('wsl');
        if ($wsl === null) {
            $this->markTestSkipped('WSL is required for the preprocessing integration test.');
        }
        $python = '/home/robotsys/waste-training/.venv/bin/python';
        $launcher = [$wsl, '-d', 'RobotSys-Ubuntu', '-u', 'robotsys', '--exec'];
        $probe = new Process([...$launcher, '/usr/bin/test', '-x', $python]);
        $probe->setTimeout(60)->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('The configured pinned Linux Python environment is unavailable.');
        }
        $this->trainingFixture();
        $paths = array_map(function (string $path): string {
            $path = str_replace('\\', '/', $path);

            return '/mnt/'.strtolower($path[0]).substr($path, 2);
        }, [resource_path('ml'), $this->directory]);
        $code = <<<'PYTHON'
import json, sys
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
import numpy as np
import tensorflow as tf
from PIL import Image
sys.path.insert(0,sys.argv[1])
import prepare_browser_waste as probe
from train_waste import TrainingError
from official.vision.ops import preprocess_ops
root=Path(sys.argv[2])
dataset=root/'dataset'
for index, split in enumerate(('train','validation','test'),1):
    pixels=np.zeros((80,100,3),dtype=np.uint8)
    pixels[:,:,0]=index*40
    pixels[:,:,1]=np.arange(100)
    pixels[:,:,2]=np.arange(80)[:,None]
    Image.fromarray(pixels).save(dataset/split/'data'/f'{index}.jpg')
sources={p:probe.file_hash(p) for p in dataset.rglob('*') if p.is_file()}
args=SimpleNamespace(dataset=dataset.resolve(),output=(root/'prepared').resolve())
report=probe.prepare(args)
assert report['status']=='prepared' and len(report['frames'])==1 and not report['promoted']
frame=report['frames'][0]
assert frame['image_id']==2 and frame['content_width']==256 and frame['content_height']==205
assert report['versions']['tensorflow']=='2.15.1'
rgb=tf.io.decode_png((args.output/'2.png').read_bytes(),channels=3).numpy()
original=tf.io.decode_jpeg((dataset/'validation/data/2.jpg').read_bytes(),channels=3)
normalized=preprocess_ops.normalize_image(original,(.5,),(.5,))
padded,_=preprocess_ops.resize_and_crop_image(normalized,[256,256],padded_size=[256,256],aug_scale_min=1.,aug_scale_max=1.)
expected=np.clip(np.rint((padded.numpy()+1)*127.5),0,255).astype(np.uint8)
np.testing.assert_array_equal(rgb,expected)
np.testing.assert_array_equal(rgb[205:],128)
assert frame['max_normalized_roundtrip_error'] <= .5/127.5+1e-6
assert all(probe.file_hash(p)==digest for p,digest in sources.items())
manifest=(args.output/'manifest.json').read_bytes()
try:
    probe.prepare(args)
except TrainingError:
    pass
else:
    raise AssertionError('Completed prepared frames were overwritten')
assert (args.output/'manifest.json').read_bytes()==manifest
with patch.object(probe,'check_environment',side_effect=TrainingError('fixture missing environment')):
    failed=SimpleNamespace(dataset=args.dataset,output=(root/'failed').resolve())
    try:
        probe.prepare(failed)
    except TrainingError:
        pass
    else:
        raise AssertionError('Missing dependencies accepted')
    assert not failed.output.exists()
bad=SimpleNamespace(dataset=args.dataset,output=(root/'wrong-dimensions').resolve())
Image.new('RGB',(99,80)).save(dataset/'validation/data/2.jpg')
try:
    probe.prepare(bad)
except TrainingError:
    pass
else:
    raise AssertionError('Source dimensions disagreeing with labels were accepted')
assert not (bad.output/'manifest.json').exists()
print('Pinned TensorFlow RGB preprocessing, provenance, rounding and no-overwrite integration passed')
PYTHON;
        $process = new Process([...$launcher, '/usr/bin/env', 'TF_NUM_INTRAOP_THREADS=2', 'TF_NUM_INTEROP_THREADS=1',
            'OMP_NUM_THREADS=2', 'OPENBLAS_NUM_THREADS=2', $python, '-u', '-', ...$paths]);
        $process->setInput($code)->setTimeout(180)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertStringContainsString('Pinned TensorFlow RGB preprocessing, provenance, rounding and no-overwrite integration passed', $process->getOutput());
    }

    public function test_postprocessed_exports_preserve_foreground_labels_with_the_real_pinned_nms_kernel(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('This optional integration test targets the configured RobotSys WSL environment.');
        }
        $wsl = (new ExecutableFinder)->find('wsl');
        if ($wsl === null) {
            $this->markTestSkipped('WSL is required for the pinned NMS integration test.');
        }
        $python = '/home/robotsys/waste-training/.venv/bin/python';
        $launcher = [$wsl, '-d', 'RobotSys-Ubuntu', '-u', 'robotsys', '--exec'];
        $probe = new Process([...$launcher, '/usr/bin/test', '-x', $python]);
        $probe->setTimeout(60)->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('The configured pinned Linux Python environment is unavailable.');
        }
        $source = str_replace('\\', '/', resource_path('ml'));
        $source = '/mnt/'.strtolower($source[0]).substr($source, 2);
        $code = <<<'PYTHON'
import sys, tempfile
from pathlib import Path
import numpy as np
import tensorflow as tf
assert tf.__version__ == '2.15.1'
sys.path.insert(0, sys.argv[1])
import train_waste
from train_waste import CLASSES
from export_waste import export_module, mask_background_logits, postprocessing_config, validate_postprocessed_model
from evaluate_waste import validate_model_labels
from mediapipe.tasks.python.metadata.metadata import MetadataDisplayer
from mediapipe.tasks.python.metadata.metadata_writers import metadata_writer, object_detector as writer_lib
logits = tf.constant([[[[8., 1., -8., -8., 2., 9., 3., 4., 5., 6.]]]])
masked = mask_background_logits(logits).numpy()
np.testing.assert_array_equal(masked[..., [1, 2, 3, 4, 6, 7, 8, 9]], logits.numpy()[..., [1, 2, 3, 4, 6, 7, 8, 9]])
np.testing.assert_array_equal(masked[..., [0, 5]], -100.)
class FixtureHead(tf.keras.layers.Layer):
    def call(self, features, training=False):
        signal = features['3']
        return {'3': tf.constant([[[[8., 1., 1.5, -8., 2.]]]]) + signal * .01}, {'3': tf.zeros([1, 1, 1, 4])}, {}
raw = tf.Module()
raw.backbone = tf.keras.layers.Lambda(lambda images: {'3': tf.reduce_mean(images, axis=[1, 2, 3], keepdims=True)})
raw.decoder = None
raw.head = FixtureHead()
anchors = {'3': tf.constant([[[[32., 32., 96., 96.]]]])}
decoder_policy = {'use_class_agnostic_nms': False, 'pre_nms_score_threshold': .05,
                  'nms_iou_threshold': .5, 'max_num_detections': 100}
with tempfile.TemporaryDirectory(prefix='nms-export-integration-') as directory:
    for regular in (True, False):
        config = postprocessing_config(decoder_policy, regular)
        module = export_module(raw, anchors, config)
        saved = str(Path(directory) / str(regular))
        tf.saved_model.save(module, saved, signatures={'serving_default': module.detect.get_concrete_function()})
        converter = tf.lite.TFLiteConverter.from_saved_model(saved)
        converter.target_spec.supported_ops = (tf.lite.OpsSet.TFLITE_BUILTINS,)
        buffer = converter.convert()
        validate_postprocessed_model(buffer, config)
        try:
            validate_postprocessed_model(buffer, {**config, 'use_regular_nms': not regular})
        except train_waste.TrainingError:
            pass
        else:
            raise AssertionError('Wrong exported NMS mode was accepted')
        content, metadata = writer_lib.MetadataWriter.create_for_models_with_nms(
            bytearray(buffer), [127.5], [127.5], metadata_writer.Labels().add(list(CLASSES.values())),
        ).populate()
        validate_model_labels(MetadataDisplayer.with_model_buffer(content))
        interpreter = tf.lite.Interpreter(model_content=bytes(content), num_threads=1)
        interpreter.allocate_tensors()
        interpreter.set_tensor(interpreter.get_input_details()[0]['index'], np.zeros([1, 256, 256, 3], np.float32))
        interpreter.invoke()
        tensors = [interpreter.get_tensor(item['index']) for item in sorted(interpreter.get_output_details(), key=lambda item: item['index'])]
        boxes, categories, scores, count = tensors
        expected = 3 if regular else 1
        assert int(count[0]) == expected
        assert set(categories[0, :expected]) == ({1., 2., 4.} if regular else {4.})
        assert all(scores[0, :expected] >= .7) and not any(categories[0, :expected] == 0)
        np.testing.assert_allclose(boxes[0, 0], [.125, .125, .375, .375], atol=1e-6)
        if regular:
            threaded = tf.lite.Interpreter(model_content=bytes(content),num_threads=2)
            threaded.allocate_tensors()
            threaded.set_tensor(threaded.get_input_details()[0]['index'],np.zeros([1,256,256,3],np.float32))
            threaded.invoke()
            output = [threaded.get_tensor(item['index']) for item in sorted(threaded.get_output_details(),key=lambda item:item['index'])]
            threaded_count = int(output[3][0])
            assert threaded_count == 2 and set(output[1][0,:threaded_count]) == {1.,4.}, 'Pinned two-thread regular NMS reproduces the omitted class-2 diagnostic'
print('Pinned postprocessed NMS integration passed')
PYTHON;
        $process = new Process([...$launcher, '/usr/bin/env', 'TF_NUM_INTRAOP_THREADS=2', 'TF_NUM_INTEROP_THREADS=1',
            'OMP_NUM_THREADS=2', 'OPENBLAS_NUM_THREADS=2', $python, '-u', '-', $source]);
        $process->setInput($code)->setTimeout(180)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertStringContainsString('Pinned postprocessed NMS integration passed', $process->getOutput());
    }

    public function test_experimental_nms_exports_validate_policy_and_preserve_source_artifacts(): void
    {
        $this->trainingFixture();
        $python = (new ExecutableFinder)->find(PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');
        if ($python === null) {
            $this->markTestSkipped('Python is required for export validation tests.');
        }
        $command = PHP_OS_FAMILY === 'Windows' ? [$python, '-3.11'] : [$python];
        $code = <<<'PYTHON'
import copy, sys, tempfile
from pathlib import Path
from types import SimpleNamespace
sys.path.insert(0, sys.argv[1])
from export_waste import postprocessing_config, validate_source
from compare_waste import file_hash
from train_waste import TrainingError, run_configuration, write_json_report
policy = {'use_class_agnostic_nms': False, 'pre_nms_score_threshold': .05,
          'nms_iou_threshold': .5, 'max_num_detections': 100}
regular, fast = postprocessing_config(policy, True), postprocessing_config(policy, False)
assert {key: value for key, value in regular.items() if key != 'use_regular_nms'} == {key: value for key, value in fast.items() if key != 'use_regular_nms'}
assert regular['use_regular_nms'] and not fast['use_regular_nms']
assert regular['input_image_size'] == (256, 256) and regular['omit_nms'] is False
for changed in [{'use_class_agnostic_nms': True}, {'use_class_agnostic_nms': None},
                {'pre_nms_score_threshold': float('nan')}, {'pre_nms_score_threshold': 0},
                {'pre_nms_score_threshold': 1}, {'nms_iou_threshold': 0}, {'nms_iou_threshold': 1.1},
                {'max_num_detections': 0}, {'max_num_detections': 101}, {'max_num_detections': True}]:
    try:
        postprocessing_config({**policy, **changed}, True)
    except TrainingError:
        pass
    else:
        raise AssertionError('Invalid postprocessing policy accepted')
try:
    postprocessing_config(policy, 1)
except TrainingError:
    pass
else:
    raise AssertionError('Non-boolean NMS mode accepted')
dataset = Path(sys.argv[2]).resolve()
with tempfile.TemporaryDirectory(prefix='export source with spaces ') as directory:
    root = Path(directory)
    run = root / 'source'
    run.mkdir()
    for name in ('waste-detector.tflite', 'float_ckpt.index', 'float_ckpt.data-00000-of-00001'):
        (run / name).write_bytes(name.encode())
    metrics = {'status': 'trained', 'architecture': 'MOBILENET_V2', 'epochs': 1, 'batch_size': 1,
               'model': {'sha256': file_hash(run / 'waste-detector.tflite')}}
    manifest = run_configuration(SimpleNamespace(dataset=dataset, epochs=1, batch_size=1), {})
    write_json_report(run / 'metrics.json', metrics)
    write_json_report(run / 'run.json', manifest)
    args = SimpleNamespace(dataset=dataset, run=run, output=root / 'experiment')
    snapshots = {path.name: path.read_bytes() for path in run.iterdir()}
    source = validate_source(args, {})
    assert source['model_sha256'] == metrics['model']['sha256']
    assert len(source['checkpoint_sha256']) == 2 and not args.output.exists()
    for changed in [{'status': 'failed'}, {'architecture': 'OTHER'}, {'model': {'sha256': '0' * 64}}]:
        write_json_report(run / 'metrics.json', {**metrics, **changed}, replace=True)
        try:
            validate_source(args, {})
        except TrainingError:
            pass
        else:
            raise AssertionError('Invalid source run accepted')
    write_json_report(run / 'metrics.json', metrics, replace=True)
    for changed in [{'dataset_sha256': '0' * 64}, {'versions': {'changed': '1'}}]:
        write_json_report(run / 'run.json', {**manifest, **changed}, replace=True)
        try:
            validate_source(args, {})
        except TrainingError:
            pass
        else:
            raise AssertionError('Changed data or runtime accepted')
    write_json_report(run / 'run.json', manifest, replace=True)
    for target in (run / 'nested', dataset / 'nested', Path(sys.argv[1]).resolve().parents[1] / 'public/unsafe-export'):
        try:
            validate_source(SimpleNamespace(dataset=dataset, run=run, output=target), {})
        except TrainingError:
            pass
        else:
            raise AssertionError('Unsafe export location accepted')
    args.output.mkdir()
    (args.output / 'existing.txt').write_bytes(b'keep')
    try:
        validate_source(args, {})
    except TrainingError:
        pass
    else:
        raise AssertionError('Occupied output accepted')
    assert (args.output / 'existing.txt').read_bytes() == b'keep'
    assert {path.name: path.read_bytes() for path in run.iterdir()} == snapshots
assert 'tensorflow' not in sys.modules and 'mediapipe' not in sys.modules
print('Experimental export validation checks passed')
PYTHON;
        $process = new Process([...$command, '-', resource_path('ml'), $this->directory.'/dataset']);
        $process->setInput($code)->setTimeout(30)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertStringContainsString('Experimental export validation checks passed', $process->getOutput());
    }

    public function test_tflite_evaluation_checks_labels_scores_and_image_coordinates(): void
    {
        $python = (new ExecutableFinder)->find(PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');
        if ($python === null) {
            $this->markTestSkipped('Python is required for TFLite evaluation tests.');
        }
        $command = PHP_OS_FAMILY === 'Windows' ? [$python, '-3.11'] : [$python];
        $code = <<<'PYTHON'
import json, sys
from types import SimpleNamespace as Value
sys.path.insert(0, sys.argv[1])
from evaluate_waste import (coco_detection, validate_model_labels, deduplication_safe_graph,
                            merge_duplicate_detections, create_evaluation_detector)
from train_waste import TrainingError
image = {'id': 7, 'width': 100, 'height': 80}
category = Value(index=1, category_name='plastic_bottle', score=0.75)
box = Value(origin_x=-10, origin_y=20, width=200, height=100)
detection = Value(categories=[category], bounding_box=box)
assert coco_detection(detection, image) == {'image_id': 7, 'category_id': 1, 'bbox': [0, 20, 100, 60], 'score': 0.75}
for index, name in enumerate(['plastic_bottle', 'other_plastic', 'paper', 'metal_can'], 1):
    category.index, category.category_name = index, name
    assert coco_detection(detection, image)['category_id'] == index
    category.index = None
    assert coco_detection(detection, image)['category_id'] == index
def rejected():
    try:
        coco_detection(detection, image)
    except TrainingError:
        return
    raise AssertionError('Invalid detection was accepted')
category.category_name = 'bottle'
rejected()
category.index, category.category_name = 1, 'metal_can'
rejected()
category.index = 4
category.category_name = 'metal_can'
for score in [float('nan'), float('inf'), -0.1, 1.1]:
    category.score = score
    rejected()
category.score = 0.75
box.origin_x = float('nan')
rejected()
box.origin_x = 110
assert coco_detection(detection, image) is None
box.origin_x, box.width = 0, -1
assert coco_detection(detection, image) is None
category.index, category.category_name = 0, 'background'
assert coco_detection(detection, image) is None
box.origin_x, box.width = 0, 100
lower = Value(index=None, category_name='other_plastic', score=0.03944511339068413)
higher = Value(index=None, category_name='other_plastic', score=0.06446720659732819)
detection.categories = [lower, higher]
assert coco_detection(detection, image)['score'] == higher.score
assert coco_detection(detection, image)['category_id'] == 2
paper = Value(index=3, category_name='paper', score=0.8)
detection.categories = [higher, paper]
assert coco_detection(detection, image)['category_id'] == 3
background = Value(index=0, category_name='background', score=0.9)
detection.categories = [paper, background]
assert coco_detection(detection, image) is None
for invalid in [Value(index=4, category_name='paper', score=0.01),
                Value(index=2, category_name='other_plastic', score=float('nan')),
                Value(index=2, category_name='unknown', score=0.01)]:
    detection.categories = [paper, invalid]
    rejected()
detection.categories = []
rejected()

def node(calculator='DetectionsDeduplicateCalculator', inputs=None, outputs=None, options=False):
    return Value(calculator=calculator, input_stream=['boxes'] if inputs is None else inputs,
                 output_stream=['merged'] if outputs is None else outputs, node_options=[],
                 HasField=lambda name: options)
graph = Value(node=[node('UnchangedCalculator'), node()])
safe = deduplication_safe_graph(graph)
assert safe is not graph and graph.node[1].calculator == 'DetectionsDeduplicateCalculator'
assert [n.calculator for n in safe.node] == ['UnchangedCalculator', 'PassThroughCalculator']
assert safe.node[1].input_stream == ['boxes'] and safe.node[1].output_stream == ['merged']
for nodes in ([], [node(), node()], [node(inputs=[])], [node(outputs=['a', 'b'])], [node(options=True)]):
    try:
        deduplication_safe_graph(Value(node=nodes))
    except TrainingError:
        pass
    else:
        raise AssertionError('Unexpected native graph layout was accepted')
configured = node()
configured.node_options = ['unexpected']
try:
    deduplication_safe_graph(Value(node=[configured]))
except TrainingError:
    pass
else:
    raise AssertionError('Unexpected native node options were discarded')

first = Value(categories=[paper], bounding_box=Value(origin_x=0, origin_y=0, width=50, height=40))
second = Value(categories=[higher], bounding_box=Value(origin_x=1, origin_y=0, width=50, height=40))
duplicate = Value(categories=[background], bounding_box=first.bounding_box)
folded = merge_duplicate_detections([first, second, duplicate])
assert len(folded) == 2 and folded[0].categories == [paper, background]
assert first.categories == [paper] and second.categories == [higher]
assert coco_detection(folded[0], image) is None
assert coco_detection(folded[1], image)['category_id'] == 2
many = [Value(categories=[paper], bounding_box=Value(origin_x=i, origin_y=0, width=1, height=1)) for i in range(1000)]
many += [first, duplicate]
assert len(merge_duplicate_detections(many)) == 1001
assert merge_duplicate_detections([]) == []
try:
    merge_duplicate_detections([Value(categories=[paper], bounding_box=Value(origin_x=float('nan'), origin_y=0, width=1, height=1))])
except TrainingError:
    pass
else:
    raise AssertionError('Invalid duplicate coordinates accepted')

from unittest.mock import patch
lifecycle = []
class Detector:
    def __init__(self, graph, mode):
        assert graph.node[1].calculator == 'PassThroughCalculator' and mode == 'image'
        lifecycle.append('safe_created')
class TaskInfo:
    def __init__(self, **kwargs):
        assert kwargs['task_graph'] == 'mediapipe.tasks.vision.ObjectDetectorGraph'
        assert kwargs['input_streams'] == ['IMAGE:image_in', 'NORM_RECT:norm_rect_in']
        assert kwargs['output_streams'] == ['DETECTIONS:detections_out', 'IMAGE:image_out']
        assert kwargs['task_options'] is options
    def generate_graph_config(self):
        lifecycle.append('task_generated')
        return graph
class Validated:
    binary_config = b'expanded graph'
    def initialize(self, graph_config):
        assert graph_config is graph
        lifecycle.append('graph_expanded')
fake_mp = Value(__version__='0.10.14', ValidatedGraphConfig=Validated,
                tasks=Value(vision=Value(ObjectDetector=Detector, RunningMode=Value(IMAGE='image'))))
options = Value(running_mode='image', result_callback=None)
protobuf = Value(CalculatorGraphConfig=Value(FromString=lambda binary: graph if binary == b'expanded graph' else None))
with patch.dict(sys.modules, {'mediapipe': fake_mp, 'mediapipe.framework.calculator_pb2': protobuf,
                             'mediapipe.tasks.python.core.task_info': Value(TaskInfo=TaskInfo)}):
    assert isinstance(create_evaluation_detector(options), Detector)
    assert lifecycle == ['task_generated', 'graph_expanded', 'safe_created']
    for version, mode, callback in [('0.10.15', 'image', None), ('0.10.14', 'video', None), ('0.10.14', 'image', lambda: None)]:
        fake_mp.__version__, options.running_mode, options.result_callback = version, mode, callback
        try:
            create_evaluation_detector(options)
        except TrainingError:
            pass
        else:
            raise AssertionError('Unsupported runtime, mode, or callback accepted')
metadata = {'subgraph_metadata': [{'output_tensor_metadata': [{'associated_files': [{'name': 'labels.txt', 'type': 'TENSOR_AXIS_LABELS'}]}]}]}
labels = b'background\nplastic_bottle\nother_plastic\npaper\nmetal_can\n'
displayer = Value(get_metadata_json=lambda: json.dumps(metadata), get_associated_file_buffer=lambda name: labels)
associated = metadata['subgraph_metadata'][0]['output_tensor_metadata'][0]['associated_files']
for label_type in ['TENSOR_AXIS_LABELS', 'TENSOR_VALUE_LABELS']:
    associated[0]['type'] = label_type
    validate_model_labels(displayer)
for invalid in [[], [{'name': 'labels.txt', 'type': 'UNKNOWN'}],
                [{'name': 'labels.txt', 'type': 'TENSOR_VALUE_LABELS', 'locale': 'en'}],
                [{'name': 'labels.txt', 'type': 'TENSOR_AXIS_LABELS'}, {'name': 'labels.txt', 'type': 'TENSOR_VALUE_LABELS'}]]:
    metadata['subgraph_metadata'][0]['output_tensor_metadata'][0]['associated_files'] = invalid
    try:
        validate_model_labels(displayer)
    except TrainingError:
        pass
    else:
        raise AssertionError('Missing, localized, unsupported, or ambiguous label metadata was accepted')
metadata['subgraph_metadata'][0]['output_tensor_metadata'][0]['associated_files'] = associated
for labels in [b'background\nbottle', b'background\nother_plastic\nplastic_bottle\npaper\nmetal_can']:
    try:
        validate_model_labels(displayer)
    except TrainingError:
        pass
    else:
        raise AssertionError('Invalid metadata label order was accepted')
print('TFLite conversion checks passed')
PYTHON;
        $process = new Process([...$command, '-', resource_path('ml')]);
        $process->setInput($code)->setTimeout(30)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('TFLite conversion checks passed', $process->getOutput());
    }

    public function test_prediction_comparison_restores_original_coordinates_and_escapes_overlay_labels(): void
    {
        $python = (new ExecutableFinder)->find(PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');
        if ($python === null) {
            $this->markTestSkipped('Python is required for prediction comparison tests.');
        }
        $command = PHP_OS_FAMILY === 'Windows' ? [$python, '-3.11'] : [$python];
        $code = <<<'PYTHON'
import json, subprocess, sys, tempfile, xml.etree.ElementTree as ET
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
sys.path.insert(0, sys.argv[1])
import compare_waste
from compare_waste import project_prediction, comparison_svg, run_mediapipe_probe, postprocessed_predictions, validated_exports
from export_waste import postprocessing_config
from train_waste import TrainingError, write_json_report
image = {'id': 7, 'width': 1000, 'height': 500}
prediction = {'image_id': 7, 'category_id': 1, 'score': 0.8, 'bbox': [25.6, 12.8, 51.2, 25.6]}
result = project_prediction(prediction, image, [0.256, 0.256])
assert all(abs(a - b) < 1e-8 for a, b in zip(result['bbox'], [100, 50, 200, 100]))
assert prediction['bbox'] == [25.6, 12.8, 51.2, 25.6], 'Do not mutate source predictions'
result = project_prediction(prediction, image, [0.5, 0.25], [5, 10])
assert all(abs(a - b) < 1e-8 for a, b in zip(result['bbox'], [142.4, 35.6, 204.8, 51.2]))
outside = {**prediction, 'bbox': [0, 150, 10, 20]}
assert project_prediction(outside, image, [0.256, 0.256]) is None
clipped = {**prediction, 'bbox': [-10, -20, 40, 60]}
assert project_prediction(clipped, image, [1, 1])['bbox'] == [0, 0, 30, 40]
for scales in [[0, 1], [-1, 1], [float('nan'), 1], [1], [1, float('inf')]]:
    try:
        project_prediction(prediction, image, scales)
    except TrainingError:
        pass
    else:
        raise AssertionError('Invalid coordinate scales accepted')
image['attribution'] = {'author': 'Fixture author', 'license_url': 'https://creativecommons.org/licenses/by/4.0/'}
svg = comparison_svg(image, b'jpeg-fixture', {'<script>alert(1)</script>': [prediction, prediction], 'Empty': []})
tree = ET.fromstring(svg)
ns = {'s': 'http://www.w3.org/2000/svg'}
assert len(tree.findall('.//s:image', ns)) == 2
assert not tree.findall('.//s:script', ns)
assert tree.findall('.//s:rect', ns), 'Overlay must include predicted boxes'
assert '<script>' not in svg
box_labels = [node for node in tree.findall('.//s:text', ns) if (node.text or '').startswith('plastic_bottle')]
assert len({node.get('y') for node in box_labels}) == 2, 'Overlapping boxes need separate readable labels'
assert 'Fixture author' in tree.find('s:metadata', ns).text
assert 'tensorflow' not in sys.modules and 'mediapipe' not in sys.modules
normalized = [[[[.05,.1,.15,.3]]], [[1.]], [[.8]], [1.]]
decoded = postprocessed_predictions(normalized,image,[.256,.256])
assert len(decoded) == 1 and decoded[0]['category_id'] == 1
assert all(abs(a-b) < 1e-8 for a,b in zip(decoded[0]['bbox'],[100,50,200,100]))
assert normalized[0][0][0] == [.05,.1,.15,.3], 'Do not mutate export outputs'
assert postprocessed_predictions([[[]],[[]],[[]],[0.]],image,[.256,.256]) == []
assert postprocessed_predictions([[[[.8,0,.9,.1]]],[[1]],[[.8]],[1]],image,[.256,.256]) == []
assert postprocessed_predictions([[[[.2,.2,.1,.3]]],[[1]],[[.8]],[1]],image,[.256,.256]) == []
for invalid in [
    [], normalized[:3], [normalized[0],normalized[1],normalized[2],[True]],
    [normalized[0],normalized[1],normalized[2],[.5]],
    [normalized[0],normalized[1],normalized[2],[float('nan')]],
    [normalized[0],normalized[1],normalized[2],[float('inf')]],
    [normalized[0],normalized[1],normalized[2],[-1]],
    [normalized[0],normalized[1],normalized[2],[101]],
    [normalized[0],[[0]],normalized[2],[1]],
    [normalized[0],[[1.5]],normalized[2],[1]],
    [normalized[0],[[True]],normalized[2],[1]],
    [normalized[0],normalized[1],[[1.1]],[1]],
    [normalized[0],normalized[1],[[float('nan')]],[1]],
    [[[[0,0,float('inf'),1]]],normalized[1],normalized[2],[1]],
    [[[[0,0,1]]],normalized[1],normalized[2],[1]],
    [normalized[0],[[]],normalized[2],[1]],
]:
    try:
        postprocessed_predictions(invalid,image,[.256,.256])
    except TrainingError:
        pass
    else:
        raise AssertionError('Invalid exact-input export prediction accepted')
with tempfile.TemporaryDirectory(prefix='probe path with spaces ') as temporary:
    root = Path(temporary)
    request_path = root / 'mediapipe_default.json'
    request = {
        'mode': 'mediapipe_default', 'model_sha256': 'a' * 64,
        'labels_sha256': 'b' * 64, 'image_sha256': {'7': 'c' * 64},
        'frames': [{'image': image}],
    }
    write_json_report(request_path, request)
    success = {
        'status': 'evaluated', 'split': 'validation', 'mode': 'mediapipe_default',
        'model_sha256': 'a' * 64, 'labels_sha256': 'b' * 64, 'image_sha256': {'7': 'c' * 64},
        'images': 1, 'predictions': [{**prediction, 'bbox': [100, 50, 200, 100]}],
        'graph': 'fixture graph', 'promoted': False,
    }
    result_path = request_path.with_suffix('.result.json')
    write_json_report(result_path, success)
    def completed(command, **kwargs):
        assert command[:2] == [sys.executable, '-u']
        assert Path(command[2]).name == 'compare_waste.py'
        assert command[3:] == ['--mediapipe-probe', str(request_path)]
        assert kwargs['stdin'] is subprocess.DEVNULL
        assert kwargs['stderr'] is subprocess.STDOUT and kwargs['check'] is False
        assert kwargs['timeout'] == 300 and kwargs['env']['PYTHONFAULTHANDLER'] == '1'
        kwargs['stdout'].write(b'worker completed\n')
        return SimpleNamespace(returncode=0)
    with patch.object(compare_waste.subprocess, 'run', side_effect=completed):
        result = run_mediapipe_probe(request_path, root / 'success.log')
    assert result['predictions'] == success['predictions']
    assert result['process'] == {'exit_code': 0, 'log': 'success.log', 'timeout_seconds': 300}
    assert (root / 'success.log').read_bytes() == b'worker completed\n'
    for index, changed in enumerate([
        {'model_sha256': 'd' * 64}, {'mode': 'mediapipe_training_geometry'},
        {'images': 0}, {'images': True}, {'promoted': True}, {'graph': None},
        {'labels_sha256': 'd' * 64}, {'image_sha256': {}},
        {'predictions': [{**prediction, 'bbox': [999, 50, 200, 100]}]},
    ]):
        write_json_report(result_path, {**success, **changed}, replace=True)
        with patch.object(compare_waste.subprocess, 'run', return_value=SimpleNamespace(returncode=0)):
            try:
                run_mediapipe_probe(request_path, root / f'invalid-{index}.log')
            except TrainingError as error:
                assert 'see ' in str(error)
            else:
                raise AssertionError('Unverified probe result accepted')
    result_path.unlink()
    for name, outcome in [('missing', SimpleNamespace(returncode=0)), ('crash', SimpleNamespace(returncode=-6))]:
        with patch.object(compare_waste.subprocess, 'run', return_value=outcome):
            try:
                run_mediapipe_probe(request_path, root / f'{name}.log')
            except TrainingError:
                pass
            else:
                raise AssertionError('Failed or missing probe accepted')
        assert (root / f'{name}.log').exists()
    with patch.object(compare_waste.subprocess, 'run', side_effect=subprocess.TimeoutExpired('fixture', 1)):
        try:
            run_mediapipe_probe(request_path, root / 'timeout.log', timeout_seconds=1)
        except TrainingError as error:
            assert 'timed out' in str(error)
        else:
            raise AssertionError('Probe timeout accepted')
    with patch.object(compare_waste.subprocess, 'run') as launch:
        try:
            run_mediapipe_probe(request_path, root / 'success.log')
        except TrainingError:
            pass
        else:
            raise AssertionError('Existing log overwritten')
        launch.assert_not_called()
    assert (root / 'success.log').read_bytes() == b'worker completed\n'
    real_run = subprocess.run
    def native_crash(*args, **kwargs):
        return real_run([sys.executable, '-u', '-c', "import os; print('native failure', flush=True); os._exit(139)"], **kwargs)
    with patch.object(compare_waste.subprocess, 'run', side_effect=native_crash):
        try:
            run_mediapipe_probe(request_path, root / 'native-crash.log')
        except TrainingError as error:
            assert '139' in str(error)
        else:
            raise AssertionError('Native crash accepted')
    assert b'native failure' in (root / 'native-crash.log').read_bytes()
    assert not result_path.exists()
    model_path = root / 'model.tflite'
    input_path = root / 'image.png'
    model_path.write_bytes(b'model fixture')
    input_path.write_bytes(b'image fixture')
    frames = [{'image': {**image, 'id': key}, 'original': str(input_path), 'input': str(input_path),
               'input_sha256': compare_waste.file_hash(input_path), 'scale_yx': [0.256, 0.256]} for key in (7, 8)]
    request = {**request, 'mode': 'mediapipe_training_geometry', 'model': str(model_path),
               'model_sha256': compare_waste.file_hash(model_path), 'frames': frames}
    write_json_report(request_path, request, replace=True)
    lifecycle = []
    class Options:
        Delegate = SimpleNamespace(CPU='cpu')
        def __init__(self, **kwargs):
            self.values = kwargs
    class Detector:
        def __enter__(self):
            lifecycle.append('open')
            return self
        def __exit__(self, *args):
            lifecycle.append('close')
        def get_graph_config(self):
            return 'fixture graph'
        def detect(self, frame):
            lifecycle.append('detect')
            return SimpleNamespace(detections=[SimpleNamespace(
                categories=[SimpleNamespace(category_name='plastic_bottle', index=1, score=0.8)],
                bounding_box=SimpleNamespace(origin_x=25.6, origin_y=12.8, width=51.2, height=25.6),
            )])
    mp = SimpleNamespace(
        Image=SimpleNamespace(create_from_file=lambda path: SimpleNamespace(width=256, height=256)),
        tasks=SimpleNamespace(BaseOptions=Options, vision=SimpleNamespace(
            ObjectDetectorOptions=Options, RunningMode=SimpleNamespace(IMAGE='image'),
            ObjectDetector=SimpleNamespace(create_from_options=lambda options: Detector()),
        )),
    )
    metadata = SimpleNamespace(MetadataDisplayer=SimpleNamespace(with_model_file=lambda path: path))
    with patch.dict(sys.modules, {'mediapipe': mp, 'mediapipe.tasks.python.metadata.metadata': metadata}):
        with patch.object(compare_waste, 'validate_model_labels') as labels_check, \
                patch.object(compare_waste, 'create_evaluation_detector', side_effect=lambda options: Detector()) as factory:
            result = compare_waste.mediapipe_probe(request_path)
        labels_check.assert_called_once_with(str(model_path))
        assert factory.call_count == 2
    assert lifecycle == ['open', 'detect', 'close', 'open', 'detect', 'close']
    assert result['images'] == 2 and result['promoted'] is False
    assert [prediction['image_id'] for prediction in result['predictions']] == [7, 8]
    assert all(abs(a - b) < 1e-8 for a, b in zip(result['predictions'][0]['bbox'], [100, 50, 200, 100]))
    import copy
    export_folder = root/'exports'
    export_folder.mkdir()
    policy = {'use_class_agnostic_nms':False,'pre_nms_score_threshold':.05,'nms_iou_threshold':.5,'max_num_detections':100}
    source = {'model_sha256':'a'*64,'checkpoint_sha256':{'index':'b'*64},'metrics_sha256':'c'*64,'dataset_sha256':'d'*64}
    versions = {'tensorflow':'2.15.1'}
    export_report = {'status':'exported','promoted':False,'retrained':False,'source':source,'versions':versions,
                     'training_postprocessing':policy,'models':{}}
    for mode in ('regular','fast'):
        folder = export_folder/mode
        folder.mkdir()
        path = folder/'waste-detector.tflite'
        path.write_bytes(f'{mode} fixture'.encode())
        config = json.loads(json.dumps(postprocessing_config(policy,mode=='regular')))
        export_report['models'][mode] = {'file':f'{mode}/waste-detector.tflite','sha256':compare_waste.file_hash(path),
                                        'bytes':path.stat().st_size,'postprocessing':config}
    export_path = export_folder/'export.json'
    export_path.write_text(json.dumps(export_report))
    models, proofs = validated_exports(export_folder,source,versions,policy)
    assert set(models) == {'regular','fast'} and len(proofs) == 3
    original_export = export_path.read_bytes()
    for change in ('status','promoted','retrained','versions','checkpoint','dataset','metrics','policy','mode','path','bytes','hash','nms'):
        invalid = copy.deepcopy(export_report)
        if change == 'status': invalid['status'] = 'exporting'
        elif change == 'promoted': invalid['promoted'] = True
        elif change == 'retrained': invalid['retrained'] = True
        elif change == 'versions': invalid['versions'] = {}
        elif change == 'checkpoint': invalid['source']['checkpoint_sha256'] = {}
        elif change == 'dataset': invalid['source']['dataset_sha256'] = 'e'*64
        elif change == 'metrics': invalid['source']['metrics_sha256'] = 'e'*64
        elif change == 'policy': invalid['training_postprocessing']['nms_iou_threshold'] = .6
        elif change == 'mode': invalid['models'].pop('fast')
        elif change == 'path': invalid['models']['regular']['file'] = '../model.tflite'
        elif change == 'bytes': invalid['models']['regular']['bytes'] = True
        elif change == 'hash': invalid['models']['regular']['sha256'] = '0'*64
        elif change == 'nms': invalid['models']['regular']['postprocessing']['use_regular_nms'] = False
        export_path.write_text(json.dumps(invalid))
        try:
            validated_exports(export_folder,source,versions,policy)
        except TrainingError:
            pass
        else:
            raise AssertionError('Mismatched postprocessed export provenance accepted')
    export_path.write_bytes(original_export)
    models['regular'].write_bytes(b'changed regular export')
    try:
        validated_exports(export_folder,source,versions,policy)
    except TrainingError:
        pass
    else:
        raise AssertionError('Changed postprocessed model bytes accepted')
    run = root/'run'
    dataset = root/'dataset'
    output = root/'cli-output'
    for folder in (run,dataset,output): folder.mkdir()
    with patch.object(compare_waste,'compare',return_value={'metrics':{},'comparison_scope':'checkpoint_only'}) as compare:
        assert compare_waste.main(['--run',str(run),'--dataset',str(dataset),'--output',str(output),
                                   '--checkpoint-only','--postprocessed-export',str(export_folder)]) == 0
        args = compare.call_args.args[0]
        assert args.checkpoint_only is True and args.postprocessed_export == export_folder.resolve()
        assert args.postprocess_threads == 1, 'Pinned regular NMS must default to the class-preserving single-thread path'
    saved = (output/'comparison.json').read_bytes()
    with patch.object(compare_waste,'compare',side_effect=TrainingError('fixture comparison failure')):
        assert compare_waste.main(['--run',str(run),'--dataset',str(dataset),'--output',str(output)]) == 1
    assert (output/'comparison.json').read_bytes() == saved
print('Prediction comparison checks passed')
PYTHON;
        $process = new Process([...$command, '-', resource_path('ml')]);
        $process->setInput($code);
        $process->setTimeout(30)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Prediction comparison checks passed', $process->getOutput());
    }

    public function test_error_analysis_matches_each_object_once_and_checks_saved_prediction_integrity(): void
    {
        $python = (new ExecutableFinder)->find(PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');
        if ($python === null) {
            $this->markTestSkipped('Python is required for error analysis tests.');
        }
        $command = PHP_OS_FAMILY === 'Windows' ? [$python, '-3.11'] : [$python];
        $code = <<<'PYTHON'
import copy, sys
sys.path.insert(0, sys.argv[1])
from pathlib import Path
from tempfile import TemporaryDirectory
from analyze_waste import analyze_image, intersection_over_union, summarize, summarize_history, validate_report
from train_waste import TrainingError
assert intersection_over_union([0, 0, 10, 10], [0, 0, 10, 10]) == 1
assert intersection_over_union([0, 0, 10, 10], [10, 0, 10, 10]) == 0
assert abs(intersection_over_union([0, 0, 10, 10], [5, 0, 10, 10]) - 1/3) < 1e-9
annotations = [{'id': index, 'image_id': 7, 'category_id': 1, 'bbox': [index * 40, 0, 20, 20]} for index in range(6)]
def prediction(box, category=1, score=.8):
    return {'image_id': 7, 'category_id': category, 'bbox': box, 'score': score}
predictions = [prediction([0, 0, 20, 20]), prediction([0, 0, 20, 20], score=.7),
               prediction([40, 0, 20, 20], score=.05), prediction([80, 0, 20, 20], category=2),
               prediction([130, 0, 20, 20]), prediction([300, 0, 20, 20])]
original = copy.deepcopy(predictions)
result = analyze_image(annotations, predictions)
assert [item['reason'] for item in result['ground_truth']] == ['matched', 'low_confidence', 'wrong_class', 'localization', 'missed', 'missed']
assert [item['reason'] for item in result['false_positives']] == ['wrong_class', 'localization', 'background_or_poor_overlap', 'duplicate']
assert predictions == original, 'Analysis must not mutate saved predictions'
stats = summarize([result])
assert stats['true_positives'] == 1 and stats['false_positives'] == 4 and stats['false_negatives'] == 5
assert stats['precision'] == .2 and stats['recall'] == 1/6
assert stats['by_class']['plastic_bottle']['low_confidence'] == 1
assert summarize([analyze_image([], [])])['precision'] == 0
assert summarize([analyze_image(annotations, predictions, .05)])['true_positives'] == 2
assert analyze_image([annotations[0], {**annotations[0], 'id': 99}], [predictions[0]])['ground_truth'][0]['reason'] == 'competing_match'
assert summarize([analyze_image([annotations[0]], [predictions[0], predictions[1]])])['true_positives'] == 1
for threshold in [0, -1, 1.1, float('nan'), True]:
    try:
        analyze_image([], [], threshold)
    except TrainingError:
        pass
    else:
        raise AssertionError('Invalid diagnostic threshold accepted')
labels = {'images': [{'id': 7, 'width': 400, 'height': 100}]}
report = {'status': 'evaluated', 'split': 'validation', 'model_sha256': 'a'*64,
          'labels_sha256': 'labels-hash', 'image_sha256': {'7': 'image-hash'}, 'predictions': original}
def validate(value):
    validate_report(value, labels, 'labels-hash', {'7': 'image-hash'})
validate(report)
for key, value in [('status', 'failed'), ('split', 'test'), ('model_sha256', 'invalid'),
                   ('labels_sha256', 'changed'), ('image_sha256', {}), ('predictions', {})]:
    changed = {**report, key: value}
    try:
        validate(changed)
    except TrainingError:
        pass
    else:
        raise AssertionError(f'Invalid report {key} accepted')
for key, value in [('image_id', 99), ('image_id', True), ('category_id', 0), ('category_id', True),
                   ('score', float('nan')), ('score', 1.01), ('bbox', [0, 0, 401, 10]),
                   ('bbox', [0, 0, -1, 10]), ('bbox', [0, 0, float('inf'), 10]), ('bbox', [1])]:
    changed = {**report, 'predictions': [{**predictions[0], key: value}]}
    try:
        validate(changed)
    except TrainingError:
        pass
    else:
        raise AssertionError(f'Invalid prediction {key} accepted')
with TemporaryDirectory() as directory:
    path = Path(directory) / 'history.csv'
    path.write_text('epoch,total_loss,val_total_loss\n0,2,1.5\n1,1,1.2\n2,.3,1.8\n')
    history = summarize_history(path)
    assert history['epochs'] == 3 and history['lowest_validation_loss_epoch']['epoch'] == 2
    assert history['last_epoch']['training_loss'] == .3 and len(history['sha256']) == 64
    for content in ['epoch,total_loss,val_total_loss\n', 'epoch,total_loss,val_total_loss\n1,2,1.5\n',
                    'epoch,total_loss,val_total_loss\n0,2,1.5\n0,1,1\n',
                    'epoch,total_loss,val_total_loss\n0,nan,1\n', 'epoch,total_loss,val_total_loss\n0,1,-1\n',
                    'epoch,total_loss,val_total_loss\n0,invalid,1\n', 'epoch,total_loss,total_loss\n0,1,1\n']:
        path.write_text(content)
        try:
            summarize_history(path)
        except TrainingError:
            pass
        else:
            raise AssertionError('Invalid history accepted')
print('Error analysis integrity checks passed')
PYTHON;
        $process = new Process([...$command, '-c', $code, resource_path('ml')]);
        $process->setTimeout(30)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Error analysis integrity checks passed', $process->getOutput());
    }

    public function test_training_annotation_audit_preserves_can_policy_and_flags_mapping_or_cap_overlap(): void
    {
        $python = (new ExecutableFinder)->find(PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');
        if ($python === null) {
            $this->markTestSkipped('Python is required for annotation audit tests.');
        }
        $command = PHP_OS_FAMILY === 'Windows' ? [$python, '-3.11'] : [$python];
        $code = <<<'PYTHON'
import copy, json, sys
from pathlib import Path
sys.path.insert(0, sys.argv[1])
from analyze_waste import audit_training_annotations
from train_waste import TrainingError
root = Path(sys.argv[2]).resolve()
dataset = root / 'audit-dataset'
(dataset / 'train').mkdir(parents=True)
categories = [{'id': 1, 'name': 'Other plastic bottle'}, {'id': 2, 'name': 'Plastic bottle cap'},
              {'id': 3, 'name': 'Aerosol'}, {'id': 4, 'name': 'Drink can'}]
source = {'images': [{'id': 7, 'file_name': 'batch/7.jpg', 'width': 200, 'height': 160}],
          'categories': categories,
          'annotations': [{'id': 1, 'image_id': 7, 'category_id': 1, 'bbox': [20, 20, 40, 40]},
                          {'id': 2, 'image_id': 7, 'category_id': 2, 'bbox': [40, 20, 10, 10]},
                          {'id': 3, 'image_id': 7, 'category_id': 3, 'bbox': [80, 20, 20, 30]},
                          {'id': 4, 'image_id': 7, 'category_id': 4, 'bbox': [100, 20, 20, 30]}]}
labels = {'images': [{'id': 7, 'file_name': '7.jpg', 'source_file_name': 'batch/7.jpg', 'width': 100, 'height': 80},
                     {'id': 9, 'source_collection': 'zenodo:12517610'}],
          'annotations': [{'id': 1, 'image_id': 7, 'category_id': 1, 'bbox': [10, 10, 20, 20]},
                          {'id': 2, 'image_id': 7, 'category_id': 2, 'bbox': [20, 10, 5, 5]},
                          {'id': 4, 'image_id': 7, 'category_id': 4, 'bbox': [50, 10, 10, 15]}]}
source_path, label_path = root / 'source.json', dataset / 'train/labels.json'
source_path.write_text(json.dumps(source))
label_path.write_text(json.dumps(labels))
before = label_path.read_bytes()
result = audit_training_annotations(dataset, source_path)
assert not result['structural_mismatches']
assert result['class_source_names']['metal_can'] == ['Food Can', 'Drink can']
assert result['excluded_source_objects']['Aerosol'] == 1
assert result['audited_source_images'] == 1 and result['separately_provenanced_images'] == 1
assert result['class_coverage']['plastic_bottle'] == {'objects': 1, 'images': 1}
assert result['cap_overlap_review_queue'] == [{'image_id': 7, 'cap_annotation_id': 2, 'bottle_annotation_id': 1,
                                            'reason': 'cap_box_mostly_inside_bottle_box'}]
assert label_path.read_bytes() == before, 'Audit must not rewrite annotation labels'
for change in ('class', 'geometry', 'missing', 'extra'):
    modified = copy.deepcopy(labels)
    if change == 'class':
        modified['annotations'][0]['category_id'] = 4
    elif change == 'geometry':
        modified['annotations'][0]['bbox'][0] += 1
    elif change == 'missing':
        modified['annotations'].pop(0)
    else:
        modified['annotations'].append({**modified['annotations'][0], 'id': 99})
    label_path.write_text(json.dumps(modified))
    assert audit_training_annotations(dataset, source_path)['structural_mismatches'], change
modified = copy.deepcopy(labels)
modified['images'][0]['source_file_name'] = 'wrong/photo.jpg'
label_path.write_text(json.dumps(modified))
try:
    audit_training_annotations(dataset, source_path)
except TrainingError:
    pass
else:
    raise AssertionError('Mismatched source photo accepted')
label_path.write_bytes(before)
source['annotations'][0]['bbox'][2] = 0
source_path.write_text(json.dumps(source))
try:
    audit_training_annotations(dataset, source_path)
except TrainingError:
    pass
else:
    raise AssertionError('Invalid source geometry accepted')
print('Training annotation policy audit passed')
PYTHON;
        $process = new Process([...$command, '-c', $code, resource_path('ml'), $this->directory]);
        $process->setTimeout(30)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Training annotation policy audit passed', $process->getOutput());
    }

    public function test_error_analysis_generates_private_reviews_and_rejects_changed_inputs_without_overwriting(): void
    {
        $executable = PHP_OS_FAMILY === 'Windows' ? 'Scripts/python.exe' : 'bin/python';
        $python = storage_path('app/private/waste-training/.venv/'.$executable);
        if (! is_file($python)) {
            $this->markTestSkipped('The isolated Pillow environment is required for review rendering tests.');
        }
        $code = <<<'PYTHON'
import copy, json, sys, xml.etree.ElementTree as ET
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
from PIL import Image
sys.path.insert(0, sys.argv[1])
from analyze_waste import analyze, file_hash, main
from train_waste import TrainingError
root = Path(sys.argv[2]).resolve()
dataset = root / 'dataset'
images = dataset / 'validation/data'
images.mkdir(parents=True)
Image.new('RGB', (100, 80), '#64883a').save(images / '7.jpg')
labels = {'images': [{'id': 7, 'file_name': '7.jpg', 'width': 100, 'height': 80,
                     'attribution': {'author': 'Fixture author', 'license_url': 'https://creativecommons.org/licenses/by/4.0/'}}],
          'annotations': [{'id': 5, 'image_id': 7, 'category_id': 1, 'bbox': [10, 10, 20, 30]}]}
label_file = dataset / 'validation/labels.json'
label_file.write_text(json.dumps(labels))
report = {'status': 'evaluated', 'split': 'validation', 'model_sha256': 'a'*64,
          'labels_sha256': file_hash(label_file), 'image_sha256': {'7': file_hash(images / '7.jpg')},
          'backend': 'fixture', 'settings': {'score_threshold': .001},
          'predictions': [{'image_id': 7, 'category_id': 1, 'bbox': [10, 10, 20, 30], 'score': .8}]}
evaluations = root / 'evaluations'
evaluations.mkdir()
baseline, candidate = evaluations / 'baseline.json', evaluations / 'candidate.json'
baseline.write_text(json.dumps(report))
candidate.write_text(json.dumps(report))
output = root / 'review'
argv = ['--dataset', str(dataset), '--baseline', str(baseline), '--evaluation', str(candidate), '--output', str(output)]
with patch('analyze_waste.validate_dataset', return_value={'fixture': True}):
    assert main(argv) == 0
result = json.loads((output / 'error-analysis.json').read_text())
assert result['split'] == 'validation' and result['promoted'] is False
assert result['results']['candidate']['summary']['true_positives'] == 1
assert len(result['results']['candidate']['threshold_diagnostics']) == 5
assert result['analyzer_sha256'] == file_hash(Path(sys.argv[1]) / 'analyze_waste.py')
svg = ET.fromstring((output / 'review-7.svg').read_text())
assert 'Fixture author' in svg.find('{http://www.w3.org/2000/svg}metadata').text
with Image.open(output / 'bottles-7.png') as review:
    assert review.width == 1500 and review.height > 740
    assert review.getpixel((10, 40)) != (255, 255, 255), 'Review must contain the actual image'
before = {path: file_hash(path) for path in output.iterdir()}
args = SimpleNamespace(dataset=dataset, baseline=baseline, evaluation=candidate, output=output, history=None, source_annotations=None)
with patch('analyze_waste.validate_dataset', return_value={}):
    try:
        analyze(args)
    except TrainingError:
        pass
    else:
        raise AssertionError('Existing review output was overwritten')
    assert {path: file_hash(path) for path in output.iterdir()} == before
    args.output = root / 'invalid-review'
    for change in [{'split': 'test'}, {'image_sha256': {}}, {'settings': {'score_threshold': .1}},
                   {'predictions': [{**report['predictions'][0], 'bbox': [0, 0, 200, 100]}]}]:
        candidate.write_text(json.dumps({**report, **change}))
        try:
            analyze(args)
        except TrainingError:
            pass
        else:
            raise AssertionError('Incompatible saved predictions accepted')
        assert not args.output.exists(), 'Invalid inputs must fail before creating review output'
    candidate.write_text(json.dumps(report))
    args.history = evaluations / 'history.csv'
    args.history.write_text('epoch,total_loss,val_total_loss\n0,2,1.5\n')
    (evaluations / 'metrics.json').write_text(json.dumps({'status': 'trained', 'model': {'sha256': 'b'*64}}))
    try:
        analyze(args)
    except TrainingError:
        pass
    else:
        raise AssertionError('History from another model accepted')
    assert not args.output.exists()
    args.history = None
    label_file.write_text(json.dumps({**labels, 'changed': True}))
    try:
        analyze(args)
    except TrainingError:
        pass
    else:
        raise AssertionError('Changed validation labels accepted')
    assert not args.output.exists()
print('Private review rendering checks passed')
PYTHON;
        $process = new Process([$python, '-c', $code, resource_path('ml'), $this->directory]);
        $process->setTimeout(30)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Private review rendering checks passed', $process->getOutput());
    }

    public function test_confidence_policies_reduce_false_positives_without_losing_any_class_matches(): void
    {
        $python = (new ExecutableFinder)->find(PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');
        if ($python === null) {
            $this->markTestSkipped('Python is required for confidence policy tests.');
        }
        $command = PHP_OS_FAMILY === 'Windows' ? [$python, '-3.11'] : [$python];
        $code = <<<'PYTHON'
import copy, sys
sys.path.insert(0, sys.argv[1])
from calibrate_waste import class_conflicts, confidence_policies
from train_waste import TrainingError
labels = {'images': [{'id': 1, 'width': 100, 'height': 100}],
          'annotations': [{'id': i, 'image_id': 1, 'category_id': i, 'bbox': [i*20-10, 10, 10, 10]} for i in (1, 2, 3)]}
predictions = [{'image_id': 1, 'category_id': i, 'bbox': [i*20-10, 10, 10, 10], 'score': score} for i, score in [(1, .45), (2, .8), (3, .4)]]
predictions += [{'image_id': 1, 'category_id': i, 'bbox': [70, 70, 10, 10], 'score': score} for i, score in [(1, .4), (2, .7), (3, .9), (4, .9)]]
predictions.append({**predictions[0], 'score': .3})
before = copy.deepcopy(predictions)
result = confidence_policies(labels, predictions, .2)
assert result['baseline']['true_positives'] == 3 and result['baseline']['false_positives'] == 5
assert result['global_policy']['threshold'] == .35 and result['global_policy']['false_positives'] == 4
assert result['per_class_policy']['plastic_bottle']['threshold'] == .45
assert result['per_class_policy']['other_plastic']['threshold'] == .75
assert result['per_class_policy']['paper']['threshold'] == .2
assert result['per_class_policy']['metal_can']['threshold'] == .2
assert result['per_class_policy']['metal_can']['reason'] == 'no_positive_matches_keep_inference_floor'
assert result['per_class_summary']['true_positives'] == 3 and result['per_class_summary']['false_positives'] == 2
assert result['per_class_summary']['matched_by_class'] == result['baseline']['matched_by_class']
assert predictions == before
assert confidence_policies(labels, list(reversed(predictions)), .2)['per_class_policy'] == result['per_class_policy']
for floor in [0, -1, 1.1, True, float('nan')]:
    try:
        confidence_policies(labels, predictions, floor)
    except TrainingError:
        pass
    else:
        raise AssertionError('Invalid inference floor accepted')
for score in [.1, 1.1, float('nan'), True]:
    try:
        confidence_policies(labels, [{**predictions[0], 'score': score}], .2)
    except TrainingError:
        pass
    else:
        raise AssertionError('Invalid or unavailable confidence accepted')
empty = confidence_policies(labels, [], .2)
assert empty['global_policy']['threshold'] == .2
assert all(p['threshold'] == .2 for p in empty['per_class_policy'].values())
boundary = confidence_policies(labels, [{**predictions[0], 'score': 1.}], 1.)
assert len(boundary['curve']) == 1 and boundary['baseline']['true_positives'] == 1
samples = [
    {'image_id':1, 'category_id':1, 'bbox':[0,0,30,30], 'score':.8},
    {'image_id':1, 'category_id':3, 'bbox':[0,0,30,30], 'score':.7},
    {'image_id':1, 'category_id':1, 'bbox':[0,0,30,30], 'score':.6},
    {'image_id':1, 'category_id':4, 'bbox':[70,70,10,10], 'score':.9},
    {'image_id':2, 'category_id':3, 'bbox':[0,0,30,30], 'score':.95},
    {'image_id':1, 'category_id':3, 'bbox':[0,0,30,30], 'score':.1},
]
before = copy.deepcopy(samples)
conflicts = class_conflicts(samples, .2)
assert [p['prediction_indices'] for p in conflicts] == [[0,1],[1,2]]
assert conflicts[0]['labels'] == ['plastic_bottle','paper'] and conflicts[0]['iou'] == 1.
assert abs(conflicts[0]['confidence_gap'] - .1) < 1e-9 and conflicts[0]['maximum_score'] == .8
assert samples == before, 'Diagnostics must not filter, reorder, or relabel inputs'
assert len(class_conflicts(samples, .7)) == 1 and class_conflicts(samples, .75) == []
assert class_conflicts([], .2) == []
edge = [samples[0], {**samples[1], 'bbox':[10,0,30,30]}]
assert class_conflicts(edge, .2)[0]['iou'] == .5
assert class_conflicts([edge[0], {**edge[1], 'bbox':[10.001,0,30,30]}], .2) == []
for threshold in [0, -1, 1.1, True, float('nan')]:
    try:
        class_conflicts(samples, threshold)
    except TrainingError:
        pass
    else:
        raise AssertionError('Invalid conflict threshold accepted')
assert 'tensorflow' not in sys.modules and 'mediapipe' not in sys.modules
print('Confidence policy checks passed')
PYTHON;
        $process = new Process([...$command, '-', resource_path('ml')]);
        $process->setInput($code)->setTimeout(30)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertStringContainsString('Confidence policy checks passed', $process->getOutput());
    }

    public function test_private_calibration_checks_exact_sources_and_never_overwrites_artifacts(): void
    {
        $this->trainingFixture();
        $python = (new ExecutableFinder)->find(PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');
        if ($python === null) {
            $this->markTestSkipped('Python is required for calibration command tests.');
        }
        $command = PHP_OS_FAMILY === 'Windows' ? [$python, '-3.11'] : [$python];
        $code = <<<'PYTHON'
import copy, json, sys
from pathlib import Path
from types import SimpleNamespace
sys.path.insert(0, sys.argv[1])
from calibrate_waste import calibrate, main
from compare_waste import file_hash
from train_waste import TrainingError
root = Path(sys.argv[2]).resolve()
dataset = root/'dataset'
labels_path = dataset/'validation/labels.json'
labels = json.loads(labels_path.read_text())
sources = root/'sources'
sources.mkdir()
model, benchmark = sources/'model.tflite', sources/'benchmark.json'
model.write_bytes(b'private model fixture')
predictions = [{'image_id': 2, 'category_id': i, 'bbox': [10, 10, 20, 30], 'score': .7} for i in (1, 2, 3, 4)]
saved = {'predictions': predictions, 'completed_image_ids': [2]}
report = {'status': 'evaluated', 'split': 'validation', 'labels_sha256': file_hash(labels_path),
          'image_sha256': {'2': file_hash(dataset/'validation/data/2.jpg')},
          'settings': {'score_threshold': .2, 'max_results': 10, 'category_selection': 'highest_score_per_detection'},
          'models': {'regular': {'model_sha256': file_hash(model), 'geometries': {'original': saved, 'padded': saved}}}}
benchmark.write_text(json.dumps(report))
args = SimpleNamespace(dataset=dataset, model=model, benchmark=benchmark, mode='regular', geometry='original', input_manifest=None, output=root/'calibration')
result = calibrate(args)
assert result['status'] == 'calibrated' and result['baseline']['true_positives'] == 4
assert result['promoted'] is False and result['deployment_ready'] is False and result['retrained'] is False
assert result['source_sha256'][str(model)] == file_hash(model)
assert result['calibrator_sha256'] == file_hash(Path(sys.argv[1])/'calibrate_waste.py')
before = (args.output/'calibration.json').read_bytes()
try:
    calibrate(args)
except TrainingError:
    pass
else:
    raise AssertionError('Existing calibration was overwritten')
assert (args.output/'calibration.json').read_bytes() == before
args.output = root/'invalid-output'
for change in [{'split': 'test'}, {'status': 'inferred'}, {'labels_sha256': '0'*64}, {'image_sha256': {}},
               {'settings': {**report['settings'], 'score_threshold': 0}},
               {'settings': {**report['settings'], 'max_results': True}},
               {'settings': {**report['settings'], 'category_selection': 'all_categories'}}]:
    benchmark.write_text(json.dumps({**report, **change}))
    try:
        calibrate(args)
    except TrainingError:
        pass
    else:
        raise AssertionError('Invalid benchmark accepted')
    assert not args.output.exists()
for change in [{'completed_image_ids': []}, {'completed_image_ids': [2, 2]},
               {'predictions': [{**predictions[0], 'score': .1}]},
               {'predictions': [{**predictions[0], 'bbox': [0, 0, 200, 100]}]},
               {'predictions': predictions*3}]:
    changed = copy.deepcopy(report)
    changed['models']['regular']['geometries']['original'].update(change)
    benchmark.write_text(json.dumps(changed))
    try:
        calibrate(args)
    except TrainingError:
        pass
    else:
        raise AssertionError('Invalid predictions or image coverage accepted')
    assert not args.output.exists()
benchmark.write_text(json.dumps(report))
original = model.read_bytes()
model.write_bytes(b'changed')
assert main(['--dataset', str(dataset), '--benchmark', str(benchmark), '--model', str(model), '--output', str(args.output)]) == 1
assert not args.output.exists()
model.write_bytes(original)
for target in [sources/'nested', dataset/'nested', Path(sys.argv[1]).resolve().parents[1]/'public/unsafe-calibration']:
    args.output = target
    try:
        calibrate(args)
    except TrainingError:
        pass
    else:
        raise AssertionError('Unsafe output accepted')
args.output = root/'padded-output'
args.geometry = 'padded'
try:
    calibrate(args)
except TrainingError:
    pass
else:
    raise AssertionError('Missing padded input proof accepted')
assert not args.output.exists()
inputs = root/'inputs'
inputs.mkdir()
manifest_path = inputs/'inputs.json'
png = inputs/'2.png'
png.write_bytes(b'padded input fixture')
manifest = {'status': 'prepared', 'split': 'validation', 'labels_sha256': file_hash(labels_path),
            'frames': [{'image': labels['images'][0], 'scale_yx': [2.56, 2.56],
                        'original_sha256': report['image_sha256']['2'], 'padded_sha256': file_hash(png)}]}
manifest_path.write_text(json.dumps(manifest))
report['input_manifest_sha256'] = file_hash(manifest_path)
report['padded_sha256'] = {'2': file_hash(png)}
benchmark.write_text(json.dumps(report))
args.input_manifest = manifest_path
padded = calibrate(args)
assert padded['geometry'] == 'padded' and padded['baseline']['true_positives'] == 4
assert padded['source_sha256'][str(png)] == file_hash(png)
args.output = root/'invalid-padded'
png.write_bytes(b'changed padded input')
try:
    calibrate(args)
except TrainingError:
    pass
else:
    raise AssertionError('Changed padded image bytes accepted')
assert not args.output.exists()
png.write_bytes(b'padded input fixture')
for change in [{'frames': manifest['frames']*2}, {'labels_sha256': '0'*64},
               {'frames': [{**manifest['frames'][0], 'scale_yx': [True, 2.56]}]},
               {'frames': [{**manifest['frames'][0], 'image': {**labels['images'][0], 'width': 101}}]}]:
    manifest_path.write_text(json.dumps({**manifest, **change}))
    report['input_manifest_sha256'] = file_hash(manifest_path)
    benchmark.write_text(json.dumps(report))
    try:
        calibrate(args)
    except TrainingError:
        pass
    else:
        raise AssertionError('Invalid padded geometry or coverage accepted')
    assert not args.output.exists()
manifest_path.write_text(json.dumps(manifest))
report['input_manifest_sha256'] = file_hash(manifest_path)
benchmark.write_text(json.dumps(report))
assert json.loads(benchmark.read_text()) == report and model.read_bytes() == original
from score_browser_waste import SETTINGS
from unittest.mock import patch
import calibrate_waste as calibration
browser_predictions = [{**p, 'label': calibration.CLASSES[p['category_id']]} for p in predictions]
browser_predictions.append({'image_id':2, 'category_id':3, 'label':'paper', 'score':.9, 'bbox':[70,60,10,10]})
browser_model = {'model_sha256':file_hash(model), 'label_space':'waste', 'input_size':256,
    'frames':[{'image_id':2, 'width':100, 'height':80, 'inference_ms':10, 'detections':[{}]*5}],
    'predictions':browser_predictions}
browser_report = {'status':'inferred', 'split':'validation', 'promoted':False, 'settings':SETTINGS,
    'labels_sha256':file_hash(labels_path), 'image_sha256':report['image_sha256'],
    'worker_sha256':'a'*64, 'benchmarker_sha256':'b'*64, 'models_config_sha256':'c'*64,
    'browser_version':'fixture', 'assets_sha256':{'worker.js':'a'*64},
    'application_sources_sha256':{'robot-camera.js':'d'*64}, 'models':{'candidate':browser_model}}
benchmark.write_text(json.dumps(browser_report))
source_bytes = benchmark.read_bytes()
browser_args = SimpleNamespace(dataset=dataset, model=model, benchmark=benchmark, mode='regular',
    geometry='original', input_manifest=None, browser_model='candidate', output=root/'browser-calibration')
browser_result = calibrate(browser_args)
assert browser_result['browser_model'] == 'candidate'
assert browser_result['input_geometry'] == 'application'
assert browser_result['baseline']['true_positives'] == 4 and browser_result['ui_baseline']['false_positives'] == 1
assert browser_result['per_class_summary']['matched_by_class'] == browser_result['baseline']['matched_by_class']
assert not browser_result['promoted'] and not browser_result['deployment_ready'] and not browser_result['retrained']
assert browser_result['inference_settings']['category_selection'] == 'first_category_as_application'
assert browser_result['source_sha256'][str(benchmark)] == file_hash(benchmark)
assert browser_result['review_files'] == ['review-2.svg']
assert browser_result['class_conflict_summary'] == {'0.2':{'pairs':6,'images':1},'0.5':{'pairs':6,'images':1}}
conflicts = browser_result['ui_reviews'][0]['class_conflicts']['0.5']
assert len(conflicts) == 6 and conflicts[0]['prediction_indices'] == [0,1]
assert all(pair['confidence_gap'] == 0 and pair['iou'] == 1 for pair in conflicts)
assert browser_result['class_conflict_settings']['prediction_indices'] == 'zero_based_in_this_image_saved_worker_predictions'
assert browser_result['ui_reviews'][0]['false_positives'][0]['reason'] == 'background_or_poor_overlap'
svg = (browser_args.output/'review-2.svg').read_text()
assert 'ground_truth' in svg and 'validation_selected_policy' in svg and 'worker_floor_0.2' in svg and '#00ff88' in svg
saved_output = (browser_args.output/'calibration.json').read_bytes()
def browser_rejected(values=browser_args):
    try:
        calibrate(values)
    except (TrainingError, ValueError):
        return
    raise AssertionError('Invalid browser calibration accepted')
browser_rejected()
assert (browser_args.output/'calibration.json').read_bytes() == saved_output
browser_args.output = root/'invalid-browser'
for change in ['test', 'settings', 'labels', 'images', 'coverage', 'generic', 'limit', 'score', 'category']:
    invalid = copy.deepcopy(browser_report)
    if change == 'test': invalid['split'] = 'test'
    elif change == 'settings': invalid['settings']['category_selection'] = 'highest_score_per_detection'
    elif change == 'labels': invalid['labels_sha256'] = '0'*64
    elif change == 'images': invalid['image_sha256'] = {}
    elif change == 'coverage': invalid['models']['candidate']['frames'] *= 2
    elif change == 'generic':
        invalid['models']['candidate']['label_space'] = 'generic'
        for p in invalid['models']['candidate']['predictions']: p['category_id'] = None
    elif change == 'limit': invalid['models']['candidate']['frames'][0]['detections'] *= 3
    elif change == 'score': invalid['models']['candidate']['predictions'][0]['score'] = .1
    elif change == 'category': invalid['models']['candidate']['predictions'][0]['label'] = 'unknown'
    benchmark.write_text(json.dumps(invalid))
    browser_rejected()
    assert not browser_args.output.exists()
benchmark.write_bytes(source_bytes)
for change in [{'browser_model':'missing'}, {'geometry':'padded'}, {'input_manifest':manifest_path}, {'mode':'fast'},
               {'output':sources/'nested-browser'}, {'output':dataset/'nested-browser'},
               {'output':Path(sys.argv[1]).resolve().parents[1]/'public/unsafe-browser-calibration'}]:
    invalid_args = SimpleNamespace(**{**vars(browser_args), **change})
    browser_rejected(invalid_args)
    assert not invalid_args.output.exists()
model.write_bytes(b'changed browser model')
browser_rejected()
assert not browser_args.output.exists()
model.write_bytes(original)
original_policies = calibration.confidence_policies
def change_source(*values):
    result = original_policies(*values)
    benchmark.write_bytes(source_bytes+b' ')
    return result
with patch.object(calibration, 'confidence_policies', side_effect=change_source):
    browser_rejected()
assert not browser_args.output.exists()
benchmark.write_bytes(source_bytes)
assert main(['--dataset',str(dataset), '--benchmark',str(benchmark), '--model',str(model),
    '--browser-model','candidate', '--output',str(root/'browser-cli')]) == 0
assert benchmark.read_bytes() == source_bytes and model.read_bytes() == original
overlapping_report = copy.deepcopy(browser_report)
overlapping_report['models']['candidate']['predictions'].pop()
overlapping_report['models']['candidate']['frames'][0]['detections'].pop()
benchmark.write_text(json.dumps(overlapping_report))
overlapping_args = SimpleNamespace(**{**vars(browser_args), 'output':root/'browser-overlapping-correct'})
overlapping_result = calibrate(overlapping_args)
assert overlapping_result['ui_baseline']['true_positives'] == 4
assert overlapping_result['ui_baseline']['false_positives'] == 0
assert overlapping_result['ui_baseline']['false_negatives'] == 0
assert overlapping_result['review_files'] == ['review-2.svg'], 'Legitimate overlapping objects still deserve a review, not suppression'
assert overlapping_result['class_conflict_summary']['0.5']['pairs'] == 6
assert overlapping_result['per_class_summary']['true_positives'] == 4
for prediction in overlapping_report['models']['candidate']['predictions']:
    prediction['score'] = .4
benchmark.write_text(json.dumps(overlapping_report))
low_args = SimpleNamespace(**{**vars(browser_args), 'output':root/'browser-worker-only-conflicts'})
low_result = calibrate(low_args)
assert low_result['class_conflict_summary'] == {'0.2':{'pairs':6,'images':1},'0.5':{'pairs':0,'images':0}}
assert low_result['per_class_summary']['true_positives'] == 4 and low_result['ui_baseline']['true_positives'] == 0
assert 'worker_floor_0.2' in (low_args.output/'review-2.svg').read_text()
padded_report = copy.deepcopy(browser_report)
padded_report['models']['candidate']['input_geometry'] = 'training_pad'
padded_report['models']['candidate']['frames'][0].update(width=256,height=256,content_width=256,content_height=205)
benchmark.write_text(json.dumps(padded_report))
padded_args = SimpleNamespace(**{**vars(browser_args), 'output':root/'browser-padded-calibration'})
padded_result = calibrate(padded_args)
assert padded_result['input_geometry'] == 'training_pad'
assert padded_result['per_class_summary'] == browser_result['per_class_summary']
assert not padded_result['promoted'] and not padded_result['deployment_ready']
assert json.loads((padded_args.output/'calibration.json').read_text())['input_geometry'] == 'training_pad'
benchmark.write_bytes(source_bytes)
assert 'tensorflow' not in sys.modules and 'mediapipe' not in sys.modules
print('Private calibration command checks passed')
PYTHON;
        $process = new Process([...$command, '-', resource_path('ml'), $this->directory]);
        $process->setInput($code)->setTimeout(30)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertStringContainsString('Private calibration command checks passed', $process->getOutput());
    }

    public function test_export_validation_records_tflite_results_separately_and_preserves_artifacts_on_failure(): void
    {
        $python = (new ExecutableFinder)->find(PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');
        if ($python === null) {
            $this->markTestSkipped('Python is required for export validation tests.');
        }
        $command = PHP_OS_FAMILY === 'Windows' ? [$python, '-3.11'] : [$python];
        $code = <<<'PYTHON'
import copy, hashlib, io, json, sys
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
sys.path.insert(0, sys.argv[1])
import train_waste
assert hasattr(train_waste, 'evaluate_export'), 'Export must automatically record TFLite validation'
root = Path(sys.argv[2]).resolve()
model_bytes = b'exported-model-fixture'
model_hash = hashlib.sha256(model_bytes).hexdigest()
template = {
    'status': 'trained', 'evaluation_backend': 'Keras (before TFLite export)',
    'evaluation': {'validation': {'coco_metrics': {'AP': 0.25}}},
    'model': {'file': 'waste-detector.tflite', 'sha256': model_hash},
    'tflite_evaluation': {'status': 'not_evaluated', 'accuracy': None}, 'promoted': False,
}
result = {
    'status': 'evaluated', 'backend': 'MediaPipe Tasks Python / TFLite CPU',
    'split': 'validation', 'model_sha256': model_hash,
    'settings': {'score_threshold': 0.001, 'max_results': 100},
    'metrics': {'AP': 0.125, 'AP_by_class': {'plastic_bottle': 0.1}},
    'predictions': [], 'promoted': False,
}
for scenario in ('success', 'failure', 'interrupted', 'wrong_model', 'nonfinite', 'occupied'):
    output = root / scenario
    output.mkdir()
    (output / 'waste-detector.tflite').write_bytes(model_bytes)
    (output / 'float_ckpt.index').write_bytes(b'checkpoint-fixture')
    metrics = copy.deepcopy(template)
    (output / 'metrics.json').write_text(json.dumps(metrics))
    args = SimpleNamespace(output=output, dataset=root / 'dataset')
    report = output / 'tflite-validation/tflite-evaluation.json'
    if scenario == 'occupied':
        report.parent.mkdir()
        report.write_text('existing-report')

    def inference(request):
        assert request.split == 'validation', 'Automatic evaluation must not use the test split'
        assert request.model == output / 'waste-detector.tflite'
        assert request.dataset == args.dataset
        assert request.output == report.parent
        on_disk = json.loads((output / 'metrics.json').read_text())
        assert on_disk['tflite_evaluation']['status'] == 'running'
        if scenario == 'failure':
            raise RuntimeError('Inference failed')
        if scenario == 'interrupted':
            raise KeyboardInterrupt()
        response = copy.deepcopy(result)
        if scenario == 'wrong_model':
            response['model_sha256'] = 'wrong-model-hash'
        if scenario == 'nonfinite':
            response['metrics']['AP'] = float('nan')
        return response

    # TensorFlow is Linux-only and expensive; exercise real persistence with controlled inference outcomes.
    with patch('train_waste.run_tflite_evaluation', side_effect=inference):
        try:
            train_waste.evaluate_export(args, metrics)
        except (train_waste.TrainingError, KeyboardInterrupt):
            assert scenario != 'success'
        else:
            assert scenario == 'success', 'Failed validation must fail the command'
    saved = json.loads((output / 'metrics.json').read_text())
    assert saved['status'] == 'trained'
    assert saved['evaluation'] == template['evaluation'], 'Keras results must remain separate'
    assert saved['promoted'] is False
    assert (output / 'waste-detector.tflite').read_bytes() == model_bytes
    assert (output / 'float_ckpt.index').read_bytes() == b'checkpoint-fixture'
    evaluation = saved['tflite_evaluation']
    if scenario == 'success':
        assert evaluation['status'] == 'evaluated'
        assert evaluation['split'] == 'validation'
        assert evaluation['metrics']['AP'] == 0.125
        assert evaluation['backend'] == 'MediaPipe Tasks Python / TFLite CPU'
        assert evaluation['model_sha256'] == model_hash
        assert evaluation['report'] == 'tflite-validation/tflite-evaluation.json'
        assert json.loads(report.read_text()) == result
    else:
        assert evaluation['status'] == ('interrupted' if scenario == 'interrupted' else 'failed')
        assert evaluation.get('error')
        assert 'metrics' not in evaluation, 'Failure must not present accuracy as measured'
        if scenario == 'occupied':
            assert report.read_text() == 'existing-report'
        else:
            assert not report.exists(), 'Failure must not leave a completed report'

for fault in ('initial_metrics', 'final_metrics', 'report'):
    output = root / fault
    output.mkdir()
    metrics = copy.deepcopy(template)
    (output / 'metrics.json').write_text(json.dumps(metrics))
    (output / 'waste-detector.tflite').write_bytes(model_bytes)
    report = output / 'tflite-validation/tflite-evaluation.json'
    real_open = io.open
    writes = 0
    injected = False

    class FailingWriter:
        def __init__(self, stream):
            self.stream = stream
        def __enter__(self):
            self.stream.__enter__()
            return self
        def __exit__(self, *args):
            return self.stream.__exit__(*args)
        def __getattr__(self, name):
            return getattr(self.stream, name)
        def write(self, content):
            self.stream.write(content[:10])
            self.stream.flush()
            raise OSError('Simulated partial disk write')

    def failing_open(file, mode='r', *args, **kwargs):
        global writes, injected
        stream = real_open(file, mode, *args, **kwargs)
        if not isinstance(file, (str, Path)) or not any(flag in mode for flag in ('w', 'x')):
            return stream
        name = Path(file).name
        is_metrics = 'metrics.json' in name or ('opener' in kwargs and Path(file) == output)
        is_report = 'tflite-evaluation.json' in name or ('opener' in kwargs and Path(file) == report.parent)
        if is_metrics:
            writes += 1
        should_fail = (
            (fault == 'initial_metrics' and is_metrics and writes == 1)
            or (fault == 'final_metrics' and is_metrics and writes == 2)
            or (fault == 'report' and is_report)
        )
        if should_fail and not injected:
            injected = True
            return FailingWriter(stream)
        return stream

    with patch('train_waste.run_tflite_evaluation', return_value=copy.deepcopy(result)), patch('io.open', side_effect=failing_open):
        try:
            train_waste.evaluate_export(SimpleNamespace(output=output, dataset=root / 'dataset'), metrics)
        except (OSError, train_waste.TrainingError):
            pass
        else:
            raise AssertionError('Disk failure must not succeed')
    assert injected, 'Fault injection did not exercise report persistence'
    saved = json.loads((output / 'metrics.json').read_text())
    assert saved['evaluation'] == template['evaluation'], 'Disk failure must preserve valid Keras metrics'
    assert (output / 'waste-detector.tflite').read_bytes() == model_bytes
    if fault == 'report':
        assert not report.exists(), 'Partial report must not be published'
    assert not list(output.rglob('*.tmp')), 'Failed temporary writes must be cleaned up'

import os, subprocess
for scenario in ('success', 'native_exit', 'timeout', 'interrupted', 'missing_report', 'malformed_report', 'wrong_model', 'occupied_log'):
    output = root / ('worker ' + scenario)
    output.mkdir()
    (output / 'waste-detector.tflite').write_bytes(model_bytes)
    (output / 'float_ckpt.index').write_bytes(b'checkpoint-fixture')
    metrics = copy.deepcopy(template)
    (output / 'metrics.json').write_text(json.dumps(metrics))
    args = SimpleNamespace(output=output, dataset=root / 'dataset')
    log = output / 'tflite-validation.log'
    if scenario == 'occupied_log':
        log.write_bytes(b'existing diagnostic log')
    calls = []
    def worker(command, **kwargs):
        calls.append(command)
        assert command[:2] == [sys.executable, '-u']
        assert Path(command[2]).name == 'evaluate_waste.py'
        assert command[command.index('--model')+1] == str(output / 'waste-detector.tflite')
        assert command[command.index('--split')+1] == 'validation'
        assert kwargs['stdin'] == subprocess.DEVNULL and kwargs['stderr'] == subprocess.STDOUT
        assert kwargs['env']['PYTHONFAULTHANDLER'] == '1'
        assert kwargs['timeout'] == 600 and not kwargs['check'] and not kwargs.get('shell', False)
        assert kwargs['env']['ROBOTSYS_PARENT_SETTING'] == 'preserved'
        assert kwargs['env']['OMP_NUM_THREADS'] == '3', 'Preserve explicit parent thread limits'
        kwargs['stdout'].write(b'child diagnostic output\n')
        staging = Path(command[command.index('--output')+1])
        assert staging.parent == output and staging != output / 'tflite-validation'
        if scenario == 'native_exit':
            return SimpleNamespace(returncode=-11)
        if scenario == 'timeout':
            raise subprocess.TimeoutExpired(command, 600)
        if scenario == 'interrupted':
            raise KeyboardInterrupt()
        if scenario != 'missing_report':
            response = copy.deepcopy(result)
            if scenario == 'wrong_model':
                response['model_sha256'] = 'changed'
            content = '{invalid' if scenario == 'malformed_report' else json.dumps(response)
            (staging / 'tflite-evaluation.json').write_text(content)
        return SimpleNamespace(returncode=0)
    with patch('train_waste.subprocess.run', side_effect=worker), patch.dict(os.environ, {
        'ROBOTSYS_PARENT_SETTING': 'preserved', 'OMP_NUM_THREADS': '3',
    }):
        try:
            train_waste.evaluate_export(args, metrics)
        except (train_waste.TrainingError, KeyboardInterrupt):
            assert scenario != 'success'
        else:
            assert scenario == 'success'
    saved = json.loads((output / 'metrics.json').read_text())
    evaluation = saved['tflite_evaluation']
    assert saved['evaluation'] == template['evaluation'] and saved['promoted'] is False
    assert (output / 'float_ckpt.index').read_bytes() == b'checkpoint-fixture'
    assert (output / 'waste-detector.tflite').read_bytes() == model_bytes
    assert evaluation['process']['log'] == 'tflite-validation.log'
    assert evaluation['process']['timed_out'] == (scenario == 'timeout')
    assert not list(output.glob('.tflite-evaluation-*')), 'Child staging must be cleaned up'
    report = output / 'tflite-validation/tflite-evaluation.json'
    if scenario == 'success':
        assert evaluation['status'] == 'evaluated' and evaluation['process']['exit_code'] == 0
        assert json.loads(report.read_text())['process']['exit_code'] == 0
    else:
        assert evaluation['status'] == ('interrupted' if scenario == 'interrupted' else 'failed')
        assert not report.exists(), 'Unverified child reports must never be published'
    if scenario == 'native_exit':
        assert evaluation['process']['exit_code'] == -11 and 'code -11' in evaluation['error']
    if scenario == 'occupied_log':
        assert log.read_bytes() == b'existing diagnostic log' and not calls
    else:
        assert log.read_bytes() == b'child diagnostic output\n' and len(calls) == 1

# Verify actual OS-level exit and timeout handling without loading the native inference library.
original_run = subprocess.run
for scenario in ('exit', 'timeout'):
    output = root / ('actual child ' + scenario)
    output.mkdir()
    request = SimpleNamespace(model=output / 'model.tflite', dataset=root / 'dataset',
                              output=output / 'tflite-validation', split='validation')
    code = "import os; print('before native exit', flush=True); os._exit(139)" if scenario == 'exit' else (
        "import time; print('before timeout', flush=True); time.sleep(5)"
    )
    def child(command, **kwargs):
        return original_run([sys.executable, '-u', '-c', code], **kwargs)
    with patch('train_waste.subprocess.run', side_effect=child):
        try:
            train_waste.run_tflite_evaluation(request, timeout_seconds=1)
        except train_waste.TFLiteEvaluationError as error:
            assert error.diagnostics['exit_code'] == (139 if scenario == 'exit' else None)
            assert error.diagnostics['timed_out'] == (scenario == 'timeout')
        else:
            raise AssertionError('OS-level failure must not succeed')
    assert (output / 'tflite-validation.log').read_bytes().startswith(b'before ')
    assert not list(output.glob('.tflite-evaluation-*'))
print('Automatic export validation checks passed')
PYTHON;
        $process = new Process([...$command, '-', resource_path('ml'), $this->directory]);
        $process->setInput($code)->setTimeout(30)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Automatic export validation checks passed', $process->getOutput());
    }

    public function test_training_command_validates_after_export_and_returns_failure_when_validation_fails(): void
    {
        $this->trainingFixture();
        $python = (new ExecutableFinder)->find(PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');
        if ($python === null) {
            $this->markTestSkipped('Python is required for training command tests.');
        }
        $command = PHP_OS_FAMILY === 'Windows' ? [$python, '-3.11'] : [$python];
        $code = <<<'PYTHON'
import hashlib, json, shutil, sys
from pathlib import Path
from types import SimpleNamespace as Value
from unittest.mock import Mock, patch
sys.path.insert(0, sys.argv[1])
import train_waste
root = Path(sys.argv[2]).resolve()

def dataset(folder, **kwargs):
    labels = json.loads((Path(folder) / 'labels.json').read_text())
    return Value(size=len(labels['images']), label_names=[item['name'] for item in labels['categories']],
                 split=Path(folder).name)

def copy_link(path, target, target_is_directory=False):
    # Portable staging for Windows tests without symlink privileges.
    shutil.copytree(target, path) if target_is_directory else shutil.copyfile(target, path)

class Model:
    training_selection = {'completed_epochs': 1, 'best_epoch': 1, 'best_validation_ap': .25,
                          'policy': {'monitor': 'val_coco_AP', 'mode': 'max'}, 'stopped_early': False}
    def evaluate(self, data, batch_size):
        evaluated.append(data.split)
        return 0.5, {'AP': 0.25}
    def export_model(self, model_name):
        (output / model_name).write_bytes(b'model-fixture')
        (output / 'metadata.json').write_text('{}')
        (output / 'float_ckpt.index').write_bytes(b'checkpoint-fixture')

api = Value(
    Dataset=Value(from_coco_folder=dataset), HParams=Value, ObjectDetectorOptions=Value,
    SupportedModels=Value(MOBILENET_V2='MOBILENET_V2'),
)
tensorflow = Value(keras=Value(utils=Value(set_random_seed=lambda seed: None)))
with patch('train_waste.check_environment') as environment:
    for flag, values in {
        '--learning-rate': ('0', '-1', 'nan', 'inf'),
        '--cosine-decay-alpha': ('-.1', '1.1', 'nan', 'inf'),
        '--cosine-decay-epochs': ('0', '-1', '1.5'),
        '--focal-loss-gamma': ('0', '.5', '3.1', 'nan', 'inf', 'True'),
        '--training-geometry': ('stretch', 'training_pad', '256', 'True'),
        '--training-pipeline': ('full_shuffle', 'random', 'True'),
        '--selection-metric': ('primary', 'ap50', 'True'),
        '--class-repeat': ('paper=0', 'paper=4', 'paper=1.5', 'background=2', 'aerosol=2', 'paper'),
        '--image-repeat': ('1=0', '1=4', '1=1.5', '-1=2', 'True=2', '1'),
    }.items():
        for value in values:
            assert train_waste.main([flag, value]) == 1
    environment.assert_not_called()

for fails, evaluate_test, repeats in ((False, False, False), (True, False, False), (False, True, False),
                                      (False, False, 'class'), (False, False, 'image'), (False, False, 'gamma'),
                                      (False, False, 'geometry'), (False, False, 'shuffle'), (False, False, 'primary')):
    evaluated = []
    output = root / (f'{repeats}-repeated-training' if repeats else 'test-evaluated' if evaluate_test else 'failed-validation' if fails else 'successful-validation')
    def inference(request):
        exported = request.model.read_bytes()
        assert exported == b'model-fixture', 'Validation must follow export'
        assert request.split == 'validation'
        if fails:
            raise RuntimeError('Inference unavailable')
        return {
            'status': 'evaluated', 'split': 'validation', 'backend': 'MediaPipe Tasks Python / TFLite CPU',
            'model_sha256': hashlib.sha256(exported).hexdigest(), 'promoted': False,
            'settings': {'score_threshold': 0.001, 'max_results': 100},
            'metrics': {'AP': 0.125}, 'predictions': [],
        }
    with (
        patch.dict(sys.modules, {'tensorflow': tensorflow}),
        patch('train_waste.check_environment', return_value={'tensorflow': '2.15.1'}),
        patch('train_waste.load_training_api', return_value=api),
        patch('train_waste.checkpoint_detector', return_value=Value(create=Mock(return_value=Model()))) as factory,
        patch('train_waste.apply_training_geometry', side_effect=lambda api, tf, data, settings: data) as geometry_transform,
        patch('train_waste.apply_training_sampling', side_effect=lambda api, tf, data, settings: Value(size=settings['images_per_epoch'],label_names=data.label_names,split=data.split)) as sampler,
        patch('train_waste.apply_training_pipeline', side_effect=lambda api, tf, data, settings: Value(**vars(data),shuffle_buffer={'records':2}) if settings['shuffle'] else data) as pipeline_transform,
        patch.object(Path, 'symlink_to', new=copy_link),
        patch('train_waste.run_tflite_evaluation', side_effect=inference),
    ):
        status = train_waste.main([
            '--dataset', str(root / 'dataset'), '--output', str(output), '--epochs', '1', '--batch-size', '1',
            '--learning-rate', '.15', '--cosine-decay-alpha', '.1', '--cosine-decay-epochs', '8',
        ] + (['--evaluate-test'] if evaluate_test else []) + (['--class-repeat', 'paper=2', '--class-repeat', 'metal_can=2'] if repeats == 'class' else ['--image-repeat','1=2'] if repeats == 'image' else ['--focal-loss-gamma','1.0'] if repeats == 'gamma' else ['--training-geometry','camera_square'] if repeats == 'geometry' else ['--training-pipeline','bounded_shuffle','--class-repeat','paper=2'] if repeats == 'shuffle' else ['--selection-metric','primary_ap'] if repeats == 'primary' else []))
        assert factory.call_args.kwargs['validation_data'].split == 'validation'
        assert factory.call_args.kwargs['batch_size'] == 1
        assert factory.call_args.args[4]['monitor'] == ('val_primary_AP' if repeats == 'primary' else 'val_coco_AP')
        if repeats == 'primary':
            assert [image['id'] for image in factory.call_args.kwargs['validation_labels']['images']] == [2]
            assert factory.call_args.args[4]['primary_label'] == train_waste.primary_label_recipe()
        else:
            assert factory.call_args.kwargs['validation_labels'] is None
        assert factory.call_args.kwargs['objective']['gamma'] == (1.0 if repeats == 'gamma' else 1.5)
        assert sampler.call_count == 1 and sampler.call_args.args[2].split == 'train'
        assert geometry_transform.call_count == 1 and geometry_transform.call_args.args[2].split == 'train'
        assert geometry_transform.call_args.args[3]['mode'] == ('camera_square' if repeats == 'geometry' else 'native')
        assert pipeline_transform.call_count == 1 and pipeline_transform.call_args.args[2].split == 'train'
    assert status == (1 if fails else 0)
    metrics = json.loads((output / 'metrics.json').read_text())
    assert metrics['tflite_evaluation']['status'] == ('failed' if fails else 'evaluated')
    assert metrics['evaluation']['validation']['coco_metrics']['AP'] == 0.25
    assert evaluated == (['validation', 'test'] if evaluate_test else ['validation'])
    assert ('test' in metrics['evaluation']) is evaluate_test
    assert metrics['test_evaluation']['status'] == ('evaluated' if evaluate_test else 'not_evaluated')
    manifest = json.loads((output / 'run.json').read_text())
    assert manifest['evaluate_test'] is evaluate_test
    assert metrics['optimizer'] == manifest['optimizer']
    assert metrics['training_objective'] == manifest['training_objective']
    assert metrics['training_geometry'] == manifest['training_geometry']
    assert manifest['training_geometry']['mode'] == ('camera_square' if repeats == 'geometry' else 'native')
    assert manifest['training_objective']['gamma'] == (1.0 if repeats == 'gamma' else 1.5)
    assert metrics['training_sampling'] == manifest['training_sampling']
    assert metrics['training_pipeline'] == manifest['training_pipeline']
    assert manifest['training_pipeline']['shuffle'] is (repeats == 'shuffle')
    assert (output/'input-pipeline.json').exists() is (repeats == 'shuffle')
    assert metrics['training_sampling']['method'] == ('bounded_whole_image_repeat' if repeats in ('class','shuffle') else 'bounded_targeted_image_repeat' if repeats == 'image' else 'none')
    assert metrics['training_sampling']['image_repeats'] == ({'1':2} if repeats == 'image' else {})
    assert metrics['training_sampling']['images_per_epoch'] == (2 if repeats in ('class','image','shuffle') else 1)
    assert manifest['optimizer']['learning_rate'] == .15
    assert manifest['optimizer']['cosine_decay_alpha'] == .1
    assert manifest['optimizer']['cosine_decay_epochs'] == 8
    hparams = factory.return_value.create.call_args.kwargs['options'].hparams
    assert hparams.learning_rate == .15 and hparams.cosine_decay_alpha == .1 and hparams.cosine_decay_epochs == 8
    assert not hasattr(hparams,'focal_loss_gamma')
    assert not hasattr(hparams,'training_geometry')
    assert not hasattr(hparams,'selection_metric')
    assert hparams.shuffle is (repeats == 'shuffle'), 'The actual detector options must enable the requested shuffle'
    if repeats in ('class','image','shuffle'):
        assert hparams.steps_per_epoch == 2
    else:
        assert not hasattr(hparams, 'steps_per_epoch')
    assert metrics['promoted'] is False
    assert metrics['completed_epochs'] == 1 and metrics['selection']['best_epoch'] == 1
    assert (output / 'waste-detector.tflite').read_bytes() == b'model-fixture'
    assert (output / 'float_ckpt.index').read_bytes() == b'checkpoint-fixture'
    assert (output / 'tflite-validation/tflite-evaluation.json').exists() is not fails
print('Training command export validation passed')
PYTHON;
        $process = new Process([...$command, '-', resource_path('ml'), $this->directory]);
        $process->setInput($code)->setTimeout(30)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Training command export validation passed', $process->getOutput());
    }

    private function checkDataset(): Process
    {
        $python = (new ExecutableFinder)->find(PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');
        if ($python === null) {
            $this->markTestSkipped('Python is required for dataset preflight tests.');
        }
        $command = PHP_OS_FAMILY === 'Windows' ? [$python, '-3.11'] : [$python];
        $process = new Process([...$command, resource_path('ml/train_waste.py'), '--check-dataset', '--dataset', $this->directory.'/dataset']);
        $process->setTimeout(30)->run();

        return $process;
    }

    private function annotations(): array
    {
        return [
            'images' => [['id' => 1, 'width' => 100, 'height' => 80, 'file_name' => 'batch/one.jpg', 'flickr_url' => 'https://live.staticflickr.com/1/12345_image.jpg']],
            'categories' => [
                ['id' => 4, 'name' => 'Clear plastic bottle'], ['id' => 5, 'name' => 'Plastic film'],
                ['id' => 6, 'name' => 'Normal paper'], ['id' => 7, 'name' => 'Drink can'], ['id' => 8, 'name' => 'Glass bottle'],
            ],
            'annotations' => array_map(fn (int $id): array => ['id' => $id, 'image_id' => 1, 'category_id' => $id, 'bbox' => [10, 10, 20, 30]], range(4, 8)),
        ];
    }

    private function licenses(): array
    {
        return ['12345' => [
            'license_url' => 'https://creativecommons.org/licenses/by/4.0/',
            'source_url' => 'https://www.flickr.com/photos/owner/12345/',
            'author' => 'Fixture photographer', 'verified' => true,
        ]];

    }
}
