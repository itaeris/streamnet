<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import DataTable from '../Components/DataTable.vue';
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

const severityTone = (severity) => ({ critical: 'danger', warning: 'warn' }[severity] || 'mute');

function findingPreview(finding) {
    return {
        key: `finding:${finding.category}:${finding.title}`,
        icon: 'report',
        title: finding.title || 'Temuan',
        subtitle: finding.category || '',
        status: { label: show(finding.severity), tone: severityTone(finding.severity) },
        tabs: [{
            id: 'overview',
            icon: 'report',
            label: 'Temuan',
            hero: true,
            sections: [{
                rows: [
                    { label: 'Severity', value: show(finding.severity), tone: severityTone(finding.severity) },
                    { label: 'Kategori', value: show(finding.category) },
                ],
            }],
            body: finding.detail || '',
        }],
    };
}

function sessionKey(session) {
    return `session:${session.name}:${session.via}:${session.address}:${session.when}`;
}

function sessionPreview(session) {
    return {
        key: sessionKey(session),
        icon: 'admin_panel_settings',
        title: session.name || 'Sesi',
        subtitle: session.via ? `via ${session.via}` : '',
        detail: session.address || '',
        status: { label: 'aktif', tone: 'ok' },
        tabs: [{
            id: 'overview',
            icon: 'admin_panel_settings',
            label: 'Sesi',
            hero: true,
            sections: [{
                rows: [
                    { label: 'User', value: show(session.name), mono: false },
                    { label: 'Via', value: show(session.via) },
                    { label: 'Address', value: show(session.address) },
                    { label: 'Group', value: show(session.group) },
                    { label: 'Sejak', value: show(session.when) },
                ],
            }],
        }],
    };
}

function addressKey(item) {
    return `address:${item.list}:${item.address}`;
}

function addressPreview(item) {
    const type = item.disabled ? 'off' : item.dynamic ? 'dynamic' : 'static';

    return {
        key: addressKey(item),
        icon: 'list_alt',
        title: item.address || 'Address',
        subtitle: item.list || '',
        status: { label: type, tone: item.disabled ? 'mute' : item.dynamic ? 'cyan' : 'ok' },
        tabs: [{
            id: 'overview',
            icon: 'list_alt',
            label: 'Address',
            hero: true,
            sections: [{
                rows: [
                    { label: 'List', value: show(item.list) },
                    { label: 'Address', value: show(item.address) },
                    { label: 'Comment', value: show(item.comment), mono: false },
                    { label: 'Dynamic', value: item.dynamic ? 'yes' : 'no' },
                    { label: 'Disabled', value: item.disabled ? 'yes' : 'no' },
                ],
            }],
        }],
    };
}

function eventKey(event) {
    return `event:${event.time}:${event.category}:${event.title}`;
}

function eventPreview(event) {
    return {
        key: eventKey(event),
        icon: 'history',
        title: event.title || 'Kejadian',
        subtitle: event.time || '',
        status: { label: show(event.severity), tone: severityTone(event.severity) },
        tabs: [{
            id: 'overview',
            icon: 'history',
            label: 'Kejadian',
            hero: true,
            sections: [{
                rows: [
                    { label: 'Waktu', value: show(event.time) },
                    { label: 'Severity', value: show(event.severity), tone: severityTone(event.severity) },
                    { label: 'Kategori', value: show(event.category) },
                ],
            }],
            body: event.detail || '',
        }],
    };
}

function activeSecurity(live) {
    const key = preview.value?.key ?? '';
    const security = live?.security ?? {};
    const lookups = [
        [security.services, (row) => servicePreview(row).key, servicePreview],
        [security.rules, ruleKey, rulePreview],
        [security.findings, (row) => findingPreview(row).key, findingPreview],
        [security.sessions, sessionKey, sessionPreview],
        [security.address_lists, addressKey, addressPreview],
        [props.events, eventKey, eventPreview],
    ];

    for (const [rows, keyOf, build] of lookups) {
        const match = (rows ?? []).find((row) => keyOf(row) === key);
        if (match) {
            return build(match);
        }
    }

    return null;
}

const severityRank = { critical: 3, warning: 2, info: 1 };
const severityBadge = (severity) => ({
    critical: 'bg-danger/15 text-danger',
    warning: 'bg-warn/15 text-warn',
}[severity] || 'bg-tile text-mist');
const actionBadge = (rule) => {
    if (rule.disabled) {
        return 'bg-tile text-mute';
    }

    return rule.action === 'drop' || rule.action === 'reject' ? 'bg-danger/15 text-danger' : 'bg-ok/10 text-ok';
};
const badge = 'rounded px-1.5 py-0.5 font-mono text-[11px]';

