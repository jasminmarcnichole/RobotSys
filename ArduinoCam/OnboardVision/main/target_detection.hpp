#pragma once

#include <algorithm>
#include <cmath>
#include <cstddef>
#include <cstdint>

namespace robotsys {

struct TargetDetection {
    int class_id;
    const char *label;
    float confidence;
    int x;
    int y;
    int width;
    int height;
    double center_x;
    double center_y;
    const char *direction;
    uint64_t timestamp_ms;
};

inline const char *target_label(int category)
{
    switch (category) {
    case 39: return "bottle";
    case 41: return "cup";
    default: return nullptr;
    }
}

inline bool make_target_detection(int category, float confidence, const int *box, size_t box_size,
                                  int frame_width, int frame_height, float threshold,
                                  uint64_t captured_at_ms, TargetDetection &output)
{
    const char *label = target_label(category);
    if (!label || !box || box_size != 4 || frame_width < 2 || frame_height < 2 ||
        !std::isfinite(threshold) || threshold < 0 || threshold > 1 ||
        !std::isfinite(confidence) || confidence < threshold || confidence > 1 || confidence < 0) {
        return false;
    }
    const int x1 = std::clamp(box[0], 0, frame_width - 1);
    const int y1 = std::clamp(box[1], 0, frame_height - 1);
    const int x2 = std::clamp(box[2], 0, frame_width - 1);
    const int y2 = std::clamp(box[3], 0, frame_height - 1);
    if (x2 <= x1 || y2 <= y1) {
        return false;
    }
    const double center_x = (static_cast<double>(x1) + x2) / 2;
    const double center_y = (static_cast<double>(y1) + y2) / 2;
    const char *direction = center_x < frame_width / 3.0 ? "LEFT" :
                            center_x >= frame_width * (2.0 / 3.0) ? "RIGHT" : "CENTER";
    output = {category, label, confidence, x1, y1, x2 - x1, y2 - y1,
              center_x, center_y, direction, captured_at_ms};
    return true;
}

} // namespace robotsys

