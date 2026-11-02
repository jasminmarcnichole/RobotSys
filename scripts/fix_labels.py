path = r"E:\Laravel\RobotSys\config\services.php"
with open(path) as f:
    s = f.read()
old = "'labels' => ['plastic_bottle', 'other_plastic', 'paper', 'metal_can']"
new = "'labels' => ['plastic_bottle', 'other_plastic', 'plastic_wrapper', 'paper', 'metal_can', 'aluminum_can']"
if old in s:
    with open(path,"w") as f:
        f.write(s.replace(old,new))
    print("ok")
else:
    print("no")
