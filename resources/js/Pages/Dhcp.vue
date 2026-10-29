<script setup>
import { computed, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import LiveRate from '../Components/LiveRate.vue';
import PagedTable from '../Components/PagedTable.vue';
import SortHead from '../Components/SortHead.vue';
import PreviewBinder from '../Components/PreviewBinder.vue';
import SegmentTabs from '../Components/SegmentTabs.vue';
import { live as boardLive, ranges } from '../composables/useBoardTime';
import { useBoardPulse } from '../composables/usePulse';
import { usePreview } from '../composables/usePreview';
import { aliasOf } from '../composables/useLeaseAlias';
import { durationSeconds, formatRate } from '../lib/format';
import { inWindow } from '../lib/time';

defineOptions({ layout: AppLayout });

const props = defineProps({
    pulse: { type: Object, default: null },
    minutes: { type: Number, default: 30 },
    from: { type: Number, default: null },
    to: { type: Number, default: null },
    leases: { type: Array, default: () => [] },
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

const status = ref('all');
const measuredLabel = (pulse) => {
    const lease = (pulse?.leases ?? []).find((row) => row.measured);
    if (lease?.measured === 'queue') {
        return `= diterima klien (setelah queue, Torch ${lease.measured_on || 'LAN'})`;
    }

    return '= masuk dari internet (sebelum queue)';
};
const dnsByAddress = ref({});
const { open, preview, isOpen } = usePreview();
const show = (value) => (value === null || value === undefined || value === '' ? '–' : String(value));
let dnsFetchedAt = 0;
let dnsAddress = '';

function previewAddress() {
    const key = preview.value?.key || '';

    return key.startsWith('lease:') ? key.split(':')[1] || '' : '';
}

async function loadDns(address) {
    const params = new URLSearchParams(window.location.search);
    const query = new URLSearchParams({ address });
    ['live', 'minutes', 'from', 'to'].forEach((name) => {
        if (params.has(name)) {
            query.set(name, params.get(name));
        }
    });

    dnsAddress = address;
    dnsFetchedAt = Date.now();

    try {
        const response = await fetch(`/dhcp/dns?${query}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) {
            return;
        }
        const data = await response.json();
        dnsByAddress.value = { ...dnsByAddress.value, [address]: data.queries ?? [] };
    } catch {
        // Keep the last list; the next refresh retries.
    }
}

watch(previewAddress, (address) => {
    if (address) {
        loadDns(address);
    }
}, { immediate: true });

watch(() => live.value?.sampled_at, () => {
    const address = previewAddress();
    if (address && (address !== dnsAddress || Date.now() - dnsFetchedAt > 15000)) {
        loadDns(address);
    }
});

function dnsFor(address) {
    return dnsByAddress.value[address] ?? null;
}

function leasePreview(lease, sampledAt = null) {
    const blocked = Boolean(lease.blocked);
    const statusLabel = blocked ? 'blocked' : show(lease.status);
    const tone = blocked ? 'danger' : lease.status === 'bound' ? 'ok' : lease.status === 'waiting' ? 'warn' : 'mute';
    const host = lease.host || '';
    const rx = Number(lease.rx_bps) || 0;
    const tx = Number(lease.tx_bps) || 0;

    return {
        key: `lease:${lease.address}:${lease.mac}`,
        icon: 'devices',
        title: aliasOf(lease) || host || lease.address || 'Lease',
        alias: lease.router_id ? { routerId: lease.router_id, value: aliasOf(lease) } : null,
        subtitle: lease.mac || '',
        detail: host ? (lease.address || '') : '',
        status: { label: statusLabel, tone },
        stream: {
            rx,
            tx,
            flows: Number(lease.flows) || 0,
            at: sampledAt,
        },
        tabs: [
            {
                id: 'overview',
                icon: 'devices',
                label: 'Perangkat',
                hero: true,
                metrics: [
                    { label: 'Status', value: statusLabel, tone },
                    { label: 'Last seen', value: show(lease.last_seen) },
                    { label: 'Expires', value: show(lease.expires_after) },
                ],
                sections: [{
                    rows: [
                        { label: 'IP address', value: show(lease.address) },
                        { label: 'MAC', value: show(lease.mac) },
                        { label: 'Hostname', value: show(lease.host) },
                        { label: 'Server', value: show(lease.server) },
                        { label: 'Dynamic', value: lease.dynamic ? 'yes' : 'no' },
                        { label: 'Blocked', value: blocked ? 'yes' : 'no', tone: blocked ? 'danger' : 'ok' },
                    ],
                }, ...(lease.measured === 'queue' ? [{
                    title: 'Bandwidth',
                    rows: [
                        { label: 'Diterima klien', value: `↓ ${formatRate(lease.rx_bps)} · ↑ ${formatRate(lease.tx_bps)}`, tone: 'cyan' },
                        { label: 'Masuk dari internet', value: `↓ ${formatRate(lease.wan_rx_bps)} · ↑ ${formatRate(lease.wan_tx_bps)}` },
                        { label: 'Dipotong queue', value: formatRate(Math.max(0, (Number(lease.wan_rx_bps) || 0) - (Number(lease.rx_bps) || 0))) },
                    ],
                }] : [])],
            },
            {
                id: 'lease',
                icon: 'schedule',
                label: 'Lease',
                sections: [{
                    title: 'Lease',
                    rows: [
                        { label: 'Status', value: statusLabel, tone },
                        { label: 'Server', value: show(lease.server) },
                        { label: 'Last seen', value: show(lease.last_seen) },
                        { label: 'Expires after', value: show(lease.expires_after) },
                        { label: 'Dynamic', value: lease.dynamic ? 'yes' : 'no' },
                        { label: 'Blocked', value: blocked ? 'yes' : 'no', tone: blocked ? 'danger' : 'ok' },
                    ],
                }],
            },
            {
                id: 'dns',
                icon: 'language',
                label: 'DNS',
                sections: dnsSections(lease.address),
            },
        ],
    };
}

function dnsSections(address) {
    const list = dnsFor(address);
    if (list === null) {
        return [{
            title: 'DNS',
            rows: [{ label: 'Query', value: 'Memuat…' }],
        }];
    }

    const browser = list.find((row) => row.browser)?.browser || '';
    const app = list[0]?.app || '';

    if (list.length === 0) {
        return [{
            title: 'DNS',
            rows: [{ label: 'Query', value: 'Belum ada.' }],
        }];
    }

    return [
        {
            title: 'DNS',
            rows: [
                { label: 'Browser', value: browser || '–' },
                { label: 'Aplikasi', value: app || '–' },
            ],
        },
        {
            title: 'Domain',
            rows: list.map((row) => ({
                label: String(row.time || '').slice(11, 19) || '–',
                value: row.app ? `${row.domain} · ${row.app}` : row.domain,
            })),
        },
    ];
}

function activeLease(live) {
    const key = preview.value?.key;
    const lease = windowed(live).map((row) => identified(row)).find((row) => `lease:${row.address}:${row.mac}` === key);

    return lease ? leasePreview(lease, live?.sampled_at) : null;
}

function seenAt(lease) {
    const seconds = durationSeconds(lease.last_seen);
    const now = Math.floor(Date.now() / 1000);

    return Number.isFinite(seconds) ? now - seconds : now;
}

function windowed(live) {
    const source = live?.leases?.length ? live.leases : props.leases;

    if (boardLive.value) {
        return source;
    }

    return source.filter((lease) => inWindow(seenAt(lease), props.minutes, props.from, props.to));
}

const rows = (live, search) => {
    const source = windowed(live);
    const needle = String(search || '').trim().toLowerCase();

        return source.filter((lease) => {
        const statusOk = status.value === 'all'
            || (status.value === 'blocked' ? lease.blocked : lease.status === status.value);
        if (!statusOk) {
            return false;
        }
        if (!needle) {
            return true;
        }
        return [lease.address, lease.mac, lease.host, lease.server, aliasOf(lease)]
            .join(' ')
            .toLowerCase()
            .includes(needle);
    }).map((lease) => identified(lease));
};

function identified(lease) {
    if (lease.router_id) {
        return lease;
    }

    const known = props.leases.find((row) => row.address === lease.address && row.mac === lease.mac);

    return known?.router_id ? { ...lease, router_id: known.router_id } : lease;
}

const summary = (live) => {
    const list = windowed(live);

    return {
        total: list.length,
        bound: list.filter((lease) => lease.status === 'bound').length,
        waiting: list.filter((lease) => lease.status === 'waiting').length,
        blocked: list.filter((lease) => lease.blocked).length,
    };
};

const filters = ['all', 'bound', 'waiting', 'blocked'];
const leaseText = (lease) => [lease.address, lease.mac, lease.host, lease.server, lease.status, lease.comment].join(' ');
const leaseLabel = (lease) => lease.host || lease.address || '';
const leaseTime = (lease) => -durationSeconds(lease.last_seen);
const seenRank = (lease) => {
    const seconds = durationSeconds(lease.last_seen);

    return Number.isFinite(seconds) ? -seconds : Number.NaN;
};
const expiresRank = (lease) => {
    const seconds = durationSeconds(lease.expires_after);

    return Number.isFinite(seconds) ? seconds : Number.NaN;
};
const columnLabels = {
    status: 'Status',
    address: 'Address',
    mac: 'MAC',
    host: 'Host',
    alias: 'Alias',
    download: 'Download',
    upload: 'Upload',
    server: 'Server',
    seen: 'Last seen',
    expires: 'Expires',
};
const columnLabel = (id) => columnLabels[id] || id;
const sortColumns = [
    { id: 'status', type: 'text', first: 'asc', get: (lease) => (lease.blocked ? 'blocked' : lease.status || '') },
    { id: 'address', type: 'text', first: 'asc', get: (lease) => lease.address || '' },
    { id: 'mac', type: 'text', first: 'asc', get: (lease) => lease.mac || '' },
    { id: 'host', type: 'text', first: 'asc', get: (lease) => lease.host || '' },
    { id: 'alias', type: 'text', first: 'asc', get: (lease) => aliasOf(lease) },
    { id: 'download', type: 'number', first: 'desc', get: (lease) => Number(lease.rx_bps) || 0 },
    { id: 'upload', type: 'number', first: 'desc', get: (lease) => Number(lease.tx_bps) || 0 },
    { id: 'server', type: 'text', first: 'asc', get: (lease) => lease.server || '' },
    { id: 'seen', type: 'number', first: 'desc', get: seenRank },
    { id: 'expires', type: 'number', first: 'asc', get: expiresRank },
];
</script>

<template>
    <Head title="Connected Devices" />

        <PreviewBinder :value="activeLease(live)" />
        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <article class="noc-card p-3">
                <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Bound</p>
                <p class="mt-1 text-2xl font-bold text-ok">{{ summary(live).bound }}</p>
            </article>
            <article class="noc-card p-3">
                <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Waiting</p>
                <p class="mt-1 text-2xl font-bold text-warn">{{ summary(live).waiting }}</p>
            </article>
            <article class="noc-card p-3">
                <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Blocked</p>
                <p class="mt-1 text-2xl font-bold text-danger">{{ summary(live).blocked }}</p>
            </article>
            <article class="noc-card p-3">
                <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Total leases</p>
                <p class="mt-1 text-2xl font-bold">{{ summary(live).total }}</p>
            </article>
        </section>

        <p class="mt-3 font-mono text-[11px] text-mute">{{ rangeLabel }} · last seen · Download/Upload {{ measuredLabel(live) }}</p>

        <SegmentTabs v-model="status" class="mt-3" :options="filters" />

        <section class="noc-card mt-3 overflow-x-auto">
            <PagedTable :rows="rows(live, query)" :text="leaseText" :label="leaseLabel" :time="leaseTime" :columns="sortColumns" default-sort="seen" placeholder="Cari IP, MAC, host...">
            <template #default="{ rows: pageRows, sortOf }">
            <table class="w-full min-w-[68rem] text-left text-sm">
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
                        v-for="lease in pageRows"
                        :key="`${lease.address}-${lease.mac}`"
                        tabindex="0"
                        class="cursor-pointer border-t border-line hover:bg-hover"
                        :class="isOpen(`lease:${lease.address}:${lease.mac}`) ? 'bg-active' : ''"
                        @click="open(leasePreview(lease, live?.sampled_at))"
                        @keydown.enter.prevent="open(leasePreview(lease, live?.sampled_at))"
                    >
                        <td class="px-3 py-2">
                            <span class="rounded px-1.5 py-0.5 font-mono text-[11px]" :class="lease.blocked ? 'bg-danger/15 text-danger' : lease.status === 'bound' ? 'bg-ok/10 text-ok' : 'bg-warn/15 text-warn'">
                                {{ lease.blocked ? 'blocked' : lease.status }}
                            </span>
                        </td>
                        <td class="px-3 py-2 font-mono text-cyan">{{ lease.address }}</td>
                        <td class="px-3 py-2 font-mono text-mist">{{ lease.mac || '–' }}</td>
                        <td class="px-3 py-2">{{ lease.host || '–' }}</td>
                        <td class="px-3 py-2">{{ aliasOf(lease) || '–' }}</td>
                        <td class="px-3 py-2 font-mono text-cyan"><LiveRate :value="lease.rx_bps" /></td>
                        <td class="px-3 py-2 font-mono text-ok"><LiveRate :value="lease.tx_bps" /></td>
                        <td class="px-3 py-2 font-mono text-mist">{{ lease.server || '–' }}</td>
                        <td class="px-3 py-2 font-mono text-mist">{{ lease.last_seen || '–' }}</td>
                        <td class="px-3 py-2 font-mono text-mist">{{ lease.expires_after || '–' }}</td>
                    </tr>
                    <tr v-if="pageRows.length === 0">
                        <td colspan="10" class="px-3 py-8 text-center font-mono text-[11px] text-mute">{{ boardLive ? 'Tidak ada lease.' : 'Tidak ada lease pada rentang ini.' }}</td>
                    </tr>
                </tbody>
            </table>
            </template>
            </PagedTable>
        </section>
</template>
