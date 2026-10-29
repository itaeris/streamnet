<script setup>
import { computed, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import MenuPanel from '../Components/MenuPanel.vue';
import PageSkeleton from '../Components/PageSkeleton.vue';
import PreviewDrawer from '../Components/PreviewDrawer.vue';
import TimeBar from '../Components/TimeBar.vue';
import { barePath, boardPaths, boardQuery, live, pinned, rangeMinutes, refreshId, refreshSeconds, syncBoardFromUrl, useBoardTime, withSite } from '../composables/useBoardTime';
import { usePreview } from '../composables/usePreview';
import { pulseKey, usePulse } from '../composables/usePulse';
import { useTheme } from '../composables/useTheme';
import { formatUptime } from '../lib/format';

const page = usePage();
const { theme, toggleTheme } = useTheme();
const current = computed(() => barePath(page.url, page.props.currentSessionSlug || ''));
const main = ref(null);
const collapsed = ref(typeof window !== 'undefined' && localStorage.getItem('sidebar') === 'collapsed');
const profileOpen = ref(false);
const profileRoot = ref(null);
const routerOpen = ref(false);
const routerRoot = ref(null);
const sessions = computed(() => page.props.sessions ?? []);
const currentSession = computed(() => sessions.value.find((item) => item.id === page.props.currentSessionId) ?? null);

function sessionLabel(item) {
    if (!item) {
        return pulse.value?.system?.identity || 'MikroTik';
    }

    const named = item.name && item.name !== item.host;
    if (named) {
        return item.name;
    }

    return (item.id === page.props.currentSessionId && pulse.value?.system?.identity) || item.name || item.host || 'MikroTik';
}
const initials = computed(() => {
    const parts = String(page.props.auth.user?.name || '').trim().split(/\s+/).filter(Boolean);
    const letters = parts.slice(0, 2).map((part) => part[0] || '').join('');

    return (letters || '?').toUpperCase();
});

function toggleSidebar() {
    collapsed.value = !collapsed.value;
    profileOpen.value = false;
    routerOpen.value = false;
    localStorage.setItem('sidebar', collapsed.value ? 'collapsed' : 'open');
}

function closeMenus(event) {
    if (!profileRoot.value?.contains(event.target)) {
        profileOpen.value = false;
    }

    if (!routerRoot.value?.contains(event.target)) {
        routerOpen.value = false;
    }
}

const links = [
    { href: '/', label: 'Network Overview', icon: 'dashboard' },
    { href: '/bandwidth', label: 'Interfaces & Traffic', icon: 'swap_horiz' },
    { href: '/dhcp', label: 'Connected Devices', icon: 'devices' },
    { href: '/dns', label: 'DNS & Apps', icon: 'language' },
    { href: '/access-points', label: 'Access Points', icon: 'wifi' },
    { href: '/topology', label: 'Topology Map', icon: 'lan' },
    { href: '/bandwidth-policy', label: 'Bandwidth Policy', icon: 'gauge' },
    { href: '/security', label: 'Firewall & Security', icon: 'shield' },
    { href: '/logs', label: 'Log Stream', icon: 'receipt_long' },
];

const titles = {
    '/': 'NOC Central',
    '/bandwidth': 'Interfaces',
    '/dhcp': 'Devices',
    '/dns': 'DNS & Apps',
    '/access-points': 'Access Points',
    '/topology': 'Topology',
    '/bandwidth-policy': 'Bandwidth Policy',
    '/security': 'Firewall',
    '/logs': 'Log Stream',
    '/pengaturan': 'Pengaturan',
    '/pengaturan/sesi': 'Pengaturan',
    '/pengaturan/unifi': 'Pengaturan',
    '/pengaturan/pengguna': 'Pengaturan',
};

const { pulse, connected } = usePulse(() => page.props.pulse ?? null);
provide(pulseKey, pulse);
const { close: closePreview } = usePreview();
const { apply } = useBoardTime();
const navigating = ref(false);
const skeletonKind = ref('table');
let refreshTimer = 0;

function skeletonFor(path) {
    path = barePath(path, page.props.currentSessionSlug || '');

    if (path === '/') {
        return 'overview';
    }

    if (path === '/bandwidth') {
        return 'chart';
    }

    if (path === '/topology') {
        return 'overview';
    }

    if (path.startsWith('/pengaturan')) {
        return 'settings';
    }

    return 'table';
}

function navigationTarget(visit) {
    if (!visit || visit.showProgress === false) {
        return '';
    }

    if (Array.isArray(visit.only) && visit.only.length > 0) {
        return '';
    }

    if (String(visit.method || 'get').toLowerCase() !== 'get') {
        return '';
    }

    const next = new URL(visit.url, window.location.origin).pathname;
    const here = window.location.pathname;

    if (next === here && visit.preserveState) {
        return '';
    }

    return next;
}

function hrefFor(path) {
    const href = withSite(path, page.props.currentSessionSlug || '');
    if (!boardPaths.includes(path)) {
        return href;
    }

    const query = new URLSearchParams(boardQuery());

    return `${href}?${query}`;
}

function windowMissing(url) {
    const path = barePath(url, page.props.currentSessionSlug || '');
    if (!boardPaths.includes(path)) {
        return false;
    }

    const params = new URLSearchParams(String(url).split('?')[1] || '');
    if (live.value) {
        return params.get('live') !== '1';
    }

    if (params.get('live') === '1' || params.has('minutes') || params.has('from') || params.has('to')) {
        return params.get('live') === '1';
    }

    return rangeMinutes.value !== 30 || Boolean(pinned.value);
}

function armRefresh() {
    window.clearInterval(refreshTimer);
    const seconds = refreshSeconds();
    if (!seconds) {
        return;
    }

    refreshTimer = window.setInterval(() => {
        router.reload({ async: true, preserveState: true, preserveScroll: true, showProgress: false });
    }, seconds * 1000);
}

watch(current, () => {
    closePreview();
    profileOpen.value = false;
    routerOpen.value = false;
    main.value?.scrollTo(0, 0);
});

watch(() => page.url, (url) => {
    syncBoardFromUrl(url);
    if (windowMissing(url)) {
        apply();
    }
});

watch([refreshId, live], armRefresh);

syncBoardFromUrl(page.url);
if (windowMissing(page.url)) {
    apply();
}

function beginNavigation(event) {
    const path = navigationTarget(event.detail?.visit);

    if (!path) {
        return;
    }

    skeletonKind.value = skeletonFor(path);
    navigating.value = true;
}

function endNavigation() {
    navigating.value = false;
}

onMounted(() => {
    document.addEventListener('pointerdown', closeMenus);
    armRefresh();
});
const stopNavigationStart = router.on('start', beginNavigation);
const stopNavigationFinish = router.on('finish', endNavigation);
onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', closeMenus);
    window.clearInterval(refreshTimer);
    stopNavigationStart();
    stopNavigationFinish();
    endNavigation();
});

