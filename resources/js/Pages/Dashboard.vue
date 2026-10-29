<script setup>
import { computed, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import PulseWatch from '../Components/PulseWatch.vue';
import PagedTable from '../Components/PagedTable.vue';
import PreviewBinder from '../Components/PreviewBinder.vue';
import LiveRate from '../Components/LiveRate.vue';
import TrafficChart from '../Components/TrafficChart.vue';
import { live as boardLive, pinned, ranges, refreshId } from '../composables/useBoardTime';
import { useBoardPulse } from '../composables/usePulse';
import { usePreview } from '../composables/usePreview';
import { clockMillis, durationSeconds, formatBytes, formatRate, formatUptime, percent } from '../lib/format';
import { adoptSeries, inWindow, pointLabel, pushPoint } from '../lib/time';

defineOptions({ layout: AppLayout });

const props = defineProps({
    pulse: { type: Object, default: null },
    minutes: { type: Number, default: 30 },
    from: { type: Number, default: null },
    to: { type: Number, default: null },
    series: { type: Array, default: () => [] },
    bridge: { type: Object, default: () => ({ name: null, series: [] }) },
    logs: { type: Array, default: () => [] },
    events: { type: Array, default: () => [] },
});

const live = useBoardPulse();
const query = '';
const rangeLabel = computed(() => (boardLive.value ? 'Live' : (ranges.find((item) => item.minutes === props.minutes)?.label ?? 'Last 30 minutes')));

const points = ref([...(props.series ?? [])]);
const bridgePoints = ref([...(props.bridge?.series ?? [])]);
const logs = ref([...(props.logs ?? [])]);

function bridgeInterface(pulse) {
    const rows = pulse?.interfaces ?? [];

    return rows.find((iface) => String(iface.name || '').toLowerCase() === 'bridge')
        ?? rows.find((iface) => String(iface.type || '').toLowerCase() === 'bridge')
        ?? null;
}

const bridgeName = computed(() => bridgeInterface(live.value)?.name || props.bridge?.name || '');

watch(() => props.series, (next) => {
    points.value = adoptSeries(points.value, next ?? [], boardLive.value);
});

watch(() => props.bridge?.series, (next) => {
    bridgePoints.value = adoptSeries(bridgePoints.value, next ?? [], boardLive.value);
});

watch(() => props.logs, (next) => {
    logs.value = [...(next ?? [])];
});

function logInside(entry) {
    if (boardLive.value) {
        return true;
    }

    return inWindow(Math.floor(clockMillis(entry.time) / 1000), props.minutes, props.from, props.to);
}

function rememberLog(entries) {
    const seen = new Set(logs.value.map((entry) => entry.id));
    const fresh = (entries ?? []).filter((entry) => entry.id && !seen.has(entry.id) && logInside(entry));
    logs.value = [...fresh, ...logs.value].filter(logInside).slice(0, 8);
}

function onPulse(next) {
    if ((boardLive.value || !pinned.value) && next?.wan) {
        const ts = next.sampled_at ? Math.floor(Date.parse(next.sampled_at) / 1000) : Math.floor(Date.now() / 1000);
        const span = boardLive.value ? 31 * 1440 : props.minutes;
        points.value = pushPoint(points.value, {
            t: pointLabel(ts, span),
            ts,
            rx: next.wan.rx_bps,
            tx: next.wan.tx_bps,
        }, span, boardLive.value ? null : props.from, boardLive.value ? null : props.to);
    }

    const bridge = bridgeInterface(next);
    if ((boardLive.value || !pinned.value) && bridge) {
        const ts = next.sampled_at ? Math.floor(Date.parse(next.sampled_at) / 1000) : Math.floor(Date.now() / 1000);
        const span = boardLive.value ? 31 * 1440 : props.minutes;
        bridgePoints.value = pushPoint(bridgePoints.value, {
            t: pointLabel(ts, span),
            ts,
            rx: bridge.rx_bps,
            tx: bridge.tx_bps,
        }, span, boardLive.value ? null : props.from, boardLive.value ? null : props.to);
    }

    rememberLog(next.logs);
}

const { open, preview, isOpen } = usePreview();
const show = (value) => (value === null || value === undefined || value === '' ? '–' : String(value));

function interfacePreview(iface, sampledAt = null, history = []) {
    const up = Boolean(iface.running);
    const rx = Number(iface.rx_bps) || 0;
    const tx = Number(iface.tx_bps) || 0;
    const total = rx + tx;

    return {
        key: `iface:${iface.name}`,
        icon: 'lan',
        title: iface.name,
        subtitle: iface.type || 'interface',
        status: { label: iface.disabled ? 'off' : (up ? 'up' : 'down'), tone: iface.disabled ? 'mute' : (up ? 'ok' : 'danger') },
        stream: {
            rx,
            tx,
            at: sampledAt,
            points: history,
        },
        tabs: [
            {
                id: 'overview',
                icon: 'lan',
                label: 'Interface',
                hero: true,
                metrics: [
                    { label: 'RX', value: formatRate(iface.rx_bps), tone: 'cyan' },
                    { label: 'TX', value: formatRate(iface.tx_bps), tone: 'ok' },
                    { label: 'Link', value: up ? 'up' : 'down', tone: up ? 'ok' : 'danger' },
                ],
                meter: {
                    rx: formatRate(iface.rx_bps),
                    tx: formatRate(iface.tx_bps),
                    rxShare: total > 0 ? `${(rx / total) * 100}%` : '0%',
                    txShare: total > 0 ? `${(tx / total) * 100}%` : '0%',
                },
                sections: [{
                    rows: [
                        { label: 'Disabled', value: iface.disabled ? 'yes' : 'no', tone: iface.disabled ? 'warn' : undefined },
                        { label: 'RX packets/s', value: show(iface.rx_pps) },
                        { label: 'TX packets/s', value: show(iface.tx_pps) },
                    ],
                }],
            },
            {
                id: 'counters',
                icon: 'bar_chart',
                label: 'Counter',
                sections: [{
                    title: 'Counters',
                    rows: [
                        { label: 'Total RX', value: formatBytes(iface.rx_bytes) },
                        { label: 'Total TX', value: formatBytes(iface.tx_bytes) },
                        { label: 'RX error', value: show(iface.rx_error), tone: Number(iface.rx_error) > 0 ? 'warn' : undefined },
                        { label: 'TX error', value: show(iface.tx_error), tone: Number(iface.tx_error) > 0 ? 'warn' : undefined },
                        { label: 'RX drop', value: show(iface.rx_drop), tone: Number(iface.rx_drop) > 0 ? 'warn' : undefined },
                        { label: 'TX drop', value: show(iface.tx_drop), tone: Number(iface.tx_drop) > 0 ? 'warn' : undefined },
                        { label: 'Link downs', value: show(iface.link_downs) },
                    ],
                }],
            },
        ],
    };
}

function activeInterface(live) {
    const key = preview.value?.key;
    const iface = (live?.interfaces ?? []).find((row) => `iface:${row.name}` === key);
    const history = iface?.name === live?.wan?.name
        ? points.value
        : (iface?.name && iface.name === bridgeName.value ? bridgePoints.value : []);

    return iface ? interfacePreview(iface, live?.sampled_at, history) : null;
}

const peakOf = (key) => Math.max(0, ...points.value.map((point) => Number(point[key]) || 0));
const bridgePeak = (key) => Math.max(0, ...bridgePoints.value.map((point) => Number(point[key]) || 0));

const leaseStats = (live) => {
    const rows = live?.leases;
    if (!Array.isArray(rows)) {
        return live?.lease_summary ?? { bound: 0, waiting: 0, blocked: 0 };
    }

    const now = Math.floor(Date.now() / 1000);
    const visible = rows.filter((lease) => {
        const seconds = durationSeconds(lease.last_seen);
        const seen = Number.isFinite(seconds) ? now - seconds : now;

        if (boardLive.value) {
            return true;
        }

        return inWindow(seen, props.minutes, props.from, props.to);
    });

    return {
        bound: visible.filter((lease) => lease.status === 'bound').length,
        waiting: visible.filter((lease) => lease.status === 'waiting').length,
        blocked: visible.filter((lease) => lease.blocked).length,
    };
};

const healthLabel = (live) => {
    const cpu = live?.system?.cpu_load ?? 0;
    const temp = live?.system?.temperature ?? 0;
    if (cpu >= 95 || temp >= 85) {
        return 'CRITICAL';
    }
    if (cpu >= 85 || temp >= 75) {
        return 'DEGRADED';
    }
    return 'HEALTHY';
};

const ifaceText = (iface) => `${iface.name} ${iface.type}`;
const ifaceLabel = (iface) => iface.name || '';

const visibleIfaces = (live, query) => {
    const needle = String(query || '').trim().toLowerCase();
    const rows = live?.interfaces ?? [];
    if (!needle) {
        return rows;
    }
    return rows.filter((iface) => `${iface.name} ${iface.type}`.toLowerCase().includes(needle));
};
</script>

<template>
    <Head title="Network Overview" />

        <PulseWatch :pulse="live" @pulse="onPulse" />
        <PreviewBinder :value="activeInterface(live)" />

        <section class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <article class="noc-card p-3">
                <div class="flex items-center justify-between">
                    <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Uplink gateway</p>
                    <span class="rounded bg-ok/10 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-ok">{{ live?.wan ? 'ACTIVE UP' : 'WAIT' }}</span>
                </div>
                <p class="mt-3 truncate text-[15px] font-semibold">{{ live?.system?.identity || 'Router' }}</p>
                <p class="mt-1 font-mono text-[13px] text-cyan">{{ live?.wan?.name || '–' }}</p>
                <div class="mt-3 flex items-center justify-between rounded bg-sink/70 px-2 py-1.5 font-mono text-[11px] text-mist">
                    <span>{{ live?.system?.board || 'MikroTik' }}</span>
                    <span>{{ live?.system?.voltage ?? '–' }} V</span>
                </div>
            </article>

            <article class="noc-card p-3">
                <div class="flex items-center justify-between">
                    <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Live throughput</p>
                    <p class="font-mono text-[11px] text-mist">WAN aggregate</p>
                </div>
                <div class="mt-2 grid grid-cols-2 gap-2">
                    <div>
                        <p class="font-mono text-[11px] text-mist">↓ RX down</p>
                        <p class="text-lg font-bold text-cyan"><LiveRate :value="live?.wan?.rx_bps" split /></p>
                        <p class="font-mono text-[11px] text-mute">Peak {{ formatRate(peakOf('rx')) }}</p>
                    </div>
                    <div>
                        <p class="font-mono text-[11px] text-mist">↑ TX up</p>
                        <p class="text-lg font-bold text-ok"><LiveRate :value="live?.wan?.tx_bps" split /></p>
                        <p class="font-mono text-[11px] text-mute">Peak {{ formatRate(peakOf('tx')) }}</p>
                    </div>
                </div>
                <div class="mt-2 h-9 rounded bg-sink/70 p-1">
                    <TrafficChart :points="points" height-class="h-7" />
                </div>
            </article>

            <article class="noc-card p-3">
                <div class="flex items-center justify-between">
                    <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Hardware telemetry</p>
                    <span class="rounded bg-ok/10 px-1.5 py-0.5 font-mono text-[11px] font-semibold" :class="healthLabel(live) === 'HEALTHY' ? 'text-ok' : 'text-warn'">{{ healthLabel(live) }}</span>
                </div>
                <div class="mt-2 grid grid-cols-3 gap-2 text-center">
                    <div class="rounded bg-sink/70 px-1 py-2">
                        <p class="font-mono text-[11px] text-mute">CPU</p>
                        <p class="text-[15px] font-bold">{{ live?.system?.cpu_load ?? 0 }}%</p>
                        <p class="font-mono text-[10px] text-cyan">{{ live?.system?.cpu_count || '–' }}c {{ live?.system?.cpu_frequency || '–' }}M</p>
                    </div>
                    <div class="rounded bg-sink/70 px-1 py-2">
                        <p class="font-mono text-[11px] text-mute">RAM</p>
                        <p class="text-[15px] font-bold">{{ percent(live?.system?.memory_used, live?.system?.memory_total) }}%</p>
                        <p class="font-mono text-[10px] text-mute">{{ formatBytes(live?.system?.memory_used) }}</p>
                    </div>
                    <div class="rounded bg-sink/70 px-1 py-2">
                        <p class="font-mono text-[11px] text-mute">Temp</p>
                        <p class="text-[15px] font-bold text-warn">{{ live?.system?.temperature ?? '–' }}°C</p>
                        <p class="font-mono text-[10px] text-mist">{{ live?.system?.voltage ?? '–' }} V</p>
                    </div>
                </div>
                <p class="mt-2 text-right font-mono text-[11px] text-mist">Uptime {{ formatUptime(live?.system?.uptime) }}</p>
            </article>

            <article class="noc-card p-3">
                <div class="flex items-center justify-between">
                    <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">State engine</p>
                    <p class="font-mono text-[11px] text-cyan">Conntrack</p>
                </div>
                <div class="mt-2 flex items-end justify-between">
                    <div>
                        <p class="text-2xl font-bold">{{ (live?.security?.connections ?? 0).toLocaleString('en-US') }}</p>
                        <p class="font-mono text-[11px] text-mute">Active connections</p>
                    </div>
                    <div class="text-right">
                        <p class="text-lg font-bold text-ok">{{ leaseStats(live).bound }}</p>
                        <p class="font-mono text-[11px] text-mute">DHCP leases</p>
                    </div>
                </div>
                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-tile">
                    <div class="h-full bg-cyan" :style="{ width: `${Math.min(100, percent(live?.system?.memory_used, live?.system?.memory_total))}%` }" />
                </div>
                <p class="mt-1.5 font-mono text-[11px] text-mute">{{ leaseStats(live).blocked }} blocked · {{ leaseStats(live).waiting }} waiting</p>
            </article>
        </section>

        <section class="mt-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2 text-[15px] font-semibold">
                    <span class="material-symbols-outlined text-[18px] text-cyan">developer_board</span>
                    Chassis port matrix
                </div>
                <div class="flex flex-wrap gap-3 font-mono text-[11px] text-mute">
                    <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-ok" /> Link up</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-mute" /> Down</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-danger" /> Errors</span>
                </div>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
                <article
                    v-for="iface in visibleIfaces(live, query)"
                    :key="iface.name"
                    tabindex="0"
                    class="noc-card cursor-pointer p-3"
                    :class="isOpen(`iface:${iface.name}`) ? 'bg-active' : ''"
                    @click="open(interfacePreview(iface, live?.sampled_at, iface.name === live?.wan?.name ? points : (iface.name === bridgeName ? bridgePoints : [])))"
                    @keydown.enter.prevent="open(interfacePreview(iface, live?.sampled_at, iface.name === live?.wan?.name ? points : (iface.name === bridgeName ? bridgePoints : [])))"
                >
                    <div class="flex items-center justify-between gap-2">
                        <p class="truncate font-mono text-[11px] font-semibold tracking-[0.12em] text-mute uppercase">{{ iface.type || 'port' }}</p>
                        <span class="size-2 shrink-0 rounded-full" :class="iface.rx_error || iface.tx_error ? 'bg-danger' : iface.running ? 'bg-ok' : 'bg-mute'" />
                    </div>
                    <p class="mt-2 truncate font-mono text-sm font-semibold text-cyan">{{ iface.name }}</p>
                    <p class="mt-1 font-mono text-[11px] text-mist">{{ iface.running ? 'up' : 'down' }} · <LiveRate :value="iface.rx_bps" /></p>
                </article>
            </div>
        </section>

        <section class="mt-3 grid gap-3 xl:grid-cols-[1.45fr_0.7fr]">
            <article class="noc-card p-3">
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <p class="text-[15px] font-semibold">{{ rangeLabel }}</p>
                        <p class="font-mono text-[11px] text-mute">{{ live?.wan?.name || 'WAN' }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 font-mono text-[11px]">
                        <span class="inline-flex items-center gap-1.5 rounded bg-tile px-2 py-1"><span class="h-1 w-2.5 rounded bg-cyan" /> RX {{ formatRate(peakOf('rx')) }}</span>
                        <span class="inline-flex items-center gap-1.5 rounded bg-tile px-2 py-1"><span class="h-1 w-2.5 rounded bg-ok" /> TX {{ formatRate(peakOf('tx')) }}</span>
                        <span class="text-cyan">{{ live?.sampled_label || '' }} {{ pinned && !boardLive ? 'RANGE' : boardLive ? 'LIVE' : refreshId === 'off' ? 'PAUSED' : refreshId.toUpperCase() }}</span>
                    </div>
                </div>
                <div class="mt-3 rounded bg-sink p-2">
                    <TrafficChart :points="points" axis height-class="h-56" />
                </div>
                <div v-if="bridgeName" class="mt-4">
                    <div class="flex flex-wrap items-end justify-between gap-2">
                        <p class="font-mono text-[11px] text-mute">{{ bridgeName }}</p>
                        <div class="flex flex-wrap items-center gap-2 font-mono text-[11px]">
                            <span class="inline-flex items-center gap-1.5 rounded bg-tile px-2 py-1"><span class="h-1 w-2.5 rounded bg-cyan" /> RX {{ formatRate(bridgePeak('rx')) }}</span>
                            <span class="inline-flex items-center gap-1.5 rounded bg-tile px-2 py-1"><span class="h-1 w-2.5 rounded bg-ok" /> TX {{ formatRate(bridgePeak('tx')) }}</span>
                        </div>
                    </div>
                    <div class="mt-2 rounded bg-sink p-2">
                        <TrafficChart :points="bridgePoints" axis height-class="h-56" />
                    </div>
                </div>
            </article>

            <div class="flex flex-col gap-3">
                <article class="noc-card p-3">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Security events</p>
                        <span class="font-mono text-[11px] text-mute">{{ rangeLabel }}</span>
                    </div>
                    <ul v-if="events.length" class="mt-2 space-y-2">
                        <li v-for="(event, index) in events" :key="`${event.time}-${event.title}-${index}`">
                            <p class="text-sm">
                                <span class="mr-1 font-mono text-[10px] uppercase" :class="event.severity === 'critical' ? 'text-danger' : 'text-warn'">{{ event.severity }}</span>
                                {{ event.title }}
                            </p>
                            <p class="font-mono text-[11px] text-mute">{{ event.time }} · {{ event.detail }}</p>
                        </li>
                    </ul>
                    <p v-else class="mt-2 font-mono text-[11px] text-mute">{{ boardLive ? 'Tidak ada kejadian.' : 'Tidak ada kejadian pada rentang ini.' }}</p>
                </article>
                <article class="noc-card flex-1 p-3">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Syslog</p>
                        <span class="font-mono text-[11px]" :class="!boardLive && refreshId === 'off' ? 'text-mute' : 'text-ok'">{{ !boardLive && refreshId === 'off' ? 'PAUSED' : rangeLabel }}</span>
                    </div>
                    <ul class="mt-2 space-y-2">
                        <li v-for="entry in logs" :key="entry.id">
                            <p class="font-mono text-[11px] text-mute">{{ entry.time }} · {{ entry.topics }}</p>
                            <p class="text-sm break-words">{{ entry.message }}</p>
                        </li>
                        <li v-if="!logs.length" class="font-mono text-[11px] text-mute">Menunggu log.</li>
                    </ul>
                </article>
            </div>
        </section>

        <section class="noc-card mt-3 overflow-x-auto">
            <div class="px-3 pt-2">
                <p class="text-[15px] font-semibold">Interface inventory</p>
            </div>
            <PagedTable :rows="visibleIfaces(live, query)" :text="ifaceText" :label="ifaceLabel" placeholder="Cari interface...">
            <template #default="{ rows: pageRows }">
            <table class="w-full min-w-[44rem] text-left text-sm">
                <thead class="font-mono text-[10px] tracking-wide text-mute uppercase">
                    <tr>
                        <th class="px-3 py-2 font-medium">Name</th>
                        <th class="px-3 py-2 font-medium">Type</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 font-medium">RX rate</th>
                        <th class="px-3 py-2 font-medium">TX rate</th>
                        <th class="px-3 py-2 font-medium">Total RX</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="iface in pageRows"
                        :key="`row-${iface.name}`"
                        tabindex="0"
                        class="cursor-pointer border-t border-line hover:bg-hover"
                        :class="isOpen(`iface:${iface.name}`) ? 'bg-active' : ''"
                        @click="open(interfacePreview(iface, live?.sampled_at, iface.name === live?.wan?.name ? points : (iface.name === bridgeName ? bridgePoints : [])))"
                        @keydown.enter.prevent="open(interfacePreview(iface, live?.sampled_at, iface.name === live?.wan?.name ? points : (iface.name === bridgeName ? bridgePoints : [])))"
                    >
                        <td class="px-3 py-2 font-mono text-cyan">{{ iface.name }}</td>
                        <td class="px-3 py-2 font-mono text-mist">{{ iface.type }}</td>
                        <td class="px-3 py-2">
                            <span class="rounded px-1.5 py-0.5 font-mono text-[11px]" :class="iface.running ? 'bg-ok/10 text-ok' : 'bg-tile text-mute'">{{ iface.running ? 'UP' : 'DOWN' }}</span>
                        </td>
                        <td class="px-3 py-2 font-mono text-cyan"><LiveRate :value="iface.rx_bps" /></td>
                        <td class="px-3 py-2 font-mono text-ok"><LiveRate :value="iface.tx_bps" /></td>
                        <td class="px-3 py-2 font-mono text-mist">{{ formatBytes(iface.rx_bytes) }}</td>
                    </tr>
                </tbody>
            </table>
            </template>
            </PagedTable>
        </section>
</template>
