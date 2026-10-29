<script setup>
import { ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import Dropdown from '../Components/Dropdown.vue';
import PulseWatch from '../Components/PulseWatch.vue';
import LiveRate from '../Components/LiveRate.vue';
import TrafficChart from '../Components/TrafficChart.vue';
import { live as boardLive, pinned } from '../composables/useBoardTime';
import { useBoardPulse } from '../composables/usePulse';
import { formatBytes } from '../lib/format';
import { adoptSeries, pointLabel, pushPoint } from '../lib/time';

defineOptions({ layout: AppLayout });

const props = defineProps({
    pulse: { type: Object, default: null },
    minutes: { type: Number, default: 30 },
    from: { type: Number, default: null },
    to: { type: Number, default: null },
    series: { type: Object, default: () => ({}) },
});

const live = useBoardPulse();
const query = '';
const cloneSeries = (value) => JSON.parse(JSON.stringify(value ?? {}));
const charts = ref(cloneSeries(props.series));
const selected = ref(props.pulse?.wan?.name || Object.keys(props.series ?? {})[0] || '');

watch(() => props.series, (next) => {
    const incoming = next ?? {};
    if (!boardLive.value || Object.keys(charts.value).length === 0) {
        charts.value = cloneSeries(incoming);
    } else {
        const merged = { ...charts.value };
        for (const [name, rows] of Object.entries(incoming)) {
            merged[name] = adoptSeries(merged[name] ?? [], rows ?? [], boardLive.value);
        }
        charts.value = merged;
    }
    if (!selected.value) {
        selected.value = Object.keys(charts.value)[0] || '';
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

    for (const iface of next?.interfaces ?? []) {
        const current = charts.value[iface.name] ?? [];
        const span = boardLive.value ? 31 * 1440 : props.minutes;
        charts.value = {
            ...charts.value,
            [iface.name]: pushPoint(current, {
                t: pointLabel(ts, span),
                ts,
                rx: iface.rx_bps,
                tx: iface.tx_bps,
            }, span, boardLive.value ? null : props.from, boardLive.value ? null : props.to),
        };
    }
}

const rateFor = (live, name) => (live?.interfaces ?? []).find((iface) => iface.name === name);

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
            <p class="font-mono text-[11px] text-mute">{{ boardLive ? 'Live · RX cyan · TX green' : 'RX cyan · TX green · rentang diatur dari bilah waktu' }}</p>
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
                    <span class="text-cyan">↓ <LiveRate :value="rateFor(live, selected)?.rx_bps" /></span>
                    <span class="mx-2 text-line">/</span>
                    <span class="text-ok">↑ <LiveRate :value="rateFor(live, selected)?.tx_bps" /></span>
                </p>
            </div>
            <div class="mt-3 rounded bg-sink p-2">
                <TrafficChart :points="charts[selected] || []" axis height-class="h-64" />
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
                <TrafficChart class="mt-2" :points="charts[iface.name] || []" height-class="h-16" />
                <p class="mt-2 font-mono text-[11px] text-mute">RX {{ formatBytes(iface.rx_bytes) }} · err {{ iface.rx_error }} · drop {{ iface.rx_drop }}</p>
            </button>
        </section>
</template>
