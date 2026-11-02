path = r"E:\Laravel\RobotSys\resources\ml\train_waste.py"
with open(path) as f:
    s = f.read()

# Update missing check
old = "        missing = [CLASSES[key] for key in (1, 2, 3, 4) if not class_counts[key]]"
new = "        missing = [CLASSES[key] for key in (1, 2, 3, 4, 5, 6) if not class_counts[key]]"
if old in s:
    s2 = s.replace(old, new)
    with open(path, "w") as f:
        f.write(s2)
    print("ok")
else:
    print("no")
