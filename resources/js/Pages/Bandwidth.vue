<script setup>
import { computed, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import Dropdown from '../Components/Dropdown.vue';
import PulseWatch from '../Components/PulseWatch.vue';
import LiveRate from '../Components/LiveRate.vue';
import TrafficChart from '../Components/TrafficChart.vue';
import { live as boardLive, liveSpans, pinned } from '../composables/useBoardTime';
import { useBoardPulse } from '../composables/usePulse';
import { formatBytes, formatRate } from '../lib/format';
import { adoptSeries, pointLabel, pushPoint } from '../lib/time';

defineOptions({ layout: AppLayout });

const props = defineProps({
    pulse: { type: Object, default: null },
    minutes: { type: Number, default: 30 },
    from: { type: Number, default: null },
    to: { type: Number, default: null },
    series: { type: Object, default: () => ({}) },
    queues: { type: Object, default: () => ({}) },
});

const live = useBoardPulse();
const query = '';
const cloneSeries = (value) => JSON.parse(JSON.stringify(value ?? {}));
const charts = ref(cloneSeries(props.series));
const queueCharts = ref(cloneSeries(props.queues));
const selected = ref(props.pulse?.wan?.name || Object.keys(props.series ?? {})[0] || '');
const selectedQueue = ref(props.pulse?.shaping?.queues?.[0]?.name || Object.keys(props.queues ?? {})[0] || '');
const loadedMinutes = ref(props.minutes);
const axisEnd = ref(props.to || Math.floor(Date.now() / 1000));
const axisStart = computed(() => props.from || axisEnd.value - (Number(props.minutes) || 30) * 60);
const spanLabel = computed(() => liveSpans.find((item) => item.minutes === props.minutes)?.label ?? '24 jam');

watch(() => props.queues, (next) => {
    const incoming = next ?? {};
    const replace = !boardLive.value || loadedMinutes.value !== props.minutes || Object.keys(queueCharts.value).length === 0;
    if (replace) {
        queueCharts.value = cloneSeries(incoming);
    } else {
        const merged = { ...queueCharts.value };
        for (const [name, rows] of Object.entries(incoming)) {
            merged[name] = adoptSeries(merged[name] ?? [], rows ?? [], true);
        }
        queueCharts.value = merged;
    }
    if (!selectedQueue.value) {
        selectedQueue.value = Object.keys(queueCharts.value)[0] || '';
    }
});

watch(() => props.series, (next) => {
    const incoming = next ?? {};
    const replace = !boardLive.value || loadedMinutes.value !== props.minutes || Object.keys(charts.value).length === 0;
    loadedMinutes.value = props.minutes;
    if (replace) {
        charts.value = cloneSeries(incoming);
    } else {
        const merged = { ...charts.value };
        for (const [name, rows] of Object.entries(incoming)) {
            merged[name] = adoptSeries(merged[name] ?? [], rows ?? [], true);
        }
        charts.value = merged;
    }
    if (!selected.value) {
        selected.value = Object.keys(charts.value)[0] || '';
    }
    if (!props.to) {
        const newest = Math.max(0, ...Object.values(charts.value).flat().map((point) => Number(point?.ts) || 0));
        if (newest > 0) {
            axisEnd.value = newest;
        }
    }
});

function onPulse(next) {
    const name = next?.wan?.name;
    if (name && !selected.value) {
        selected.value = name;
    }

    if (pinned.value && !boardLive.value) {
        return;
    }

    const ts = next?.sampled_at ? Math.floor(Date.parse(next.sampled_at) / 1000) : Math.floor(Date.now() / 1000);
    if (!props.to) {
        axisEnd.value = ts;
    }

    for (const iface of next?.interfaces ?? []) {
        const current = charts.value[iface.name] ?? [];
        charts.value = {
            ...charts.value,
            [iface.name]: pushPoint(current, {
                t: pointLabel(ts, props.minutes),
                ts,
                rx: iface.rx_bps,
                tx: iface.tx_bps,
            }, props.minutes, props.from, props.to),
        };
    }

    let queuesMerged = queueCharts.value;
    for (const queue of next?.shaping?.queues ?? []) {
        if (!queue?.name) {
            continue;
        }
        queuesMerged = {
            ...queuesMerged,
            [queue.name]: pushPoint(queuesMerged[queue.name] ?? [], {
                t: pointLabel(ts, props.minutes),
                ts,
                rx: queue.rate_down,
                tx: queue.rate_up,
            }, props.minutes, props.from, props.to),
        };
    }
    queueCharts.value = queuesMerged;
    if (!selectedQueue.value && next?.shaping?.queues?.[0]?.name) {
        selectedQueue.value = next.shaping.queues[0].name;
    }
}

const rateFor = (live, name) => (live?.interfaces ?? []).find((iface) => iface.name === name);

const queueRows = (live) => {
    const rows = live?.shaping?.queues ?? [];
    if (rows.length) {
        return rows.filter((queue) => queue.name);
    }

    return Object.keys(queueCharts.value).map((name) => ({ name, rate_down: 0, rate_up: 0, disabled: false, target: '' }));
};

const queueRate = (live, name) => (live?.shaping?.queues ?? []).find((queue) => queue.name === name);

const visibleIfaces = (live, query) => {
    const needle = String(query || '').trim().toLowerCase();
    const rows = live?.interfaces ?? [];
    if (!needle) {
        return rows;
    }
    return rows.filter((iface) => iface.name.toLowerCase().includes(needle));
};
</script>

<template>
    <Head title="Interfaces & Traffic" />

        <PulseWatch :pulse="live" @pulse="onPulse" />

        <div>
            <h2 class="text-[15px] font-semibold">Interfaces & traffic</h2>
            <p class="font-mono text-[11px] text-mute">{{ boardLive ? `Live · ${spanLabel} · RX cyan · TX green` : 'RX cyan · TX green · rentang diatur dari bilah waktu' }}</p>
        </div>

        <article class="noc-card mt-3 p-3">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <label class="font-mono text-[11px] text-mute">
                    Interface
                    <Dropdown
                        v-model="selected"
                        :options="(live?.interfaces || []).map((iface) => ({ value: iface.name, label: iface.name }))"
                    />
                </label>
                <p class="font-mono text-sm">
                    <span class="text-cyan">↓ <LiveRate :key="`${selected}-rx`" :value="rateFor(live, selected)?.rx_bps" /></span>
                    <span class="mx-2 text-line">/</span>
                    <span class="text-ok">↑ <LiveRate :key="`${selected}-tx`" :value="rateFor(live, selected)?.tx_bps" /></span>
                </p>
            </div>
            <div class="mt-3 rounded bg-sink p-2">
                <TrafficChart :key="selected" :points="charts[selected] || []" axis height-class="h-64" :domain-start="axisStart" :domain-end="axisEnd" />
            </div>
        </article>

        <section class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <button
                v-for="iface in visibleIfaces(live, query)"
                :key="iface.name"
                type="button"
                class="noc-card p-3 text-left"
                :class="selected === iface.name ? 'outline outline-cyan-2' : ''"
                @click="selected = iface.name"
            >
                <div class="flex items-center justify-between gap-3">
                    <p class="truncate font-mono text-sm text-cyan">{{ iface.name }}</p>
                    <span class="rounded px-1.5 py-0.5 font-mono text-[10px]" :class="iface.running ? 'bg-ok/10 text-ok' : 'bg-tile text-mute'">{{ iface.running ? 'UP' : 'DOWN' }}</span>
                </div>
                <p class="mt-2 font-mono text-[11px]">
                    <span class="text-cyan"><LiveRate :value="iface.rx_bps" /></span>
                    <span class="text-mute"> · </span>
                    <span class="text-ok"><LiveRate :value="iface.tx_bps" /></span>
                </p>
                <TrafficChart class="mt-2" :points="charts[iface.name] || []" height-class="h-16" :domain-start="axisStart" :domain-end="axisEnd" />
                <p class="mt-2 font-mono text-[11px] text-mute">RX {{ formatBytes(iface.rx_bytes) }} · err {{ iface.rx_error }} · drop {{ iface.rx_drop }}</p>
            </button>
        </section>

        <section class="mt-6">
            <h2 class="text-[15px] font-semibold">Queue traffic</h2>
            <p class="font-mono text-[11px] text-mute">Download cyan · upload green</p>

            <p v-if="!queueRows(live).length" class="mt-3 font-mono text-[11px] text-mute">Belum ada simple queue.</p>

            <article v-else class="noc-card mt-3 p-3">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <label class="font-mono text-[11px] text-mute">
                        Queue
                        <Dropdown
                            v-model="selectedQueue"
                            :options="queueRows(live).map((queue) => ({ value: queue.name, label: queue.name }))"
                        />
                    </label>
                    <p class="font-mono text-sm">
                        <span class="text-cyan">↓ <LiveRate :key="`${selectedQueue}-down`" :value="queueRate(live, selectedQueue)?.rate_down" /></span>
                        <span class="mx-2 text-line">/</span>
                        <span class="text-ok">↑ <LiveRate :key="`${selectedQueue}-up`" :value="queueRate(live, selectedQueue)?.rate_up" /></span>
                    </p>
                </div>
                <div class="mt-3 rounded bg-sink p-2">
                    <TrafficChart :key="`queue-${selectedQueue}`" :points="queueCharts[selectedQueue] || []" axis height-class="h-64" :domain-start="axisStart" :domain-end="axisEnd" />
                </div>
            </article>

            <div v-if="queueRows(live).length" class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <button
                    v-for="queue in queueRows(live)"
                    :key="queue.name"
                    type="button"
                    class="noc-card p-3 text-left"
                    :class="selectedQueue === queue.name ? 'outline outline-cyan-2' : ''"
                    @click="selectedQueue = queue.name"
                >
                    <div class="flex items-center justify-between gap-3">
                        <p class="truncate font-mono text-sm text-cyan">{{ queue.name }}</p>
                        <span class="rounded px-1.5 py-0.5 font-mono text-[10px]" :class="queue.disabled ? 'bg-tile text-mute' : 'bg-ok/10 text-ok'">{{ queue.disabled ? 'OFF' : 'ON' }}</span>
                    </div>
                    <p v-if="queue.target" class="mt-1 truncate font-mono text-[11px] text-mute">{{ queue.target }}</p>
                    <p class="mt-2 font-mono text-[11px]">
                        <span class="text-cyan"><LiveRate :value="queue.rate_down" /></span>
                        <span class="text-mute"> · </span>
                        <span class="text-ok"><LiveRate :value="queue.rate_up" /></span>
                    </p>
                    <TrafficChart class="mt-2" :points="queueCharts[queue.name] || []" height-class="h-16" :domain-start="axisStart" :domain-end="axisEnd" />
                    <p class="mt-2 font-mono text-[11px] text-mute">max ↓ {{ formatRate(queue.max_down) }} · ↑ {{ formatRate(queue.max_up) }}</p>
                </button>
            </div>
        </section>
</template>
