<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import ToggleSwitch from '../Components/ToggleSwitch.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    section: { type: String, default: 'app' },
    sessions: { type: Array, default: () => [] },
    currentSessionId: { type: Number, default: null },
    unifi: { type: Object, default: null },
    unifiStatus: { type: Object, default: null },
});

const unifiForm = useForm({
    url: props.unifi?.url || '',
    api_key: '',
    site: props.unifi?.site || '',
    verify_tls: props.unifi?.verify_tls ?? true,
});
const confirmDisconnect = ref(false);

function submitUnifi() {
    unifiForm.put('/pengaturan/unifi', {
        preserveScroll: true,
        onSuccess: () => unifiForm.reset('api_key'),
    });
}

function disconnectUnifi() {
    router.delete('/pengaturan/unifi', {
        preserveScroll: true,
        onSuccess: () => {
            confirmDisconnect.value = false;
            unifiForm.url = '';
            unifiForm.site = '';
            unifiForm.api_key = '';
        },
    });
}

const page = usePage();
const editing = ref(null);
const pendingDelete = ref(null);

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

const createForm = useForm({
    name: '',
    host: '',
    scheme: 'api',
    port: '',
    username: '',
    password: '',
    wan_interface: '',
    enabled: true,
});

const editForm = useForm({
    name: '',
    host: '',
    scheme: 'http',
    port: '',
    username: '',
    password: '',
    wan_interface: '',
    enabled: true,
});

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const fieldClass = 'mt-1 w-full rounded-full bg-sink px-3 py-2 text-sm outline-none focus:ring-1 focus:ring-cyan-2';

