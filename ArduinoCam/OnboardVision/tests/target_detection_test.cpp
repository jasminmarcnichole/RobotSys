#include <cassert>
#include <cstring>
#include <limits>
#include "target_detection.hpp"

int main()
{
    using namespace robotsys;
    TargetDetection target = {};
    int left[] = {10, 20, 70, 80};
    assert(make_target_detection(39, .8f, left, 4, 320, 240, .5f, 1234, target));
    assert(std::strcmp(target.label, "bottle") == 0);
    assert(target.class_id == 39 && target.timestamp_ms == 1234);
    assert(target.x == 10 && target.y == 20 && target.width == 60 && target.height == 60);
    assert(target.center_x == 40 && target.center_y == 50);
    assert(std::strcmp(target.direction, "LEFT") == 0);
    int center[] = {120, 40, 200, 100};
    assert(make_target_detection(41, .5f, center, 4, 320, 240, .5f, 1300, target));
    assert(std::strcmp(target.label, "cup") == 0 && std::strcmp(target.direction, "CENTER") == 0);
    int right[] = {250, 20, 310, 90};
    assert(make_target_detection(39, 1, right, 4, 320, 240, .5f, 1400, target));
    assert(std::strcmp(target.direction, "RIGHT") == 0);
    int clamped[] = {-10, -20, 400, 300};
    assert(make_target_detection(39, .7f, clamped, 4, 320, 240, .5f, 1500, target));
    assert(target.x == 0 && target.y == 0 && target.width == 319 && target.height == 239);
    int inverted[] = {100, 50, 20, 70};
    int outside[] = {400, 20, 500, 50};
    int empty[] = {20, 20, 20, 50};
    for (const int *box : {inverted, outside, empty}) {
        assert(!make_target_detection(39, .8f, box, 4, 320, 240, .5f, 0, target));
    }
    for (int category : {0, 1, 38, 40, 42, 79, 80, -1}) {
        assert(!make_target_detection(category, .8f, left, 4, 320, 240, .5f, 0, target));
    }
    for (float score : {-.1f, .49f, 1.01f, std::numeric_limits<float>::quiet_NaN(),
                        std::numeric_limits<float>::infinity()}) {
        assert(!make_target_detection(39, score, left, 4, 320, 240, .5f, 0, target));
    }
    assert(!make_target_detection(39, .8f, nullptr, 4, 320, 240, .5f, 0, target));
    assert(!make_target_detection(39, .8f, left, 3, 320, 240, .5f, 0, target));
    assert(!make_target_detection(39, .8f, left, 4, 0, 240, .5f, 0, target));
    assert(!make_target_detection(39, .8f, left, 4, 320, 1, .5f, 0, target));
    assert(!make_target_detection(39, .8f, left, 4, 320, 240, -.1f, 0, target));
    assert(!make_target_detection(39, .8f, left, 4, 320, 240, 1.1f, 0, target));
    assert(!make_target_detection(39, .8f, left, 4, 320, 240,
                                   std::numeric_limits<float>::quiet_NaN(), 0, target));
    int boundary[] = {90, 20, 110, 60};
    assert(make_target_detection(39, .8f, boundary, 4, 300, 240, .5f, 0, target));
    assert(std::strcmp(target.direction, "CENTER") == 0);
    boundary[0] = 190; boundary[2] = 210;
    assert(make_target_detection(39, .8f, boundary, 4, 300, 240, .5f, 0, target));
    assert(std::strcmp(target.direction, "RIGHT") == 0);
}
