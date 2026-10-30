<script setup>
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { live } from '../composables/useBoardTime';
import { formatRate } from '../lib/format';
import { pointLabel } from '../lib/time';

const props = defineProps({
    points: { type: Array, default: () => [] },
    heightClass: { type: String, default: 'h-28' },
    axis: { type: Boolean, default: false },
    domainStart: { type: Number, default: 0 },
    domainEnd: { type: Number, default: 0 },
});

const uid = useId();
const width = 800;
const height = 180;
const padTop = 10;
const hover = ref(null);
const rendered = ref([]);
const view = ref(null);
let fromPoints = [];
let toPoints = [];
let fromDomain = null;
let toDomain = null;
let startedAt = 0;
let frame = 0;
const glideMs = 900;

const chart = computed(() => {
    const raw = rendered.value;
    const spanMinutes = raw.length > 1 && raw.at(-1)?.ts && raw[0]?.ts
        ? (raw.at(-1).ts - raw[0].ts) / 60
        : 0;
    const barsMode = spanMinutes >= 60;
    const source = live.value || barsMode ? raw : smooth(raw);
    if (source.length === 0) {
        return { empty: true };
    }

    const samples = source.length === 1 ? [source[0], { ...source[0] }] : source;
    const rawMax = Math.max(1, ...samples.map((point) => Math.max(Number(point.rx) || 0, Number(point.tx) || 0)));
    const max = niceCeil(rawMax);
    const plotHeight = height - padTop - 2;
    const yOf = (value) => padTop + (1 - (Number(value) || 0) / max) * plotHeight;
    const stamps = samples.map((point) => point.ts).filter((stamp) => stamp > 0);
    const ownDomain = stamps.length > 1 ? { start: Math.min(...stamps), end: Math.max(...stamps) } : null;
    const forced = Number(props.domainEnd) > Number(props.domainStart)
        ? { start: Number(props.domainStart), end: Number(props.domainEnd) }
        : null;
    const domain = view.value?.end > view.value?.start ? view.value : (forced || ownDomain);
    const timed = Boolean(domain && domain.end > domain.start);
    const xOf = (point, index) => {
        if (timed && point.ts) {
            return ((point.ts - domain.start) / (domain.end - domain.start)) * width;
        }

        return samples.length <= 1 ? 0 : (index / (samples.length - 1)) * width;
    };
    const coords = (key) => samples.map((point, index) => {
        const next = samples[index + 1];
        const delta = next?.ts && point.ts ? next.ts - point.ts : 0;

        return {
            x: xOf(point, index),
            y: yOf(point[key]),
            gap: delta > 20 * 60,
        };
    });
    const rx = coords('rx');
    const tx = coords('tx');
    const rxPath = barsMode ? line(rx) : curve(rx);
    const txPath = barsMode ? line(tx) : curve(tx);
    const rxStart = rx[0]?.x ?? 0;
    const rxEnd = rx.at(-1)?.x ?? width;
    const txStart = tx[0]?.x ?? 0;
    const txEnd = tx.at(-1)?.x ?? width;
    const peakIndex = samples.reduce((best, point, index) => {
        const heightOf = (row) => Math.max(Number(row?.rx) || 0, Number(row?.tx) || 0);

        return heightOf(point) > heightOf(samples[best]) ? index : best;
    }, 0);
    const peakRx = Number(samples[peakIndex]?.rx) || 0;
    const peakTx = Number(samples[peakIndex]?.tx) || 0;
    const peakKey = peakRx >= peakTx ? 'rx' : 'tx';
    const peak = peakKey === 'rx' ? rx[peakIndex] : tx[peakIndex];
    const peakX = Math.min(width, Math.max(0, peak.x));
    const peakY = Math.min(height, Math.max(0, peak.y));

    return {
        empty: false,
        max,
        mid: max / 2,
        bars: barsMode,
        rxPath,
        txPath,
        rxBars: barsMode ? bars(rx) : '',
        txBars: barsMode ? bars(tx) : '',
        rxArea: barsMode ? '' : `${rxPath} L ${rxEnd.toFixed(1)} ${height} L ${rxStart.toFixed(1)} ${height} Z`,
        txArea: barsMode ? '' : `${txPath} L ${txEnd.toFixed(1)} ${height} L ${txStart.toFixed(1)} ${height} Z`,
        peak: {
            left: `${(peakX / width) * 100}%`,
            top: `${(peakY / height) * 100}%`,
            label: formatRate(peakKey === 'rx' ? peakRx : peakTx),
            tone: peakKey === 'rx' ? 'text-cyan ring-cyan/50' : 'text-ok ring-ok/50',
            dot: peakKey === 'rx' ? 'bg-cyan shadow-[0_0_0_3px_rgba(76,215,246,0.25)]' : 'bg-ok shadow-[0_0_0_3px_rgba(78,222,163,0.25)]',
            align: peakX > width * 0.62 ? 'right' : 'left',
            above: peakY > 46,
        },
        samples,
        timed,
        domain,
        start: axisLabel(domain, samples[0]?.t || '', 0),
        middle: axisLabel(domain, samples[Math.floor(samples.length / 2)]?.t || '', 0.5),
        end: axisLabel(domain, samples.at(-1)?.t || '', 1),
    };
});

