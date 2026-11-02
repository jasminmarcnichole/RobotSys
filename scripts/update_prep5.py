path = r"E:\Laravel\RobotSys\resources\ml\prepare_waste.py"
with open(path) as f:
    s = f.read()

# Move wrapper
old = "        'Garbage bag', 'Other plastic wrapper', 'Single-use carrier bag',"
new = "        'Garbage bag', 'Single-use carrier bag',"
s2 = s.replace(old, new)
# Add wrapper class
old2 = "    'plastic_bottle': ['Clear plastic bottle', 'Other plastic bottle'],"
new2 = "    'plastic_wrapper': [\n        'Other plastic wrapper', 'Wrapper', 'Candy wrapper', 'Cellophane wrapper',\n        'Drink pouch', 'Crisp packet', 'Chocolate wrapper', 'Snack wrapper',\n        'Flexible plastic packaging', 'Drinkpouch', 'CandyWrapper', 'CellophaneWrapper',\n    ],\n    'plastic_bottle': ['Clear plastic bottle', 'Other plastic bottle'],"
if old2 in s2:
    s3 = s2.replace(old2, new2)
    with open(path, "w") as f:
        f.write(s3)
    print("ok")
else:
    print("no")
