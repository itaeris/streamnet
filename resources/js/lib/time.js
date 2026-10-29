const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

export function windowBounds(minutes, from, to, now = Math.floor(Date.now() / 1000)) {
    const end = Number(to) || now;
    const start = Number(from) || end - (Number(minutes) || 30) * 60;

    return { start, end };
}

export function inWindow(ts, minutes, from, to, now = Math.floor(Date.now() / 1000)) {
    if (!ts) {
        return false;
    }

    const { start, end } = windowBounds(minutes, from, to, now);

    return ts >= start && ts <= end + 2;
}

export function pointLabel(ts, minutes) {
    const date = new Date(ts * 1000);
    const hours = String(date.getHours()).padStart(2, '0');
    const mins = String(date.getMinutes()).padStart(2, '0');

    if (minutes >= 1440) {
        return `${date.getDate()} ${months[date.getMonth()]} ${hours}:${mins}`;
    }

    const seconds = String(date.getSeconds()).padStart(2, '0');

    return `${hours}:${mins}:${seconds}`;
}

export function adoptSeries(current, incoming, live = false) {
    const next = incoming ?? [];
    if (!live || !current?.length || next.length === 0) {
        return [...next];
    }

    const currentStart = Number(current[0]?.ts) || 0;
    const incomingStart = Number(next[0]?.ts) || 0;
    if (incomingStart > 0 && currentStart > 0 && incomingStart < currentStart - 30) {
        return [...next];
    }

    const last = Number(current.at(-1)?.ts) || 0;
    const extra = next.filter((point) => (Number(point.ts) || 0) > last);

    return extra.length ? current.concat(extra) : current;
}

export function pushPoint(points, point, minutes, from, to, max = 240) {
    const ts = point.ts || Math.floor(Date.now() / 1000);
    const nextPoint = { ...point, ts, t: point.t || pointLabel(ts, minutes) };
    const last = points.at(-1);
    const rows = last?.ts === ts ? points.slice(0, -1).concat(nextPoint) : points.concat(nextPoint);
    const end = to || Math.floor(Date.now() / 1000);
    const start = from || end - minutes * 60;
    const trimmed = rows.filter((row) => !row.ts || (row.ts >= start && row.ts <= end + 2));

    if (trimmed.length <= max) {
        return trimmed;
    }

    const step = Math.ceil(trimmed.length / max);

    return trimmed.filter((row, index) => index % step === 0 || index === trimmed.length - 1);
}
