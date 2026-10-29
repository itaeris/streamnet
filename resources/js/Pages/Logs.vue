<script setup>
import { computed, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import PagedTable from '../Components/PagedTable.vue';
import PreviewBinder from '../Components/PreviewBinder.vue';
import PulseWatch from '../Components/PulseWatch.vue';
import SegmentTabs from '../Components/SegmentTabs.vue';
import { live as boardLive, ranges, refreshId } from '../composables/useBoardTime';
import { useBoardPulse } from '../composables/usePulse';
import { usePreview } from '../composables/usePreview';
import { clockMillis } from '../lib/format';
import { inWindow } from '../lib/time';

defineOptions({ layout: AppLayout });

const props = defineProps({
    pulse: { type: Object, default: null },
    minutes: { type: Number, default: 30 },
    from: { type: Number, default: null },
    to: { type: Number, default: null },
    logs: { type: Array, default: () => [] },
});

const live = useBoardPulse();
const query = '';
const rangeLabel = computed(() => {
    if (boardLive.value) {
        return 'Live';
    }

    if (props.from && props.to) {
        return 'Rentang khusus';
    }

    return ranges.find((item) => item.minutes === props.minutes)?.label ?? `Last ${props.minutes} minutes`;
});

const entries = ref([...(props.logs ?? [])]);
const topic = ref('all');
const { open, preview, isOpen } = usePreview();

function logPreview(entry) {
    const severity = entry.severity || 'info';
    const tone = severity === 'critical' || severity === 'error' ? 'danger' : severity === 'warning' ? 'warn' : 'mute';

    return {
        key: `log:${entry.id}`,
        icon: 'article',
        title: entry.topics || 'log',
        subtitle: entry.time || '',
        status: { label: severity, tone },
        tabs: [{
            id: 'overview',
            icon: 'article',
            label: 'Log',
            hero: true,
            sections: [{
                rows: [
                    { label: 'Time', value: entry.time || '–' },
                    { label: 'Topics', value: entry.topics || '–' },
                    { label: 'Severity', value: severity, tone },
                ],
            }],
            body: entry.message || '',
        }],
    };
}

function activeLog() {
    const key = preview.value?.key;
    const entry = entries.value.find((row) => `log:${row.id}` === key);

    return entry ? logPreview(entry) : null;
}

watch(() => props.logs, (next) => {
    entries.value = [...(next ?? [])];
});

function logInside(entry) {
    if (boardLive.value) {
        return true;
    }

    return inWindow(Math.floor(clockMillis(entry.time) / 1000), props.minutes, props.from, props.to);
}

function onPulse(next) {
    const fresh = (next?.logs ?? []).filter((entry) => entry.id && !entries.value.some((current) => current.id === entry.id) && logInside(entry));
    entries.value = [...fresh, ...entries.value].filter(logInside).slice(0, 400);
}

const topics = computed(() => {
    const names = new Set(['all']);
    for (const entry of entries.value) {
        for (const part of String(entry.topics || '').split(',')) {
            if (part) {
                names.add(part);
            }
        }
    }
    return [...names].slice(0, 12);
});

const visible = (search) => {
    const needle = String(search || '').trim().toLowerCase();

    return entries.value.filter((entry) => {
        const topicOk = topic.value === 'all' || String(entry.topics || '').split(',').includes(topic.value);
        if (!topicOk) {
            return false;
        }
        if (!needle) {
            return true;
        }
        return `${entry.message} ${entry.topics}`.toLowerCase().includes(needle);
    });
};

const logText = (entry) => `${entry.message} ${entry.topics}`;
const logLabel = (entry) => entry.message || entry.topics || '';
const logTime = (entry) => clockMillis(entry.time);

function tone(entry) {
    if (entry.severity === 'critical') {
        return 'text-danger';
    }
    if (entry.severity === 'warning') {
        return 'text-warn';
    }
    return 'text-mute';
}
</script>

<template>
    <Head title="Log Stream" />

        <PulseWatch :pulse="live" @pulse="onPulse" />
        <PreviewBinder :value="activeLog()" />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-[15px] font-semibold">Log stream</h2>
                <p class="font-mono text-[11px] text-mute">{{ rangeLabel }}</p>
            </div>
            <span class="rounded px-2 py-0.5 font-mono text-[11px]" :class="boardLive || refreshId !== 'off' ? 'bg-ok/10 text-ok' : 'bg-tile text-mute'">{{ boardLive ? 'LIVE' : refreshId === 'off' ? 'PAUSED' : refreshId.toUpperCase() }}</span>
        </div>

        <SegmentTabs v-model="topic" class="mt-3" :options="topics" />

        <div class="noc-card mt-3 overflow-hidden bg-sink">
            <PagedTable :rows="visible(query)" :text="logText" :label="logLabel" :time="logTime" default-sort="latest" placeholder="Cari pesan log...">
            <template #default="{ rows: pageRows }">
            <ol>
            <li
                v-for="entry in pageRows"
                :key="entry.id"
                tabindex="0"
                class="cursor-pointer border-b border-line px-3 py-2.5 last:border-0 hover:bg-hover"
                :class="isOpen(`log:${entry.id}`) ? 'bg-active' : ''"
                @click="open(logPreview(entry))"
                @keydown.enter.prevent="open(logPreview(entry))"
            >
                <p class="font-mono text-[11px]" :class="tone(entry)">{{ entry.time }} · {{ entry.topics }}</p>
                <p class="mt-1 text-sm break-words">{{ entry.message }}</p>
            </li>
            <li v-if="pageRows.length === 0" class="px-3 py-8 text-center font-mono text-[11px] text-mute">{{ boardLive ? 'Tidak ada log.' : 'Tidak ada log pada rentang ini.' }}</li>
            </ol>
            </template>
            </PagedTable>
        </div>
</template>
