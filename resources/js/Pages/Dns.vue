<script setup>
import { computed, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import PagedTable from '../Components/PagedTable.vue';
import SegmentTabs from '../Components/SegmentTabs.vue';
import SiteMark from '../Components/SiteMark.vue';
import PreviewBinder from '../Components/PreviewBinder.vue';
import SortHead from '../Components/SortHead.vue';
import { live as boardLive, ranges } from '../composables/useBoardTime';
import { useBoardPulse } from '../composables/usePulse';
import { usePreview } from '../composables/usePreview';
import { clockMillis } from '../lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    pulse: { type: Object, default: null },
    minutes: { type: Number, default: 30 },
    from: { type: Number, default: null },
    to: { type: Number, default: null },
    queries: { type: Array, default: () => [] },
    appSites: { type: Object, default: () => ({}) },
});

const live = useBoardPulse();
const query = '';
const entries = ref([...(props.queries ?? [])]);
const { open, preview, isOpen } = usePreview();

const rangeLabel = computed(() => {
    if (boardLive.value) {
        return 'Live';
    }

    if (props.from && props.to) {
        return 'Rentang khusus';
    }

    return ranges.find((item) => item.minutes === props.minutes)?.label ?? `Last ${props.minutes} minutes`;
});

watch(() => props.queries, (next) => {
    entries.value = [...(next ?? [])];
});

const devices = computed(() => {
    const leases = new Map((live.value?.leases ?? []).map((lease) => [lease.address, lease]));
    const groups = new Map();

    entries.value.forEach((row) => {
        const current = groups.get(row.address) ?? {
            address: row.address,
            host: leases.get(row.address)?.host || '',
            mac: leases.get(row.address)?.mac || '',
            browser: '',
            app: row.app || '',
            queries: 0,
            last: row.time || '',
            domains: [],
        };

        current.queries += 1;
        if (!current.browser && row.browser) {
            current.browser = row.browser;
        }
        if (current.domains.length < 40) {
            current.domains.push(row.domain);
        }
        groups.set(row.address, current);
    });

    return [...groups.values()];
});

const appCount = computed(() => new Set(entries.value.map((row) => row.app).filter(Boolean)).size);

const publicSuffixes = new Set(['co.id', 'ac.id', 'go.id', 'or.id', 'sch.id', 'web.id', 'my.id', 'co.uk', 'com.au', 'com.br']);
const browserSites = {
    Chrome: 'www.google.com',
    Firefox: 'www.mozilla.org',
    Edge: 'www.microsoft.com',
    Brave: 'brave.com',
    Opera: 'www.opera.com',
};

function registrable(domain) {
    const parts = String(domain || '').toLowerCase().split('.').filter(Boolean);
    if (parts.length <= 2) {
        return parts.join('.');
    }

    const tail = parts.slice(-2).join('.');

    return publicSuffixes.has(tail) ? parts.slice(-3).join('.') : tail;
}

function infraHost(host) {
    if (host.startsWith('_') || host.includes('.cdn.') || host.includes('.fna.')) {
        return true;
    }

    const head = host.split('.')[0];

    return ['cdn', 'static', 'api', 'media', 'dit', 'graph', 'g', 'v', 'mmg', 'pps', 'crashlogs', 'g-fallback', 'webtp', 'flows'].includes(head)
        || head.startsWith('media-');
}

function iconHosts(domains, mapped = '') {
    const counts = new Map();
    for (const row of domains ?? []) {
        const host = String(row.domain || row[0] || '').toLowerCase().replace(/\.$/, '');
        const queries = Number(row.queries ?? row[1]) || 1;
        if (!host || host.includes('in-addr.arpa') || infraHost(host)) {
            continue;
        }
        counts.set(host, (counts.get(host) || 0) + queries);
    }

    const score = (host) => {
        const labels = host.split('.');
        const head = labels[0];
        if (head === 'web' || head === 'www') {
            return 2;
        }

        const tail = labels.slice(-2).join('.');

        return labels.length === 2 || (labels.length === 3 && publicSuffixes.has(tail)) ? 1 : 0;
    };
    const hosts = [...counts.entries()]
        .sort((left, right) => score(right[0]) - score(left[0]) || right[1] - left[1])
        .map(([host]) => host);
    if (mapped) {
        hosts.push(String(mapped).toLowerCase());
    }

    return [...new Set(hosts.filter(Boolean))].slice(0, 5);
}

