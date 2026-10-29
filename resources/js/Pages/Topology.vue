<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import { useBoardPulse } from '../composables/usePulse';
import { formatRate } from '../lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    pulse: { type: Object, default: null },
    unifi: { type: Object, default: () => ({ ok: false, configured: false }) },
});

const page = usePage();
const board = useBoardPulse();
const live = computed(() => board.value ?? props.pulse);
const shownClients = 3;
const shownDevices = 8;

const wan = computed(() => live.value?.wan ?? null);
const identity = computed(() => live.value?.system?.identity || live.value?.session_name || 'MikroTik');
const host = computed(() => live.value?.session_host || '');
const sessionHref = computed(() => (page.props.currentSessionSlug ? `/sesi/${page.props.currentSessionSlug}` : '/sesi'));

const bridges = computed(() => (live.value?.interfaces ?? []).filter((row) => {
    if (row.disabled) {
        return false;
    }

    return String(row.type || '').toLowerCase() === 'bridge' || String(row.name || '').toLowerCase() === 'bridge';
}));

const ports = computed(() => {
    const wanName = wan.value?.name;

    return (live.value?.interfaces ?? [])
        .filter((row) => {
            if (row.disabled || row.name === wanName) {
                return false;
            }

            const type = String(row.type || '').toLowerCase();
            const name = String(row.name || '').toLowerCase();

            return ['ether', 'vlan', 'bond'].includes(type) && name !== 'bridge';
        })
        .sort((a, b) => Number(Boolean(b.running)) - Number(Boolean(a.running)) || String(a.name).localeCompare(String(b.name)));
});

const portPeak = computed(() => Math.max(1, ...ports.value.map((row) => (Number(row.rx_bps) || 0) + (Number(row.tx_bps) || 0))));

const accessPoints = computed(() => (props.unifi?.devices ?? []).filter((device) => device.access_point));
const switches = computed(() => (props.unifi?.devices ?? []).filter((device) => !device.access_point));

const apMacs = computed(() => new Set(accessPoints.value.map((device) => String(device.mac || '').toLowerCase()).filter(Boolean)));

const clientsByAp = computed(() => {
    const groups = {};
    for (const client of props.unifi?.clients ?? []) {
        const id = client.uplink_id || '';
        if (!id) {
            continue;
        }
        groups[id] = groups[id] || [];
        groups[id].push(client);
    }

    return groups;
});

const wirelessMacs = computed(() => new Set((props.unifi?.clients ?? []).map((client) => String(client.mac || '').toLowerCase()).filter(Boolean)));

const wired = computed(() => [...(live.value?.leases ?? [])]
    .filter((lease) => {
        const mac = String(lease.mac || '').toLowerCase();
        if (!lease.address) {
            return false;
        }

        return mac === '' || (!apMacs.value.has(mac) && !wirelessMacs.value.has(mac));
    })
    .sort((a, b) => ((Number(b.rx_bps) || 0) + (Number(b.tx_bps) || 0)) - ((Number(a.rx_bps) || 0) + (Number(a.tx_bps) || 0))));

function clientName(client) {
    return client.display_name || client.name || client.hostname || client.ip || client.mac || 'Klien';
}

function leaseName(lease) {
    return lease.comment || lease.host || lease.address || 'Perangkat';
}

function rate(bps) {
    return formatRate(Number(bps) || 0);
}

function busy(row) {
    return (Number(row?.rx_bps) || 0) + (Number(row?.tx_bps) || 0) > 0;
}

function portLoad(row) {
    return Math.round((((Number(row.rx_bps) || 0) + (Number(row.tx_bps) || 0)) / portPeak.value) * 100);
}

</script>

