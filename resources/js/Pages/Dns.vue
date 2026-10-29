<script setup>
import { computed, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import PagedTable from '../Components/PagedTable.vue';
import PreviewBinder from '../Components/PreviewBinder.vue';
import SortHead from '../Components/SortHead.vue';
import { live as boardLive, ranges } from '../composables/useBoardTime';
import { useBoardPulse } from '../composables/usePulse';
import { usePreview } from '../composables/usePreview';
import { clockMillis } from '../lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    pulse: { type: Object, default: null },
    minutes: { type: Number, default: 30 },
    from: { type: Number, default: null },
    to: { type: Number, default: null },
    queries: { type: Array, default: () => [] },
});

const live = useBoardPulse();
const query = '';
const entries = ref([...(props.queries ?? [])]);
const { open, preview, isOpen } = usePreview();

const rangeLabel = computed(() => {
    if (boardLive.value) {
        return 'Live';
    }

    if (props.from && props.to) {
        return 'Rentang khusus';
    }

    return ranges.find((item) => item.minutes === props.minutes)?.label ?? `Last ${props.minutes} minutes`;
});

watch(() => props.queries, (next) => {
    entries.value = [...(next ?? [])];
});

const devices = computed(() => {
    const leases = new Map((live.value?.leases ?? []).map((lease) => [lease.address, lease]));
    const groups = new Map();

    entries.value.forEach((row) => {
        const current = groups.get(row.address) ?? {
            address: row.address,
            host: leases.get(row.address)?.host || '',
            mac: leases.get(row.address)?.mac || '',
            browser: '',
            app: row.app || '',
            queries: 0,
            last: row.time || '',
            domains: [],
        };

        current.queries += 1;
        if (!current.browser && row.browser) {
            current.browser = row.browser;
        }
        if (current.domains.length < 40) {
            current.domains.push(row.domain);
        }
        groups.set(row.address, current);
    });

    return [...groups.values()];
});

const appCount = computed(() => new Set(entries.value.map((row) => row.app).filter(Boolean)).size);

const columnLabels = {
    host: 'Perangkat',
    browser: 'Browser',
    app: 'Aplikasi',
    queries: 'Query',
    last: 'Terakhir',
};
const columnLabel = (id) => columnLabels[id] || id;
const sortColumns = [
    { id: 'host', type: 'text', first: 'asc', get: (row) => row.host || row.address || '' },
    { id: 'browser', type: 'text', first: 'asc', get: (row) => row.browser || '' },
    { id: 'app', type: 'text', first: 'asc', get: (row) => row.app || '' },
    { id: 'queries', type: 'number', first: 'desc', get: (row) => row.queries },
    { id: 'last', type: 'number', first: 'desc', get: (row) => clockMillis(row.last) },
];

function devicePreview(device) {
    const list = entries.value.filter((row) => row.address === device.address).slice(0, 24);

    return {
        key: `dns:${device.address}`,
        icon: 'language',
        title: device.host || device.address,
        subtitle: device.mac || device.address,
        detail: device.address,
        status: device.browser ? { label: device.browser, tone: 'ok' } : { label: 'DNS', tone: 'mute' },
        tabs: [{
            id: 'overview',
            icon: 'language',
            label: 'DNS',
            hero: true,
            sections: [{
                title: 'Domain terbaru',
                rows: list.map((row) => ({
                    label: String(row.time || '').slice(11, 19) || '–',
                    value: `${row.domain} · ${row.app}`,
                })),
            }],
        }],
    };
}

function activeDevice() {
    const key = preview.value?.key;
    const device = devices.value.find((row) => `dns:${row.address}` === key);

    return device ? devicePreview(device) : null;
}

const deviceText = (row) => [row.host, row.address, row.mac, row.browser, row.app, row.domains.join(' ')].join(' ');
const deviceLabel = (row) => row.host || row.address || '';
</script>

<template>
    <Head title="DNS & Apps" />

    <PreviewBinder :value="activeDevice()" />

    <div>
        <h2 class="text-[15px] font-semibold">DNS & Apps</h2>
        <p class="font-mono text-[11px] text-mute">{{ rangeLabel }} · domain yang ditanyakan tiap perangkat</p>
    </div>

    <section class="mt-3 grid gap-3 sm:grid-cols-3">
        <article class="noc-card p-3">
            <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Perangkat</p>
            <p class="mt-1 text-2xl font-bold">{{ devices.length }}</p>
        </article>
        <article class="noc-card p-3">
            <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Query</p>
            <p class="mt-1 text-2xl font-bold text-cyan">{{ entries.length }}</p>
        </article>
        <article class="noc-card p-3">
            <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Aplikasi</p>
            <p class="mt-1 text-2xl font-bold">{{ appCount }}</p>
        </article>
    </section>

    <p class="mt-3 font-mono text-[11px] text-mute">Aplikasi ditebak dari domain. Browser hanya muncul kalau domainnya khas browser itu.</p>

    <section class="noc-card mt-3 overflow-x-auto">
        <PagedTable :rows="devices" :text="deviceText" :label="deviceLabel" :columns="sortColumns" default-sort="queries" placeholder="Cari perangkat, domain, aplikasi...">
            <template #default="{ rows: pageRows, sortOf }">
                <table class="w-full min-w-[48rem] text-left text-sm">
                    <thead class="font-mono text-[10px] tracking-wide text-mute uppercase">
                        <tr>
                            <th v-for="column in sortColumns" :key="column.id" class="group p-0 font-medium">
                                <SortHead :active="sortOf(column.id).active" :icon="sortOf(column.id).icon" @sort="sortOf(column.id).toggle()">
                                    {{ columnLabel(column.id) }}
                                </SortHead>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="device in pageRows"
                            :key="device.address"
                            tabindex="0"
                            class="cursor-pointer border-t border-line hover:bg-hover"
                            :class="isOpen(`dns:${device.address}`) ? 'bg-active' : ''"
                            @click="open(devicePreview(device))"
                            @keydown.enter.prevent="open(devicePreview(device))"
                        >
                            <td class="px-3 py-2">
                                <p>{{ device.host || '–' }}</p>
                                <p class="font-mono text-[11px] text-cyan">{{ device.address }}</p>
                            </td>
                            <td class="px-3 py-2 font-mono text-[11px]" :class="device.browser ? 'text-ok' : 'text-mute'">{{ device.browser || '–' }}</td>
                            <td class="px-3 py-2">{{ device.app || '–' }}</td>
                            <td class="px-3 py-2 font-mono text-mist">{{ device.queries }}</td>
                            <td class="px-3 py-2 font-mono text-[11px] text-mist">{{ device.last || '–' }}</td>
                        </tr>
                        <tr v-if="pageRows.length === 0">
                            <td colspan="5" class="px-3 py-8 text-center font-mono text-[11px] text-mute">Belum ada query DNS pada rentang ini.</td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </PagedTable>
    </section>
</template>