function rank(rows, nameOf, siteOf) {
    const groups = new Map();

    rows.forEach((row) => {
        const name = nameOf(row);
        if (!name) {
            return;
        }

        const current = groups.get(name) ?? { name, users: new Set(), queries: 0, sites: new Map(), domains: new Map(), last: '' };
        current.users.add(row.address);
        current.queries += 1;
        if (row.domain) {
            current.domains.set(row.domain, (current.domains.get(row.domain) || 0) + 1);
        }
        if (row.time && row.time > current.last) {
            current.last = row.time;
        }
        const site = siteOf(row);
        if (site) {
            current.sites.set(site, (current.sites.get(site) || 0) + 1);
        }
        groups.set(name, current);
    });

    return [...groups.values()]
        .map((item) => {
            const ordered = [...item.domains.entries()]
                .sort((left, right) => right[1] - left[1])
                .map(([domain, queries]) => ({ domain, queries }));

            return {
                name: item.name,
                users: item.users.size,
                addresses: [...item.users],
                queries: item.queries,
                last: item.last,
                site: [...item.sites.entries()].sort((left, right) => right[1] - left[1])[0]?.[0] || '',
                domains: ordered.slice(0, 12),
                logoDomains: ordered.slice(0, 40),
            };
        })
        .sort((left, right) => right.users - left.users || right.queries - left.queries);
}

const apps = computed(() => rank(
    entries.value,
    (row) => row.app || '',
    (row) => registrable(row.domain),
).map((item) => {
    const hosts = iconHosts(item.logoDomains, props.appSites?.[item.name] || '');

    return {
        ...item,
        hosts,
        site: hosts[0] || item.site,
    };
}));
const browsers = computed(() => rank(
    entries.value,
    (row) => row.browser || '',
    (row) => browserSites[row.browser] || '',
));
const links = computed(() => rank(
    entries.value,
    (row) => registrable(row.domain),
    (row) => registrable(row.domain),
));
const siteForApp = (name) => apps.value.find((item) => item.name === name)?.site || '';
const topApps = computed(() => apps.value.slice(0, 6));
const storedTab = localStorage.getItem('dns.tab');
const tab = ref(['apps', 'browsers', 'links', 'devices'].includes(storedTab) ? storedTab : 'apps');
watch(tab, (value) => localStorage.setItem('dns.tab', value));
const tabOptions = computed(() => [
    { id: 'apps', label: apps.value.length ? `Aplikasi (${apps.value.length})` : 'Aplikasi' },
    { id: 'browsers', label: browsers.value.length ? `Browser (${browsers.value.length})` : 'Browser' },
    { id: 'links', label: links.value.length ? `Tautan (${links.value.length})` : 'Tautan' },
    { id: 'devices', label: devices.value.length ? `Perangkat (${devices.value.length})` : 'Perangkat' },
]);
const activeRank = computed(() => (tab.value === 'browsers' ? browsers.value : tab.value === 'links' ? links.value : apps.value));
const emptyRank = computed(() => (tab.value === 'browsers' ? 'Browser belum ketahuan.' : tab.value === 'links' ? 'Belum ada tautan.' : 'Belum ada aplikasi.'));

const rankColumns = [
    { id: 'name', type: 'text', first: 'asc', get: (row) => row.name || '' },
    { id: 'site', type: 'text', first: 'asc', get: (row) => row.site || '' },
    { id: 'users', type: 'number', first: 'desc', get: (row) => row.users },
    { id: 'queries', type: 'number', first: 'desc', get: (row) => row.queries },
];
const rankLabels = { name: 'Nama', site: 'Situs', users: 'Pengguna', queries: 'Query' };
const rankText = (row) => [row.name, row.site, ...row.domains.map((item) => item.domain), ...row.addresses].join(' ');
const rankLabel = (row) => row.name || '';

const columnLabels = {
    host: 'Perangkat',
    browser: 'Browser',
    app: 'Aplikasi',
    queries: 'Query',
    last: 'Terakhir',
};
const columnLabel = (id) => columnLabels[id] || id;
const sortColumns = [
    { id: 'host', type: 'text', first: 'asc', get: (row) => row.host || row.address || '' },
    { id: 'browser', type: 'text', first: 'asc', get: (row) => row.browser || '' },
    { id: 'app', type: 'text', first: 'asc', get: (row) => row.app || '' },
    { id: 'queries', type: 'number', first: 'desc', get: (row) => row.queries },
    { id: 'last', type: 'number', first: 'desc', get: (row) => clockMillis(row.last) },
];

