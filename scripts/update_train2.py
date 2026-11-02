path = r"E:\Laravel\RobotSys\resources\ml\train_waste.py"
with open(path) as f:
    s = f.read()

# Update validation message
old = "            raise TrainingError(f\"{split}: categories must match background (0) and the four waste classes (1-4).\")"
new = "            raise TrainingError(f\"{split}: categories must match background (0) and the six waste classes (1-6).\")"
if old in s:
    s2 = s.replace(old, new)
    with open(path, "w") as f:
        f.write(s2)
    print("ok")
else:
    print("no")
