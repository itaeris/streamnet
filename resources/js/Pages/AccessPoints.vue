<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import DataTable from '../Components/DataTable.vue';
import LiveRate from '../Components/LiveRate.vue';
import PreviewBinder from '../Components/PreviewBinder.vue';
import { usePreview } from '../composables/usePreview';
import { formatRate, formatSeconds } from '../lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    pulse: { type: Object, default: null },
    unifi: { type: Object, default: () => ({ ok: false, error: 'Belum ada data.' }) },
});

const { open, preview, isOpen } = usePreview();
const show = (value) => (value === null || value === undefined || value === '' ? '–' : String(value));
const pct = (value) => (value === null || value === undefined ? '–' : `${Math.round(Number(value))}%`);
const badge = 'rounded px-1.5 py-0.5 font-mono text-[11px]';

const devices = computed(() => (props.unifi?.devices ?? []).filter((device) => device.access_point));
const clients = computed(() => props.unifi?.clients ?? []);
const wireless = computed(() => clients.value.filter((client) => client.type === 'wireless'));
const online = computed(() => devices.value.filter((device) => device.state === 'online').length);
const totals = computed(() => devices.value.reduce((sum, device) => ({
    rx: sum.rx + (Number(device.rx_bps) || 0),
    tx: sum.tx + (Number(device.tx_bps) || 0),
}), { rx: 0, tx: 0 }));

const stateBadge = (state) => ({
    online: 'bg-ok/10 text-ok',
    offline: 'bg-danger/15 text-danger',
}[state] || 'bg-warn/15 text-warn');
const stateTone = (state) => ({ online: 'ok', offline: 'danger' }[state] || 'warn');
const retryTone = (value) => (value === null || value === undefined ? undefined : value >= 25 ? 'danger' : value >= 10 ? 'warn' : 'ok');
const bandLabel = (radio) => `${radio.band} GHz`;
const channels = (device) => (device.radios ?? []).map((radio) => radio.channel).filter(Boolean).join(' · ');

function since(iso) {
    const at = Date.parse(iso || '');
    if (Number.isNaN(at)) {
        return '–';
    }

    return formatSeconds((Date.now() - at) / 1000);
}

function stamp(iso) {
    const at = new Date(iso || '');
    if (Number.isNaN(at.getTime())) {
        return '–';
    }

    return at.toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
}

function apKey(device) {
    return `ap:${device.id}`;
}

function apPreview(device) {
    const attached = clients.value.filter((client) => client.uplink_id === device.id);

    return {
        key: apKey(device),
        icon: 'wifi',
        title: device.name || device.mac || 'Access point',
        subtitle: device.model || '',
        detail: device.ip || '',
        status: { label: device.state, tone: stateTone(device.state) },
        stream: {
            rx: Number(device.rx_bps) || 0,
            tx: Number(device.tx_bps) || 0,
            flows: null,
            at: props.unifi?.fetched_at ?? null,
        },
        tabs: [
            {
                id: 'overview',
                icon: 'wifi',
                label: 'Access point',
                hero: true,
                metrics: [
                    { label: 'Klien', value: show(device.clients) },
                    { label: 'CPU', value: pct(device.cpu) },
                    { label: 'Memori', value: pct(device.memory) },
                ],
                sections: [{
                    rows: [
                        { label: 'IP address', value: show(device.ip) },
                        { label: 'MAC', value: show(device.mac) },
                        { label: 'Model', value: show(device.model), mono: false },
                        { label: 'Firmware', value: show(device.firmware), tone: device.firmware_updatable ? 'warn' : undefined },
                        { label: 'Update firmware', value: device.firmware_updatable ? 'tersedia' : 'terbaru', tone: device.firmware_updatable ? 'warn' : 'ok' },
                        { label: 'Uptime', value: formatSeconds(device.uptime) },
                        { label: 'Load 1 mnt', value: show(device.load) },
                        { label: 'Diadopsi', value: stamp(device.adopted_at) },
                    ],
                }],
            },
            {
                id: 'radios',
                icon: 'radio',
                label: 'Radio',
                sections: (device.radios ?? []).map((radio) => ({
                    title: bandLabel(radio),
                    rows: [
                        { label: 'Standar', value: show(radio.standard) },
                        { label: 'Channel', value: show(radio.channel) },
                        { label: 'Lebar kanal', value: radio.width ? `${radio.width} MHz` : '–' },
                        { label: 'TX retry', value: radio.retries === null || radio.retries === undefined ? '–' : `${radio.retries}%`, tone: retryTone(radio.retries) },
                    ],
                })),
            },
            {
                id: 'clients',
                icon: 'group',
                label: 'Klien',
                sections: [{
                    title: `${attached.length} klien`,
                    rows: attached.length
                        ? attached.map((client) => ({ label: client.name || client.mac, value: client.ip || '–', mono: true }))
                        : [{ label: 'Klien', value: 'Belum ada.' }],
                }],
            },
        ],
    };
}

