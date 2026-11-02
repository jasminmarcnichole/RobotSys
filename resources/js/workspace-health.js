export function healthSignals(telemetry, online = true, timestamp = Date.now()) {
    const signals = [];
    if (!online) signals.push({ tone: 'danger', title: 'Connection unavailable', detail: 'Reconnect before sending commands or saving a review.' });
    const reportedAt = Date.parse(telemetry?.created_at ?? '');
    const age = timestamp - reportedAt;
    if (!telemetry) {
        signals.push({ tone: 'warning', title: 'Waiting for robot telemetry', detail: 'Connect the robot to see its reported state, battery, and collection activity.' });
    } else if (!Number.isFinite(age) || age < 0) {
        signals.push({ tone: 'warning', title: 'Check the robot timestamp', detail: 'The latest report does not have a usable current timestamp.' });
    } else if (age > 15000) {
        signals.push({ tone: 'warning', title: 'Robot telemetry is stale', detail: 'No robot report received in the last 15 seconds. Displayed readings are from the last report.' });
    }
    const battery = telemetry?.battery_level;
    if (battery !== null && battery !== undefined && Number.isFinite(Number(battery)) && Number(battery) <= 20 && Number(battery) >= 0) {
        signals.push({ tone: Number(battery) <= 10 ? 'danger' : 'warning', title: 'Low reported battery', detail: `Last reported charge: ${Math.round(Number(battery))}%. Check the robot before continuing.` });
    }
    for (const [key, label] of [['plastic_bin_level', 'Plastic bin'], ['general_bin_level', 'General bin']]) {
        const level = String(telemetry?.[key] ?? '').trim().toLowerCase();
        if (['high', 'full'].includes(level)) signals.push({ tone: 'warning', title: `${label} needs attention`, detail: `Last reported level: ${level}. Review the bin before further collection.` });
    }
    return signals.length ? signals : [{ tone: 'success', title: 'Recent telemetry received', detail: 'No battery or bin-level alerts in the latest robot report.' }];
}
