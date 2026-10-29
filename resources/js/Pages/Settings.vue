<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import DataTable from '../Components/DataTable.vue';

defineOptions({ layout: AppLayout });

defineProps({
    section: { type: String, default: 'app' },
    users: { type: Array, default: () => [] },
});

const editingUser = ref(null);
const confirmUserDelete = ref(false);
const userForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

function startUser(user = null) {
    editingUser.value = user;
    confirmUserDelete.value = false;
    userForm.clearErrors();
    userForm.name = user?.name ?? '';
    userForm.email = user?.email ?? '';
    userForm.password = '';
    userForm.password_confirmation = '';
}

function submitUser() {
    const options = {
        preserveScroll: true,
        onSuccess: () => startUser(null),
    };

    if (editingUser.value) {
        userForm.put(`/pengaturan/pengguna/${editingUser.value.id}`, options);
    } else {
        userForm.post('/pengaturan/pengguna', options);
    }
}

function deleteUser() {
    router.delete(`/pengaturan/pengguna/${editingUser.value.id}`, {
        preserveScroll: true,
        onSuccess: () => startUser(null),
    });
}

const userColumns = [
    { id: 'name', label: 'Nama', type: 'text', first: 'asc', get: (row) => row.name || '' },
    { id: 'email', label: 'Email', type: 'text', first: 'asc', get: (row) => row.email || '', class: 'font-mono text-[12px] text-mist' },
    { id: 'created', label: 'Dibuat', type: 'text', first: 'desc', get: (row) => row.created_at || '', class: 'font-mono text-[12px] text-mute whitespace-nowrap' },
];

const page = usePage();

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
</script>

<template>
    <Head :title="section === 'users' ? 'Pengguna' : 'Aplikasi'" />

        <nav class="mb-4 inline-flex max-w-full overflow-x-auto rounded-xl bg-well p-1" role="tablist">
            <Link
                v-for="item in [{ id: 'app', href: '/pengaturan', label: 'Aplikasi' }, { id: 'users', href: '/pengaturan/pengguna', label: 'Pengguna' }]"
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

        <section v-if="section === 'users'" class="grid gap-3 xl:grid-cols-[1.4fr_1fr]">
            <DataTable
                title="Pengguna"
                :rows="users"
                :columns="userColumns"
                :row-key="(row) => row.id"
                default-sort="name"
                placeholder="Cari nama, email..."
                empty="Belum ada pengguna."
                min-width="min-w-[32rem]"
                clickable
                :is-active="(row) => editingUser?.id === row.id"
                @open="startUser"
            >
                <template #name="{ row }">
                    <span class="inline-flex items-center gap-2">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-cyan-2 text-[11px] font-semibold text-[#003640]">{{ (row.name || '?').slice(0, 2).toUpperCase() }}</span>
                        {{ row.name }}
                        <span v-if="row.self" class="rounded-full bg-cyan/10 px-2 py-0.5 text-[10px] font-medium text-cyan">Anda</span>
                    </span>
                </template>
            </DataTable>

            <article class="noc-card h-fit p-4">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="text-[15px] font-semibold">{{ editingUser ? `Ubah ${editingUser.name}` : 'Tambah pengguna' }}</p>
                        <p class="mt-1 text-sm text-mist">{{ editingUser ? 'Kosongkan password kalau tidak ingin menggantinya.' : 'Pengguna baru bisa masuk ke dashboard dengan email dan password ini.' }}</p>
                    </div>
                    <button v-if="editingUser" type="button" class="rounded-full bg-tile px-3 py-1.5 text-xs text-mist" @click="startUser(null)">Tambah baru</button>
                </div>
                <form class="mt-4 grid gap-3" @submit.prevent="submitUser">
                    <label class="block font-mono text-[11px] text-mute">
                        Nama
                        <input v-model="userForm.name" required class="text-paper" :class="fieldClass">
                    </label>
                    <label class="block font-mono text-[11px] text-mute">
                        Email
                        <input v-model="userForm.email" type="email" required autocomplete="off" class="text-paper" :class="fieldClass">
                    </label>
                    <label class="block font-mono text-[11px] text-mute">
                        Password
                        <input v-model="userForm.password" type="password" :required="!editingUser" autocomplete="new-password" :placeholder="editingUser ? 'Kosongkan untuk tetap' : 'Minimal 8 karakter'" class="text-paper" :class="fieldClass">
                    </label>
                    <label class="block font-mono text-[11px] text-mute">
                        Ulangi password
                        <input v-model="userForm.password_confirmation" type="password" :required="!editingUser && !!userForm.password" autocomplete="new-password" class="text-paper" :class="fieldClass">
                    </label>
                    <p v-if="userForm.hasErrors || page.props.errors?.user" class="text-sm text-danger">{{ Object.values(userForm.errors)[0] || page.props.errors.user }}</p>
                    <p v-else-if="userForm.recentlySuccessful" class="text-sm text-ok">Tersimpan.</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="submit" class="rounded-full bg-cyan-2 px-3 py-2 text-sm font-semibold text-[#003640]" :disabled="userForm.processing">{{ editingUser ? 'Simpan' : 'Tambah pengguna' }}</button>
                        <template v-if="editingUser && !editingUser.self">
                            <button v-if="!confirmUserDelete" type="button" class="rounded-full bg-tile px-3 py-2 text-sm text-danger" @click="confirmUserDelete = true">Hapus</button>
                            <template v-else>
                                <button type="button" class="rounded-full bg-danger/15 px-3 py-2 text-sm text-danger" @click="deleteUser">Ya, hapus</button>
                                <button type="button" class="rounded-full bg-tile px-3 py-2 text-sm text-mist" @click="confirmUserDelete = false">Batal</button>
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
