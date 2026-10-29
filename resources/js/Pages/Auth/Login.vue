<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useTheme } from '../../composables/useTheme';

const { theme, toggleTheme } = useTheme();
const reveal = ref(false);

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

const bars = Array.from({ length: 36 }, (_, index) => {
    const wave = Math.sin(index / 3.2) * 0.28 + Math.sin(index / 1.7) * 0.14;
    const rx = Math.min(0.96, Math.max(0.12, 0.5 + wave + ((index * 37) % 11) / 40));
    const tx = Math.max(0.06, rx * (0.32 + ((index * 13) % 7) / 30));

    return { rx, tx, delay: `${index * 45}ms` };
});

const features = [
    { icon: 'swap_horiz', title: 'Trafik realtime', text: 'WAN, bridge, dan tiap interface dari websocket.' },
    { icon: 'devices', title: 'Perangkat & DNS', text: 'Lease DHCP, alias, dan aplikasi yang dibuka.' },
    { icon: 'shield', title: 'Firewall & log', text: 'Rule, temuan, dan kejadian dalam satu tempat.' },
];

const errorText = () => form.errors.email || form.errors.password || '';
</script>

<template>
    <Head title="Masuk" />

    <div class="relative flex min-h-screen items-center justify-center p-4 sm:p-8">
        <button
            type="button"
            class="absolute top-4 right-4 z-10 flex size-9 items-center justify-center rounded-full bg-well text-mist hover:text-paper"
            v-tip="theme === 'light' ? 'Mode gelap' : 'Mode terang'"
            @click="toggleTheme"
        >
            <Icon class="text-[18px]" :name="theme === 'light' ? 'dark_mode' : 'light_mode'" />
        </button>

        <div class="shell-panel grid w-full max-w-5xl overflow-hidden shadow-2xl shadow-black/10 lg:grid-cols-[1.1fr_1fr]">
            <aside class="relative hidden overflow-hidden border-r border-edge bg-sink p-10 lg:flex lg:flex-col">
                <div class="pointer-events-none absolute -top-24 -left-24 size-80 rounded-full bg-cyan-2/20 blur-3xl" />
                <div class="pointer-events-none absolute -right-20 -bottom-28 size-80 rounded-full bg-ok/15 blur-3xl" />

                <div class="relative flex items-center gap-2.5">
                    <div class="flex size-10 items-center justify-center rounded-xl bg-cyan-2 text-[#003640]">
                        <Icon class="text-[22px]" name="hub" />
                    </div>
                    <div>
                        <p class="text-[15px] font-semibold">StreamNet</p>
                        <p class="text-[11px] text-mute">MikroTik gateway monitor</p>
                    </div>
                </div>

                <div class="relative mt-14">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-ok/10 px-2.5 py-1 text-[11px] font-medium text-ok">
                        <span class="size-1.5 animate-pulse rounded-full bg-ok" />
                        Live telemetry
                    </span>
                    <h1 class="mt-4 max-w-sm text-3xl leading-tight font-semibold">Pantau gateway kamu, detik demi detik.</h1>
                    <p class="mt-3 max-w-sm text-sm text-mist">Satu konsol untuk trafik, perangkat, DNS, dan keamanan router MikroTik.</p>
                </div>

                <div class="relative mt-10 rounded-2xl border border-edge bg-panel/70 p-4 backdrop-blur">
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-mute">ether1 · WAN</span>
                        <span class="flex items-center gap-3">
                            <span class="inline-flex items-center gap-1.5 text-cyan"><span class="h-1 w-2.5 rounded-full bg-cyan" /> RX</span>
                            <span class="inline-flex items-center gap-1.5 text-ok"><span class="h-1 w-2.5 rounded-full bg-ok" /> TX</span>
                        </span>
                    </div>
                    <div class="mt-3 flex h-28 items-end gap-[3px]" aria-hidden="true">
                        <div v-for="(bar, index) in bars" :key="index" class="relative flex h-full flex-1 items-end">
                            <span class="login-bar absolute bottom-0 w-full rounded-t-sm bg-cyan/70" :style="{ height: `${bar.rx * 100}%`, animationDelay: bar.delay }" />
                            <span class="login-bar absolute bottom-0 w-full rounded-t-sm bg-ok/80" :style="{ height: `${bar.tx * 100}%`, animationDelay: bar.delay }" />
                        </div>
                    </div>
                </div>

                <ul class="relative mt-auto grid gap-4 pt-10">
                    <li v-for="feature in features" :key="feature.title" class="flex items-start gap-3">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-tile text-cyan">
                            <Icon class="text-[18px]" :name="feature.icon" />
                        </span>
                        <span>
                            <span class="block text-sm font-medium">{{ feature.title }}</span>
                            <span class="block text-[12px] text-mute">{{ feature.text }}</span>
                        </span>
                    </li>
                </ul>
            </aside>

            <main class="flex flex-col justify-center bg-panel p-6 sm:p-10 lg:p-12">
                <div class="flex items-center gap-2.5 lg:hidden">
                    <div class="flex size-9 items-center justify-center rounded-xl bg-cyan-2 text-[#003640]">
                        <Icon class="text-[20px]" name="hub" />
                    </div>
                    <div>
                        <p class="text-[15px] font-semibold">StreamNet</p>
                        <p class="text-[11px] text-mute">MikroTik gateway monitor</p>
                    </div>
                </div>

                <div class="mt-8 lg:mt-0">
                    <h2 class="text-2xl font-semibold">Selamat datang</h2>
                    <p class="mt-1.5 text-sm text-mist">Masuk dengan akun operator untuk membuka konsol jaringan.</p>
                </div>

                <form class="mt-8 grid gap-4" @submit.prevent="submit">
                    <div
                        v-if="errorText()"
                        class="flex items-start gap-2 rounded-xl bg-danger/10 px-3 py-2.5 text-sm text-danger"
                        role="alert"
                    >
                        <Icon class="mt-px text-[18px]" name="error" />
                        <span>{{ errorText() }}</span>
                    </div>

                    <label class="block">
                        <span class="text-[12px] font-medium text-mist">Email</span>
                        <span class="relative mt-1.5 block">
                            <Icon class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-[18px] text-mute" name="mail" />
                            <input
                                v-model="form.email"
                                type="email"
                                autocomplete="username"
                                required
                                autofocus
                                placeholder="nama@kantor.id"
                                class="h-11 w-full rounded-full border border-transparent bg-sink pr-4 pl-10 text-sm outline-none transition placeholder:text-mute focus:border-cyan-2/60 focus:ring-4 focus:ring-cyan-2/15"
                            >
                        </span>
                    </label>

                    <label class="block">
                        <span class="text-[12px] font-medium text-mist">Password</span>
                        <span class="relative mt-1.5 block">
                            <Icon class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-[18px] text-mute" name="lock" />
                            <input
                                v-model="form.password"
                                :type="reveal ? 'text' : 'password'"
                                autocomplete="current-password"
                                required
                                placeholder="••••••••"
                                class="h-11 w-full rounded-full border border-transparent bg-sink pr-11 pl-10 text-sm outline-none transition placeholder:text-mute focus:border-cyan-2/60 focus:ring-4 focus:ring-cyan-2/15"
                            >
                            <button
                                type="button"
                                class="absolute top-1/2 right-2 flex size-8 -translate-y-1/2 items-center justify-center rounded-full text-mute hover:bg-hover hover:text-paper"
                                v-tip="reveal ? 'Sembunyikan' : 'Tampilkan'"
                                @click="reveal = !reveal"
                            >
                                <Icon class="text-[18px]" :name="reveal ? 'visibility_off' : 'visibility'" />
                            </button>
                        </span>
                    </label>

                    <label class="flex w-fit cursor-pointer items-center gap-2 text-sm text-mist select-none">
                        <input v-model="form.remember" type="checkbox" class="size-4 rounded accent-cyan-2">
                        Ingat sesi ini
                    </label>

                    <button
                        type="submit"
                        class="mt-2 flex h-11 w-full items-center justify-center gap-2 rounded-full bg-cyan-2 font-semibold text-[#003640] transition hover:brightness-110 active:scale-[0.99] disabled:opacity-60"
                        :disabled="form.processing"
                    >
                        <span v-if="form.processing" class="size-4 animate-spin rounded-full border-2 border-[#003640]/30 border-t-[#003640]" />
                        {{ form.processing ? 'Memeriksa…' : 'Masuk' }}
                        <Icon v-if="!form.processing" class="text-[18px]" name="arrow_forward" />
                    </button>
                </form>

                <p class="mt-10 text-center text-[11px] text-mute lg:text-left">Akses hanya untuk operator jaringan.</p>
            </main>
        </div>
    </div>
</template>

<style scoped>
.login-bar {
    transform-origin: bottom;
    animation: login-rise 2.6s ease-in-out infinite alternate;
}

@keyframes login-rise {
    0% {
        transform: scaleY(0.55);
    }

    100% {
        transform: scaleY(1);
    }
}

@media (prefers-reduced-motion: reduce) {
    .login-bar {
        animation: none;
    }
}
</style>