function clientKey(client) {
    return `wifi-client:${client.id}`;
}

function clientPreview(client) {
    return {
        key: clientKey(client),
        icon: 'devices',
        title: client.name || client.mac || 'Klien',
        subtitle: client.mac || '',
        detail: client.ip || '',
        status: { label: client.type || 'klien', tone: 'cyan' },
        tabs: [{
            id: 'overview',
            icon: 'devices',
            label: 'Klien',
            hero: true,
            sections: [{
                rows: [
                    { label: 'IP address', value: show(client.ip) },
                    { label: 'MAC', value: show(client.mac) },
                    { label: 'Access point', value: show(client.uplink), mono: false },
                    { label: 'Tersambung', value: since(client.connected_at) },
                    { label: 'Sejak', value: stamp(client.connected_at) },
                ],
            }],
        }],
    };
}

function activeItem() {
    const key = preview.value?.key ?? '';
    const device = devices.value.find((row) => apKey(row) === key);
    if (device) {
        return apPreview(device);
    }

    const client = clients.value.find((row) => clientKey(row) === key);

    return client ? clientPreview(client) : null;
}

const apColumns = [
    { id: 'state', label: 'Status', type: 'text', first: 'asc', get: (row) => row.state || '' },
    { id: 'name', label: 'Nama', type: 'text', first: 'asc', get: (row) => row.name || '' },
    { id: 'model', label: 'Model', type: 'text', first: 'asc', get: (row) => row.model || '', class: 'text-mist' },
    { id: 'ip', label: 'IP', type: 'text', first: 'asc', get: (row) => row.ip || '', class: 'font-mono text-cyan' },
    { id: 'clients', label: 'Klien', type: 'number', first: 'desc', get: (row) => Number(row.clients) || 0, class: 'font-mono' },
    { id: 'rx', label: 'Download', type: 'number', first: 'desc', get: (row) => Number(row.rx_bps) || 0, show: (row) => formatRate(row.rx_bps), class: 'font-mono text-cyan' },
    { id: 'tx', label: 'Upload', type: 'number', first: 'desc', get: (row) => Number(row.tx_bps) || 0, show: (row) => formatRate(row.tx_bps), class: 'font-mono text-ok' },
    { id: 'cpu', label: 'CPU', type: 'number', first: 'desc', get: (row) => (row.cpu ?? Number.NaN), show: (row) => pct(row.cpu), class: 'font-mono text-mist' },
    { id: 'memory', label: 'Memori', type: 'number', first: 'desc', get: (row) => (row.memory ?? Number.NaN), show: (row) => pct(row.memory), class: 'font-mono text-mist' },
    { id: 'channels', label: 'Channel', type: 'text', first: 'asc', get: channels, class: 'font-mono text-mist' },
    { id: 'uptime', label: 'Uptime', type: 'number', first: 'desc', get: (row) => (row.uptime ?? Number.NaN), show: (row) => formatSeconds(row.uptime), class: 'text-mist whitespace-nowrap' },
];