const findingColumns = [
    { id: 'severity', label: 'Severity', type: 'number', first: 'desc', get: (row) => severityRank[row.severity] ?? 0, show: (row) => row.severity },
    { id: 'category', label: 'Kategori', type: 'text', first: 'asc', get: (row) => row.category || '', class: 'font-mono text-mist' },
    { id: 'title', label: 'Temuan', type: 'text', first: 'asc', get: (row) => row.title || '', class: 'text-xs' },
    { id: 'detail', label: 'Detail', type: 'text', first: 'asc', get: (row) => row.detail || '', class: 'font-mono text-[11px] text-mist' },
];

const ruleColumns = [
    { id: 'chain', label: 'Chain', type: 'text', first: 'asc', get: (row) => row.chain || '', class: 'font-mono' },
    { id: 'action', label: 'Action', type: 'text', first: 'asc', get: (row) => row.action || '' },
    { id: 'comment', label: 'Comment', type: 'text', first: 'asc', get: (row) => row.comment || '', class: 'text-mist' },
    { id: 'in', label: 'In', type: 'text', first: 'asc', get: (row) => row.in_interface || '*', class: 'font-mono text-mist' },
    { id: 'out', label: 'Out', type: 'text', first: 'asc', get: (row) => row.out_interface || '*', class: 'font-mono text-mist' },
    { id: 'packets', label: 'Packets', type: 'number', first: 'desc', get: (row) => Number(row.packets) || 0, show: (row) => (Number(row.packets) || 0).toLocaleString('en-US'), class: 'font-mono' },
    { id: 'bytes', label: 'Bytes', type: 'number', first: 'desc', get: (row) => Number(row.bytes) || 0, show: (row) => formatBytes(row.bytes), class: 'font-mono text-cyan' },
];

const serviceColumns = [
    { id: 'name', label: 'Service', type: 'text', first: 'asc', get: (row) => row.name || '', class: 'font-mono text-cyan' },
    { id: 'port', label: 'Port', type: 'number', first: 'asc', get: (row) => Number(row.port) || 0, show: (row) => `${row.port}/${row.proto}`, class: 'font-mono text-mist' },
    { id: 'access', label: 'Akses', type: 'text', first: 'asc', get: (row) => (row.disabled ? '' : row.address || 'semua IP'), class: 'font-mono text-[11px]' },
    { id: 'state', label: 'State', type: 'text', first: 'asc', get: (row) => (row.disabled ? 'off' : 'on') },
];

const sessionColumns = [
    { id: 'name', label: 'User', type: 'text', first: 'asc', get: (row) => row.name || '' },
    { id: 'via', label: 'Via', type: 'text', first: 'asc', get: (row) => row.via || '', class: 'font-mono text-mist' },
    { id: 'address', label: 'Address', type: 'text', first: 'asc', get: (row) => row.address || '', class: 'font-mono text-cyan' },
    { id: 'group', label: 'Group', type: 'text', first: 'asc', get: (row) => row.group || '', class: 'font-mono text-mist' },
    { id: 'when', label: 'Sejak', type: 'text', first: 'desc', get: (row) => row.when || '', class: 'font-mono text-mist' },
];

const addressColumns = [
    { id: 'list', label: 'List', type: 'text', first: 'asc', get: (row) => row.list || '', class: 'font-mono text-cyan' },
    { id: 'address', label: 'Address', type: 'text', first: 'asc', get: (row) => row.address || '', class: 'font-mono' },
    { id: 'comment', label: 'Comment', type: 'text', first: 'asc', get: (row) => row.comment || '', class: 'text-mist' },
    { id: 'type', label: 'Tipe', type: 'text', first: 'asc', get: (row) => (row.disabled ? 'off' : row.dynamic ? 'dynamic' : 'static'), class: 'font-mono text-[11px] text-mist' },
];

