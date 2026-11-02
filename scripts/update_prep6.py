path = r"E:\Laravel\RobotSys\resources\ml\prepare_waste.py"
with open(path) as f:
    s = f.read()

# Add aluminum_can
old = "    'metal_can': ['Food Can', 'Drink can'],"
new = "    'metal_can': ['Food Can'],\n    'aluminum_can': ['Drink can', 'Aluminum can', 'Soda can'],"
if old in s:
    s2 = s.replace(old, new)
    with open(path, "w") as f:
        f.write(s2)
    print("ok")
else:
    print("no")
