<script setup>
import { computed, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import MenuPanel from '../Components/MenuPanel.vue';
import PreviewDrawer from '../Components/PreviewDrawer.vue';
import TimeBar from '../Components/TimeBar.vue';
import { boardPaths, boardQuery, live, pinned, rangeMinutes, refreshId, refreshSeconds, syncBoardFromUrl, useBoardTime } from '../composables/useBoardTime';
import { usePreview } from '../composables/usePreview';
import { pulseKey, usePulse } from '../composables/usePulse';
import { useTheme } from '../composables/useTheme';
import { formatUptime } from '../lib/format';

const page = usePage();
const { theme, toggleTheme } = useTheme();
const current = computed(() => page.url.split('?')[0]);
const main = ref(null);
const profileOpen = ref(false);
const profileRoot = ref(null);
const routerOpen = ref(false);
const routerRoot = ref(null);
const sessions = computed(() => page.props.sessions ?? []);
const currentSession = computed(() => sessions.value.find((item) => item.id === page.props.currentSessionId) ?? null);
const initials = computed(() => {
    const parts = String(page.props.auth.user?.name || '').trim().split(/\s+/).filter(Boolean);
    const letters = parts.slice(0, 2).map((part) => part[0] || '').join('');

    return (letters || '?').toUpperCase();
});

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
    { href: '/security', label: 'Firewall & Security', icon: 'shield' },
    { href: '/logs', label: 'Log Stream', icon: 'receipt_long' },
];

const titles = {
    '/': 'NOC Central',
    '/bandwidth': 'Interfaces',
    '/dhcp': 'Devices',
    '/dns': 'DNS & Apps',
    '/security': 'Firewall',
    '/logs': 'Log Stream',
    '/pengaturan': 'Pengaturan',
};

const { pulse, connected } = usePulse(() => page.props.pulse ?? null);
provide(pulseKey, pulse);
const { close: closePreview } = usePreview();
const { apply } = useBoardTime();
let refreshTimer = 0;

function hrefFor(path) {
    if (!boardPaths.includes(path)) {
        return path;
    }

    const query = new URLSearchParams(boardQuery());

    return `${path}?${query}`;
}

