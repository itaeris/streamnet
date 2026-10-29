export function rateParts(bps) {
    const n = Number(bps) || 0;
    const abs = Math.abs(n);

    if (abs >= 1e9) {
        return { value: (n / 1e9).toFixed(2), unit: 'Gbps' };
    }

    if (abs >= 1e6) {
        return { value: (n / 1e6).toFixed(1), unit: 'Mbps' };
    }

    if (abs >= 1e3) {
        return { value: (n / 1e3).toFixed(1), unit: 'Kbps' };
    }

    return { value: String(Math.round(n)), unit: 'bps' };
}

export function formatRate(bps) {
    const n = Number(bps) || 0;
    const abs = Math.abs(n);

    if (abs >= 1e9) {
        return `${(n / 1e9).toFixed(2)} Gbps`;
    }

    if (abs >= 1e6) {
        return `${(n / 1e6).toFixed(2)} Mbps`;
    }

    if (abs >= 1e3) {
        return `${(n / 1e3).toFixed(1)} Kbps`;
    }

    const parts = rateParts(bps);

    return `${parts.value} ${parts.unit}`;
}

export function formatBytes(bytes) {
    const n = Number(bytes) || 0;
    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    let value = n;
    let index = 0;

    while (value >= 1024 && index < units.length - 1) {
        value /= 1024;
        index += 1;
    }

    return `${value.toFixed(index === 0 ? 0 : 1)} ${units[index]}`;
}

export function formatUptime(value) {
    const raw = String(value || '').trim();
    const match = raw.match(/^(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/);
    if (!match || raw === '') {
        return raw || '–';
    }

    const parts = [
        [match[1], 'minggu'],
        [match[2], 'hari'],
        [match[3], 'jam'],
        [match[4], 'mnt'],
        [match[5], 'dtk'],
    ].filter(([amount]) => amount && amount !== '0');

    if (parts.length === 0) {
        return raw;
    }

    return parts.slice(0, 2).map(([amount, label]) => `${amount} ${label}`).join(' ');
}

export function formatSeconds(seconds) {
    const total = Math.max(0, Math.floor(Number(seconds)));
    if (!Number.isFinite(total) || seconds === null || seconds === undefined) {
        return '–';
    }

    const weeks = Math.floor(total / 604800);
    const days = Math.floor((total % 604800) / 86400);
    const hours = Math.floor((total % 86400) / 3600);
    const minutes = Math.floor((total % 3600) / 60);

    return formatUptime(`${weeks}w${days}d${hours}h${minutes}m${total % 60}s`);
}

export function durationSeconds(value) {
    const raw = String(value || '').trim();
    const match = raw.match(/^(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/);

    if (!match || raw === '' || match[0] !== raw) {
        return Number.POSITIVE_INFINITY;
    }

    const weeks = Number(match[1] || 0);
    const days = Number(match[2] || 0);
    const hours = Number(match[3] || 0);
    const minutes = Number(match[4] || 0);
    const seconds = Number(match[5] || 0);

    return (((weeks * 7 + days) * 24 + hours) * 60 + minutes) * 60 + seconds;
}

export function clockMillis(value) {
    const parsed = Date.parse(String(value || '').replace(' ', 'T'));

    return Number.isNaN(parsed) ? 0 : parsed;
}

export function percent(used, total) {
    if (!total) {
        return 0;
    }

    return Math.max(0, Math.min(100, Math.round((used / total) * 100)));
}
