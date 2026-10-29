<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import PagedTable from '../Components/PagedTable.vue';
import PreviewBinder from '../Components/PreviewBinder.vue';
import { live as boardLive, ranges } from '../composables/useBoardTime';
import { useBoardPulse } from '../composables/usePulse';
import { usePreview } from '../composables/usePreview';
import { formatBytes } from '../lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    pulse: { type: Object, default: null },
    minutes: { type: Number, default: 30 },
    from: { type: Number, default: null },
    to: { type: Number, default: null },
    events: { type: Array, default: () => [] },
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

const { open, preview, isOpen } = usePreview();
const show = (value) => (value === null || value === undefined || value === '' ? '–' : String(value));

function servicePreview(service) {
    const off = Boolean(service.disabled);

    return {
        key: `service:${service.name}:${service.port}:${service.proto}`,
        icon: 'dns',
        title: service.name || 'Service',
        subtitle: `${service.port}/${service.proto}`,
        status: { label: off ? 'off' : 'on', tone: off ? 'mute' : 'ok' },
        tabs: [
            {
                id: 'overview',
                icon: 'dns',
                label: 'Service',
                hero: true,
                metrics: [
                    { label: 'Port', value: show(service.port) },
                    { label: 'Proto', value: show(service.proto) },
                    { label: 'State', value: off ? 'off' : 'on', tone: off ? 'mute' : 'ok' },
                ],
                sections: [{
                    rows: [
                        { label: 'Address', value: service.address || 'semua IP', tone: !off && !service.address ? 'warn' : undefined },
                        { label: 'Invalid', value: service.invalid ? 'yes' : 'no', tone: service.invalid ? 'danger' : undefined },
                    ],
                }],
            },
        ],
    };
}

function ruleKey(rule) {
    return `rule:${rule.chain}:${rule.action}:${rule.comment}:${rule.in_interface}:${rule.out_interface}`;
}

function rulePreview(rule) {
    const dropping = rule.action === 'drop' || rule.action === 'reject';

    return {
        key: ruleKey(rule),
        icon: 'shield',
        title: rule.comment || `${rule.chain || 'chain'} · ${rule.action || 'action'}`,
        subtitle: rule.comment ? `${rule.chain || ''} · ${rule.action || ''}` : '',
        status: {
            label: rule.disabled ? 'off' : (rule.action || 'on'),
            tone: rule.disabled ? 'mute' : (dropping ? 'danger' : 'ok'),
        },
        tabs: [
            {
                id: 'overview',
                icon: 'shield',
                label: 'Rule',
                hero: true,
                sections: [{
                    rows: [
                        { label: 'Chain', value: show(rule.chain) },
                        { label: 'Action', value: show(rule.action), tone: dropping ? 'danger' : 'ok' },
                        { label: 'Comment', value: show(rule.comment), mono: false },
                        { label: 'In interface', value: rule.in_interface || '*' },
                        { label: 'Out interface', value: rule.out_interface || '*' },
                        { label: 'Disabled', value: rule.disabled ? 'yes' : 'no' },
                    ],
                }],
            },
            {
                id: 'stats',
                icon: 'bar_chart',
                label: 'Trafik',
                metrics: [
                    { label: 'Packets', value: show(rule.packets) },
                    { label: 'Bytes', value: formatBytes(rule.bytes), tone: 'cyan' },
                ],
                sections: [{
                    title: 'Counters',
                    rows: [
                        { label: 'Packets', value: show(rule.packets) },
                        { label: 'Bytes', value: formatBytes(rule.bytes) },
                    ],
                }],
            },
        ],
    };
}

const serviceText = (service) => [service.name, service.port, service.proto, service.address].join(' ');
const serviceLabel = (service) => service.name || '';
const ruleText = (rule) => [rule.chain, rule.action, rule.comment, rule.in_interface, rule.out_interface].join(' ');
const ruleLabel = (rule) => rule.comment || rule.chain || '';

function activeSecurity(live) {
    const key = preview.value?.key ?? '';
    const services = live?.security?.services ?? [];
    const service = services.find((row) => servicePreview(row).key === key);
    if (service) {
        return servicePreview(service);
    }

    const rule = (live?.security?.rules ?? []).find((row) => ruleKey(row) === key);

    return rule ? rulePreview(rule) : null;
}