<template>
    <Head title="Topology Map" />

    <div v-if="!live" class="noc-card flex flex-1 items-center justify-center px-6 py-14 text-center">
        <div>
            <p class="text-[15px] font-semibold">Belum ada topologi</p>
            <p class="mt-1 text-sm text-mist">Poller belum mengirim data router ini.</p>
        </div>
    </div>

    <div v-else class="noc-card relative flex min-h-full flex-1 flex-col overflow-hidden">
        <div class="topo-grid pointer-events-none absolute inset-0" />

        <div class="relative flex flex-1 flex-col justify-start px-4 py-6 sm:px-8">
            <div class="map mx-auto flex w-full flex-col items-center">
                <article class="topo-node">
                    <span class="topo-icon bg-cyan-2 text-[#003640]">
                        <Icon class="text-[18px]" name="language" />
                    </span>
                    <div class="min-w-0">
                        <p class="topo-kicker">ISP</p>
                        <p class="text-sm font-semibold whitespace-nowrap">Internet</p>
                    </div>
                </article>

                <div class="stem" />

                <article class="topo-node border-cyan/30">
                    <span class="topo-icon bg-tile text-cyan">
                        <Icon class="text-[18px]" name="swap_horiz" />
                    </span>
                    <div>
                        <p class="topo-kicker">WAN</p>
                        <p class="text-sm font-semibold whitespace-nowrap">{{ wan?.name || 'ether' }}</p>
                        <p class="mt-1 flex flex-wrap gap-x-2 font-mono text-[11px]">
                            <span class="text-cyan">↓ {{ rate(wan?.rx_bps) }}</span>
                            <span class="text-ok">↑ {{ rate(wan?.tx_bps) }}</span>
                        </p>
                    </div>
                </article>

                <div class="stem" />

                <article class="topo-node topo-router">
                    <span class="topo-icon bg-cyan-2 text-[#003640]">
                        <Icon class="text-[20px]" name="router" />
                    </span>
                    <div>
                        <p class="topo-kicker">Router</p>
                        <p class="text-[15px] font-semibold whitespace-nowrap">{{ identity }}</p>
                        <p class="font-mono text-[11px] whitespace-nowrap text-cyan">{{ host }}</p>
                        <p class="mt-1 text-[11px] text-mist">
                            <span :class="live.online ? 'text-ok' : 'text-danger'">{{ live.online ? 'Online' : 'Offline' }}</span>
                            <span v-if="live.system?.version"> · RouterOS {{ live.system.version }}</span>
                        </p>
                    </div>
                </article>

                <template v-if="bridges.length === 1">
                    <div class="stem" />
                    <article class="topo-node">
                        <span class="topo-icon bg-tile text-mist">
                            <Icon class="text-[18px]" name="lan" />
                        </span>
                        <div>
                            <p class="topo-kicker">Bridge</p>
                            <p class="text-sm font-semibold whitespace-nowrap">{{ bridges[0].name }}</p>
                            <p class="text-[11px]" :class="bridges[0].running ? 'text-ok' : 'text-mute'">{{ bridges[0].running ? 'Running' : 'Down' }}</p>
                        </div>
                    </article>
                </template>

                <template v-else-if="bridges.length > 1">
                    <div class="stem" />
                    <ul class="branch">
                        <li v-for="bridge in bridges" :key="bridge.name" class="leaf">
                            <article class="topo-node">
                                <span class="topo-icon bg-tile text-mist">
                                    <Icon class="text-[18px]" name="lan" />
                                </span>
                                <div>
                                    <p class="topo-kicker">Bridge</p>
                                    <p class="text-sm font-semibold whitespace-nowrap">{{ bridge.name }}</p>
                                    <p class="text-[11px]" :class="bridge.running ? 'text-ok' : 'text-mute'">{{ bridge.running ? 'Running' : 'Down' }}</p>
                                </div>
                            </article>
                        </li>
                    </ul>
                </template>

                <template v-if="ports.length">
                    <div class="stem" />
                    <ul class="branch">
                            <li v-for="port in ports" :key="port.name" class="leaf">
                                <article class="topo-port" :class="port.running ? '' : 'is-down'">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-sm font-semibold whitespace-nowrap">{{ port.name }}</p>
                                        <span class="size-1.5 shrink-0 rounded-full" :class="port.running ? 'bg-ok' : 'bg-mute'" />
                                    </div>
                                    <p class="mt-1 font-mono text-[10px] tracking-wide uppercase" :class="port.running ? 'text-ok' : 'text-mute'">
                                        {{ port.running ? port.type : 'down' }}
                                    </p>
                                    <p v-if="port.running" class="mt-2 flex flex-col gap-0.5 font-mono text-[11px]">
                                        <span class="text-cyan">↓ {{ rate(port.rx_bps) }}</span>
                                        <span class="text-ok">↑ {{ rate(port.tx_bps) }}</span>
                                    </p>
                                    <div class="meter mt-2 h-1 overflow-hidden rounded-full bg-well">
                                        <div class="h-full rounded-full bg-cyan" :style="{ width: port.running && busy(port) ? `${Math.max(portLoad(port), 8)}%` : '0%' }" />
                                    </div>
                                </article>
                            </li>
                        </ul>
                </template>

                <template v-if="switches.length">
                    <div class="stem" />
                    <div class="flex flex-wrap justify-center gap-3">
                        <article v-for="device in switches" :key="device.id" class="topo-node">
                            <span class="topo-icon bg-tile text-cyan">
                                <Icon class="text-[18px]" name="hub" />
                            </span>
                            <div>
                                <p class="topo-kicker">Switch</p>
                                <p class="text-sm font-semibold whitespace-nowrap">{{ device.name || device.model }}</p>
                                <p class="font-mono text-[11px] whitespace-nowrap text-mute">{{ device.ip }}</p>
                            </div>
                        </article>
                    </div>
                </template>

                <template v-if="accessPoints.length">
                    <div class="stem" />
                    <ul class="branch">
                            <li v-for="ap in accessPoints" :key="ap.id" class="leaf">
                                <div class="flex flex-col items-stretch gap-2">
                                    <article class="topo-port">
                                        <div class="flex items-center gap-2">
                                            <span class="topo-icon size-8" :class="ap.state === 'online' ? 'bg-ok/15 text-ok' : 'bg-tile text-mute'">
                                                <Icon class="text-[16px]" name="wifi" />
                                            </span>
                                            <div>
                                                <p class="text-sm font-semibold whitespace-nowrap">{{ ap.name || ap.model || 'Access point' }}</p>
                                                <p class="font-mono text-[10px] whitespace-nowrap text-mute">{{ ap.ip || ap.model }}</p>
                                            </div>
                                        </div>
                                        <p class="mt-2 text-[11px]" :class="ap.state === 'online' ? 'text-ok' : 'text-mute'">{{ ap.clients }} klien</p>
                                    </article>
                                    <p
                                        v-for="client in (clientsByAp[ap.id] || []).slice(0, shownClients)"
                                        :key="client.id || client.mac"
                                        class="truncate rounded-full bg-tile px-2.5 py-1 text-center text-[11px] text-mist"
                                        :title="clientName(client)"
                                    >
                                        {{ clientName(client) }}
                                    </p>
                                    <p v-if="(clientsByAp[ap.id] || []).length > shownClients" class="text-center text-[11px] text-mute">
                                        +{{ (clientsByAp[ap.id] || []).length - shownClients }} klien
                                    </p>
                                </div>
                            </li>
                        </ul>
                </template>

                <template v-if="wired.length">
                    <div class="stem" />
                    <ul class="branch">
                        <li v-for="lease in wired.slice(0, shownDevices)" :key="lease.address + lease.mac" class="leaf">
                            <article class="topo-chip">
                                <span class="size-1.5 shrink-0 rounded-full" :class="busy(lease) ? 'bg-cyan' : 'bg-ok'" />
                                <span class="min-w-0">
                                    <span class="block text-xs font-medium whitespace-nowrap" :title="leaseName(lease)">{{ leaseName(lease) }}</span>
                                    <span v-if="leaseName(lease) !== lease.address" class="block font-mono text-[10px] whitespace-nowrap text-mute">{{ lease.address }}</span>
                                    <span v-if="busy(lease)" class="block font-mono text-[10px] whitespace-nowrap text-cyan">↓ {{ rate(lease.rx_bps) }}</span>
                                </span>
                            </article>
                        </li>
                        <li v-if="wired.length > shownDevices" class="leaf">
                            <article class="topo-chip justify-center text-xs text-mute">
                                +{{ wired.length - shownDevices }}
                            </article>
                        </li>
                    </ul>
                </template>

                <div v-if="unifi && unifi.configured === false" class="stem" />
                <Link
                    v-if="unifi && unifi.configured === false"
                    :href="sessionHref"
                    class="topo-node border-dashed hover:text-paper"
                >
                    <span class="topo-icon bg-tile text-mute">
                        <Icon class="text-[18px]" name="wifi" />
                    </span>
                    <span>
                        <span class="block font-medium">Access point belum tergambar</span>
                        <span class="text-[12px] text-cyan">Sambungkan API UniFi di sesi ini</span>
                    </span>
                </Link>
            </div>
        </div>
    </div>
