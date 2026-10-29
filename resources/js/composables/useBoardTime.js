import { ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

export const ranges = [
    { minutes: 5, label: 'Last 5 minutes' },
    { minutes: 15, label: 'Last 15 minutes' },
    { minutes: 30, label: 'Last 30 minutes' },
    { minutes: 60, label: 'Last 1 hour' },
    { minutes: 180, label: 'Last 3 hours' },
    { minutes: 360, label: 'Last 6 hours' },
    { minutes: 720, label: 'Last 12 hours' },
    { minutes: 1440, label: 'Last 24 hours' },
    { minutes: 2880, label: 'Last 2 days' },
];

export const boardPaths = ['/', '/bandwidth', '/dhcp', '/dns', '/logs', '/security', '/pengaturan', '/pengaturan/sesi'];

export const refreshes = [
    { id: 'off', label: 'Off', seconds: 0 },
    { id: 'auto', label: 'Auto', seconds: null },
    { id: '5s', label: '5s', seconds: 5 },
    { id: '10s', label: '10s', seconds: 10 },
    { id: '30s', label: '30s', seconds: 30 },
    { id: '1m', label: '1m', seconds: 60 },
    { id: '5m', label: '5m', seconds: 300 },
    { id: '15m', label: '15m', seconds: 900 },
    { id: '30m', label: '30m', seconds: 1800 },
    { id: '1h', label: '1h', seconds: 3600 },
    { id: '2h', label: '2h', seconds: 7200 },
    { id: '1d', label: '1d', seconds: 86400 },
];

function stored(key, fallback) {
    if (typeof localStorage === 'undefined') {
        return fallback;
    }

    return localStorage.getItem(key) ?? fallback;
}

const storedMinutes = Number(stored('board.minutes', '30'));
export const rangeMinutes = ref(ranges.some((item) => item.minutes === storedMinutes) ? storedMinutes : 30);
export const refreshId = ref(refreshes.some((item) => item.id === stored('board.refresh', 'auto')) ? stored('board.refresh', 'auto') : 'auto');
export const live = ref(stored('board.live', '0') === '1');
export const pinned = ref(null);

let lastAccepted = 0;
let reloading = false;
let reloadQueued = false;
let lastLiveReload = 0;
let reloadTimer = 0;
const liveReloadGap = 5000;

function rememberLive(on) {
    localStorage.setItem('board.live', on ? '1' : '0');
    document.cookie = `board_live=${on ? '1' : '0'};path=/;max-age=31536000;SameSite=Lax`;
}

if (typeof window !== 'undefined') {
    watch(rangeMinutes, (value) => localStorage.setItem('board.minutes', String(value)));
    watch(refreshId, (value) => localStorage.setItem('board.refresh', value));
    watch(live, rememberLive);
    rememberLive(live.value);
}

export function acceptsPulse(now = Date.now()) {
    if (live.value) {
        return true;
    }

    const option = refreshes.find((item) => item.id === refreshId.value);

    if (!option || option.seconds === 0) {
        return false;
    }

    if (option.seconds === null) {
        return true;
    }

    if (now - lastAccepted < option.seconds * 1000) {
        return false;
    }

    lastAccepted = now;

    return true;
}

export function allowNextPulse() {
    lastAccepted = 0;
}

export function syncLivePage() {
    if (!live.value) {
        return;
    }

    const wait = liveReloadGap - (Date.now() - lastLiveReload);
    if (reloading || wait > 0) {
        reloadQueued = true;
        if (!reloading && !reloadTimer) {
            reloadTimer = window.setTimeout(() => {
                reloadTimer = 0;
                reloadQueued = false;
                syncLivePage();
            }, Math.max(0, wait));
        }

        return;
    }

    reloading = true;
    lastLiveReload = Date.now();
    router.reload({
        async: true,
        preserveState: true,
        preserveScroll: true,
        showProgress: false,
        onFinish: () => {
            reloading = false;
            if (!reloadQueued || !live.value) {
                reloadQueued = false;
                return;
            }

            reloadQueued = false;
            syncLivePage();
        },
    });
}

export function refreshSeconds() {
    if (live.value) {
        return 0;
    }

    const option = refreshes.find((item) => item.id === refreshId.value);

    if (!option || option.seconds === 0) {
        return 0;
    }

    if (option.seconds === null) {
        return 5;
    }

    return option.seconds;
}

export function boardQuery() {
    if (live.value) {
        return { live: 1 };
    }

    if (pinned.value) {
        return { from: pinned.value.from, to: pinned.value.to };
    }

    return { minutes: rangeMinutes.value };
}

export function syncBoardFromUrl(url) {
    const params = new URLSearchParams(String(url).split('?')[1] || '');

    if (params.get('live') === '1') {
        live.value = true;
        pinned.value = null;

        return;
    }

    if (params.has('minutes')) {
        const minutes = Number(params.get('minutes'));
        if (ranges.some((item) => item.minutes === minutes)) {
            rangeMinutes.value = minutes;
        }
    }

    const from = Number(params.get('from'));
    const to = Number(params.get('to'));
    if (params.has('from') && params.has('to') && to > from) {
        pinned.value = { from, to };
        if (to < Math.floor(Date.now() / 1000) - 2) {
            live.value = false;
        }
    } else if (params.has('minutes')) {
        pinned.value = null;
    }
}

export function useBoardTime() {
    const page = usePage();

    function timedPath() {
        const path = page.url.split('?')[0];

        return boardPaths.includes(path) ? path : null;
    }

    function apply() {
        const path = timedPath();
        if (!path) {
            return;
        }

        router.get(path, boardQuery(), { preserveState: true, preserveScroll: true, replace: true });
    }

    function selectRange(minutes) {
        rangeMinutes.value = minutes;
        pinned.value = null;
        apply();
    }

    function selectRefresh(id) {
        refreshId.value = id;
        allowNextPulse();
    }

    function setLive(on) {
        live.value = on;
        allowNextPulse();
        if (on) {
            pinned.value = null;
        }

        apply();
    }

    function shiftWindow(direction) {
        const now = Math.floor(Date.now() / 1000);
        const end = pinned.value?.to ?? now;
        const start = pinned.value?.from ?? end - rangeMinutes.value * 60;
        const span = Math.max(60, end - start);
        let nextEnd = end + direction * span;
        let nextStart = start + direction * span;

        if (nextEnd > now) {
            nextEnd = now;
            nextStart = now - span;
        }

        if (nextEnd >= now - 2) {
            pinned.value = null;
        } else {
            live.value = false;
            pinned.value = { from: nextStart, to: nextEnd };
            const match = ranges.find((item) => item.minutes * 60 === span);
            if (match) {
                rangeMinutes.value = match.minutes;
            }
        }

        apply();
    }

    function zoomOut() {
        const span = pinned.value
            ? Math.round((pinned.value.to - pinned.value.from) / 60)
            : rangeMinutes.value;
        const next = ranges.find((item) => item.minutes > span);
        if (!next) {
            return;
        }

        rangeMinutes.value = next.minutes;
        pinned.value = null;
        apply();
    }

    function refreshNow() {
        allowNextPulse();
        router.reload({ preserveState: true, preserveScroll: true });
    }

    return { apply, selectRange, selectRefresh, setLive, shiftWindow, zoomOut, refreshNow, timedPath };
}