function devicePreview(device) {
    const list = entries.value.filter((row) => row.address === device.address).slice(0, 24);

    return {
        key: `dns:${device.address}`,
        icon: 'language',
        title: device.host || device.address,
        subtitle: device.mac || device.address,
        detail: device.address,
        status: device.browser ? { label: device.browser, tone: 'ok' } : { label: 'DNS', tone: 'mute' },
        tabs: [{
            id: 'overview',
            icon: 'language',
            label: 'DNS',
            hero: true,
            sections: [{
                title: 'Domain terbaru',
                rows: list.map((row) => ({
                    label: String(row.time || '').slice(11, 19) || '–',
                    value: `${row.domain} · ${row.app}`,
                })),
            }],
        }],
    };
}

function rankPreview(item, kind) {
    const leases = new Map((live.value?.leases ?? []).map((lease) => [lease.address, lease]));
    const people = item.addresses.map((address) => ({
        label: leases.get(address)?.host || address,
        value: address,
    }));

    return {
        key: `dns-${kind}:${item.name}`,
        icon: 'language',
        title: item.name,
        subtitle: item.site || '',
        detail: item.last || '',
        status: { label: `${item.users} pengguna`, tone: 'ok' },
        tabs: [{
            id: 'overview',
            icon: 'language',
            label: 'DNS',
            hero: true,
            sections: [
                { title: 'Pengguna', rows: people },
                {
                    title: 'Domain',
                    rows: item.domains.map((domain) => ({
                        label: domain.domain,
                        value: `${domain.queries} query`,
                    })),
                },
            ],
        }],
    };
}

function activeDevice() {
    const key = preview.value?.key || '';
    const device = devices.value.find((row) => `dns:${row.address}` === key);
    if (device) {
        return devicePreview(device);
    }

    const ranked = key.startsWith('dns-apps:')
        ? apps.value.find((row) => `dns-apps:${row.name}` === key)
        : key.startsWith('dns-browsers:')
            ? browsers.value.find((row) => `dns-browsers:${row.name}` === key)
            : key.startsWith('dns-links:')
                ? links.value.find((row) => `dns-links:${row.name}` === key)
                : null;
    const kind = key.startsWith('dns-browsers:') ? 'browsers' : key.startsWith('dns-links:') ? 'links' : 'apps';

    return ranked ? rankPreview(ranked, kind) : null;
}

const deviceText = (row) => [row.host, row.address, row.mac, row.browser, row.app, row.domains.join(' ')].join(' ');
const deviceLabel = (row) => row.host || row.address || '';
</script>

