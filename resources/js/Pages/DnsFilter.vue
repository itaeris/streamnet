<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import DataTable from '../Components/DataTable.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    filter: { type: Object, default: null },
});

const form = useForm({ domain: '' });
const removing = ref('');
const sites = computed(() => props.filter?.sites ?? []);
const loading = computed(() => props.filter === null);
const online = computed(() => props.filter?.online === true);

const columns = [
    { id: 'domain', label: 'Situs', type: 'text', first: 'asc', get: (row) => row.domain || '' },
    { id: 'dns', label: 'Filter DNS', type: 'number', first: 'desc', get: (row) => (row.dns ? 1 : 0), show: (row) => (row.dns ? 'NXDOMAIN' : '–') },
    { id: 'firewall', label: 'Block firewall', type: 'number', first: 'desc', get: (row) => (row.firewall ? 1 : 0), show: (row) => (row.firewall ? 'drop' : '–') },
    { id: 'remove', label: '', type: 'text', first: 'asc', get: () => '' },
];

function block() {
    form.post('/dns-filter', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
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
        <p class="font-mono text-[11px] text-mute">DNS router menjawab NXDOMAIN, dan firewall membuang trafik ke alamat situs itu.</p>
    </div>

    <section class="mt-3 grid gap-3 sm:grid-cols-3">
        <article class="noc-card p-3">
            <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Situs diblokir</p>
            <p class="mt-1 text-2xl font-bold">{{ loading ? '–' : sites.length }}</p>
        </article>
        <article class="noc-card p-3">
            <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Filter DNS</p>
            <p class="mt-1 text-2xl font-bold" :class="sites.some((site) => site.dns) ? 'text-ok' : 'text-mute'">{{ sites.some((site) => site.dns) ? 'Aktif' : 'Kosong' }}</p>
        </article>
        <article class="noc-card p-3">
            <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Rule firewall</p>
            <p class="mt-1 text-2xl font-bold" :class="filter?.rule ? 'text-ok' : 'text-mute'">{{ filter?.rule ? 'Drop aktif' : 'Belum ada' }}</p>
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
        <p v-if="form.errors.domain" class="w-full text-[11px] text-danger">{{ form.errors.domain }}</p>
        <p v-else-if="filter && !online" class="w-full text-[11px] text-warn">Router tidak menjawab, jadi daftar blokir belum bisa diubah.</p>
        <p v-else class="w-full font-mono text-[10px] text-mute">www ikut terblokir. FastTrack yang ada di depan rule ini bisa melewatkan blokir firewall; filter DNS tetap berlaku untuk klien yang memakai DNS router.</p>
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
        min-width="min-w-[36rem]"
    >
        <template #dns="{ row }">
            <span class="rounded px-1.5 py-0.5 font-mono text-[11px]" :class="row.dns ? 'bg-ok/10 text-ok' : 'text-mute'">{{ row.dns ? 'NXDOMAIN' : '–' }}</span>
        </template>
        <template #firewall="{ row }">
            <span class="rounded px-1.5 py-0.5 font-mono text-[11px]" :class="row.firewall ? 'bg-warn/15 text-warn' : 'text-mute'">{{ row.firewall ? 'drop' : '–' }}</span>
        </template>
        <template #remove="{ row }">
            <button type="button" class="font-mono text-[11px] text-danger disabled:opacity-50" :disabled="removing === row.domain" @click.stop="remove(row.domain)">
                {{ removing === row.domain ? 'Menghapus…' : 'Buka blokir' }}
            </button>
        </template>
    </DataTable>
</template>