watch(() => signature(props.points), () => {
    follow(props.points);
});

watch(live, (on) => {
    if (on) {
        return;
    }

    cancelAnimationFrame(frame);
    view.value = null;
    rendered.value = clone(props.points);
});

follow(props.points);

function follow(next) {
    const target = clone(next);
    const canGlide = live.value && continues(rendered.value, target);

    if (!canGlide) {
        cancelAnimationFrame(frame);
        view.value = null;
        rendered.value = target;

        return;
    }

    fromPoints = clone(rendered.value);
    toPoints = target;
    fromDomain = forcedDomain() ? null : domainOf(fromPoints);
    toDomain = forcedDomain() ? null : domainOf(toPoints);
    startedAt = performance.now();
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(tick);
}

function tick(now) {
    const progress = Math.min(1, (now - startedAt) / glideMs);
    const eased = 1 - (1 - progress) ** 3;
    rendered.value = mix(fromPoints, toPoints, eased);
    view.value = forcedDomain() || !(fromDomain && toDomain)
        ? null
        : {
            start: fromDomain.start + (toDomain.start - fromDomain.start) * eased,
            end: fromDomain.end + (toDomain.end - fromDomain.end) * eased,
        };

    if (progress < 1) {
        frame = requestAnimationFrame(tick);
    }
}

function mix(from, to, progress) {
    const known = new Map(from.map((point) => [point.ts, point]));
    const previous = from.at(-1) ?? { rx: 0, tx: 0 };

    return to.map((point, index) => {
        const base = known.get(point.ts) ?? from[index] ?? previous;

        return {
            t: point.t,
            ts: point.ts,
            rx: base.rx + ((Number(point.rx) || 0) - base.rx) * progress,
            tx: base.tx + ((Number(point.tx) || 0) - base.tx) * progress,
        };
    });
}

function continues(previous, next) {
    if (!previous.length || !next.length) {
        return false;
    }

    const previousStart = Number(previous[0]?.ts) || 0;
    const nextStart = Number(next[0]?.ts) || 0;
    if (!(previousStart > 0 && nextStart > 0 && Math.abs(previousStart - nextStart) <= 2 && next.length >= previous.length && next.length <= previous.length + 2)) {
        return false;
    }

    const shared = Math.min(previous.length, next.length - 1);

    for (let index = 0; index < shared; index += 1) {
        if (previous[index].ts !== next[index].ts) {
            return false;
        }
    }

    return true;
}

function clone(points) {
    return (points ?? []).map((point) => ({
        t: point.t || '',
        ts: Number(point.ts) || 0,
        rx: Number(point.rx) || 0,
        tx: Number(point.tx) || 0,
    }));
}

function forcedDomain() {
    return Number(props.domainEnd) > Number(props.domainStart)
        ? { start: Number(props.domainStart), end: Number(props.domainEnd) }
        : null;
}

function axisLabel(domain, fallback, at) {
    if (!domain || (!forcedDomain() && !view.value)) {
        return fallback;
    }

    const ts = Math.round(domain.start + (domain.end - domain.start) * at);

    return pointLabel(ts, Math.max(1, (domain.end - domain.start) / 60));
}

function domainOf(points) {
    const stamps = points.map((point) => point.ts).filter((stamp) => stamp > 0);
    if (stamps.length < 2) {
        return null;
    }

    return { start: Math.min(...stamps), end: Math.max(...stamps) };
}

function signature(points) {
    const last = points?.at?.(-1);

    return `${points?.length ?? 0}:${last?.ts ?? ''}:${last?.rx ?? ''}:${last?.tx ?? ''}`;
}

onBeforeUnmount(() => cancelAnimationFrame(frame));

