path = r"E:\Laravel\RobotSys\resources\ml\prepare_waste.py"
with open(path) as f:
    s = f.read()

# Remove wrapper from other_plastic
old = "        'Garbage bag', 'Other plastic wrapper', 'Single-use carrier bag',"
new = "        'Garbage bag', 'Single-use carrier bag',"
s2 = s.replace(old, new)
# Add plastic_wrapper class
old2 = "    'plastic_bottle': [\n        'Unlabeled litter', 'Plastic bottle',"
new2 = "    'plastic_wrapper': [\n        'Other plastic wrapper', 'Wrapper', 'Candy wrapper', 'Cellophane wrapper',\n        'Drink pouch', 'Crisp packet', 'Chocolate wrapper', 'Snack wrapper',\n        'Flexible plastic packaging', 'Drinkpouch', 'CandyWrapper', 'CellophaneWrapper',\n    ],\n    'plastic_bottle': [\n        'Unlabeled litter', 'Plastic bottle',"
if old2 in s2:
    s3 = s2.replace(old2, new2)
    with open(path, "w") as f:
        f.write(s3)
    print("ok")
else:
    print("no")
