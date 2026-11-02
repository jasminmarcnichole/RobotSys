import json
import os
import shutil
from pathlib import Path

src = Path(r"E:\Laravel\RobotSys\storage\app\private\waste-training\hitl-wrapper-review-20261004")
out = Path(r"E:\Laravel\RobotSys\storage\app\private\waste-training\hitl-wrapper-processed")
out.mkdir(parents=True, exist_ok=True)

# Read review index
idx = json.load(open(src / "review-index.json"))
# Just copy images for now
for img in ["objects-1.png", "objects-2.png", "objects-3.png", "objects-4.png"]:
    if (src / img).exists():
        shutil.copy(src / img, out / img)
        print("copied", img)
print("done")
