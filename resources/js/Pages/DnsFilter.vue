<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import DataTable from '../Components/DataTable.vue';
import LiveRate from '../Components/LiveRate.vue';
import PreviewBinder from '../Components/PreviewBinder.vue';
import ToggleSwitch from '../Components/ToggleSwitch.vue';
import { usePreview } from '../composables/usePreview';
import { formatBytes, formatRate } from '../lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    filter: { type: Object, default: null },
});

const form = useForm({ domain: '' });
const removing = ref('');
const pending = ref({});
const toggleError = ref('');
const dropBps = ref(0);
const { open, preview, isOpen } = usePreview();
let mark = null;

const sites = computed(() => props.filter?.sites ?? []);
const loading = computed(() => props.filter === null);
const online = computed(() => props.filter?.online === true);
const active = computed(() => props.filter?.active === true);

watch(() => [props.filter?.sampled_at, props.filter?.bytes, props.filter?.packets], () => {
    const value = props.filter;
    if (!value?.sampled_at) {
        return;
    }

    const bytes = Number(value.bytes) || 0;
    const packets = Number(value.packets) || 0;
    const stamp = Date.parse(value.sampled_at);
    if (!Number.isFinite(stamp)) {
        return;
    }

    if (mark && stamp > mark.at) {
        const seconds = (stamp - mark.at) / 1000;
        if (seconds > 0 && bytes >= mark.bytes) {
            dropBps.value = ((bytes - mark.bytes) * 8) / seconds;
        } else {
            dropBps.value = 0;
        }
    }

    mark = { at: stamp, bytes, packets };
});

const columns = [
    { id: 'domain', label: 'Situs', type: 'text', first: 'asc', get: (row) => row.domain || '' },
    { id: 'layer7', label: 'Regexp', type: 'number', first: 'desc', get: (row) => (row.enabled ? 1 : 0), show: (row) => (row.enabled ? 'masuk' : '–') },
    { id: 'firewall', label: 'Block', type: 'number', first: 'desc', get: (row) => (row.firewall ? 1 : 0), show: (row) => (row.firewall ? 'Layer 7' : '–') },
    { id: 'enabled', label: 'Aktif', type: 'number', first: 'desc', get: (row) => (shown(row) ? 1 : 0), show: (row) => (shown(row) ? 'ya' : 'tidak') },
    { id: 'remove', label: '', type: 'text', first: 'asc', get: () => '' },
];

function shown(row) {
    return Object.prototype.hasOwnProperty.call(pending.value, row.domain) ? pending.value[row.domain] : row.enabled === true;
}

function sitePreview(row) {
    const on = shown(row);

    return {
        key: `dns-filter:${row.domain}`,
        icon: 'block',
        title: row.domain,
        subtitle: 'Layer 7 · TCP 80, 443',
        detail: 'Angka trafik ini milik rule yang sama, untuk semua situs yang sedang aktif.',
        status: { label: on ? 'aktif' : 'mati', tone: on ? 'danger' : 'mute' },
        switch: {
            on,
            label: on ? 'Blokir aktif' : 'Blokir mati',
            hint: 'Mati: situs tetap di daftar, tapi dikeluarkan dari regexp.',
            run: () => toggle(row),
        },
        stream: {
            rx: dropBps.value,
            tx: 0,
            rxLabel: '↓ drop',
            txLabel: '',
            at: props.filter?.sampled_at ?? null,
        },
        tabs: [{
            id: 'overview',
            icon: 'block',
            label: 'Situs',
            hero: true,
            metrics: [
                { label: 'Dijatuhkan', value: formatRate(dropBps.value), tone: 'cyan' },
                { label: 'Paket', value: Number(props.filter?.packets || 0).toLocaleString('id-ID') },
                { label: 'Byte', value: formatBytes(props.filter?.bytes) },
            ],
            sections: [{
                rows: [
                    { label: 'Regexp', value: String(row.domain).replaceAll('.', '\\.') },
                    { label: 'Port', value: '80, 443' },
                    { label: 'Status', value: on ? 'aktif' : 'mati', tone: on ? 'danger' : 'mute' },
                ],
            }],
        }],
    };
}

const activeSite = computed(() => {
    const key = preview.value?.key;
    const row = sites.value.find((site) => `dns-filter:${site.domain}` === key);

    return row ? sitePreview(row) : null;
});