<template>
    <Head title="DNS & Apps" />

    <PreviewBinder :value="activeDevice()" />

    <div>
        <h2 class="text-[15px] font-semibold">DNS & Apps</h2>
        <p class="font-mono text-[11px] text-mute">{{ rangeLabel }} · berapa pengguna membuka tiap aplikasi, browser, dan tautan</p>
    </div>

    <section class="mt-3 grid gap-3 sm:grid-cols-3">
        <article class="noc-card p-3">
            <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Perangkat</p>
            <p class="mt-1 text-2xl font-bold">{{ devices.length }}</p>
        </article>
        <article class="noc-card p-3">
            <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Query</p>
            <p class="mt-1 text-2xl font-bold text-cyan">{{ entries.length }}</p>
        </article>
        <article class="noc-card p-3">
            <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Aplikasi</p>
            <p class="mt-1 text-2xl font-bold">{{ appCount }}</p>
        </article>
    </section>

    <section v-if="topApps.length" class="mt-3">
        <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Paling sering dibuka</p>
        <div class="mt-2 flex gap-2 overflow-x-auto pb-1">
            <button
                v-for="app in topApps"
                :key="app.name"
                type="button"
                class="flex w-44 shrink-0 items-center gap-2.5 rounded-xl border border-edge bg-panel px-3 py-2 text-left hover:bg-hover"
                :class="isOpen(`dns-apps:${app.name}`) ? 'bg-active' : ''"
                @click="open(rankPreview(app, 'apps'))"
            >
                <SiteMark :domain="app.site" :domains="app.hosts" :label="app.name" />
                <span class="min-w-0">
                    <span class="block truncate text-sm font-medium">{{ app.name }}</span>
                    <span class="block font-mono text-[10px] text-mute">{{ app.users }} pengguna</span>
                </span>
            </button>
        </div>
    </section>

    <SegmentTabs v-model="tab" class="mt-3" :options="tabOptions" />

    <section v-if="tab !== 'devices'" class="noc-card mt-3 overflow-x-auto">
        <PagedTable :rows="activeRank" :text="rankText" :label="rankLabel" :columns="rankColumns" default-sort="users" placeholder="Cari nama, situs, domain...">
            <template #default="{ rows: pageRows, sortOf }">
                <table class="w-full min-w-[40rem] text-left text-sm">
                    <thead class="font-mono text-[10px] tracking-wide text-mute uppercase">
                        <tr>
                            <th v-for="column in rankColumns" :key="column.id" class="group p-0 font-medium">
                                <SortHead :active="sortOf(column.id).active" :icon="sortOf(column.id).icon" @sort="sortOf(column.id).toggle()">
                                    {{ rankLabels[column.id] }}
                                </SortHead>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in pageRows"
                            :key="item.name"
                            tabindex="0"
                            class="cursor-pointer border-t border-line hover:bg-hover"
                            :class="isOpen(`dns-${tab}:${item.name}`) ? 'bg-active' : ''"
                            @click="open(rankPreview(item, tab))"
                            @keydown.enter.prevent="open(rankPreview(item, tab))"
                        >
                            <td class="px-3 py-2">
                                <span class="flex items-center gap-2">
                                    <SiteMark :domain="item.site" :domains="item.hosts" :label="item.name" />
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium">{{ item.name }}</span>
                                    </span>
                                </span>
                            </td>
                            <td class="px-3 py-2 font-mono text-[11px] text-mist">{{ item.site || '–' }}</td>
                            <td class="px-3 py-2 font-mono">{{ item.users }}</td>
                            <td class="px-3 py-2 font-mono text-mist">{{ item.queries }}</td>
                        </tr>
                        <tr v-if="pageRows.length === 0">
                            <td colspan="4" class="px-3 py-8 text-center font-mono text-[11px] text-mute">{{ emptyRank }}</td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </PagedTable>
    </section>

    <section v-else class="noc-card mt-3 overflow-x-auto">
        <PagedTable :rows="devices" :text="deviceText" :label="deviceLabel" :columns="sortColumns" default-sort="queries" placeholder="Cari perangkat, domain, aplikasi...">
            <template #default="{ rows: pageRows, sortOf }">
                <table class="w-full min-w-[48rem] text-left text-sm">
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
                            v-for="device in pageRows"
                            :key="device.address"
                            tabindex="0"
                            class="cursor-pointer border-t border-line hover:bg-hover"
                            :class="isOpen(`dns:${device.address}`) ? 'bg-active' : ''"
                            @click="open(devicePreview(device))"
                            @keydown.enter.prevent="open(devicePreview(device))"
                        >
                            <td class="px-3 py-2">
                                <p>{{ device.host || '–' }}</p>
                                <p class="font-mono text-[11px] text-cyan">{{ device.address }}</p>
                            </td>
                            <td class="px-3 py-2 font-mono text-[11px]" :class="device.browser ? 'text-ok' : 'text-mute'">{{ device.browser || '–' }}</td>
                            <td class="px-3 py-2">
                                <span class="flex items-center gap-2">
                                    <SiteMark v-if="device.app" :domain="siteForApp(device.app)" :domains="apps.find((item) => item.name === device.app)?.hosts" :label="device.app" />
                                    <span>{{ device.app || '–' }}</span>
                                </span>
                            </td>
                            <td class="px-3 py-2 font-mono text-mist">{{ device.queries }}</td>
                            <td class="px-3 py-2 font-mono text-[11px] text-mist">{{ device.last || '–' }}</td>
                        </tr>
                        <tr v-if="pageRows.length === 0">
                            <td colspan="5" class="px-3 py-8 text-center font-mono text-[11px] text-mute">Belum ada query DNS pada rentang ini.</td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </PagedTable>
    </section>
</template>
