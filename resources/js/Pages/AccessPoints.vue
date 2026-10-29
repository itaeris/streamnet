<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import DataTable from '../Components/DataTable.vue';
import LiveRate from '../Components/LiveRate.vue';
import PreviewBinder from '../Components/PreviewBinder.vue';
import { usePreview } from '../composables/usePreview';
import { formatBytes, formatRate, formatSeconds } from '../lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    pulse: { type: Object, default: null },
    unifi: { type: Object, default: () => ({ ok: false, configured: false, error: null }) },
});

const { open, preview, isOpen } = usePreview();
const show = (value) => (value === null || value === undefined || value === '' ? '–' : String(value));
const pct = (value) => (value === null || value === undefined ? '–' : `${Math.round(Number(value))}%`);
const badge = 'rounded px-1.5 py-0.5 font-mono text-[11px]';

const page = usePage();
const sessionSettingsHref = computed(() => {
    const current = (page.props.sessions ?? []).find((item) => item.id === page.props.currentSessionId);

    return current?.slug || current?.name ? `/sesi/${encodeURIComponent(current.slug || current.name)}` : '/sesi';
});
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

const experienceLabel = (score) => {
    if (score === null || score === undefined) {
        return '';
    }

    return score >= 90 ? 'Excellent' : score >= 70 ? 'Good' : score >= 40 ? 'Fair' : 'Poor';
};
const experienceTone = (score) => ({ Excellent: 'ok', Good: 'warn', Fair: 'warn', Poor: 'danger' }[experienceLabel(score)]);
const experienceClass = (score) => ({ ok: 'text-ok', warn: 'text-warn', danger: 'text-danger' }[experienceTone(score)] || 'text-mute');
const technology = (client) => [client.standard, client.streams].filter(Boolean).join(', ');
const channelText = (client) => (client.channel ? `${client.channel}${client.band ? ` (${client.band}${client.channel_width ? `, ${client.channel_width} MHz` : ''})` : ''}` : '');
const macTail = (mac) => String(mac || '').slice(-5);
const clientName = (client) => {
    const name = client.name || client.display_name || client.hostname || client.mac || '';
    const tail = macTail(client.mac);

    return tail && name.endsWith(` ${tail}`) ? name.slice(0, -tail.length - 1) : name;
};

