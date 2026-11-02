path = r"E:\Laravel\RobotSys\resources\ml\prepare_waste.py"
with open(path) as f:
    s = f.read()

# Try different formatting
old = "        'Garbage bag', 'Other plastic wrapper', 'Single-use carrier bag',"
new = "        'Garbage bag', 'Single-use carrier bag',"
if old in s:
    s2 = s.replace(old, new)
    # Add new class
    old2 = "    'plastic_bottle': ["
    # Find position to add after other_plastic
    # Add plastic_wrapper
    insert = "    'plastic_wrapper': [\n        'Other plastic wrapper', 'Wrapper', 'Candy wrapper', 'Cellophane wrapper',\n        'Drink pouch', 'Crisp packet', 'Chocolate wrapper', 'Snack wrapper',\n        'Flexible plastic packaging', 'Drinkpouch', 'CandyWrapper', 'CellophaneWrapper',\n    ],\n"
    # Insert before plastic_bottle or after other_plastic block end
    marker = "    ],\n    'plastic_bottle': ["
    if marker in s2:
        s3 = s2.replace(marker, "    ],\n" + insert + "    'plastic_bottle': [")
        with open(path, "w") as f:
            f.write(s3)
        print("ok")
    else:
        print("no2")
else:
    print("no1")