function onMove(event) {
    const samples = chart.value.samples ?? [];
    if (samples.length === 0) {
        return;
    }

    const rect = event.currentTarget.getBoundingClientRect();
    const ratio = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
    const index = chart.value.timed
        ? nearest(samples, chart.value.domain, ratio)
        : Math.round(ratio * (samples.length - 1));
    const point = samples[index];
    const plotHeight = height - padTop - 2;
    const yPercent = (value) => {
        const y = padTop + (1 - (Number(value) || 0) / chart.value.max) * plotHeight;

        return `${(y / height) * 100}%`;
    };

    const span = chart.value.timed ? chart.value.domain.end - chart.value.domain.start : 0;
    const left = span > 0 && point.ts
        ? ((point.ts - chart.value.domain.start) / span) * 100
        : (index / Math.max(1, samples.length - 1)) * 100;

    hover.value = {
        left: `${left}%`,
        flip: ratio > 0.62,
        time: point.t || '',
        rx: formatRate(point.rx),
        tx: formatRate(point.tx),
        rxTop: yPercent(point.rx),
        txTop: yPercent(point.tx),
    };
}

function onLeave() {
    hover.value = null;
}

function nearest(samples, domain, ratio) {
    const target = ratio * width;
    let best = 0;
    let bestDistance = Infinity;

    samples.forEach((point, index) => {
        const x = domain.end > domain.start && point.ts
            ? ((point.ts - domain.start) / (domain.end - domain.start)) * width
            : (index / Math.max(1, samples.length - 1)) * width;
        const distance = Math.abs(x - target);
        if (distance < bestDistance) {
            best = index;
            bestDistance = distance;
        }
    });

    return best;
}

function smooth(points, window = 3) {
    return points.map((point, index) => {
        const slice = points.slice(Math.max(0, index - window + 1), index + 1);
        const avg = (key) => slice.reduce((sum, row) => sum + (Number(row[key]) || 0), 0) / slice.length;

        return { ...point, rx: avg('rx'), tx: avg('tx') };
    });
}

function niceCeil(value) {
    if (value <= 1) {
        return 1;
    }

    const padded = value * 1.12;
    const exponent = 10 ** Math.floor(Math.log10(padded));
    const fraction = padded / exponent;
    const nice = fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 2.5 ? 2.5 : fraction <= 5 ? 5 : 10;

    return nice * exponent;
}

function curve(coords) {
    const clamp = (value) => Math.max(padTop, Math.min(height, value));
    let path = `M ${coords[0].x.toFixed(1)} ${coords[0].y.toFixed(1)}`;

    for (let index = 0; index < coords.length - 1; index += 1) {
        const previous = coords[index - 1] ?? coords[index];
        const current = coords[index];
        const next = coords[index + 1];
        const after = coords[index + 2] ?? next;
        const c1x = current.x + (next.x - previous.x) / 6;
        const c1y = clamp(current.y + (next.y - previous.y) / 6);
        const c2x = next.x - (after.x - current.x) / 6;
        const c2y = clamp(next.y - (after.y - current.y) / 6);
        path += ` C ${c1x.toFixed(1)} ${c1y.toFixed(1)}, ${c2x.toFixed(1)} ${c2y.toFixed(1)}, ${next.x.toFixed(1)} ${next.y.toFixed(1)}`;
    }

    return path;
}

function line(coords) {
    return coords.map((point, index) => {
        const command = index === 0 || coords[index - 1].gap ? 'M' : 'L';

        return `${command} ${point.x.toFixed(1)} ${point.y.toFixed(1)}`;
    }).join(' ');
}

function bars(coords) {
    if (coords.length === 0) {
        return '';
    }

    const gaps = coords.slice(1).map((point, index) => Math.abs(point.x - coords[index].x)).filter((gap) => gap > 0.5).sort((left, right) => left - right);
    const slot = gaps.length ? gaps[Math.floor(gaps.length / 2)] : 8;
    const barWidth = Math.max(1.4, Math.min(slot * 0.72, 16));

    return coords.map((point) => {
        const y = Math.min(height - 0.5, Math.max(padTop, point.y));
        const x = Math.max(0, point.x - barWidth / 2);
        const barHeight = Math.max(0.8, height - y);

        return `M ${x.toFixed(1)} ${y.toFixed(1)} h ${barWidth.toFixed(1)} v ${barHeight.toFixed(1)} h ${(-barWidth).toFixed(1)} Z`;
    }).join(' ');
}
</script>

