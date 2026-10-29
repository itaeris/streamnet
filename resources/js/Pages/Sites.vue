<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { useTheme } from '../composables/useTheme';

defineProps({
    sessions: { type: Array, default: () => [] },
});

const { theme, toggleTheme } = useTheme();
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

        <main class="mx-auto flex w-full max-w-5xl flex-col gap-5 px-4 pb-12 sm:px-8">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold">Sesi</h1>
                    <p class="mt-1 text-sm text-mist">Masuk ke router yang ingin dipantau. API UniFi mengikuti sesi yang dipilih.</p>
                </div>
                <Link href="/sesi/baru" class="inline-flex h-10 items-center gap-2 rounded-full bg-tile px-4 text-sm font-semibold text-paper">
                    <Icon class="text-[18px]" name="add" />
                    Tambah sesi
                </Link>
            </div>

            <div v-if="sessions.length" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <article v-for="session in sessions" :key="session.id" class="noc-card p-4">
                    <Link
                        :href="`/pengaturan/sesi/${session.id}/aktif`"
                        method="post"
                        as="button"
                        class="block w-full text-left"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-[17px] font-semibold">{{ session.identity || session.name }}</p>
                                <p class="mt-1 flex items-center gap-1.5 text-sm text-mist">
                                    <span class="size-2 rounded-full" :class="session.online ? 'bg-ok' : 'bg-mute'" />
                                    {{ session.online ? 'MikroTik online' : (session.enabled ? 'Belum ada sampel' : 'Tidak dipantau') }}
                                </p>
                            </div>
                            <Icon class="shrink-0 text-[20px] text-mute" name="arrow_forward" />
                        </div>
                        <div class="mt-4 h-2 overflow-hidden rounded-full bg-tile">
                            <div class="h-full rounded-full" :class="session.online ? 'w-full bg-ok' : 'w-0'" />
                        </div>
                        <div class="mt-3 flex items-center justify-between gap-2 font-mono text-[11px] text-mute">
                            <span class="truncate">{{ session.host }}</span>
                            <span class="shrink-0">{{ session.sampled_label || '' }}</span>
                        </div>
                    </Link>
                    <div class="mt-3 flex items-center justify-between gap-2">
                        <p class="text-xs" :class="session.unifi ? 'text-ok' : 'text-mute'">
                            {{ session.unifi ? 'UniFi terhubung' : 'UniFi belum dihubungkan' }}
                        </p>
                        <Link :href="`/sesi/${encodeURIComponent(session.slug || session.name)}`" class="inline-flex items-center gap-1 text-xs font-semibold text-cyan">
                            <Icon class="text-[14px]" name="settings" />
                            Pengaturan
                        </Link>
                    </div>
                </article>
            </div>

            <article v-else class="noc-card px-6 py-14 text-center">
                <p class="text-[15px] font-semibold">Belum ada sesi</p>
                <p class="mt-2 text-sm text-mist">Tambahkan router MikroTik dulu, lalu masuk ke sesi itu.</p>
                <Link href="/sesi/baru" class="mt-5 inline-flex h-10 items-center rounded-full bg-cyan-2 px-4 text-sm font-semibold text-[#003640]">Tambah sesi</Link>
            </article>
        </main>
    </div>
</template>
