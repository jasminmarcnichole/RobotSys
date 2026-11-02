import shutil, os
src = r"E:\Laravel\RobotSys\resources\ml"
dst = r"\\wsl.localhost\RobotSys-Ubuntu\home\robotsys\waste-training\ml"
os.makedirs(dst, exist_ok=True)
for f in ["prepare_waste.py", "train_waste.py", "prepare_hitl.py"]:
    shutil.copy(os.path.join(src,f), os.path.join(dst,f))
    print("copied", f)
