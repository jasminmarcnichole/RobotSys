# simulate
CLASSES = {0: "background", 1: "plastic_bottle", 2: "other_plastic", 3: "plastic_wrapper", 4: "paper", 5: "metal_can", 6: "aluminum_can"}
categories = [{"id": 0, "name": "background"}, {"id": 1, "name": "plastic_bottle"}, {"id": 2, "name": "other_plastic"}, {"id": 3, "name": "plastic_wrapper"}, {"id": 4, "name": "paper"}, {"id": 5, "name": "metal_can"}, {"id": 6, "name": "aluminum_can"}]
print(len(categories) != len(CLASSES))
print({item["id"]: item.get("name") for item in categories} != CLASSES)