</template>

<style scoped>
.topo-grid {
    background-image: radial-gradient(circle, var(--color-line) 1px, transparent 1px);
    background-size: 22px 22px;
    mask-image: radial-gradient(ellipse at center, black 40%, transparent 82%);
}

.topo-node {
    display: flex;
    width: max-content;
    max-width: 100%;
    align-items: center;
    gap: 0.75rem;
    border: 1px solid var(--color-edge);
    background: color-mix(in srgb, var(--color-panel) 88%, transparent);
    border-radius: 1rem;
    padding: 0.7rem 0.9rem;
}

.topo-router {
    border-color: color-mix(in srgb, var(--color-cyan) 45%, var(--color-edge));
    background: color-mix(in srgb, var(--color-cyan) 10%, var(--color-panel));
    box-shadow: 0 0 0 4px color-mix(in srgb, var(--color-cyan) 8%, transparent);
}

.topo-icon {
    display: flex;
    width: 2.25rem;
    height: 2.25rem;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    border-radius: 0.7rem;
}

.topo-kicker {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--color-mute);
}

.map {
    --wire: color-mix(in srgb, var(--color-cyan) 72%, transparent);
}

.stem {
    width: 2px;
    height: 1.75rem;
    margin-bottom: -2px;
    flex-shrink: 0;
    background: var(--wire);
}

