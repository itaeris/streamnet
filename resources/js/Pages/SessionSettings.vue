<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useTheme } from '../composables/useTheme';
import ToggleSwitch from '../Components/ToggleSwitch.vue';

const props = defineProps({
    session: { type: Object, default: null },
    unifi: { type: Object, default: null },
    unifiStatus: { type: Object, default: null },
});

const { theme, toggleTheme } = useTheme();
const confirmDelete = ref(false);
const confirmDisconnect = ref(false);

const protocols = [
    { value: 'api', label: 'API' },
    { value: 'api-ssl', label: 'API-SSL' },
    { value: 'http', label: 'REST HTTP' },
    { value: 'https', label: 'REST HTTPS' },
];

const portHint = (scheme) => ({ api: '8728', 'api-ssl': '8729', https: '443', http: '80' }[scheme] || '8728');

function protocolOf(session) {
    if (session.scheme === 'api' || session.scheme === 'api-ssl') {
        return session.scheme;
    }

    if (Number(session.port) === 8729) {
        return 'api-ssl';
    }

    if (Number(session.port) === 8728 || session.transport === 'api') {
        return 'api';
    }

    return session.scheme || 'http';
}

const form = useForm({
    name: props.session?.name ?? '',
    host: props.session?.host ?? '',
    scheme: props.session ? protocolOf(props.session) : 'api',
    port: props.session?.port || '',
    username: props.session?.username ?? '',
    password: '',
    wan_interface: props.session?.wan_interface || '',
    enabled: props.session?.enabled ?? true,
});

const unifiForm = useForm({
    url: props.unifi?.url || '',
    api_key: '',
    site: props.unifi?.site || '',
    verify_tls: props.unifi?.verify_tls ?? true,
});

const fieldClass = 'mt-1.5 h-10 w-full rounded-xl bg-sink px-3 text-sm text-paper outline-none focus:ring-1 focus:ring-cyan-2';
const labelClass = 'block text-xs font-medium text-mist';

function sessionPath(suffix = '') {
    return `/sesi/${encodeURIComponent(props.session.slug || props.session.name)}${suffix}`;
}

function submit() {
    if (props.session) {
        form.put(sessionPath(), {
            preserveScroll: true,
            onSuccess: () => form.reset('password'),
        });

        return;
    }

    form.post('/sesi');
}

function submitUnifi() {
    unifiForm.put(sessionPath('/unifi'), {
        preserveScroll: true,
        onSuccess: () => unifiForm.reset('api_key'),
    });
}

function disconnectUnifi() {
    router.delete(sessionPath('/unifi'), {
        preserveScroll: true,
        onSuccess: () => {
            confirmDisconnect.value = false;
            unifiForm.url = '';
            unifiForm.site = '';
            unifiForm.api_key = '';
        },
    });
}

function destroySession() {
    router.delete(sessionPath());
}
</script>

