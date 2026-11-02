path = r"E:\Laravel\RobotSys\resources\ml\train_waste.py"
with open(path) as f:
    s = f.read()

# Update annotations_by_class
old = "            \"annotations_by_class\": {CLASSES[key]: class_counts[key] for key in (1, 2, 3, 4)},"
new = "            \"annotations_by_class\": {CLASSES[key]: class_counts[key] for key in (1, 2, 3, 4, 5, 6)},"
if old in s:
    s2 = s.replace(old, new)
    with open(path, "w") as f:
        f.write(s2)
    print("ok")
else:
    print("no")
