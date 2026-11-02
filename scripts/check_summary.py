import json
p=r"E:\Laravel\RobotSys\storage\app\private\waste-training\taco-realdirt-hitl-can-reviewed-20261004\summary.json"
d=json.load(open(p))
print(d.get("num_images"), d.get("categories"))
