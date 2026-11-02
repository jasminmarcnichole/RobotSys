path = r"E:\Laravel\RobotSys\resources\ml\train_waste.py"
with open(path) as f:
    s = f.read()

old = "CLASSES = {\n    0: \"background\",\n    1: \"plastic_bottle\",\n    2: \"other_plastic\",\n    3: \"paper\",\n    4: \"metal_can\",\n}"
new = "CLASSES = {\n    0: \"background\",\n    1: \"plastic_bottle\",\n    2: \"other_plastic\",\n    3: \"plastic_wrapper\",\n    4: \"paper\",\n    5: \"metal_can\",\n    6: \"aluminum_can\",\n}"
if old in s:
    s2 = s.replace(old, new)
    with open(path, "w") as f:
        f.write(s2)
    print("ok")
else:
    print("no")