<template>
    <Head :title="session ? session.name : 'Tambah sesi'" />

    <div class="min-h-screen bg-canvas text-paper">
        <header class="flex items-center justify-between gap-3 px-4 py-4 sm:px-8">
            <Link href="/sesi" class="flex items-center gap-2.5">
                <div class="flex size-9 items-center justify-center rounded-xl bg-cyan-2 text-[#003640]">
                    <Icon class="text-[20px]" name="hub" />
                </div>
                <div class="leading-none">
                    <p class="text-[15px] font-semibold">StreamNet</p>
                    <p class="mt-1 text-[11px] text-mute">Semua sesi</p>
                </div>
            </Link>
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

        <main class="mx-auto w-full max-w-6xl px-4 pb-16 sm:px-8">
            <div class="mb-5 flex flex-wrap items-center justify-between gap-4">
                <div class="flex min-w-0 items-center gap-3">
                    <Link href="/sesi" class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-well text-mist hover:text-paper" v-tip="'Semua sesi'">
                        <Icon class="text-[20px]" name="chevron_left" />
                    </Link>
                    <div class="min-w-0">
                        <h1 class="truncate text-2xl font-semibold">{{ session ? session.name : 'Tambah sesi' }}</h1>
                        <p class="mt-0.5 truncate text-sm text-mist">
                            {{ session ? (session.identity || session.host) : 'Router baru muncul di daftar sesi setelah disimpan.' }}
                        </p>
                    </div>
                </div>
                <Link
                    v-if="session"
                    :href="`/pengaturan/sesi/${session.id}/aktif`"
                    method="post"
                    as="button"
                    class="inline-flex h-10 items-center gap-2 rounded-full bg-cyan-2 px-4 text-sm font-semibold text-[#003640]"
                >
                    <Icon class="text-[18px]" name="arrow_forward" />
                    Masuk
                </Link>
            </div>

            <div class="grid items-start gap-4" :class="session ? 'xl:grid-cols-2' : 'mx-auto max-w-3xl'">
                <article class="noc-card p-5">
                    <div class="flex items-start gap-3">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-tile text-cyan">
                            <Icon class="text-[20px]" name="router" />
                        </span>
                        <div>
                            <p class="text-[15px] font-semibold">MikroTik</p>
                            <p class="mt-0.5 text-sm text-mist">API port 8728, API-SSL 8729. REST hanya kalau service www terbuka.</p>
                        </div>
                    </div>
                    <form class="mt-5 grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                        <label :class="labelClass">
                            Nama
                            <input v-model="form.name" required maxlength="80" :class="fieldClass">
                        </label>
                        <label :class="labelClass">
                            Host
                            <input v-model="form.host" required placeholder="10.10.10.1" :class="fieldClass">
                        </label>
                        <label :class="labelClass">
                            Protokol
                            <select v-model="form.scheme" :class="fieldClass">
                                <option v-for="item in protocols" :key="item.value" :value="item.value">{{ item.label }}</option>
                            </select>
                        </label>
                        <label :class="labelClass">
                            Port
                            <input v-model="form.port" inputmode="numeric" :placeholder="portHint(form.scheme)" :class="fieldClass">
                        </label>
                        <label :class="labelClass">
                            Username
                            <input v-model="form.username" required autocomplete="off" :class="fieldClass">
                        </label>
                        <label :class="labelClass">
                            Password
                            <input v-model="form.password" type="password" :required="!session" autocomplete="new-password" :placeholder="session ? 'Kosongkan untuk tetap' : ''" :class="fieldClass">
                        </label>
                        <label class="sm:col-span-2" :class="labelClass">
                            Interface WAN
                            <input v-model="form.wan_interface" placeholder="ether1" :class="fieldClass">
                        </label>
                        <ToggleSwitch v-model="form.enabled" class="sm:col-span-2" label="Ikut dipantau" hint="Poller mengambil data router ini." />
                        <p v-if="form.hasErrors" class="text-sm text-danger sm:col-span-2">{{ Object.values(form.errors)[0] }}</p>
                        <p v-else-if="form.recentlySuccessful" class="text-sm text-ok sm:col-span-2">Tersimpan.</p>
                        <div class="sm:col-span-2">
                            <button type="submit" class="h-10 rounded-full bg-cyan-2 px-4 text-sm font-semibold text-[#003640]" :disabled="form.processing">
                                {{ session ? 'Simpan' : 'Tambah sesi' }}
                            </button>
                        </div>
                    </form>
                </article>

                <article v-if="session" class="noc-card p-5">
                    <div class="flex items-start gap-3">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl" :class="unifiStatus?.connected ? 'bg-ok/10 text-ok' : 'bg-tile text-mute'">
                            <Icon class="text-[20px]" name="wifi" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[15px] font-semibold">{{ unifiStatus?.connected ? 'UniFi terhubung' : 'UniFi' }}</p>
                            <p v-if="unifiStatus?.connected" class="mt-0.5 text-sm text-mist">
                                Site {{ unifiStatus.site }} · {{ unifiStatus.access_points }} access point · {{ unifiStatus.clients }} klien
                            </p>
                            <p v-else-if="unifiStatus?.configured" class="mt-0.5 text-sm text-mist">Console belum bisa dihubungi. {{ unifiStatus.reason }}</p>
                            <p v-else class="mt-0.5 text-sm text-mist">API key ini hanya untuk sesi {{ session.name }}.</p>
                        </div>
                    </div>
                    <form class="mt-5 grid gap-4 sm:grid-cols-2" @submit.prevent="submitUnifi">
                        <label class="sm:col-span-2" :class="labelClass">
                            Alamat console
                            <input v-model="unifiForm.url" required placeholder="https://unifi.contoh.id" :class="fieldClass">
                        </label>
                        <label :class="labelClass">
                            API key
                            <input v-model="unifiForm.api_key" type="password" autocomplete="new-password" :required="!unifi?.has_key" :placeholder="unifi?.has_key ? 'Kosongkan untuk tetap' : ''" :class="fieldClass">
                        </label>
                        <label :class="labelClass">
                            Site
                            <input v-model="unifiForm.site" placeholder="Kosongkan untuk site pertama" :class="fieldClass">
                        </label>
                        <ToggleSwitch v-model="unifiForm.verify_tls" class="sm:col-span-2" label="Verifikasi sertifikat TLS" hint="Matikan kalau console memakai sertifikat self-signed atau diakses lewat IP." />
                        <p v-if="unifiForm.hasErrors" class="text-sm text-danger sm:col-span-2">{{ Object.values(unifiForm.errors)[0] }}</p>
                        <p v-else-if="unifiForm.recentlySuccessful" class="text-sm text-ok sm:col-span-2">Tersimpan.</p>
                        <div class="flex flex-wrap items-center gap-2 sm:col-span-2">
                            <button type="submit" class="h-10 rounded-full bg-cyan-2 px-4 text-sm font-semibold text-[#003640]" :disabled="unifiForm.processing">{{ unifi?.has_key ? 'Simpan UniFi' : 'Hubungkan' }}</button>
                            <template v-if="unifi?.has_key">
                                <button v-if="!confirmDisconnect" type="button" class="h-10 rounded-full bg-tile px-4 text-sm text-danger" @click="confirmDisconnect = true">Putuskan</button>
                                <template v-else>
                                    <button type="button" class="h-10 rounded-full bg-danger/15 px-4 text-sm text-danger" @click="disconnectUnifi">Ya, putuskan</button>
                                    <button type="button" class="h-10 rounded-full bg-tile px-4 text-sm text-mist" @click="confirmDisconnect = false">Batal</button>
                                </template>
                            </template>
                        </div>
                    </form>
                </article>
            </div>

            <div v-if="session" class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-danger/25 px-4 py-3">
                <div>
                    <p class="text-sm font-semibold">Hapus sesi</p>
                    <p class="text-sm text-mist">Sampel, koneksi UniFi, dan riwayat sesi ini ikut terhapus.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button v-if="!confirmDelete" type="button" class="h-10 rounded-full bg-tile px-4 text-sm text-danger" @click="confirmDelete = true">Hapus sesi</button>
                    <template v-else>
                        <button type="button" class="h-10 rounded-full bg-danger/15 px-4 text-sm text-danger" @click="destroySession">Ya, hapus</button>
                        <button type="button" class="h-10 rounded-full bg-tile px-4 text-sm text-mist" @click="confirmDelete = false">Batal</button>
                    </template>
                </div>
            </div>
        </main>
    </div>
</template>