function block() {
    form.post('/dns-filter', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function toggle(row) {
    const next = !shown(row);
    pending.value = { ...pending.value, [row.domain]: next };
    toggleError.value = '';
    router.put('/dns-filter', { domain: row.domain, enabled: next }, {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => {
            toggleError.value = errors.domain || 'Router menolak perubahan.';
        },
        onFinish: () => {
            const copy = { ...pending.value };
            delete copy[row.domain];
            pending.value = copy;
        },
    });
}

function remove(domain) {
    removing.value = domain;
    router.delete('/dns-filter', {
        data: { domain },
        preserveScroll: true,
        onFinish: () => {
            removing.value = '';
        },
    });
}
</script>

<template>
    <Head title="DNS Filter" />

    <div>
        <h2 class="text-[15px] font-semibold">Filter DNS & block situs</h2>
        <p class="font-mono text-[11px] text-mute">Situs yang diblokir masuk ke satu regexp Layer 7. Trafik TCP yang cocok dengan regexp itu dijatuhkan.</p>
    </div>

    <section class="mt-3 grid gap-3 sm:grid-cols-3">
        <article class="noc-card p-3">
            <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Situs diblokir</p>
            <p class="mt-1 text-2xl font-bold">{{ loading ? '–' : sites.length }}</p>
        </article>
        <article class="noc-card p-3">
            <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Layer 7</p>
            <p class="mt-1 text-2xl font-bold" :class="active ? 'text-ok' : 'text-mute'">{{ loading ? '–' : (sites.length === 0 ? 'Kosong' : (active ? 'Aktif' : 'Mati')) }}</p>
        </article>
        <article class="noc-card p-3">
            <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Trafik dijatuhkan</p>
            <p class="mt-1 text-2xl font-bold text-cyan"><LiveRate :value="dropBps" split /></p>
            <p class="mt-1 font-mono text-[10px] text-mute">{{ loading ? '–' : `${Number(filter?.packets || 0).toLocaleString('id-ID')} paket` }}</p>
        </article>
    </section>

    <form class="noc-card mt-3 flex flex-wrap items-end gap-2 p-3" @submit.prevent="block">
        <label class="min-w-64 flex-1">
            <span class="text-[11px] text-mute">Domain</span>
            <input
                v-model="form.domain"
                type="text"
                placeholder="facebook.com"
                class="mt-1.5 h-10 w-full rounded-xl bg-sink px-3 text-sm text-paper outline-none focus:ring-1 focus:ring-cyan-2"
                :disabled="!online || form.processing"
            >
        </label>
        <button type="submit" class="h-10 rounded-full bg-cyan-2 px-4 text-sm font-semibold text-[#003640] disabled:opacity-60" :disabled="!online || form.processing || !form.domain.trim()">
            {{ form.processing ? 'Memblokir…' : 'Blokir situs' }}
        </button>
        <p v-if="form.errors.domain || toggleError" class="w-full text-[11px] text-danger">{{ form.errors.domain || toggleError }}</p>
        <p v-else-if="filter && !online" class="w-full text-[11px] text-warn">Router tidak menjawab, jadi daftar blokir belum bisa diubah.</p>
        <p v-else class="w-full font-mono text-[10px] text-mute">www ikut terblokir, karena nama situs ada di dalam regexp. Port 80 dan 443. Angka trafik bergerak saat Live menyala.</p>
        <p v-if="filter?.regexp" class="w-full font-mono text-[10px] break-all text-mute">{{ filter.regexp }}</p>
    </form>

    <DataTable
        class="mt-3"
        title="Situs yang diblokir"
        :rows="sites"
        :columns="columns"
        :row-key="(row) => row.domain"
        default-sort="domain"
        placeholder="Cari domain..."
        :empty="loading ? 'Memuat dari router…' : 'Belum ada situs yang diblokir.'"
        min-width="min-w-[40rem]"
        clickable
        :is-active="(row) => isOpen(`dns-filter:${row.domain}`)"
        @open="(row) => open(sitePreview(row))"
    >
        <template #layer7="{ row }">
            <span class="rounded px-1.5 py-0.5 font-mono text-[11px]" :class="row.enabled ? 'bg-ok/10 text-ok' : 'text-mute'">{{ row.enabled ? 'masuk' : '–' }}</span>
        </template>
        <template #firewall="{ row }">
            <span class="rounded px-1.5 py-0.5 font-mono text-[11px]" :class="row.firewall ? 'bg-warn/15 text-warn' : 'text-mute'">{{ row.firewall ? 'Layer 7' : '–' }}</span>
        </template>
        <template #enabled="{ row }">
            <span class="inline-flex" @click.stop>
                <ToggleSwitch :model-value="shown(row)" @update:model-value="toggle(row)" />
            </span>
        </template>
        <template #remove="{ row }">
            <button type="button" class="font-mono text-[11px] text-danger disabled:opacity-50" :disabled="removing === row.domain" @click.stop="remove(row.domain)">
                {{ removing === row.domain ? 'Menghapus…' : 'Buka blokir' }}
            </button>
        </template>
    </DataTable>

    <PreviewBinder :value="activeSite" />
</template>
