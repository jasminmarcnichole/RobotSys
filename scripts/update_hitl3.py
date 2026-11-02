path = r"E:\Laravel\RobotSys\resources\ml\prepare_hitl.py"
with open(path) as f:
    s = f.read()

# Update choices
old = '    parser.add_argument("--review-class", action="append", choices=("plastic_bottle", "other_plastic", "paper", "metal_can"),'
new = '    parser.add_argument("--review-class", action="append", choices=("plastic_bottle", "other_plastic", "plastic_wrapper", "paper", "metal_can", "aluminum_can"),'
if old in s:
    s2 = s.replace(old, new)
    with open(path, "w") as f:
        f.write(s2)
    print("ok")
else:
    print("no")
