<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import DataTable from '../Components/DataTable.vue';
import LiveRate from '../Components/LiveRate.vue';
import PreviewBinder from '../Components/PreviewBinder.vue';
import { useBoardPulse } from '../composables/usePulse';
import { usePreview } from '../composables/usePreview';
import { aliasOf } from '../composables/useLeaseAlias';
import { formatRate } from '../lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    pulse: { type: Object, default: null },
    wanPeak: { type: Object, default: () => ({ rx: 0, tx: 0 }) },
    incidents: { type: Array, default: () => [] },
});

const live = useBoardPulse();
const { open, preview, isOpen } = usePreview();
const shaping = computed(() => live.value?.shaping ?? props.pulse?.shaping ?? null);
const main = computed(() => shaping.value?.main ?? null);
const downType = computed(() => shaping.value?.down_type ?? null);
const upType = computed(() => shaping.value?.up_type ?? null);
const capacity = computed(() => Number(main.value?.max_down) || 0);
const capDown = computed(() => Number(downType.value?.rate) || 0);
const threshold = computed(() => (capDown.value > 0 ? capDown.value * 0.9 : capacity.value * 0.5));
const usage = computed(() => (capacity.value > 0 ? Math.min(100, Math.round(((Number(main.value?.rate_down) || 0) / capacity.value) * 100)) : 0));
const badge = 'rounded px-1.5 py-0.5 font-mono text-[11px]';
const mbps = (bps) => Math.round(((Number(bps) || 0) / 1_000_000) * 10) / 10;
const seconds = (value) => Number(String(value || '').replace(/[^\d]/g, '')) || 10;

const form = useForm({
    capacity_down: 0,
    capacity_up: 0,
    client_down: 0,
    client_up: 0,
    burst: false,
    burst_down: 0,
    burst_up: 0,
    burst_time: 10,
    target: '',
});
const confirming = ref(false);
let seeded = false;

function seed() {
    if (!shaping.value) {
        return;
    }

    form.defaults({
        capacity_down: mbps(main.value?.max_down) || 100,
        capacity_up: mbps(main.value?.max_up) || 50,
        client_down: mbps(downType.value?.rate),
        client_up: mbps(upType.value?.rate),
        burst: (Number(downType.value?.burst_rate) || 0) > 0,
        burst_down: mbps(downType.value?.burst_rate),
        burst_up: mbps(upType.value?.burst_rate),
        burst_time: seconds(downType.value?.burst_time),
        target: main.value?.target || '10.10.10.0/24',
    });
    form.reset();
    seeded = true;
}

watch(shaping, () => {
    if (!seeded || !form.isDirty) {
        seed();
    }
}, { immediate: true });

const unit = (value) => {
    const n = Number(value) || 0;

    return n >= 1000 && n % 1000 === 0 ? `${n / 1000}G` : `${n}M`;
};

const script = computed(() => {
    const burst = form.burst;
    const pcq = (rate, burstRate) => [
        `pcq-rate=${Number(rate) > 0 ? unit(rate) : 0}`,
        `pcq-burst-rate=${burst ? unit(burstRate) : 0}`,
        `pcq-burst-threshold=${burst ? unit(Math.round(Number(rate) * 0.75 * 10) / 10) : 0}`,
        `pcq-burst-time=${burst ? form.burst_time : 10}s`,
    ].join(' ');
    const maxLimit = `${unit(form.capacity_up)}/${unit(form.capacity_down)}`;

    if (main.value) {
        return [
            `/queue type set [find name="${downType.value?.name}"] ${pcq(form.client_down, form.burst_down)}`,
            `/queue type set [find name="${upType.value?.name}"] ${pcq(form.client_up, form.burst_up)}`,
            `/queue simple set [find name="${main.value.name}"] max-limit=${maxLimit}`,
        ].join('\n');
    }

    return [
        `/queue type add name=streamnet-pcq-down kind=pcq pcq-classifier=dst-address ${pcq(form.client_down, form.burst_down)}`,
        `/queue type add name=streamnet-pcq-up kind=pcq pcq-classifier=src-address ${pcq(form.client_up, form.burst_up)}`,
        `/queue simple add name=streamnet-fair-share target=${form.target || '10.10.10.0/24'} queue=streamnet-pcq-up/streamnet-pcq-down max-limit=${maxLimit}`,
    ].join('\n');
});

function apply() {
    form.put('/bandwidth-policy', {
        preserveScroll: true,
        onSuccess: () => {
            confirming.value = false;
            form.defaults();
        },
    });
}