function clientPreview(client) {
    const score = client.experience;

    return {
        key: clientKey(client),
        icon: 'devices',
        title: clientName(client) || 'Klien',
        subtitle: client.mac || '',
        detail: client.ip || '',
        status: score !== null && score !== undefined
            ? { label: experienceLabel(score), tone: experienceTone(score) }
            : { label: client.type || 'klien', tone: 'cyan' },
        stream: client.down_bps !== undefined
            ? { rx: Number(client.down_bps) || 0, tx: Number(client.up_bps) || 0, flows: null, at: props.unifi?.fetched_at ?? null }
            : null,
        tabs: [
            {
                id: 'overview',
                icon: 'devices',
                label: 'Klien',
                hero: true,
                metrics: [
                    { label: 'Experience', value: score !== null && score !== undefined ? `${score}%` : '–', tone: experienceTone(score) },
                    { label: 'Sinyal', value: client.signal ? `${client.signal} dBm` : '–', tone: client.signal ? (client.signal >= -67 ? 'ok' : client.signal >= -75 ? 'warn' : 'danger') : undefined },
                    { label: 'Retry', value: client.retries !== null && client.retries !== undefined ? `${client.retries}%` : '–', tone: client.retries >= 25 ? 'danger' : client.retries >= 10 ? 'warn' : undefined },
                ],
                sections: [{
                    rows: [
                        { label: 'IP address', value: show(client.ip) },
                        { label: 'MAC', value: show(client.mac) },
                        { label: 'Vendor', value: show(client.vendor), mono: false },
                        { label: 'Access point', value: show(client.uplink || client.uplink_name), mono: false },
                        { label: 'WiFi', value: show(client.ssid), mono: false },
                        { label: 'Total sesi', value: client.total_bytes ? formatBytes(client.total_bytes) : '–' },
                        { label: 'Uptime', value: client.uptime ? formatSeconds(client.uptime) : since(client.connected_at) },
                        { label: 'Tersambung sejak', value: stamp(client.connected_at) },
                    ],
                }],
            },
            {
                id: 'radio',
                icon: 'radio',
                label: 'Radio',
                sections: [{
                    rows: [
                        { label: 'Teknologi', value: show(technology(client)) },
                        { label: 'Channel', value: show(channelText(client)) },
                        { label: 'Sinyal', value: client.signal ? `${client.signal} dBm` : '–' },
                        { label: 'Noise', value: client.noise ? `${client.noise} dBm` : '–' },
                        { label: 'Link rate ↓', value: client.link_down ? formatRate(client.link_down) : '–' },
                        { label: 'Link rate ↑', value: client.link_up ? formatRate(client.link_up) : '–' },
                        { label: 'TX retry', value: client.retries !== null && client.retries !== undefined ? `${client.retries}%` : '–' },
                    ],
                }],
            },
        ],
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
    { id: 'name', label: 'Nama', type: 'text', first: 'asc', get: clientName },
    { id: 'vendor', label: 'Vendor', type: 'text', first: 'asc', get: (row) => row.vendor || '', class: 'text-mist' },
    { id: 'uplink', label: 'Connection', type: 'text', first: 'asc', get: (row) => row.uplink || row.uplink_name || '' },
    { id: 'ssid', label: 'WiFi', type: 'text', first: 'asc', get: (row) => row.ssid || '', class: 'text-mist' },
    { id: 'experience', label: 'Experience', type: 'number', first: 'desc', get: (row) => (row.experience ?? Number.NaN), show: (row) => experienceLabel(row.experience) },
    { id: 'technology', label: 'Teknologi', type: 'text', first: 'asc', get: technology, class: 'text-mist whitespace-nowrap' },
    { id: 'channel', label: 'Channel', type: 'text', first: 'asc', get: channelText, class: 'font-mono text-[12px] text-mist whitespace-nowrap' },
    { id: 'ip', label: 'IP address', type: 'text', first: 'asc', get: (row) => row.ip || '', class: 'font-mono text-cyan' },
    { id: 'down', label: 'Download', type: 'number', first: 'desc', get: (row) => Number(row.down_bps) || 0, show: (row) => formatRate(row.down_bps), class: 'font-mono text-cyan whitespace-nowrap' },
    { id: 'up', label: 'Upload', type: 'number', first: 'desc', get: (row) => Number(row.up_bps) || 0, show: (row) => formatRate(row.up_bps), class: 'font-mono text-ok whitespace-nowrap' },
    { id: 'total', label: 'Total sesi', type: 'number', first: 'desc', get: (row) => Number(row.total_bytes) || 0, show: (row) => (row.total_bytes ? formatBytes(row.total_bytes) : '–'), class: 'font-mono text-mist whitespace-nowrap' },
    { id: 'uptime', label: 'Uptime', type: 'number', first: 'desc', get: (row) => (row.uptime ?? Number.NaN), show: (row) => (row.uptime ? formatSeconds(row.uptime) : since(row.connected_at)), class: 'text-mist whitespace-nowrap' },
];
</script>

<template>
    <Head title="Access Points" />

    <PreviewBinder :value="activeItem()" />

    <div v-if="!unifi?.ok" class="noc-card flex flex-col items-center px-6 py-14 text-center">
        <span class="flex size-12 items-center justify-center rounded-2xl bg-tile text-mute">
            <Icon class="text-[24px]" name="wifi" />
        </span>
        <p class="mt-4 text-[15px] font-semibold">Belum terhubung ke UniFi</p>
        <p class="mt-1 max-w-md text-sm text-mist">
            {{ unifi?.configured ? 'Console UniFi sesi ini belum bisa dihubungi. Cek alamat, API key, atau koneksi ke console.' : 'Sesi MikroTik ini belum punya API UniFi. Sambungkan console-nya di pengaturan.' }}
        </p>
        <p v-if="unifi?.configured && unifi?.error" class="mt-2 font-mono text-[11px] text-mute">{{ unifi.error }}</p>
        <Link :href="sessionSettingsHref" class="mt-5 inline-flex h-10 items-center gap-2 rounded-full bg-cyan-2 px-4 text-sm font-semibold text-[#003640]">
            <Icon class="text-[16px]" name="settings" />
            {{ unifi?.configured ? 'Periksa pengaturan UniFi' : 'Hubungkan di Pengaturan' }}
        </Link>
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
            default-sort="down"
            placeholder="Cari nama, IP, MAC, AP, SSID..."
            empty="Tidak ada klien WiFi."
            min-width="min-w-[92rem]"
            clickable
            :is-active="(row) => isOpen(clientKey(row))"
            @open="(row) => open(clientPreview(row))"
        >
            <template #name="{ row }">
                <span class="inline-flex items-center gap-2 whitespace-nowrap">
                    <span class="size-1.5 shrink-0 rounded-full bg-ok" />
                    {{ clientName(row) }}
                    <span class="font-mono text-[11px] text-mute">{{ macTail(row.mac) }}</span>
                </span>
            </template>
            <template #experience="{ row }">
                <span :class="experienceClass(row.experience)">{{ experienceLabel(row.experience) || '–' }}</span>
            </template>
            <template #down="{ row }">
                <span class="whitespace-nowrap">↓ <LiveRate :value="row.down_bps" /></span>
            </template>
            <template #up="{ row }">
                <span class="whitespace-nowrap">↑ <LiveRate :value="row.up_bps" /></span>
            </template>
        </DataTable>
    </template>
</template>