const liveLabel = computed(() => {
    if (!pulse.value) {
        return 'WAIT';
    }

    if (!pulse.value.online) {
        return 'DOWN';
    }

    return connected.value ? 'LIVE' : 'POLL';
});

const liveClass = computed(() => {
    if (!pulse.value?.online) {
        return 'text-danger';
    }

    return connected.value ? 'text-ok' : 'text-warn';
});

const dotClass = computed(() => {
    if (!pulse.value?.online) {
        return 'bg-danger';
    }

    return connected.value ? 'bg-ok' : 'bg-warn';
});
</script>

<template>
    <div class="flex h-dvh flex-col gap-2.5 overflow-hidden bg-canvas p-3 text-paper">
        <header class="z-30 shrink-0">
            <div class="flex items-center justify-between gap-3 px-1">
                <div class="flex min-w-0 items-center gap-3">
                    <Link href="/sesi" class="flex size-9 items-center justify-center rounded-lg text-mute hover:bg-tile hover:text-paper" v-tip="'Semua sesi'">
                        <Icon class="text-[20px]" name="sites" />
                    </Link>
                    <button type="button" class="hidden size-9 items-center justify-center rounded-lg text-mute hover:bg-tile hover:text-paper lg:flex" v-tip:right="collapsed ? 'Buka sidebar' : 'Tutup sidebar'" @click="toggleSidebar">
                        <Icon class="text-[20px]" :name="collapsed ? 'left_panel_open' : 'left_panel_close'" />
                    </button>
                    <div class="flex items-center gap-2">
                        <div class="flex size-8 shrink-0 items-center justify-center rounded-md bg-tile text-cyan">
                            <Icon class="text-[20px]" name="hub" />
                        </div>
                        <div class="leading-none">
                            <p class="text-[15px] font-semibold tracking-tight">StreamNet</p>
                            <p class="mt-0.5 hidden text-[11px] text-mist sm:block">MikroTik Gateway Monitor</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5 rounded-full bg-tile px-2 py-0.5">
                        <span class="size-2 rounded-full" :class="[dotClass, pulse?.online && connected ? 'animate-pulse' : '']" />
                        <span class="font-mono text-[11px]" :class="liveClass">{{ liveLabel }}</span>
                    </div>
                    <span class="hidden font-mono text-xs text-cyan sm:inline">{{ titles[current] || 'NOC' }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <TimeBar />
                    <button
                        type="button"
                        class="flex size-9 items-center justify-center rounded-full bg-well text-mist hover:text-paper"
                        v-tip="theme === 'light' ? 'Mode gelap' : 'Mode terang'"
                        @click="toggleTheme"
                    >
                        <Icon class="text-[18px]" :name="theme === 'light' ? 'dark_mode' : 'light_mode'" />
                    </button>
                    <span class="hidden font-mono text-[11px] text-mist xl:inline">{{ pulse?.sampled_label || '–' }}</span>
                    <Link :href="hrefFor('/pengaturan')" class="p-1 text-mute lg:hidden" v-tip="'Pengaturan'">
                        <Icon class="text-[18px]" name="settings" />
                    </Link>
                    <Link href="/logout" method="post" as="button" class="p-1 text-mute lg:hidden" v-tip="'Keluar'">
                        <Icon class="text-[18px]" name="logout" />
                    </Link>
                </div>
            </div>
            <nav class="flex gap-1 overflow-x-auto px-3 pb-2 lg:hidden">
                <Link
                    v-for="link in links"
                    :key="link.href"
                    :href="hrefFor(link.href)"
                    class="shrink-0 rounded-md px-2.5 py-1.5 text-xs"
                    :class="current === link.href ? 'bg-cyan-2 font-semibold text-[#003640]' : 'bg-tile text-mist'"
                >
                    {{ link.label }}
                </Link>
            </nav>
        </header>
        <div class="flex min-h-0 min-w-0 flex-1">
        <div class="shell-panel flex min-h-0 min-w-0 flex-1 overflow-hidden">
        <aside class="z-40 hidden h-full shrink-0 flex-col border-r border-edge transition-[width] duration-200 lg:flex" :class="collapsed ? 'w-16' : 'w-72'">
            <div class="px-3 pt-3">
                <div ref="routerRoot" class="relative rounded-lg bg-well p-3" :class="collapsed ? 'flex justify-center p-2' : ''">
                    <template v-if="collapsed">
                        <button type="button" class="text-cyan" v-tip:right="sessionLabel(currentSession)" @click="routerOpen = !routerOpen">
                            <Icon class="text-[22px]" name="router" />
                        </button>
                        <MenuPanel :open="routerOpen" class="absolute top-0 left-full z-50 ml-2 w-56 origin-top-left rounded-lg bg-menu p-2 shadow-md ring-1 ring-edge">
                            <div class="flex flex-col gap-1">
                                <Link
                                    v-for="item in sessions"
                                    :key="item.id"
                                    :href="`/pengaturan/sesi/${item.id}/aktif`"
                                    method="post"
                                    as="button"
                                    class="rounded-md px-2 py-1.5 text-left"
                                    :class="item.id === page.props.currentSessionId ? 'bg-cyan-2 font-semibold text-[#003640]' : 'text-mist hover:bg-tile'"
                                >
                                    <span class="block truncate text-xs">{{ sessionLabel(item) }}</span>
                                    <span class="block truncate font-mono text-[10px] opacity-80">{{ item.host }}</span>
                                </Link>
                            </div>
                        </MenuPanel>
                    </template>
                    <template v-else>
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Active router</p>
                        <button type="button" class="text-mute" v-tip="'Ganti router'" @click="routerOpen = !routerOpen">
                            <Icon class="text-[18px] transition-transform duration-200" :class="routerOpen ? 'rotate-180' : ''" name="expand_more" />
                        </button>
                    </div>
                    <p class="mt-1 truncate text-[15px] font-semibold">{{ sessionLabel(currentSession) }}</p>
                    <p v-if="currentSession" class="truncate font-mono text-[11px] text-cyan">{{ currentSession.host }}</p>
                    <MenuPanel :open="routerOpen" inline>
                    <div class="mt-2 flex flex-col gap-1 border-t border-edge pt-2">
                        <Link
                            v-for="item in sessions"
                            :key="item.id"
                            :href="`/pengaturan/sesi/${item.id}/aktif`"
                            method="post"
                            as="button"
                            class="rounded-md px-2 py-1.5 text-left"
                            :class="item.id === page.props.currentSessionId ? 'bg-cyan-2 font-semibold text-[#003640]' : 'text-mist hover:bg-tile'"
                        >
                            <span class="block truncate text-xs">{{ sessionLabel(item) }}</span>
                            <span class="block truncate font-mono text-[10px] opacity-80">{{ item.host }}</span>
                        </Link>
                    </div>
                    </MenuPanel>
                    <div class="mt-2 flex items-center justify-between gap-1.5 font-mono text-[11px] whitespace-nowrap text-mist">
                        <span class="inline-flex min-w-0 items-center gap-1"><Icon class="shrink-0 text-[14px] text-cyan" name="schedule" /><span class="truncate">{{ formatUptime(pulse?.system?.uptime) }}</span></span>
                        <span class="inline-flex shrink-0 items-center gap-1"><Icon class="text-[14px] text-ok" name="memory" />{{ pulse?.system?.cpu_load ?? '–' }}%</span>
                        <span class="inline-flex shrink-0 items-center gap-1"><Icon class="text-[14px] text-warn" name="thermostat" />{{ pulse?.system?.temperature ?? '–' }}°C</span>
                    </div>
                    </template>
                </div>
            </div>

            <div class="mt-5 px-3">
                <p v-if="!collapsed" class="px-2 text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Telemetry navigation</p>
                <nav class="mt-2 flex flex-col gap-1">
                    <Link
                        v-for="link in links"
                        :key="link.href"
                        :href="hrefFor(link.href)"
                        class="flex items-center gap-3 rounded-lg py-2.5 text-sm transition"
                        :class="[collapsed ? 'justify-center px-0' : 'px-3', current === link.href ? 'bg-cyan-2 font-semibold text-[#003640]' : 'text-mist hover:bg-tile hover:text-paper']"
                        v-tip:right="collapsed ? link.label : ''"
                    >
                        <Icon class="text-[20px]" :name="link.icon" />
                        <span v-if="!collapsed">{{ link.label }}</span>
                    </Link>
                </nav>
            </div>

            <div class="mt-auto flex flex-col gap-2 p-3">
                <div v-if="!collapsed" class="flex items-center justify-between px-2 py-1">
                    <span class="font-mono text-[11px] text-mute">RouterOS</span>
                    <span class="rounded bg-tile px-1.5 py-0.5 font-mono text-[11px] text-cyan">{{ pulse?.system?.version || '–' }}</span>
                </div>
                <div ref="profileRoot" class="relative rounded-3xl bg-tile" :class="collapsed ? '' : 'overflow-hidden'">
                    <button type="button" class="flex w-full items-center gap-2 text-left" :class="collapsed ? 'justify-center px-1 py-2' : 'px-2.5 py-2'" v-tip:right="collapsed ? page.props.auth.user?.name : ''" @click="profileOpen = !profileOpen">
                        <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-cyan-2 text-[11px] font-semibold text-[#003640]">
                            {{ initials }}
                        </div>
                        <template v-if="!collapsed">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-xs font-semibold">{{ page.props.auth.user?.name }}</p>
                                <p class="truncate font-mono text-[11px] text-mute">{{ page.props.auth.user?.email }}</p>
                            </div>
                            <Icon class="shrink-0 text-[18px] text-mute transition-transform duration-200" :class="profileOpen ? 'rotate-180' : ''" name="expand_more" />
                        </template>
                    </button>
                    <MenuPanel :open="profileOpen" :inline="!collapsed" :class="collapsed ? 'absolute bottom-0 left-full z-50 mb-0 ml-2 w-52 origin-bottom-left rounded-lg bg-menu shadow-md ring-1 ring-edge' : ''">
                        <div :class="collapsed ? '' : 'border-t border-edge'">
                            <Link
                                :href="hrefFor('/pengaturan')"
                                class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-hover"
                                :class="current.startsWith('/pengaturan') ? 'font-semibold text-paper' : ''"
                            >
                                <Icon class="text-[18px]" name="settings" />
                                Pengaturan
                            </Link>
                            <Link href="/logout" method="post" as="button" class="block w-full px-3 py-2.5 text-left text-sm hover:bg-hover">
                                Keluar
                            </Link>
                        </div>
                    </MenuPanel>
                </div>
            </div>
        </aside>

            <div class="flex min-w-0 flex-1 flex-col">
            <main ref="main" class="flex min-h-0 flex-1 flex-col overflow-y-auto p-3">
                <div v-if="pulse && pulse.online === false" class="noc-card mb-3 border-danger/40 px-3 py-2 text-sm text-danger">
                    Router tidak terjangkau. {{ pulse.error }}
                </div>
                <div v-else-if="!pulse" class="noc-card mb-3 border-warn/40 px-3 py-2 text-sm text-warn">
                    Belum ada sampel. Pemantau MikroTik belum mengirim data.
                </div>
                <div class="flex min-h-0 flex-1 flex-col">
                    <PageSkeleton v-if="navigating" :kind="skeletonKind" />
                    <slot v-else />
                </div>
            </main>
            <footer class="flex shrink-0 items-center justify-between gap-3 border-t border-edge px-4 py-2.5 font-mono text-[11px] text-mist">
                <span class="font-sans text-xs font-semibold text-paper">StreamNet</span>
                <span class="min-w-0 truncate">{{ sessionLabel(currentSession) }}</span>
                <span class="shrink-0 text-mute">{{ pulse?.sampled_label || '–' }}</span>
            </footer>
            </div>
            <PreviewDrawer />
        </div>
        </div>
    </div>
</template>