const checks = computed(() => {
    const items = [];
    const peak = Number(props.wanPeak?.rx) || 0;

    items.push(main.value
        ? { tone: 'ok', title: `Queue PCQ "${main.value.name}" aktif`, text: `Membagi ${main.value.target} secara adil antar klien. Sekarang ${main.value.users_down} klien aktif.` }
        : { tone: 'danger', title: 'Belum ada queue PCQ', text: 'Tanpa PCQ, klien rebutan bandwidth dan yang paling rakus menang. Isi form di bawah untuk membuatnya.' });

    items.push(shaping.value?.fasttrack
        ? { tone: 'danger', title: 'FastTrack aktif', text: 'Koneksi yang di-fasttrack melewati simple queue, jadi batas tidak berlaku. Nonaktifkan rule fasttrack-connection di IP › Firewall › Filter.' }
        : { tone: 'ok', title: 'Tidak ada FastTrack', text: 'Semua trafik melewati queue, jadi batas berlaku.' });

    if (main.value) {
        items.push(peak >= capacity.value * 0.8
            ? { tone: 'ok', title: 'Kapasitas queue sempat penuh', text: `Puncak WAN 24 jam ${formatRate(peak)} dari kapasitas ${formatRate(capacity.value)}. Saat penuh, PCQ yang membagi.` }
            : { tone: 'warn', title: 'Kapasitas queue mungkin terlalu besar', text: `Kapasitas ${formatRate(capacity.value)}, puncak WAN 24 jam cuma ${formatRate(peak)}. Kalau paket ISP lebih kecil, set kapasitas ±90% kecepatan ISP. Kalau tidak, antrean terjadi di ISP dan router tidak bisa membagi.` });
    }

    items.push(capDown.value > 0
        ? { tone: 'ok', title: 'Batas per klien aktif', text: `Tiap klien maksimal ${formatRate(capDown.value)} download / ${formatRate(upType.value?.rate)} upload. Sisanya diteruskan ke klien lain.` }
        : { tone: 'warn', title: 'Belum ada batas per klien', text: 'Saat jaringan sepi, satu klien bisa memakai hampir seluruh kapasitas. Set batas per klien supaya tidak ada yang mendominasi.' });

    return items;
});

const toneIcon = (tone) => (tone === 'ok' ? 'check_circle' : 'error');
const toneText = (tone) => ({ ok: 'text-ok', warn: 'text-warn', danger: 'text-danger' }[tone] || 'text-mist');

const consumers = computed(() => (live.value?.leases ?? [])
    .filter((lease) => (Number(lease.rx_bps) || 0) + (Number(lease.tx_bps) || 0) + (Number(lease.wan_rx_bps) || 0) > 0));
const afterQueue = computed(() => (live.value?.leases ?? []).some((lease) => lease.measured === 'queue'));
const trimmed = (lease) => Math.max(0, (Number(lease.wan_rx_bps) || 0) - (Number(lease.rx_bps) || 0));
const shareOf = (lease) => (capacity.value > 0 ? Math.round(((Number(lease.rx_bps) || 0) / capacity.value) * 100) : 0);
const hogging = (lease) => threshold.value > 0 && (Number(lease.rx_bps) || 0) >= threshold.value;
const nameOf = (lease) => aliasOf(lease) || lease.host || lease.address || '';

function consumerKey(lease) {
    return `bw:${lease.address}`;
}

function consumerPreview(lease) {
    return {
        key: consumerKey(lease),
        icon: 'devices',
        title: nameOf(lease) || 'Klien',
        subtitle: lease.mac || '',
        detail: lease.address || '',
        status: hogging(lease) ? { label: 'di atas ambang', tone: 'warn' } : { label: 'normal', tone: 'ok' },
        stream: { rx: Number(lease.rx_bps) || 0, tx: Number(lease.tx_bps) || 0, flows: lease.flows ?? null, at: live.value?.sampled_at ?? null },
        tabs: [{
            id: 'overview',
            icon: 'devices',
            label: 'Klien',
            hero: true,
            metrics: [
                { label: 'Porsi', value: `${shareOf(lease)}%`, tone: hogging(lease) ? 'warn' : undefined },
                { label: 'Batas', value: capDown.value > 0 ? formatRate(capDown.value) : 'bagi rata' },
                { label: 'Koneksi', value: String(lease.flows ?? '–') },
            ],
            sections: [{
                rows: [
                    { label: 'IP address', value: lease.address || '–' },
                    { label: 'MAC', value: lease.mac || '–' },
                    { label: 'Host', value: lease.host || '–', mono: false },
                    { label: 'Diterima klien', value: `↓ ${formatRate(lease.rx_bps)} · ↑ ${formatRate(lease.tx_bps)}`, tone: 'cyan' },
                    { label: 'Masuk dari internet', value: `↓ ${formatRate(lease.wan_rx_bps)} · ↑ ${formatRate(lease.wan_tx_bps)}` },
                    { label: 'Dipotong queue', value: formatRate(trimmed(lease)) },
                ],
            }],
        }],
    };
}