<template>
    <div v-if="chart.empty" class="flex items-center font-mono text-[11px] text-mute" :class="heightClass">
        Belum ada sampel.
    </div>
    <div v-else>
        <div class="flex" :class="heightClass">
            <div v-if="axis" class="flex w-20 shrink-0 flex-col justify-between py-1 pr-2 text-right font-mono text-[10px] leading-none whitespace-nowrap text-mute">
                <span>{{ formatRate(chart.max) }}</span>
                <span>{{ formatRate(chart.mid) }}</span>
                <span>0</span>
            </div>
            <div class="relative min-w-0 flex-1">
                <svg class="h-full w-full overflow-hidden" :viewBox="`0 0 ${width} ${height}`" preserveAspectRatio="none">
                    <defs>
                        <linearGradient :id="`${uid}-rx`" x1="0" x2="0" y1="0" y2="1">
                            <stop offset="0%" stop-color="var(--color-cyan)" :stop-opacity="chart.bars ? 0.78 : 0.38" />
                            <stop offset="100%" stop-color="var(--color-cyan)" :stop-opacity="chart.bars ? 0.34 : 0" />
                        </linearGradient>
                        <linearGradient :id="`${uid}-tx`" x1="0" x2="0" y1="0" y2="1">
                            <stop offset="0%" stop-color="var(--color-ok)" :stop-opacity="chart.bars ? 0.62 : 0.28" />
                            <stop offset="100%" stop-color="var(--color-ok)" :stop-opacity="chart.bars ? 0.22 : 0" />
                        </linearGradient>
                    </defs>
                    <g stroke="var(--color-line)" stroke-width="1" vector-effect="non-scaling-stroke">
                        <line v-for="step in chart.bars ? 5 : 0" :key="`v-${step}`" :x1="(width / 4) * (step - 1)" :x2="(width / 4) * (step - 1)" y1="0" :y2="height" />
                        <line x1="0" :x2="width" :y1="padTop" :y2="padTop" />
                        <line x1="0" :x2="width" :y1="height * 0.34" :y2="height * 0.34" />
                        <line x1="0" :x2="width" :y1="height * 0.67" :y2="height * 0.67" />
                        <line x1="0" :x2="width" :y1="height - 1" :y2="height - 1" />
                    </g>
                    <path v-if="chart.bars" :d="chart.txBars" :fill="`url(#${uid}-tx)`" />
                    <path v-if="chart.bars" :d="chart.rxBars" :fill="`url(#${uid}-rx)`" />
                    <path v-if="!chart.bars" :d="chart.txArea" :fill="`url(#${uid}-tx)`" />
                    <path v-if="!chart.bars" :d="chart.rxArea" :fill="`url(#${uid}-rx)`" />
                    <path v-if="!chart.bars" :d="chart.txPath" fill="none" stroke="var(--color-ok)" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                    <path v-if="!chart.bars" :d="chart.rxPath" fill="none" stroke="var(--color-cyan)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                </svg>
                <span
                    v-if="axis"
                    class="pointer-events-none absolute size-2 -translate-x-1/2 -translate-y-1/2 rounded-full"
                    :class="chart.peak.dot"
                    :style="{ left: chart.peak.left, top: chart.peak.top }"
                />
                <span
                    v-if="axis && !hover"
                    class="pointer-events-none absolute z-10 whitespace-nowrap rounded bg-tooltip px-1.5 py-0.5 font-mono text-[10px] shadow-md ring-1"
                    :class="[
                        chart.peak.tone,
                        chart.peak.align === 'right' ? '-translate-x-full' : 'translate-x-1',
                        chart.peak.above ? '-translate-y-full' : 'translate-y-1',
                    ]"
                    :style="{ left: chart.peak.left, top: chart.peak.above ? `calc(${chart.peak.top} - 8px)` : `calc(${chart.peak.top} + 8px)` }"
                >
                    {{ chart.peak.label }}
                </span>
                <div class="absolute inset-0 cursor-crosshair" @mousemove="onMove" @mouseleave="onLeave">
                    <template v-if="hover">
                        <span class="pointer-events-none absolute inset-y-0 w-px bg-paper/25" :style="{ left: hover.left }" />
                        <span class="pointer-events-none absolute size-2 -translate-x-1/2 -translate-y-1/2 rounded-full bg-cyan" :style="{ left: hover.left, top: hover.rxTop }" />
                        <span class="pointer-events-none absolute size-2 -translate-x-1/2 -translate-y-1/2 rounded-full bg-ok" :style="{ left: hover.left, top: hover.txTop }" />
                        <div
                            class="pointer-events-none absolute top-2 z-10 rounded bg-tooltip px-2 py-1.5 font-mono text-[10px] leading-4 shadow-md ring-1 ring-line"
                            :class="hover.flip ? '-translate-x-[calc(100%+8px)]' : 'translate-x-2'"
                            :style="{ left: hover.left }"
                        >
                            <p class="text-mist">{{ hover.time }}</p>
                            <p class="text-cyan">↓ {{ hover.rx }}</p>
                            <p class="text-ok">↑ {{ hover.tx }}</p>
                        </div>
                    </template>
                </div>
            </div>
        </div>
        <div v-if="axis" class="mt-1 flex justify-between pl-20 font-mono text-[10px] text-mute">
            <span>{{ chart.start }}</span>
            <span>{{ chart.middle }}</span>
            <span class="text-cyan">{{ chart.end }}</span>
        </div>
    </div>
</template>
