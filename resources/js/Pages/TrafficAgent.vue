<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import DataTable from '../Components/DataTable.vue';
import PreviewBinder from '../Components/PreviewBinder.vue';
import { useBoardPulse } from '../composables/usePulse';
import { usePreview } from '../composables/usePreview';
import { formatRate } from '../lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    pulse: { type: Object, default: null },
});

const live = useBoardPulse();
const agent = computed(() => live.value?.agent ?? props.pulse?.agent ?? null);
const findings = computed(() => agent.value?.findings ?? []);
const users = computed(() => agent.value?.users ?? []);
const scoreClass = computed(() => {
    const score = Number(agent.value?.score);
    if (!Number.isFinite(score) || score <= 0) {
        return 'text-mute';
    }

    if (score >= 80) {
        return 'text-ok';
    }

    return score >= 50 ? 'text-warn' : 'text-danger';
});
const badge = 'rounded px-1.5 py-0.5 font-mono text-[11px]';
const severityRank = { critical: 2, warning: 1 };

const columns = [
    { id: 'direction', label: 'Arah', type: 'text', first: 'asc', get: (row) => row.direction || '' },
    { id: 'severity', label: 'Severity', type: 'number', first: 'desc', get: (row) => severityRank[row.severity] ?? 0, show: (row) => row.severity, class: 'text-xs' },
    { id: 'title', label: 'Temuan', type: 'text', first: 'asc', get: (row) => row.title || '', class: 'text-xs' },
    { id: 'local', label: 'Klien', type: 'text', first: 'asc', get: (row) => row.local_name || row.local || '' },
    { id: 'remote', label: 'Lawan', type: 'text', first: 'asc', get: (row) => row.remote || '', class: 'font-mono text-[12px]' },
    { id: 'port', label: 'Port', type: 'number', first: 'desc', get: (row) => Number(row.port) || 0, show: (row) => (row.port ? String(row.port) : '–'), class: 'font-mono text-[12px]' },
    { id: 'rate', label: 'Trafik', type: 'number', first: 'desc', get: (row) => (Number(row.down) || 0) + (Number(row.up) || 0), class: 'font-mono text-[12px] whitespace-nowrap' },
];

function clientLabel(row) {
    if (row.local_name && row.local) {
        return `${row.local_name} · ${row.local}`;
    }

    return row.local_name || row.local || '–';
}

const { open, preview, isOpen } = usePreview();
const show = (value) => (value === null || value === undefined || value === '' ? '–' : String(value));
const scoreTone = computed(() => (scoreClass.value === 'text-ok' ? 'ok' : scoreClass.value === 'text-warn' ? 'warn' : 'danger'));

function findingKey(row) {
    return `agent:finding:${row.direction}:${row.local}:${row.remote || ''}:${row.port || ''}:${row.title}`;
}

function findingPreview(row) {
    return {
        key: findingKey(row),
        icon: 'search',
        title: row.title || 'Temuan',
        subtitle: clientLabel(row),
        detail: row.remote ? `${row.remote}${row.port ? `:${row.port}` : ''}` : '',
        status: { label: row.direction === 'in' ? 'masuk' : 'keluar', tone: row.direction === 'in' ? 'warn' : 'cyan' },
        tabs: [{
            id: 'overview',
            icon: 'search',
            label: 'Temuan',
            hero: true,
            metrics: [
                { label: 'Unduh', value: formatRate(row.down), tone: 'cyan' },
                { label: 'Unggah', value: formatRate(row.up), tone: 'ok' },
                { label: 'Keamanan', value: agent.value?.score ? `${agent.value.score}%` : '–', tone: scoreTone.value },
            ],
            sections: [{
                rows: [
                    { label: 'Arah', value: row.direction === 'in' ? 'masuk' : 'keluar', tone: row.direction === 'in' ? 'warn' : 'cyan' },
                    { label: 'Severity', value: show(row.severity), tone: row.severity === 'critical' ? 'danger' : 'warn' },
                    { label: 'Klien', value: clientLabel(row), mono: false },
                    { label: 'Lawan', value: show(row.remote) },
                    { label: 'Port', value: row.port ? String(row.port) : '–' },
                ],
            }],
            body: row.detail || '',
        }],
    };
}