function activeItem() {
    const key = preview.value?.key ?? '';
    const lease = consumers.value.find((row) => consumerKey(row) === key);

    return lease ? consumerPreview(lease) : null;
}

const consumerColumns = [
    { id: 'name', label: 'Klien', type: 'text', first: 'asc', get: nameOf },
    { id: 'address', label: 'IP', type: 'text', first: 'asc', get: (row) => row.address || '', class: 'font-mono text-cyan' },
    { id: 'rx', label: 'Download diterima', type: 'number', first: 'desc', get: (row) => Number(row.rx_bps) || 0, show: (row) => formatRate(row.rx_bps), class: 'font-mono text-cyan' },
    { id: 'tx', label: 'Upload', type: 'number', first: 'desc', get: (row) => Number(row.tx_bps) || 0, show: (row) => formatRate(row.tx_bps), class: 'font-mono text-ok' },
    { id: 'wan', label: 'Sebelum queue', type: 'number', first: 'desc', get: (row) => Number(row.wan_rx_bps) || 0, show: (row) => formatRate(row.wan_rx_bps), class: 'font-mono text-mist' },
    { id: 'share', label: 'Porsi kapasitas', type: 'number', first: 'desc', get: shareOf, show: (row) => `${shareOf(row)}%` },
    { id: 'state', label: 'Status', type: 'number', first: 'desc', get: (row) => (hogging(row) ? 1 : 0), show: (row) => (hogging(row) ? 'di atas ambang' : 'normal') },
];

const incidentColumns = [
    { id: 'time', label: 'Waktu', type: 'text', first: 'desc', get: (row) => row.time || '', class: 'font-mono text-mist whitespace-nowrap' },
    { id: 'title', label: 'Insiden', type: 'text', first: 'asc', get: (row) => row.title || '' },
    { id: 'detail', label: 'Detail', type: 'text', first: 'asc', get: (row) => row.detail || '', class: 'font-mono text-[11px] text-mist' },
];

const steps = [
    { icon: 'gauge', title: '1. Kapasitas total', text: 'Set ±90% dari kecepatan ISP. Antrean jadi terjadi di router, bukan di ISP, sehingga router yang mengatur siapa dapat berapa.' },
    { icon: 'fair_share', title: '2. PCQ bagi rata', text: 'Kapasitas dibagi sama rata ke klien yang sedang aktif. Klien yang diam tidak memakan jatah. Sisa jatahnya otomatis dipakai klien lain.' },
    { icon: 'burst', title: '3. Batas per klien', text: 'Plafon per klien. Klien yang menyentuh batas berhenti di situ, dan sisa bandwidth diteruskan ke klien yang masih kekurangan.' },
];
</script>

