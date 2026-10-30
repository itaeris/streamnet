<script setup>
import { Head, Link } from '@inertiajs/vue3';
import TrafficChart from '../Components/TrafficChart.vue';
import { useTheme } from '../composables/useTheme';
import { formatRate } from '../lib/format';

defineProps({
    sessions: { type: Array, default: () => [] },
});

const { theme, toggleTheme } = useTheme();

function statusOf(session) {
    if (session.online) {
        return { label: 'Online', dot: 'bg-ok', text: 'text-ok', chip: 'bg-ok/10' };
    }

    if (session.enabled) {
        return { label: 'Menunggu', dot: 'bg-warn', text: 'text-warn', chip: 'bg-warn/10' };
    }

    return { label: 'Mati', dot: 'bg-mute', text: 'text-mute', chip: 'bg-tile' };
}
</script>

<template>
    <Head title="Sesi" />

    <div class="min-h-screen bg-canvas text-paper">
        <header class="flex items-center justify-between gap-3 px-4 py-4 sm:px-8">
            <div class="flex items-center gap-2.5">
                <div class="flex size-9 items-center justify-center rounded-xl bg-cyan-2 text-[#003640]">
                    <Icon class="text-[20px]" name="hub" />
                </div>
                <div class="leading-none">
                    <p class="text-[15px] font-semibold">StreamNet</p>
                    <p class="mt-1 text-[11px] text-mute">Pilih sesi</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="flex size-9 items-center justify-center rounded-full bg-well text-mist hover:text-paper"
                    v-tip="theme === 'light' ? 'Mode gelap' : 'Mode terang'"
                    @click="toggleTheme"
                >
                    <Icon class="text-[18px]" :name="theme === 'light' ? 'dark_mode' : 'light_mode'" />
                </button>
                <Link href="/logout" method="post" as="button" class="rounded-full bg-well px-3 py-2 text-sm text-mist hover:text-paper">Keluar</Link>
            </div>
        </header>

        <main class="mx-auto w-full max-w-xl px-4 pb-16 sm:px-6">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold">Sesi</h1>
                    <p class="mt-1 text-sm text-mist">Pilih router yang ingin dipantau.</p>
                </div>
                <Link href="/sesi/baru" class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full bg-tile px-3 text-sm font-semibold text-paper">
                    <Icon class="text-[16px]" name="add" />
                    Tambah
                </Link>
            </div>

            <div v-if="sessions.length" class="mt-5 space-y-3">
                <article v-for="session in sessions" :key="session.id" class="noc-card overflow-hidden">
                    <div class="flex items-start justify-between gap-3 px-4 pt-4">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-tile text-cyan">
                                <Icon class="text-[20px]" name="hub" />
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-[15px] font-semibold">{{ session.identity || session.name }}</p>
                                <p class="mt-0.5 truncate font-mono text-[11px] text-mute">
                                    {{ session.host }}<template v-if="session.board"> · {{ session.board }}</template>
                                </p>
                            </div>
                        </div>
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-1 text-[11px] font-semibold" :class="[statusOf(session).chip, statusOf(session).text]">
                            <span class="size-1.5 rounded-full" :class="statusOf(session).dot" />
                            {{ statusOf(session).label }}
                        </span>
                    </div>

                    <div class="px-4 pt-4">
                        <div class="flex items-center justify-between gap-3 font-mono text-[11px]">
                            <span class="text-mute">{{ session.series?.length ? 'WAN · 6 jam' : 'WAN' }}</span>
                            <span v-if="session.online" class="flex items-center gap-2">
                                <span class="text-cyan">↓ {{ formatRate(session.rx) }}</span>
                                <span class="text-ok">↑ {{ formatRate(session.tx) }}</span>
                            </span>
                            <span v-else class="text-mute">{{ session.sampled_label || 'belum ada sampel' }}</span>
                        </div>
                        <div class="mt-2 rounded-xl bg-sink px-2 py-2">
                            <TrafficChart :points="session.series || []" height-class="h-24" />
                        </div>
                    </div>

                    <div class="mt-4 flex items-center justify-between gap-3 border-t border-line px-4 py-3">
                        <p class="truncate text-xs" :class="session.unifi ? 'text-ok' : 'text-mute'">
                            {{ session.unifi ? 'UniFi terhubung' : 'UniFi belum dihubungkan' }}
                        </p>
                        <div class="flex shrink-0 items-center gap-2">
                            <Link :href="`/sesi/${encodeURIComponent(session.slug || session.name)}`" class="inline-flex h-8 items-center gap-1 rounded-full px-2.5 text-xs font-semibold text-mist hover:bg-hover hover:text-paper">
                                <Icon class="text-[14px]" name="settings" />
                                Pengaturan
                            </Link>
                            <Link
                                :href="`/pengaturan/sesi/${session.id}/aktif`"
                                method="post"
                                as="button"
                                class="inline-flex h-8 items-center gap-1 rounded-full bg-cyan-2 px-3 text-xs font-semibold text-[#003640]"
                            >
                                Masuk
                                <Icon class="text-[14px]" name="arrow_forward" />
                            </Link>
                        </div>
                    </div>
                </article>
            </div>

            <article v-else class="noc-card mt-5 px-6 py-14 text-center">
                <p class="text-[15px] font-semibold">Belum ada sesi</p>
                <p class="mt-2 text-sm text-mist">Tambahkan router MikroTik dulu, lalu masuk ke sesi itu.</p>
                <Link href="/sesi/baru" class="mt-5 inline-flex h-10 items-center rounded-full bg-cyan-2 px-4 text-sm font-semibold text-[#003640]">Tambah sesi</Link>
            </article>
        </main>
    </div>
</template>