function userPreview(user) {
    const flows = user.flows || [];

    return {
        key: `agent:user:${user.address}`,
        icon: 'devices',
        title: user.name || user.address || 'User',
        subtitle: user.address || '',
        detail: user.mac || '',
        status: { label: user.severity === 'critical' ? 'critical' : 'warning', tone: user.severity === 'critical' ? 'danger' : 'warn' },
        tabs: [{
            id: 'overview',
            icon: 'devices',
            label: 'User',
            hero: true,
            metrics: [
                { label: 'Unduh', value: formatRate(user.down), tone: 'cyan' },
                { label: 'Unggah', value: formatRate(user.up), tone: 'ok' },
                { label: 'Keamanan', value: agent.value?.score ? `${agent.value.score}%` : '–', tone: scoreTone.value },
            ],
            sections: [
                {
                    title: 'Alasan',
                    rows: (user.reasons || []).map((reason, index) => ({ label: String(index + 1), value: reason, mono: false })),
                },
                {
                    title: 'Koneksi',
                    rows: flows.map((flow, index) => ({
                        label: flow.direction === 'in' ? `Masuk ${index + 1}` : `Keluar ${index + 1}`,
                        value: `${show(flow.remote)}${flow.port ? `:${flow.port}` : ''} · ↓ ${formatRate(flow.down)} ↑ ${formatRate(flow.up)}`,
                    })),
                },
            ].filter((section) => section.rows.length),
        }],
    };
}

function summaryPreview() {
    return {
        key: 'agent:summary',
        icon: 'search',
        title: 'Agen trafik',
        subtitle: live.value?.sampled_label || '',
        status: { label: agent.value?.score ? `${agent.value.score}%` : '–', tone: scoreTone.value },
        tabs: [{
            id: 'overview',
            icon: 'search',
            label: 'Agen',
            hero: true,
            metrics: [
                { label: 'Keamanan', value: agent.value?.score ? `${agent.value.score}%` : '–', tone: scoreTone.value },
                { label: 'Masuk', value: agent.value ? agent.value.inbound.toLocaleString('id-ID') : '–', tone: 'warn' },
                { label: 'Keluar', value: agent.value ? agent.value.outbound.toLocaleString('id-ID') : '–', tone: 'cyan' },
            ],
            sections: [{
                rows: [
                    { label: 'Mencurigakan', value: agent.value ? String(agent.value.suspicious) : '–', tone: findings.value.length ? 'danger' : 'ok' },
                    { label: 'User', value: String(users.value.length) },
                ],
            }],
            body: '100 berarti cuplikan ini bersih. Angka mengecil kalau ada koneksi masuk yang mencurigakan, port keluar yang tidak biasa, atau unggahan user yang jauh lebih besar dari unduhannya.',
        }],
    };
}

const active = computed(() => {
    const key = preview.value?.key;
    if (!key) {
        return null;
    }

    if (key === 'agent:summary') {
        return summaryPreview();
    }

    const finding = findings.value.find((row) => findingKey(row) === key);
    if (finding) {
        return findingPreview(finding);
    }

    const user = users.value.find((row) => `agent:user:${row.address}` === key);

    return user ? userPreview(user) : null;
});
</script>

