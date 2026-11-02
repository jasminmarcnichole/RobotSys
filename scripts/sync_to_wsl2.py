import shutil, os
src = r"E:\Laravel\RobotSys\resources\ml"
dst = r"\\wsl.localhost\RobotSys-Ubuntu\home\robotsys\waste-training\ml"
for f in ["prepare_hitl.py"]:
    shutil.copy(os.path.join(src,f), os.path.join(dst,f))
    print("copied", f)
