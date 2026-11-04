<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import PageSkeleton from './PageSkeleton.vue';

const props = defineProps({
    active: { type: Boolean, default: false },
    name: { type: String, default: '' },
});

const emit = defineEmits(['cancel']);

const phase = ref('directing');
let timer = 0;
let stops = [];

function clearTimer() {
    window.clearTimeout(timer);
    timer = 0;
}

function reset() {
    clearTimer();
    phase.value = 'directing';
}

watch(() => props.active, (on) => {
    reset();
    if (!on) {
        return;
    }

    timer = window.setTimeout(() => {
        phase.value = 'skeleton';
    }, 720);
}, { immediate: true });

function listen() {
    const stop = (event) => router.on(event, () => emit('cancel'));
    stops = ['error', 'exception', 'invalid', 'cancel'].map(stop);
}

listen();

onBeforeUnmount(() => {
    clearTimer();
    stops.forEach((stop) => stop());
});
</script>

<template>
    <Teleport to="body">
        <div v-if="active" class="fixed inset-0 z-50 overflow-y-auto bg-canvas text-paper" role="status" aria-live="polite">
            <p class="sr-only">Directing{{ name ? ` ${name}` : '' }}</p>
            <Transition name="enter-gate" mode="out-in">
                <div v-if="phase === 'directing'" key="directing" class="flex min-h-full flex-col items-center justify-center px-6">
                    <p class="directing-word font-mono text-[11px] font-semibold tracking-[0.32em] text-cyan uppercase">Directing</p>
                    <p v-if="name" class="mt-3 max-w-md truncate text-center text-xl font-semibold">{{ name }}</p>
                    <div class="directing-track mt-5 h-px w-36 overflow-hidden bg-line">
                        <span class="directing-line block h-full w-full bg-cyan-2" />
                    </div>
                </div>
                <div v-else key="skeleton" class="mx-auto w-full max-w-6xl px-3 py-4">
                    <PageSkeleton kind="overview" />
                </div>
            </Transition>
        </div>
    </Teleport>
</template>
