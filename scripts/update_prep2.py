path = r"E:\Laravel\RobotSys\resources\ml\prepare_waste.py"
with open(path) as f:
    s = f.read()

# Update CLASS_SOURCES
old = """    'other_plastic': [
        'Plastic bottle cap', 'Disposable plastic cup', 'Other plastic cup',
        'Plastic lid', 'Other plastic', 'Plastic film', 'Six pack rings',
        'Garbage bag', 'Other plastic wrapper', 'Single-use carrier bag',
        'Disposable food container', 'Other plastic container',
        'Plastic glooves', 'Plastic utensils', 'Plastic straw',
        'Cigarette', 'Foam cup', 'Pop tab', 'Lid', 'Plastic',
    ],"""
new = """    'other_plastic': [
        'Plastic bottle cap', 'Disposable plastic cup', 'Other plastic cup',
        'Plastic lid', 'Other plastic', 'Plastic film', 'Six pack rings',
        'Garbage bag', 'Single-use carrier bag',
        'Disposable food container', 'Other plastic container',
        'Plastic glooves', 'Plastic utensils', 'Plastic straw',
        'Cigarette', 'Foam cup', 'Pop tab', 'Lid', 'Plastic',
    ],
    'plastic_wrapper': [
        'Other plastic wrapper', 'Wrapper', 'Candy wrapper', 'Cellophane wrapper',
        'Drink pouch', 'Crisp packet', 'Chocolate wrapper', 'Snack wrapper',
        'Flexible plastic packaging',
    ],"""
if old in s:
    s2 = s.replace(old, new)
    with open(path, "w") as f:
        f.write(s2)
    print("ok")
else:
    print("no")