.topo-port,
.topo-chip {
    width: max-content;
    max-width: 100%;
    border: 1px solid var(--color-edge);
    background: color-mix(in srgb, var(--color-tile) 90%, transparent);
    border-radius: 1rem;
}

.topo-port {
    position: relative;
    padding: 0.7rem 0.85rem 1.15rem;
}

.meter {
    position: absolute;
    right: 0.85rem;
    bottom: 0.6rem;
    left: 0.85rem;
}

.topo-port.is-down {
    opacity: 0.55;
}

.topo-chip {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.5rem 0.7rem;
}

.branch {
    display: flex;
    width: max-content;
    max-width: 100%;
    margin: 0;
    padding: 0;
    list-style: none;
}

.leaf {
    position: relative;
    display: flex;
    flex: 0 0 auto;
    flex-direction: column;
    align-items: center;
    padding: 1.65rem 0.85rem 0;
    list-style: none;
}

.leaf::before,
.leaf::after {
    content: '';
    position: absolute;
    top: 0;
    box-sizing: border-box;
    width: calc(50% + 1px);
    height: 1.8rem;
    border-top: 2px solid var(--wire);
}

.leaf::before {
    right: 50%;
}

.leaf::after {
    left: calc(50% - 1px);
    border-left: 2px solid var(--wire);
}

.leaf:first-child::before {
    border-top-color: transparent;
}

.leaf:last-child::after {
    border-top-color: transparent;
    border-left-color: transparent;
}

.leaf:last-child::before {
    border-right: 2px solid var(--wire);
    border-top-right-radius: 16px;
}

.leaf:first-child::after {
    border-top-left-radius: 16px;
}

.leaf:only-child {
    padding-top: 0;
}

.leaf:only-child::before,
.leaf:only-child::after {
    display: none;
}
</style>
