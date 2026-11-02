path = r"E:\Laravel\RobotSys\resources\ml\prepare_hitl.py"
with open(path) as f:
    s = f.read()

# Move wrappers to plastic_wrapper
old = """OTHER_PLASTIC = {"bag", "binbag", "bottlecap", "candywrapper", "carrierbag", "cellophanewrapper", "container",
                 "cup", "drinkpouch", "film", "foil", "fork", "gloves", "lid", "piece", "plastic", "pop_tab",
                 "stirrer", "straw", "strawwrapper", "tiewrap", "wrapper", "ziplock"}"""
new = """OTHER_PLASTIC = {"bag", "binbag", "bottlecap", "carrierbag", "container",
                 "cup", "film", "foil", "fork", "gloves", "lid", "piece", "plastic", "pop_tab",
                 "stirrer", "straw", "tiewrap", "ziplock"}
PLASTIC_WRAPPER = {"candywrapper", "cellophanewrapper", "drinkpouch", "strawwrapper", "wrapper"}"""
if old in s:
    s2 = s.replace(old, new)
    # Add mapping
    old2 = "    tags = normalize_tags(standardize_tags(raw_tags or {}))"
    new2 = "    tags = normalize_tags(standardize_tags(raw_tags or {}))\n    obj = tags.get('Object')\n    if obj in PLASTIC_WRAPPER and category != 'plastic_wrapper':\n        category = 'plastic_wrapper'"
    if old2 in s2:
        s3 = s2.replace(old2, new2)
        with open(path, "w") as f:
            f.write(s3)
        print("ok")
    else:
        print("no2")
else:
    print("no1")