function windowMissing(url) {
    const path = String(url).split('?')[0];
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

onMounted(() => {
    document.addEventListener('pointerdown', closeMenus);
    armRefresh();
});
onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', closeMenus);
    window.clearInterval(refreshTimer);
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
                    <div class="flex items-center gap-2">
                        <div class="flex size-8 shrink-0 items-center justify-center rounded-md bg-tile text-cyan">
                            <span class="material-symbols-outlined text-[20px]">hub</span>
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
                        :title="theme === 'light' ? 'Mode gelap' : 'Mode terang'"
                        @click="toggleTheme"
                    >
                        <span class="material-symbols-outlined text-[18px]">{{ theme === 'light' ? 'dark_mode' : 'light_mode' }}</span>
                    </button>
                    <span class="hidden font-mono text-[11px] text-mist xl:inline">{{ pulse?.sampled_label || '–' }}</span>
                    <Link :href="hrefFor('/pengaturan')" class="p-1 text-mute lg:hidden" title="Pengaturan">
                        <span class="material-symbols-outlined text-[18px]">settings</span>
                    </Link>
                    <Link href="/logout" method="post" as="button" class="p-1 text-mute lg:hidden" title="Keluar">
                        <span class="material-symbols-outlined text-[18px]">logout</span>
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
        <aside class="z-40 hidden h-full w-72 shrink-0 flex-col overflow-hidden border-r border-edge lg:flex">
            <div class="p-3">
                <div ref="routerRoot" class="rounded-lg bg-well p-3">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Active router</p>
                        <button type="button" class="text-mute" title="Ganti router" @click="routerOpen = !routerOpen">
                            <span class="material-symbols-outlined text-[18px] transition-transform duration-200" :class="routerOpen ? 'rotate-180' : ''">expand_more</span>
                        </button>
                    </div>
                    <p class="mt-1 truncate text-[15px] font-semibold">{{ currentSession?.name || pulse?.system?.board || 'MikroTik' }}</p>
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
                            <span class="block truncate text-xs">{{ item.name }}</span>
                            <span class="block truncate font-mono text-[10px] opacity-80">{{ item.host }}</span>
                        </Link>
                    </div>
                    </MenuPanel>
                    <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 font-mono text-[11px] text-mist">
                        <span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[14px] text-cyan">schedule</span>{{ formatUptime(pulse?.system?.uptime) }}</span>
                        <span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[14px] text-ok">memory</span>{{ pulse?.system?.cpu_load ?? '–' }}%</span>
                        <span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[14px] text-warn">thermostat</span>{{ pulse?.system?.temperature ?? '–' }}°C</span>
                    </div>
                </div>
            </div>

            <div class="px-3">
                <p class="px-2 text-[10px] font-semibold tracking-[0.14em] text-mute uppercase">Telemetry navigation</p>
                <nav class="mt-2 flex flex-col gap-1">
                    <Link
                        v-for="link in links"
                        :key="link.href"
                        :href="hrefFor(link.href)"
                        class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition"
                        :class="current === link.href ? 'bg-cyan-2 font-semibold text-[#003640]' : 'text-mist hover:bg-tile hover:text-paper'"
                    >
                        <span class="material-symbols-outlined text-[20px]">{{ link.icon }}</span>
                        {{ link.label }}
                    </Link>
                </nav>
            </div>

            <div class="mt-auto flex flex-col gap-2 p-3">
                <div class="flex items-center justify-between px-2 py-1">
                    <span class="font-mono text-[11px] text-mute">RouterOS</span>
                    <span class="rounded bg-tile px-1.5 py-0.5 font-mono text-[11px] text-cyan">{{ pulse?.system?.version || '–' }}</span>
                </div>
                <div ref="profileRoot" class="overflow-hidden rounded-3xl bg-tile">
                    <button type="button" class="flex w-full items-center gap-2 px-2.5 py-2 text-left" @click="profileOpen = !profileOpen">
                        <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-cyan-2 text-[11px] font-semibold text-[#003640]">
                            {{ initials }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-xs font-semibold">{{ page.props.auth.user?.name }}</p>
                            <p class="truncate font-mono text-[11px] text-mute">{{ page.props.auth.user?.email }}</p>
                        </div>
                        <span class="material-symbols-outlined shrink-0 text-[18px] text-mute transition-transform duration-200" :class="profileOpen ? 'rotate-180' : ''">expand_more</span>
                    </button>
                    <MenuPanel :open="profileOpen" inline>
                        <div class="border-t border-edge">
                            <Link
                                :href="hrefFor('/pengaturan')"
                                class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-hover"
                                :class="current === '/pengaturan' ? 'font-semibold text-paper' : ''"
                            >
                                <span class="material-symbols-outlined text-[18px]">settings</span>
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
            <main ref="main" class="min-h-0 flex-1 overflow-y-auto p-3">
                <div v-if="pulse && pulse.online === false" class="noc-card mb-3 border-danger/40 px-3 py-2 text-sm text-danger">
                    Router tidak terjangkau. {{ pulse.error }}
                </div>
                <div v-else-if="!pulse" class="noc-card mb-3 border-warn/40 px-3 py-2 text-sm text-warn">
                    Belum ada sampel. Pemantau MikroTik belum mengirim data.
                </div>
                <slot />
            </main>
            <footer class="flex shrink-0 items-center justify-between gap-3 border-t border-edge px-4 py-2.5 font-mono text-[11px] text-mist">
                <span class="font-sans text-xs font-semibold text-paper">StreamNet</span>
                <span class="min-w-0 truncate">{{ currentSession?.name || pulse?.system?.identity || 'MikroTik' }}</span>
                <span class="shrink-0 text-mute">{{ pulse?.sampled_label || '–' }}</span>
            </footer>
            </div>
            <PreviewDrawer />
        </div>
        </div>
    </div>
</template>
