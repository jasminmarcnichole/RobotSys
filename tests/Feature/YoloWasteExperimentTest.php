<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class YoloWasteExperimentTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/yolo-'.Str::uuid());
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        $root = realpath(storage_path('framework/testing'));
        $target = realpath($this->directory);
        if ($root !== false && $target !== false && str_starts_with($target, $root.DIRECTORY_SEPARATOR)) {
            File::deleteDirectory($target);
        }
        parent::tearDown();
    }

    public function test_yolo_reports_require_real_custom_labels_valid_boxes_and_frozen_limits(): void
    {
        $this->runPython(<<<'PY'
import copy, sys
sys.path.insert(0,sys.argv[1])
import trash_detection as yolo
from train_waste import TrainingError
def rejects(callback):
    try: callback()
    except TrainingError: return
    raise AssertionError('Unsafe report accepted')
labels={'images':[{'id':7,'width':100,'height':80}]}
row={'image_id':7,'category_id':1,'label':'plastic_bottle','bbox':[10,10,20,20],'score':.8}
report={'schema_version':1,'status':'inferred','backend':'Ultralytics Python CPU','split':'validation',
        'names':yolo.NAMES,'settings':yolo.SETTINGS,'promoted':False,'deployment_ready':False,
        'model_sha256':'a'*64,'predictions':[row]}
yolo.validate_predictions(report,labels)
yolo.verify_names(dict(enumerate(yolo.NAMES)))
rejects(lambda:yolo.verify_names(['person','bottle']))
rejects(lambda:yolo.verify_names(list(reversed(yolo.NAMES))))
rejects(lambda:yolo.verify_names({1:'plastic_bottle'}))
rejects(lambda:yolo.verify_names(None))
for change in ({'score':float('nan')},{'score':1.01},{'score':.1},{'category_id':0},
               {'label':'bottle'},{'image_id':999},{'bbox':[90,10,20,20]},{'bbox':[10,10,-2,20]}):
    bad=copy.deepcopy(report);bad['predictions'][0].update(change)
    rejects(lambda:yolo.validate_predictions(bad,labels))
for change in ({'split':'test'},{'promoted':True},{'deployment_ready':True},{'model_sha256':'invalid'},
               {'predictions':[row]*11},{'predictions':[None]},{'names':['bottle']}):
    bad=copy.deepcopy(report);bad.update(change)
    rejects(lambda:yolo.validate_predictions(bad,labels))
bad=copy.deepcopy(report);bad['settings']['ui_score_threshold']=.1
rejects(lambda:yolo.validate_predictions(bad,labels))
print('YOLO report guards passed')
PY);
    }

    public function test_yolo_conversion_preserves_box_geometry_and_all_class_ids(): void
    {
        $this->runPython(<<<'PY'
import sys
from pathlib import Path
sys.path.insert(0,sys.argv[1])
import trash_detection as yolo
from train_waste import TrainingError
root=Path(sys.argv[2]);label=root/'9.txt'
labels={'images':[{'id':9,'width':100,'height':80}],
        'annotations':[{'image_id':9,'category_id':index,'bbox':[10,10,20,20]} for index in range(1,5)]}
correct=''.join(f'{index} 0.2 0.25 0.2 0.25\n' for index in range(4))
label.write_text(correct)
yolo.verify_converted_boxes(labels,root)
for wrong in (correct.replace('3 0.2','2 0.2'),correct.replace('0.25','0.35'),correct+'0 0.2 0.25 0.2 0.25\n'):
    label.write_text(wrong)
    try:yolo.verify_converted_boxes(labels,root)
    except TrainingError:pass
    else:raise AssertionError('Incorrect conversion accepted')
print('YOLO conversion guards passed')
PY);
    }

    public function test_yolo_prepared_manifest_covers_every_file_and_protects_source_splits(): void
    {
        $this->runPython(<<<'PY'
import copy, hashlib, json, sys
from pathlib import Path
sys.path.insert(0,sys.argv[1])
import trash_detection as yolo
from train_waste import TrainingError,validate_dataset
root=Path(sys.argv[2]);source=root/'source';prepared=root/'prepared';prepared.mkdir()
def write(path,data):path.parent.mkdir(parents=True,exist_ok=True);path.write_text(json.dumps(data))
def rejects(callback):
    try:callback()
    except TrainingError:return
    raise AssertionError('Tampered manifest accepted')
write(source/'summary.json',{'status':'prepared','full_dataset':True,'license_verified_images':12})
for offset,split in enumerate(('train','validation','test')):
    images=[];annotations=[]
    for category in range(1,5):
        identity=offset*10+category;photo=str(1000+identity);content=b'\xff\xd8\xff'+f'photo-{identity}'.encode()
        path=source/split/'data'/f'{identity}.jpg';path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(content)
        images.append({'id':identity,'file_name':path.name,'width':100,'height':80,'sha256':hashlib.sha256(content).hexdigest(),
                       'source_photo_id':photo,'attribution':{'verified':True,'author':'Fixture',
                       'license_url':'https://creativecommons.org/licenses/by/4.0/',
                       'source_url':f'https://www.flickr.com/photos/owner/{photo}/'}})
        annotations.append({'id':identity,'image_id':identity,'category_id':category,'bbox':[10,10,20,20]})
    write(source/split/'labels.json',{'images':images,'annotations':annotations,
          'categories':[{'id':key,'name':value} for key,value in yolo.CLASSES.items()]})
audit=validate_dataset(source)
config={'path':str(prepared/'converted'),'train':'images/train','val':'images/val','names':yolo.NAMES}
write(prepared/'data.yaml',config)
files={'data.yaml':yolo.file_hash(prepared/'data.yaml')};source_images={}
for split,target in (('train','train'),('validation','val')):
    labels=json.loads((source/split/'labels.json').read_text())
    for image in labels['images']:
        original=source/split/'data'/image['file_name']
        copied=prepared/'converted/images'/target/image['file_name'];copied.parent.mkdir(parents=True,exist_ok=True);copied.write_bytes(original.read_bytes())
        label=prepared/'converted/labels'/target/f"{image['id']}.txt";label.parent.mkdir(parents=True,exist_ok=True);label.write_text('0 .2 .25 .2 .25\n')
        source_images[f"{split}/{image['file_name']}"]=yolo.file_hash(original)
        for path in (copied,label):files[path.relative_to(prepared).as_posix()]=yolo.file_hash(path)
manifest={'schema_version':1,'status':'prepared','source_dataset':str(source),'names':yolo.NAMES,'promoted':False,
          'protected_test_exported':False,'counts':audit['counts'],'configuration':config,'files_sha256':files,
          'source_images_sha256':source_images,'source_labels_sha256':{s:yolo.file_hash(source/s/'labels.json') for s in ('train','validation','test')}}
write(prepared/'manifest.json',manifest)
yolo.verify_prepared(prepared)
bad=copy.deepcopy(manifest);bad['files_sha256'].pop(next(key for key in files if key!='data.yaml'));write(prepared/'manifest.json',bad)
rejects(lambda:yolo.verify_prepared(prepared))
write(prepared/'manifest.json',manifest)
label.write_text('changed');rejects(lambda:yolo.verify_prepared(prepared));label.write_text('0 .2 .25 .2 .25\n')
write(prepared/'data.yaml',{**config,'test':'images/test'});rejects(lambda:yolo.verify_prepared(prepared));write(prepared/'data.yaml',config)
write(source/'summary.json',{'status':'prepared','full_dataset':True,'evaluation_only':True});rejects(lambda:yolo.verify_prepared(prepared))
assert not (prepared/'converted/images/test').exists()
print('YOLO manifest guards passed')
PY);
    }

    public function test_yolo_training_uses_only_the_prepared_split_and_preserves_existing_runs(): void
    {
        $this->runPython(<<<'PY'
import sys
from pathlib import Path
from types import SimpleNamespace
sys.path.insert(0,sys.argv[1])
import trash_detection as yolo
from train_waste import TrainingError
root=Path(sys.argv[2]);data=root/'data';data.mkdir();(data/'manifest.json').write_text('{}')
weights=root/'initial.pt';weights.write_bytes(b'initial');output=root/'run'
calls=[]
class FakeModel:
    names=dict(enumerate(yolo.NAMES))
    def __init__(self,path):pass
    def train(self,**options):
        calls.append(options);best=Path(options['project'])/options['name']/'weights/best.pt'
        best.parent.mkdir();best.write_bytes(b'trained')
        return SimpleNamespace(results_dict={'metrics/mAP50(B)':.25})
yolo.yolo_api=lambda:FakeModel
yolo.versions=lambda:{'ultralytics':'fixture'}
yolo.verify_prepared=lambda path:{'counts':{'train':{'images':4},'validation':{'images':4}}}
args=SimpleNamespace(dataset=data,output=output,weights=weights,epochs=3,batch_size=4,imgsz=320)
result=yolo.train(args)
assert result['status']=='trained' and result['promoted'] is False and result['test_inference'] is False
assert calls[0]['device']=='cpu' and calls[0]['workers']==0 and calls[0]['val'] is True
assert calls[0]['data']==str(data/'data.yaml') and calls[0]['imgsz']==320
assert 'test' not in calls[0] and calls[0]['cache'] is False and weights.read_bytes()==b'initial'
before=(output/'metrics.json').read_bytes()
try:yolo.train(args)
except TrainingError:pass
else:raise AssertionError('Existing run overwritten')
assert (output/'metrics.json').read_bytes()==before
print('YOLO training guards passed')
PY);
    }

    public function test_yolo_evaluation_preserves_frozen_settings_and_rejects_changed_weights_or_dimensions(): void
    {
        $this->runPython(<<<'PY'
import json, sys
from pathlib import Path
from types import SimpleNamespace
sys.path.insert(0,sys.argv[1])
import trash_detection as yolo
from train_waste import TrainingError
root=Path(sys.argv[2]);source=root/'source';prepared=root/'prepared';prepared.mkdir()
photo=source/'validation/data/1.jpg';photo.parent.mkdir(parents=True);photo.write_bytes(b'photo')
labels={'images':[{'id':1,'file_name':'1.jpg','width':100,'height':80}]}
(source/'validation/labels.json').write_text(json.dumps(labels))
weights=root/'custom.pt';weights.write_bytes(b'custom')
calls=[];shape=(80,100);mutate=False
box=SimpleNamespace(cls=SimpleNamespace(item=lambda:2),conf=SimpleNamespace(item=lambda:.7),
                    xyxy=[SimpleNamespace(tolist=lambda:[10,10,30,30])])
class FakeModel:
    names=dict(enumerate(yolo.NAMES))
    def __init__(self,path):pass
    def predict(self,**options):
        calls.append(options)
        if mutate:weights.write_bytes(b'changed')
        return [SimpleNamespace(boxes=[box],orig_shape=shape)]
yolo.yolo_api=lambda:FakeModel
yolo.versions=lambda:{'ultralytics':'fixture'}
yolo.verify_prepared=lambda path:{'source_dataset':str(source)}
args=SimpleNamespace(dataset=prepared,output=root/'good',weights=weights,imgsz=320)
report=yolo.evaluate(args);yolo.validate_predictions(report,labels)
assert report['predictions'][0]['category_id']==3 and report['predictions'][0]['bbox']==[10,10,20,20]
assert report['settings']==yolo.SETTINGS and report['promoted'] is False
assert calls[0]['conf']==.2 and calls[0]['max_det']==10 and calls[0]['device']=='cpu'
assert calls[0]['source']==str(photo) and calls[0]['save'] is False
for directory,dimensions,change in [('wrong-size',(100,80),False),('changed-weights',(80,100),True)]:
    args.output=root/directory;shape=dimensions;mutate=change
    try:yolo.evaluate(args)
    except TrainingError:pass
    else:raise AssertionError('Changed inference inputs accepted')
    assert not args.output.exists()
print('YOLO inference guards passed')
PY);
    }

    private function runPython(string $code): void
    {
        $python = (new ExecutableFinder)->find(PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');
        if ($python === null) {
            $this->markTestSkipped('Python is required for YOLO experiment guards.');
        }
        $command = PHP_OS_FAMILY === 'Windows' ? [$python, '-3.11'] : [$python];
        $process = new Process([...$command, '-', resource_path('ml'), $this->directory]);
        $process->setInput($code)->setTimeout(30)->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
    }
}
