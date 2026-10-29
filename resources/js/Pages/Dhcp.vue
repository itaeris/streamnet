<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import LiveRate from '../Components/LiveRate.vue';
import PagedTable from '../Components/PagedTable.vue';
import SortHead from '../Components/SortHead.vue';
import PreviewBinder from '../Components/PreviewBinder.vue';
import { live as boardLive, ranges } from '../composables/useBoardTime';
import { useBoardPulse } from '../composables/usePulse';
import { usePreview } from '../composables/usePreview';
import { durationSeconds } from '../lib/format';
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
const { open, preview, isOpen } = usePreview();
const show = (value) => (value === null || value === undefined || value === '' ? '–' : String(value));

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
        title: host || lease.address || 'Lease',
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
                        { label: 'Comment', value: show(lease.comment), mono: false },
                    ],
                }],
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
        ],
    };
}

function activeLease(live) {
    const key = preview.value?.key;
    const lease = windowed(live).find((row) => leasePreview(row).key === key);

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
        return [lease.address, lease.mac, lease.host, lease.server, lease.comment]
            .join(' ')
            .toLowerCase()
            .includes(needle);
    });
};

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

        <p class="mt-3 font-mono text-[11px] text-mute">{{ rangeLabel }} · last seen</p>

        <div class="mt-3 flex flex-wrap gap-1">
            <button
                v-for="filter in filters"
                :key="filter"
                type="button"
                class="rounded px-2.5 py-1 font-mono text-[11px] capitalize"
                :class="status === filter ? 'bg-cyan-2 font-semibold text-[#003640]' : 'bg-tile text-mist'"
                @click="status = filter"
            >
                {{ filter }}
            </button>
        </div>

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
                        <td class="px-3 py-2 font-mono text-cyan"><LiveRate :value="lease.rx_bps" /></td>
                        <td class="px-3 py-2 font-mono text-ok"><LiveRate :value="lease.tx_bps" /></td>
                        <td class="px-3 py-2 font-mono text-mist">{{ lease.server || '–' }}</td>
                        <td class="px-3 py-2 font-mono text-mist">{{ lease.last_seen || '–' }}</td>
                        <td class="px-3 py-2 font-mono text-mist">{{ lease.expires_after || '–' }}</td>
                    </tr>
                    <tr v-if="pageRows.length === 0">
                        <td colspan="9" class="px-3 py-8 text-center font-mono text-[11px] text-mute">{{ boardLive ? 'Tidak ada lease.' : 'Tidak ada lease pada rentang ini.' }}</td>
                    </tr>
                </tbody>
            </table>
            </template>
            </PagedTable>
        </section>
</template>