<template>
    <Head title="Bandwidth Policy" />

    <PreviewBinder :value="activeItem()" />

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <article class="noc-card p-3">
            <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Kapasitas queue</p>
            <p class="mt-1 text-2xl font-bold">{{ main ? formatRate(main.max_down) : '–' }}</p>
            <p class="font-mono text-[11px] text-mute">upload {{ main ? formatRate(main.max_up) : '–' }}</p>
        </article>
        <article class="noc-card p-3">
            <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Pemakaian sekarang</p>
            <p class="mt-1 text-2xl font-bold text-cyan"><LiveRate :value="main?.rate_down ?? 0" /></p>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-well">
                <div class="h-full rounded-full transition-all duration-500" :class="usage >= 90 ? 'bg-danger' : usage >= 70 ? 'bg-warn' : 'bg-cyan'" :style="{ width: `${usage}%` }" />
            </div>
        </article>
        <article class="noc-card p-3">
            <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Klien aktif di queue</p>
            <p class="mt-1 text-2xl font-bold">{{ main?.users_down ?? '–' }}</p>
            <p class="font-mono text-[11px] text-mute">jatah rata ±{{ main && main.users_down ? formatRate(capacity / main.users_down) : '–' }}</p>
        </article>
        <article class="noc-card p-3">
            <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Batas per klien</p>
            <p class="mt-1 text-2xl font-bold" :class="capDown > 0 ? 'text-ok' : 'text-warn'">{{ capDown > 0 ? formatRate(capDown) : 'Bagi rata' }}</p>
            <p class="font-mono text-[11px] text-mute">{{ capDown > 0 ? `upload ${formatRate(upType?.rate)}` : 'tanpa plafon' }}</p>
        </article>
    </section>

    <section class="mt-3 grid gap-3 xl:grid-cols-[1.1fr_1fr]">
        <article class="noc-card p-4">
            <p class="text-[15px] font-semibold">Cara kerjanya</p>
            <p class="mt-1 text-sm text-mist">Saat bandwidth habis, bukan klien yang paling rakus yang menang. Router membagi rata, memberi plafon, lalu meneruskan sisa ke yang kekurangan.</p>
            <ul class="mt-4 grid gap-3">
                <li v-for="step in steps" :key="step.title" class="flex gap-3">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-tile text-cyan">
                        <Icon class="text-[18px]" :name="step.icon" />
                    </span>
                    <span>
                        <span class="block text-sm font-medium">{{ step.title }}</span>
                        <span class="block text-[12px] text-mist">{{ step.text }}</span>
                    </span>
                </li>
            </ul>
        </article>

        <article class="noc-card p-4">
            <p class="text-[15px] font-semibold">Pemeriksaan router</p>
            <ul class="mt-3 grid gap-3">
                <li v-for="check in checks" :key="check.title" class="flex gap-2.5">
                    <Icon class="mt-0.5 text-[18px]" :class="toneText(check.tone)" :name="toneIcon(check.tone)" />
                    <span>
                        <span class="block text-sm font-medium">{{ check.title }}</span>
                        <span class="block text-[12px] text-mist">{{ check.text }}</span>
                    </span>
                </li>
                <li v-if="!shaping" class="text-sm text-mute">Menunggu data queue dari poller…</li>
            </ul>
        </article>
    </section>

    <section class="noc-card mt-3 p-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <p class="text-[15px] font-semibold">Atur kebijakan</p>
                <p class="mt-1 text-sm text-mist">Angka dalam Mbps. Batas per klien 0 berarti bagi rata tanpa plafon.</p>
            </div>
            <span v-if="main" class="rounded-full bg-tile px-2.5 py-1 font-mono text-[11px] text-mist">{{ main.name }} · {{ main.target }}</span>
        </div>

        <form class="mt-4 grid gap-4 lg:grid-cols-2" @submit.prevent="confirming = true">
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block text-[12px] font-medium text-mist">
                    Kapasitas download
                    <input v-model.number="form.capacity_down" type="number" min="1" step="0.1" class="mt-1.5 h-10 w-full rounded-full bg-sink px-4 text-sm text-paper outline-none focus:ring-2 focus:ring-cyan-2/40">
                </label>
                <label class="block text-[12px] font-medium text-mist">
                    Kapasitas upload
                    <input v-model.number="form.capacity_up" type="number" min="1" step="0.1" class="mt-1.5 h-10 w-full rounded-full bg-sink px-4 text-sm text-paper outline-none focus:ring-2 focus:ring-cyan-2/40">
                </label>
                <label class="block text-[12px] font-medium text-mist">
                    Batas per klien download
                    <input v-model.number="form.client_down" type="number" min="0" step="0.1" class="mt-1.5 h-10 w-full rounded-full bg-sink px-4 text-sm text-paper outline-none focus:ring-2 focus:ring-cyan-2/40">
                </label>
                <label class="block text-[12px] font-medium text-mist">
                    Batas per klien upload
                    <input v-model.number="form.client_up" type="number" min="0" step="0.1" class="mt-1.5 h-10 w-full rounded-full bg-sink px-4 text-sm text-paper outline-none focus:ring-2 focus:ring-cyan-2/40">
                </label>
                <label v-if="!main" class="block text-[12px] font-medium text-mist sm:col-span-2">
                    Subnet LAN (target queue)
                    <input v-model="form.target" placeholder="10.10.10.0/24" class="mt-1.5 h-10 w-full rounded-full bg-sink px-4 font-mono text-sm text-paper outline-none focus:ring-2 focus:ring-cyan-2/40">
                </label>

                <label class="flex items-center gap-2 text-sm text-mist sm:col-span-2">
                    <input v-model="form.burst" type="checkbox" class="size-4 accent-cyan-2">
                    Izinkan burst: boleh lebih cepat sebentar (buka web, unduhan kecil), lalu turun ke batas
                </label>
                <template v-if="form.burst">
                    <label class="block text-[12px] font-medium text-mist">
                        Burst download
                        <input v-model.number="form.burst_down" type="number" min="0" step="0.1" class="mt-1.5 h-10 w-full rounded-full bg-sink px-4 text-sm text-paper outline-none focus:ring-2 focus:ring-cyan-2/40">
                    </label>
                    <label class="block text-[12px] font-medium text-mist">
                        Burst upload
                        <input v-model.number="form.burst_up" type="number" min="0" step="0.1" class="mt-1.5 h-10 w-full rounded-full bg-sink px-4 text-sm text-paper outline-none focus:ring-2 focus:ring-cyan-2/40">
                    </label>
                    <label class="block text-[12px] font-medium text-mist sm:col-span-2">
                        Lama burst (detik)
                        <input v-model.number="form.burst_time" type="number" min="1" max="120" class="mt-1.5 h-10 w-full rounded-full bg-sink px-4 text-sm text-paper outline-none focus:ring-2 focus:ring-cyan-2/40">
                    </label>
                </template>
            </div>

            <div class="flex min-w-0 flex-col">
                <p class="text-[12px] font-medium text-mist">Perintah RouterOS yang akan dijalankan</p>
                <pre class="mt-1.5 flex-1 overflow-x-auto rounded-xl bg-sink p-3 font-mono text-[11px] leading-5 whitespace-pre text-paper">{{ script }}</pre>
                <p v-if="form.hasErrors" class="mt-2 text-sm text-danger">{{ Object.values(form.errors)[0] }}</p>
                <p v-else-if="form.recentlySuccessful" class="mt-2 text-sm text-ok">Diterapkan. Data queue diperbarui di poll berikutnya.</p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <template v-if="!confirming">
                        <button type="submit" class="h-10 rounded-full bg-cyan-2 px-4 text-sm font-semibold text-[#003640] disabled:opacity-60" :disabled="form.processing || !shaping">Terapkan ke router</button>
                        <button v-if="form.isDirty" type="button" class="h-10 rounded-full bg-tile px-4 text-sm text-mist" @click="form.reset()">Kembalikan</button>
                    </template>
                    <template v-else>
                        <span class="text-sm text-warn">Ubah queue di router sekarang?</span>
                        <button type="button" class="h-10 rounded-full bg-cyan-2 px-4 text-sm font-semibold text-[#003640] disabled:opacity-60" :disabled="form.processing" @click="apply">{{ form.processing ? 'Menerapkan…' : 'Ya, terapkan' }}</button>
                        <button type="button" class="h-10 rounded-full bg-tile px-4 text-sm text-mist" @click="confirming = false">Batal</button>
                    </template>
                </div>
            </div>
        </form>
    </section>

    <p class="mt-3 font-mono text-[11px] text-mute">
        {{ afterQueue ? 'Download diterima = rate setelah queue (Torch di LAN). Sebelum queue = yang masuk dari internet, termasuk yang dibuang queue.' : 'Pengukuran setelah queue belum aktif. Angka download masih dari conntrack (sebelum queue).' }}
    </p>

    <DataTable
        class="mt-3"
        title="Pemakai terbesar sekarang"
        :rows="consumers"
        :columns="consumerColumns"
        :row-key="(row) => row.address"
        default-sort="rx"
        placeholder="Cari klien, IP..."
        empty="Belum ada trafik klien."
        min-width="min-w-[52rem]"
        clickable
        :is-active="(row) => isOpen(consumerKey(row))"
        @open="(row) => open(consumerPreview(row))"
    >
        <template #share="{ row }">
            <span class="flex items-center gap-2">
                <span class="h-1.5 w-24 overflow-hidden rounded-full bg-well">
                    <span class="block h-full rounded-full" :class="hogging(row) ? 'bg-warn' : 'bg-cyan'" :style="{ width: `${Math.min(100, shareOf(row))}%` }" />
                </span>
                <span class="font-mono text-[11px] text-mist">{{ shareOf(row) }}%</span>
            </span>
        </template>
        <template #state="{ row }">
            <span :class="[badge, hogging(row) ? 'bg-warn/15 text-warn' : 'bg-ok/10 text-ok']">{{ hogging(row) ? 'di atas ambang' : 'normal' }}</span>
        </template>
    </DataTable>

    <DataTable
        class="mt-3"
        title="Riwayat insiden · 7 hari"
        :rows="incidents"
        :columns="incidentColumns"
        :row-key="(row, index) => `${row.time}-${index}`"
        default-sort="time"
        placeholder="Cari insiden..."
        empty="Belum ada insiden. Insiden dicatat saat satu klien memakai di atas ambang selama ±15 detik."
        min-width="min-w-[44rem]"
    />
</template>