<template>
    <Head title="Agen Trafik" />

    <PreviewBinder :value="active" />

    <div>
        <h2 class="text-[15px] font-semibold">Agen trafik</h2>
        <p class="font-mono text-[11px] text-mute">Memeriksa koneksi masuk, koneksi keluar, dan trafik tiap user. Trafik web biasa di port 80 dan 443 tidak ditandai, kecuali unggahan user jauh lebih besar dari unduhannya.</p>
    </div>

    <section class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <button type="button" class="noc-card cursor-pointer p-3 text-left transition hover:brightness-110" :class="isOpen('agent:summary') ? 'outline outline-cyan-2' : ''" @click="open(summaryPreview())">
            <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Keamanan trafik</p>
            <p class="mt-1 text-2xl font-bold" :class="scoreClass">{{ agent?.score ? `${agent.score}%` : '–' }}</p>
            <p class="font-mono text-[11px] text-mute">1 berisiko · 100 aman</p>
        </button>
        <article class="noc-card p-3">
            <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Masuk</p>
            <p class="mt-1 text-2xl font-bold text-warn">{{ agent ? agent.inbound.toLocaleString('id-ID') : '–' }}</p>
            <p class="font-mono text-[11px] text-mute">koneksi dari luar</p>
        </article>
        <article class="noc-card p-3">
            <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Keluar</p>
            <p class="mt-1 text-2xl font-bold text-cyan">{{ agent ? agent.outbound.toLocaleString('id-ID') : '–' }}</p>
            <p class="font-mono text-[11px] text-mute">koneksi dari klien</p>
        </article>
        <article class="noc-card p-3">
            <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Mencurigakan</p>
            <p class="mt-1 text-2xl font-bold" :class="findings.length ? 'text-danger' : 'text-ok'">{{ agent ? agent.suspicious.toLocaleString('id-ID') : '–' }}</p>
            <p class="font-mono text-[11px] text-mute">pada cuplikan ini</p>
        </article>
    </section>

    <section v-if="users.length" class="mt-3">
        <h3 class="text-[15px] font-semibold">Trafik mencurigakan per user</h3>
        <div class="mt-2 grid gap-3 lg:grid-cols-2">
            <button v-for="user in users" :key="user.address" type="button" class="noc-card cursor-pointer p-3 text-left transition hover:brightness-110" :class="isOpen(`agent:user:${user.address}`) ? 'outline outline-cyan-2' : ''" @click="open(userPreview(user))">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold">{{ user.name || user.address }}</p>
                        <p class="truncate font-mono text-[11px] text-mute">
                            {{ user.address }}<template v-if="user.mac"> · {{ user.mac }}</template>
                        </p>
                    </div>
                    <p class="shrink-0 font-mono text-[11px]">
                        <span class="text-cyan">↓ {{ formatRate(user.down) }}</span>
                        <span class="text-ok"> ↑ {{ formatRate(user.up) }}</span>
                    </p>
                </div>
                <div class="mt-2 flex flex-wrap gap-1">
                    <span v-for="reason in user.reasons" :key="reason" :class="[badge, user.severity === 'critical' ? 'bg-danger/15 text-danger' : 'bg-warn/15 text-warn']">{{ reason }}</span>
                </div>
                <ul v-if="user.flows?.length" class="mt-2 space-y-1">
                    <li v-for="(flow, index) in user.flows" :key="`${flow.remote}-${flow.port}-${index}`" class="font-mono text-[11px] text-mist">
                        <span :class="flow.direction === 'in' ? 'text-warn' : 'text-cyan'">{{ flow.direction === 'in' ? 'masuk' : 'keluar' }}</span>
                        {{ flow.remote }}<template v-if="flow.port">:{{ flow.port }}</template>
                        <span class="text-cyan"> ↓ {{ formatRate(flow.down) }}</span>
                        <span class="text-ok"> ↑ {{ formatRate(flow.up) }}</span>
                    </li>
                </ul>
            </button>
        </div>
    </section>

    <DataTable
        class="mt-3"
        title="Temuan agen"
        :rows="findings"
        :columns="columns"
        :row-key="(row, index) => `${row.direction}-${row.local}-${row.remote}-${row.port}-${index}`"
        default-sort="severity"
        placeholder="Cari klien, lawan, atau temuan..."
        :empty="agent ? 'Tidak ada trafik mencurigakan pada cuplikan ini.' : 'Menunggu cuplikan koneksi dari router.'"
        min-width="min-w-[52rem]"
        clickable
        :is-active="(row) => isOpen(findingKey(row))"
        @open="(row) => open(findingPreview(row))"
    >
        <template #direction="{ row }">
            <span :class="[badge, row.direction === 'in' ? 'bg-warn/15 text-warn' : 'bg-cyan/10 text-cyan']">{{ row.direction === 'in' ? 'masuk' : 'keluar' }}</span>
        </template>
        <template #severity="{ row }">
            <span :class="[badge, row.severity === 'critical' ? 'bg-danger/15 text-danger' : 'bg-warn/15 text-warn']">{{ row.severity }}</span>
        </template>
        <template #title="{ row }">
            <span class="block text-xs">{{ row.title }}</span>
            <span class="font-mono text-[10px] text-mute">{{ row.detail }}</span>
        </template>
        <template #local="{ row }">
            <span class="font-mono text-[12px]">{{ clientLabel(row) }}</span>
        </template>
        <template #rate="{ row }">
            <span class="text-cyan">↓ {{ formatRate(row.down) }}</span>
            <span class="text-mute"> · </span>
            <span class="text-ok">↑ {{ formatRate(row.up) }}</span>
        </template>
    </DataTable>
</template>
