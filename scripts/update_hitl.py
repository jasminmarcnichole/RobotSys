path = r"E:\Laravel\RobotSys\resources\ml\prepare_hitl.py"
with open(path) as f:
    s = f.read()

old = "        CLASSES[key]: sum(a[\"category_id\"] == key for a in labels[\"annotations\"]) for key in (1, 2, 3, 4)}} for split, labels in datasets.items()"
new = "        CLASSES[key]: sum(a[\"category_id\"] == key for a in labels[\"annotations\"]) for key in (1, 2, 3, 4, 5, 6)}} for split, labels in datasets.items()"
if old in s:
    s2 = s.replace(old, new)
    with open(path, "w") as f:
        f.write(s2)
    print("ok")
else:
    print("no")