const eventColumns = [
    { id: 'time', label: 'Waktu', type: 'text', first: 'desc', get: (row) => row.time || '', class: 'font-mono text-mist whitespace-nowrap' },
    { id: 'severity', label: 'Severity', type: 'number', first: 'desc', get: (row) => severityRank[row.severity] ?? 0, show: (row) => row.severity },
    { id: 'category', label: 'Kategori', type: 'text', first: 'asc', get: (row) => row.category || '', class: 'font-mono text-mist' },
    { id: 'title', label: 'Kejadian', type: 'text', first: 'asc', get: (row) => row.title || '' },
    { id: 'detail', label: 'Detail', type: 'text', first: 'asc', get: (row) => row.detail || '', class: 'font-mono text-[11px] text-mist' },
];

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

        <DataTable
            class="mt-3"
            title="Temuan konfigurasi"
            :rows="live?.security?.findings || []"
            :columns="findingColumns"
            :row-key="(row) => `${row.category}-${row.title}`"
            default-sort="severity"
            placeholder="Cari temuan..."
            empty="Tidak ada temuan konfigurasi saat ini."
            min-width="min-w-[48rem]"
            clickable
            :is-active="(row) => isOpen(findingPreview(row).key)"
            @open="(row) => open(findingPreview(row))"
        >
            <template #severity="{ row }">
                <span :class="[badge, severityBadge(row.severity)]">{{ row.severity }}</span>
            </template>
        </DataTable>

        <DataTable
            class="mt-3"
            title="Firewall filter"
            :rows="live?.security?.rules || []"
            :columns="ruleColumns"
            :row-key="(row, index) => `${ruleKey(row)}-${index}`"
            default-sort="packets"
            placeholder="Cari rule..."
            empty="Tidak ada rule."
            min-width="min-w-[56rem]"
            clickable
            :is-active="(row) => isOpen(ruleKey(row))"
            @open="(row) => open(rulePreview(row))"
        >
            <template #action="{ row }">
                <span :class="[badge, actionBadge(row)]">{{ row.disabled ? `${row.action} · off` : row.action }}</span>
            </template>
        </DataTable>

        <section class="mt-3 grid gap-3 xl:grid-cols-2">
            <DataTable
                title="Router services"
                :rows="live?.security?.services || []"
                :columns="serviceColumns"
                :row-key="(row) => `${row.name}-${row.port}-${row.proto}`"
                default-sort="name"
                placeholder="Cari service..."
                empty="Tidak ada service."
                min-width="min-w-[28rem]"
                clickable
                :is-active="(row) => isOpen(servicePreview(row).key)"
                @open="(row) => open(servicePreview(row))"
            >
                <template #access="{ row }">
                    <span :class="row.disabled ? 'text-mute' : row.address ? 'text-mist' : 'text-warn'">{{ row.disabled ? '–' : (row.address || 'semua IP') }}</span>
                </template>
                <template #state="{ row }">
                    <span :class="[badge, row.disabled ? 'bg-tile text-mute' : 'bg-ok/10 text-ok']">{{ row.disabled ? 'off' : 'on' }}</span>
                </template>
            </DataTable>

            <DataTable
                title="Sesi admin aktif"
                :rows="live?.security?.sessions || []"
                :columns="sessionColumns"
                :row-key="(row, index) => `${row.name}-${row.via}-${row.when}-${index}`"
                default-sort="when"
                placeholder="Cari sesi..."
                empty="Tidak ada sesi."
                min-width="min-w-[28rem]"
                clickable
                :is-active="(row) => isOpen(sessionKey(row))"
                @open="(row) => open(sessionPreview(row))"
            />
        </section>

        <DataTable
            class="mt-3"
            title="Address list"
            :rows="live?.security?.address_lists || []"
            :columns="addressColumns"
            :row-key="(row, index) => `${row.list}-${row.address}-${index}`"
            default-sort="list"
            placeholder="Cari list, address..."
            empty="Tidak ada address list."
            min-width="min-w-[40rem]"
            clickable
            :is-active="(row) => isOpen(addressKey(row))"
            @open="(row) => open(addressPreview(row))"
        />

        <DataTable
            class="mt-3"
            :title="`Riwayat kejadian · ${rangeLabel}`"
            :rows="events"
            :columns="eventColumns"
            :row-key="(row, index) => `${row.time}-${row.title}-${index}`"
            default-sort="time"
            placeholder="Cari kejadian..."
            :empty="boardLive ? 'Tidak ada kejadian.' : 'Tidak ada kejadian pada rentang ini.'"
            min-width="min-w-[56rem]"
            clickable
            :is-active="(row) => isOpen(eventKey(row))"
            @open="(row) => open(eventPreview(row))"
        >
            <template #severity="{ row }">
                <span :class="[badge, severityBadge(row.severity)]">{{ row.severity }}</span>
            </template>
        </DataTable>
</template>