function submitPassword() {
    passwordForm.put('/pengaturan/password', {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
}

function submitCreate() {
    createForm.post('/pengaturan/sesi', {
        preserveScroll: true,
        onSuccess: () => createForm.reset(),
    });
}

function startEdit(session) {
    pendingDelete.value = null;
    editing.value = session.id;
    editForm.clearErrors();
    editForm.name = session.name;
    editForm.host = session.host;
    editForm.scheme = protocolOf(session);
    editForm.port = session.port || '';
    editForm.username = session.username;
    editForm.password = '';
    editForm.wan_interface = session.wan_interface || '';
    editForm.enabled = session.enabled;
}

function submitEdit(id) {
    editForm.put(`/pengaturan/sesi/${id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
            editForm.reset('password');
        },
    });
}

function endpoint(session) {
    if (session.transport === 'api' || session.scheme === 'api' || session.scheme === 'api-ssl') {
        const tls = session.scheme === 'api-ssl' || Number(session.port) === 8729;
        const port = session.port || (tls ? 8729 : 8728);

        return `${tls ? 'api-ssl' : 'api'}://${session.host}:${port}`;
    }

    const port = session.port ? `:${session.port}` : '';

    return `${session.scheme}://${session.host}${port}`;
}
</script>

<template>
    <Head :title="section === 'sessions' ? 'Sesi MikroTik' : section === 'unifi' ? 'UniFi' : 'Aplikasi'" />

        <nav class="mb-4 inline-flex max-w-full overflow-x-auto rounded-xl bg-well p-1" role="tablist">
            <Link
                v-for="item in [{ id: 'app', href: '/pengaturan', label: 'Aplikasi' }, { id: 'sessions', href: '/pengaturan/sesi', label: 'Sesi MikroTik' }, { id: 'unifi', href: '/pengaturan/unifi', label: 'UniFi' }]"
                :key="item.id"
                :href="item.href"
                role="tab"
                :aria-selected="section === item.id"
                class="flex h-8 shrink-0 items-center rounded-lg px-3.5 text-[12px] whitespace-nowrap transition-colors duration-150"
                :class="section === item.id ? 'bg-panel font-semibold text-cyan shadow-sm ring-1 ring-edge' : 'text-mute hover:bg-hover hover:text-paper'"
            >
                {{ item.label }}
            </Link>
        </nav>

        <template v-if="section === 'sessions'">
        <section class="mb-3">
            <h2 class="text-[15px] font-semibold">Sesi MikroTik</h2>
            <p class="mt-1 max-w-2xl text-sm text-mist">Tambah router lain di sini. API MikroTik memakai port 8728. REST hanya kalau service www memang dibuka, biasanya port 80.</p>
        </section>

        <section class="grid gap-3 xl:grid-cols-2">
            <article v-for="session in sessions" :key="session.id" class="noc-card p-3">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="truncate text-[15px] font-semibold">{{ session.name }}</p>
                            <span
                                class="rounded px-1.5 py-0.5 font-mono text-[11px]"
                                :class="session.online ? 'bg-ok/10 text-ok' : 'bg-tile text-mute'"
                            >{{ session.online ? 'ONLINE' : 'WAIT' }}</span>
                            <span v-if="session.id === currentSessionId" class="rounded bg-cyan/10 px-1.5 py-0.5 font-mono text-[11px] text-cyan">DIPANTAU</span>
                            <span v-if="!session.enabled" class="rounded bg-tile px-1.5 py-0.5 font-mono text-[11px] text-mute">NONAKTIF</span>
                        </div>
                        <p class="mt-1 truncate font-mono text-[12px] text-cyan">{{ endpoint(session) }}</p>
                        <p class="mt-1 font-mono text-[11px] text-mute">{{ session.username }} · WAN {{ session.wan_interface || 'otomatis' }}</p>
                        <p v-if="session.identity" class="mt-1 text-sm text-mist">{{ session.identity }}</p>
                        <p v-if="session.error" class="mt-1 text-sm text-danger">{{ session.error }}</p>
                    </div>
                    <Link
                        :href="`/pengaturan/sesi/${session.id}/aktif`"
                        method="post"
                        as="button"
                        class="shrink-0 rounded-full bg-cyan-2 px-2.5 py-1.5 text-xs font-semibold text-[#003640]"
                    >
                        Pantau
                    </Link>
                </div>

                <form v-if="editing === session.id" class="mt-3 grid gap-3 border-t border-edge pt-3 sm:grid-cols-2" @submit.prevent="submitEdit(session.id)">
                    <label class="block font-mono text-[11px] text-mute">
                        Nama
                        <input v-model="editForm.name" required class="text-paper" :class="fieldClass">
                    </label>
                    <label class="block font-mono text-[11px] text-mute">
                        Host
                        <input v-model="editForm.host" required class="text-paper" :class="fieldClass">
                    </label>
                    <label class="block font-mono text-[11px] text-mute">
                        Protokol
                        <select v-model="editForm.scheme" class="text-paper" :class="fieldClass">
                            <option v-for="protocol in protocols" :key="protocol.value" :value="protocol.value">{{ protocol.label }}</option>
                        </select>
                    </label>
                    <label class="block font-mono text-[11px] text-mute">
                        Port
                        <input v-model="editForm.port" inputmode="numeric" :placeholder="portHint(editForm.scheme)" class="text-paper" :class="fieldClass">
                    </label>
                    <label class="block font-mono text-[11px] text-mute">
                        Username
                        <input v-model="editForm.username" required autocomplete="off" class="text-paper" :class="fieldClass">
                    </label>
                    <label class="block font-mono text-[11px] text-mute">
                        Password
                        <input v-model="editForm.password" type="password" autocomplete="new-password" placeholder="Kosongkan untuk tetap" class="text-paper" :class="fieldClass">
                    </label>
                    <label class="block font-mono text-[11px] text-mute sm:col-span-2">
                        Interface WAN
                        <input v-model="editForm.wan_interface" placeholder="ether1" class="text-paper" :class="fieldClass">
                    </label>
                    <ToggleSwitch v-model="editForm.enabled" class="sm:col-span-2" label="Ikut dipantau" hint="Poller mengambil data router ini." />
                    <p v-if="editForm.hasErrors" class="text-sm text-danger sm:col-span-2">{{ Object.values(editForm.errors)[0] }}</p>
                    <div class="flex gap-2 sm:col-span-2">
                        <button type="submit" class="rounded-full bg-cyan-2 px-3 py-2 text-sm font-semibold text-[#003640]" :disabled="editForm.processing">Simpan</button>
                        <button type="button" class="rounded-full bg-tile px-3 py-2 text-sm text-mist" @click="editing = null">Batal</button>
                    </div>
                </form>

                <div v-else class="mt-3 flex gap-2 border-t border-edge pt-3">
                    <button type="button" class="rounded-full bg-tile px-3 py-1.5 text-xs text-mist" @click="startEdit(session)">Ubah</button>
                    <button
                        v-if="pendingDelete !== session.id"
                        type="button"
                        class="rounded-full bg-tile px-3 py-1.5 text-xs text-danger"
                        @click="pendingDelete = session.id"
                    >
                        Hapus
                    </button>
                    <Link
                        v-else
                        :href="`/pengaturan/sesi/${session.id}`"
                        method="delete"
                        as="button"
                        class="rounded-full bg-danger/15 px-3 py-1.5 text-xs text-danger"
                    >
                        Hapus sesi dan sampelnya
                    </Link>
                </div>
            </article>

            <article class="noc-card p-3">
                <p class="text-[15px] font-semibold">Tambah sesi</p>
                <form class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="submitCreate">
                    <label class="block font-mono text-[11px] text-mute">
                        Nama
                        <input v-model="createForm.name" required class="text-paper" :class="fieldClass">
                    </label>
                    <label class="block font-mono text-[11px] text-mute">
                        Host
                        <input v-model="createForm.host" required placeholder="10.10.10.1" class="text-paper" :class="fieldClass">
                    </label>
                    <label class="block font-mono text-[11px] text-mute">
                        Protokol
                        <select v-model="createForm.scheme" class="text-paper" :class="fieldClass">
                            <option v-for="protocol in protocols" :key="`new-${protocol.value}`" :value="protocol.value">{{ protocol.label }}</option>
                        </select>
                    </label>
                    <label class="block font-mono text-[11px] text-mute">
                        Port
                        <input v-model="createForm.port" inputmode="numeric" :placeholder="portHint(createForm.scheme)" class="text-paper" :class="fieldClass">
                    </label>
                    <label class="block font-mono text-[11px] text-mute">
                        Username
                        <input v-model="createForm.username" required autocomplete="off" class="text-paper" :class="fieldClass">
                    </label>
                    <label class="block font-mono text-[11px] text-mute">
                        Password
                        <input v-model="createForm.password" type="password" required autocomplete="new-password" class="text-paper" :class="fieldClass">
                    </label>
                    <label class="block font-mono text-[11px] text-mute sm:col-span-2">
                        Interface WAN
                        <input v-model="createForm.wan_interface" placeholder="ether1" class="text-paper" :class="fieldClass">
                    </label>
                    <ToggleSwitch v-model="createForm.enabled" class="sm:col-span-2" label="Ikut dipantau" hint="Poller mengambil data router ini." />
                    <p v-if="createForm.hasErrors" class="text-sm text-danger sm:col-span-2">{{ Object.values(createForm.errors)[0] }}</p>
                    <div class="sm:col-span-2">
                        <button type="submit" class="rounded-full bg-cyan-2 px-3 py-2 text-sm font-semibold text-[#003640]" :disabled="createForm.processing">Simpan sesi</button>
                    </div>
                </form>
            </article>
        </section>
        </template>

        <section v-else-if="section === 'unifi'" class="grid max-w-3xl gap-3">
            <article class="noc-card flex items-start gap-3 p-4">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-lg" :class="unifiStatus?.connected ? 'bg-ok/10 text-ok' : 'bg-tile text-mute'">
                    <Icon class="text-[20px]" name="wifi" />
                </span>
                <div class="min-w-0">
                    <p class="text-[15px] font-semibold">{{ unifiStatus?.connected ? 'Terhubung ke UniFi' : 'Belum terhubung' }}</p>
                    <p v-if="unifiStatus?.connected" class="mt-1 text-sm text-mist">
                        Site {{ unifiStatus.site }} · {{ unifiStatus.access_points }} access point · {{ unifiStatus.clients }} klien
                    </p>
                    <p v-else-if="unifiStatus?.configured" class="mt-1 text-sm text-mist">Console belum bisa dihubungi. {{ unifiStatus.reason }}</p>
                    <p v-else class="mt-1 text-sm text-mist">Isi alamat console dan API key untuk menampilkan access point di menu Access Points.</p>
                </div>
            </article>

            <article class="noc-card p-4">
                <p class="text-[15px] font-semibold">Console UniFi</p>
                <p class="mt-1 text-sm text-mist">Buat API key di UniFi Network → Settings → Control Plane → Integrations.</p>
                <form class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="submitUnifi">
                    <label class="block font-mono text-[11px] text-mute sm:col-span-2">
                        Alamat console
                        <input v-model="unifiForm.url" required placeholder="https://unifi.contoh.id" class="text-paper" :class="fieldClass">
                    </label>
                    <label class="block font-mono text-[11px] text-mute">
                        API key
                        <input v-model="unifiForm.api_key" type="password" autocomplete="new-password" :required="!unifi?.has_key" :placeholder="unifi?.has_key ? 'Kosongkan untuk tetap' : ''" class="text-paper" :class="fieldClass">
                    </label>
                    <label class="block font-mono text-[11px] text-mute">
                        Site
                        <input v-model="unifiForm.site" placeholder="Kosongkan untuk site pertama" class="text-paper" :class="fieldClass">
                    </label>
                    <ToggleSwitch v-model="unifiForm.verify_tls" class="sm:col-span-2" label="Verifikasi sertifikat TLS" hint="Matikan kalau console memakai sertifikat self-signed atau diakses lewat IP." />
                    <p v-if="unifiForm.hasErrors" class="text-sm text-danger sm:col-span-2">{{ Object.values(unifiForm.errors)[0] }}</p>
                    <p v-else-if="unifiForm.recentlySuccessful" class="text-sm text-ok sm:col-span-2">Tersimpan.</p>
                    <div class="flex flex-wrap items-center gap-2 sm:col-span-2">
                        <button type="submit" class="rounded-full bg-cyan-2 px-3 py-2 text-sm font-semibold text-[#003640]" :disabled="unifiForm.processing">{{ unifi?.has_key ? 'Simpan' : 'Hubungkan' }}</button>
                        <template v-if="unifi?.has_key">
                            <button v-if="!confirmDisconnect" type="button" class="rounded-full bg-tile px-3 py-2 text-sm text-danger" @click="confirmDisconnect = true">Putuskan</button>
                            <template v-else>
                                <button type="button" class="rounded-full bg-danger/15 px-3 py-2 text-sm text-danger" @click="disconnectUnifi">Ya, putuskan</button>
                                <button type="button" class="rounded-full bg-tile px-3 py-2 text-sm text-mist" @click="confirmDisconnect = false">Batal</button>
                            </template>
                        </template>
                    </div>
                </form>
            </article>
        </section>

        <section v-else class="noc-card max-w-lg p-3">
            <p class="text-[15px] font-semibold">Password</p>
            <p class="mt-1 text-sm text-mist">Ganti password masuk untuk {{ page.props.auth?.user?.email || 'akun ini' }}.</p>
            <form class="mt-3 grid gap-3" @submit.prevent="submitPassword">
                <label class="block font-mono text-[11px] text-mute">
                    Password saat ini
                    <input v-model="passwordForm.current_password" type="password" required autocomplete="current-password" class="text-paper" :class="fieldClass">
                </label>
                <label class="block font-mono text-[11px] text-mute">
                    Password baru
                    <input v-model="passwordForm.password" type="password" required autocomplete="new-password" class="text-paper" :class="fieldClass">
                </label>
                <label class="block font-mono text-[11px] text-mute">
                    Ulangi password baru
                    <input v-model="passwordForm.password_confirmation" type="password" required autocomplete="new-password" class="text-paper" :class="fieldClass">
                </label>
                <p v-if="passwordForm.hasErrors" class="text-sm text-danger">{{ Object.values(passwordForm.errors)[0] }}</p>
                <p v-else-if="passwordForm.recentlySuccessful" class="text-sm text-ok">Password diperbarui.</p>
                <div>
                    <button type="submit" class="rounded-full bg-cyan-2 px-3 py-2 text-sm font-semibold text-[#003640]" :disabled="passwordForm.processing">Simpan password</button>
                </div>
            </form>
        </section>
</template>
