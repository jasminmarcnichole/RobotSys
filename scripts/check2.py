import json
p=r"E:\Laravel\RobotSys\storage\app\private\waste-training\taco-realdirt-hitl-can-reviewed-20261004\summary.json"
d=json.load(open(p))
# Try common keys
for k in ["num_images","images","counts","annotations_by_class","categories"]:
    if k in d:
        print(k, d[k])
        break
else:
    print(list(d.keys())[:10])