const clientColumns = [
    { id: 'name', label: 'Nama', type: 'text', first: 'asc', get: (row) => row.name || '' },
    { id: 'ip', label: 'IP', type: 'text', first: 'asc', get: (row) => row.ip || '', class: 'font-mono text-cyan' },
    { id: 'mac', label: 'MAC', type: 'text', first: 'asc', get: (row) => row.mac || '', class: 'font-mono text-mist' },
    { id: 'uplink', label: 'Access point', type: 'text', first: 'asc', get: (row) => row.uplink || '' },
    { id: 'connected', label: 'Tersambung', type: 'number', first: 'desc', get: (row) => -(Date.parse(row.connected_at || '') || 0), show: (row) => since(row.connected_at), class: 'text-mist whitespace-nowrap' },
];
</script>

<template>
    <Head title="Access Points" />

    <PreviewBinder :value="activeItem()" />

    <div v-if="!unifi?.ok" class="noc-card flex items-start gap-3 p-4">
        <Icon class="mt-0.5 text-[20px] text-danger" name="error" />
        <div>
            <p class="text-sm font-semibold">UniFi tidak bisa dibaca</p>
            <p class="mt-1 text-sm text-mist">{{ unifi?.error || 'Tidak ada data.' }}</p>
        </div>
    </div>

    <template v-else>
        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <article class="noc-card p-3">
                <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Access points</p>
                <p class="mt-1 text-2xl font-bold" :class="online === devices.length ? 'text-ok' : 'text-warn'">{{ online }}<span class="text-base text-mute"> / {{ devices.length }}</span></p>
            </article>
            <article class="noc-card p-3">
                <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Klien WiFi</p>
                <p class="mt-1 text-2xl font-bold text-cyan">{{ wireless.length }}</p>
            </article>
            <article class="noc-card p-3">
                <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Download</p>
                <p class="mt-1 text-2xl font-bold text-cyan"><LiveRate :value="totals.rx" /></p>
            </article>
            <article class="noc-card p-3">
                <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Upload</p>
                <p class="mt-1 text-2xl font-bold text-ok"><LiveRate :value="totals.tx" /></p>
            </article>
        </section>

        <p class="mt-3 font-mono text-[11px] text-mute">UniFi · {{ unifi.site?.name }} · {{ stamp(unifi.fetched_at) }}</p>

        <DataTable
            class="mt-3"
            title="Access point"
            :rows="devices"
            :columns="apColumns"
            :row-key="(row) => row.id"
            default-sort="name"
            placeholder="Cari AP, IP, model..."
            empty="Tidak ada access point."
            min-width="min-w-[64rem]"
            clickable
            :is-active="(row) => isOpen(apKey(row))"
            @open="(row) => open(apPreview(row))"
        >
            <template #state="{ row }">
                <span :class="[badge, stateBadge(row.state)]">{{ row.state }}</span>
            </template>
            <template #name="{ row }">
                <span class="inline-flex items-center gap-1.5">
                    {{ row.name || '–' }}
                    <span v-if="row.firmware_updatable" v-tip="'Update firmware tersedia'" class="size-1.5 rounded-full bg-warn" />
                </span>
            </template>
            <template #rx="{ row }">
                <LiveRate :value="row.rx_bps" />
            </template>
            <template #tx="{ row }">
                <LiveRate :value="row.tx_bps" />
            </template>
        </DataTable>

        <DataTable
            class="mt-3"
            title="Klien WiFi"
            :rows="wireless"
            :columns="clientColumns"
            :row-key="(row) => row.id"
            default-sort="uplink"
            placeholder="Cari nama, IP, MAC, AP..."
            empty="Tidak ada klien WiFi."
            min-width="min-w-[48rem]"
            clickable
            :is-active="(row) => isOpen(clientKey(row))"
            @open="(row) => open(clientPreview(row))"
        />
    </template>
</template>