const groups = (live) => {
    const map = {};
    for (const item of live?.security?.address_lists ?? []) {
        const name = item.list || 'tanpa-nama';
        (map[name] ??= []).push(item);
    }
    return map;
};

const severityClass = (severity) => {
    if (severity === 'critical') {
        return 'border-danger/40 text-danger';
    }
    if (severity === 'warning') {
        return 'border-warn/40 text-warn';
    }
    return 'border-line text-mist';
};

const filteredRules = (live, search) => {
    const needle = String(search || '').trim().toLowerCase();
    const rules = live?.security?.rules ?? [];
    if (!needle) {
        return rules;
    }
    return rules.filter((rule) => `${rule.chain} ${rule.action} ${rule.comment} ${rule.in_interface} ${rule.out_interface}`.toLowerCase().includes(needle));
};

const eventCount = computed(() => props.events.length);
</script>

<template>
    <Head title="Firewall & Security" />

        <PreviewBinder :value="activeSecurity(live)" />
        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <article class="noc-card p-3">
                <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Connections</p>
                <p class="mt-1 text-2xl font-bold text-cyan">{{ (live?.security?.connections ?? 0).toLocaleString('en-US') }}</p>
            </article>
            <article class="noc-card p-3">
                <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Findings</p>
                <p class="mt-1 text-2xl font-bold text-warn">{{ live?.security?.findings?.length ?? 0 }}</p>
            </article>
            <article class="noc-card p-3">
                <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Admin sessions</p>
                <p class="mt-1 text-2xl font-bold">{{ live?.security?.sessions?.length ?? 0 }}</p>
            </article>
            <article class="noc-card p-3">
                <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Events · {{ rangeLabel }}</p>
                <p class="mt-1 text-2xl font-bold">{{ eventCount }}</p>
            </article>
        </section>

        <section class="mt-3 grid gap-3 lg:grid-cols-2">
            <article
                v-for="finding in live?.security?.findings || []"
                :key="finding.title"
                class="noc-card border p-3"
                :class="severityClass(finding.severity)"
            >
                <p class="font-mono text-[10px] tracking-wide uppercase">{{ finding.severity }} · {{ finding.category }}</p>
                <p class="mt-1 text-sm text-paper">{{ finding.title }}</p>
                <p class="mt-1 font-mono text-[11px] text-mist">{{ finding.detail }}</p>
            </article>
            <article v-if="!(live?.security?.findings || []).length" class="noc-card p-3 font-mono text-[11px] text-mute">
                Tidak ada temuan konfigurasi saat ini.
            </article>
        </section>

        <section class="mt-3 grid gap-3 xl:grid-cols-2">
            <article class="noc-card p-3">
                <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Active admin sessions</p>
                <ul class="mt-2 space-y-2">
                    <li v-for="session in live?.security?.sessions || []" :key="`${session.name}-${session.via}-${session.when}`">
                        <p class="text-sm">{{ session.name }} <span class="text-mist">via {{ session.via }}</span></p>
                        <p class="font-mono text-[11px] text-mute">{{ session.address }} · {{ session.group }} · {{ session.when }}</p>
                    </li>
                    <li v-if="!(live?.security?.sessions || []).length" class="font-mono text-[11px] text-mute">Tidak ada sesi.</li>
                </ul>
            </article>

            <article class="noc-card overflow-hidden">
                <p class="px-3 pt-2 text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Router services</p>
                <PagedTable :rows="live?.security?.services || []" :text="serviceText" :label="serviceLabel" placeholder="Cari service...">
                <template #default="{ rows: pageRows }">
                <table class="w-full text-left text-sm">
                    <tbody>
                        <tr
                            v-for="service in pageRows"
                            :key="service.name"
                            tabindex="0"
                            class="cursor-pointer border-t border-line hover:bg-hover"
                            :class="isOpen(servicePreview(service).key) ? 'bg-active' : ''"
                            @click="open(servicePreview(service))"
                            @keydown.enter.prevent="open(servicePreview(service))"
                        >
                            <td class="px-3 py-2 font-mono text-cyan">{{ service.name }}</td>
                            <td class="px-3 py-2 font-mono text-mist">{{ service.port }}/{{ service.proto }}</td>
                            <td class="px-3 py-2 font-mono text-[11px]" :class="service.disabled ? 'text-mute' : 'text-warn'">
                                {{ service.disabled ? 'off' : (service.address || 'semua IP') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                </template>
                </PagedTable>
            </article>
        </section>

        <section class="noc-card mt-3 overflow-x-auto">
            <div class="px-3 pt-2">
                <p class="text-[15px] font-semibold">Firewall filter</p>
            </div>
            <PagedTable :rows="filteredRules(live, query)" :text="ruleText" :label="ruleLabel" placeholder="Cari rule...">
            <template #default="{ rows: pageRows }">
            <table class="w-full min-w-[48rem] text-left text-sm">
                <thead class="font-mono text-[10px] tracking-wide text-mute uppercase">
                    <tr>
                        <th class="px-3 py-2 font-medium">Chain</th>
                        <th class="px-3 py-2 font-medium">Action</th>
                        <th class="px-3 py-2 font-medium">Comment</th>
                        <th class="px-3 py-2 font-medium">Interface</th>
                        <th class="px-3 py-2 font-medium">Packets</th>
                        <th class="px-3 py-2 font-medium">Bytes</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(rule, index) in pageRows"
                        :key="`${rule.chain}-${rule.comment}-${index}`"
                        tabindex="0"
                        class="cursor-pointer border-t border-line hover:bg-hover"
                        :class="isOpen(ruleKey(rule)) ? 'bg-active' : ''"
                        @click="open(rulePreview(rule))"
                        @keydown.enter.prevent="open(rulePreview(rule))"
                    >
                        <td class="px-3 py-2 font-mono">{{ rule.chain }}</td>
                        <td class="px-3 py-2 font-mono" :class="rule.action === 'drop' || rule.action === 'reject' ? 'text-danger' : 'text-paper'">{{ rule.action }}</td>
                        <td class="px-3 py-2 text-mist">{{ rule.comment || '–' }}</td>
                        <td class="px-3 py-2 font-mono text-[11px] text-mist">{{ rule.in_interface || '*' }} → {{ rule.out_interface || '*' }}</td>
                        <td class="px-3 py-2 font-mono">{{ rule.packets }}</td>
                        <td class="px-3 py-2 font-mono">{{ formatBytes(rule.bytes) }}</td>
                    </tr>
                </tbody>
            </table>
            </template>
            </PagedTable>
        </section>

        <section class="mt-3 grid gap-3 lg:grid-cols-2">
            <article v-for="(items, name) in groups(live)" :key="name" class="noc-card p-3">
                <p class="font-mono text-sm text-cyan">{{ name }}</p>
                <ul class="mt-2 space-y-1.5">
                    <li v-for="item in items" :key="`${name}-${item.address}`" class="text-sm">
                        <span class="font-mono">{{ item.address }}</span>
                        <span v-if="item.comment" class="text-mist"> · {{ item.comment }}</span>
                    </li>
                </ul>
            </article>
        </section>

        <section class="noc-card mt-3 p-3">
            <div class="flex items-center justify-between gap-2">
                <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Event history</p>
                <span class="font-mono text-[11px] text-mute">{{ rangeLabel }}</span>
            </div>
            <ul class="mt-2 space-y-2">
                <li v-for="(event, index) in events" :key="`${event.time}-${event.title}-${index}`">
                    <p class="font-mono text-[11px]" :class="event.severity === 'critical' ? 'text-danger' : 'text-warn'">{{ event.time }} · {{ event.category }}</p>
                    <p class="text-sm">{{ event.title }}</p>
                    <p class="font-mono text-[11px] text-mute">{{ event.detail }}</p>
                </li>
                <li v-if="events.length === 0" class="font-mono text-[11px] text-mute">{{ boardLive ? 'Tidak ada kejadian.' : 'Tidak ada kejadian pada rentang ini.' }}</li>
            </ul>
        </section>
</template>
