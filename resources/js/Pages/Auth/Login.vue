<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { useTheme } from '../../composables/useTheme';

const { theme, toggleTheme } = useTheme();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Masuk" />

    <div class="relative flex min-h-screen items-center justify-center px-6 py-16">
        <button
            type="button"
            class="absolute top-4 right-4 flex size-9 items-center justify-center rounded-full bg-well text-mist hover:text-paper"
            :title="theme === 'light' ? 'Mode gelap' : 'Mode terang'"
            @click="toggleTheme"
        >
            <span class="material-symbols-outlined text-[18px]">{{ theme === 'light' ? 'dark_mode' : 'light_mode' }}</span>
        </button>
        <form class="noc-card w-full max-w-sm p-6" @submit.prevent="submit">
            <div class="flex items-center gap-2">
                <div class="flex size-8 items-center justify-center rounded-md bg-tile text-cyan">
                    <span class="material-symbols-outlined text-[20px]">hub</span>
                </div>
                <div>
                    <p class="text-[15px] font-semibold">StreamNet</p>
                    <p class="font-mono text-[11px] text-mute">MikroTik gateway monitor</p>
                </div>
            </div>

            <h2 class="mt-6 text-lg font-semibold">Masuk</h2>
            <p class="mt-1 text-sm text-mist">Akun operator untuk konsol jaringan.</p>

            <label class="mt-6 block font-mono text-[11px] text-mute" for="email">Email</label>
            <input
                id="email"
                v-model="form.email"
                type="email"
                autocomplete="username"
                required
                class="mt-1 w-full rounded bg-sink px-3 py-2.5 text-sm outline-none focus:ring-1 focus:ring-cyan-2"
            >
            <p v-if="form.errors.email" class="mt-2 text-sm text-danger">{{ form.errors.email }}</p>

            <label class="mt-4 block font-mono text-[11px] text-mute" for="password">Password</label>
            <input
                id="password"
                v-model="form.password"
                type="password"
                autocomplete="current-password"
                required
                class="mt-1 w-full rounded bg-sink px-3 py-2.5 text-sm outline-none focus:ring-1 focus:ring-cyan-2"
            >
            <p v-if="form.errors.password" class="mt-2 text-sm text-danger">{{ form.errors.password }}</p>

            <label class="mt-4 flex items-center gap-2 text-sm text-mist">
                <input v-model="form.remember" type="checkbox" class="accent-cyan-2">
                Ingat sesi ini
            </label>

            <button
                type="submit"
                class="mt-6 w-full rounded bg-cyan-2 px-4 py-2.5 font-semibold text-[#003640] disabled:opacity-60"
                :disabled="form.processing"
            >
                Masuk
            </button>
        </form>
    </div>
</template>
