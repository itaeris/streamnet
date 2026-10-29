<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { live } from '../composables/useBoardTime';
import { formatRate, rateParts } from '../lib/format';

const props = defineProps({
    value: { type: [Number, String], default: 0 },
    split: { type: Boolean, default: false },
});

const glideMs = 900;
const shown = ref(Number(props.value) || 0);
const parts = computed(() => rateParts(shown.value));
let from = shown.value;
let target = shown.value;
let startedAt = 0;
let frame = 0;

watch(() => Number(props.value) || 0, (next) => {
    if (!live.value || next === shown.value) {
        cancelAnimationFrame(frame);
        frame = 0;
        shown.value = from = target = next;

        return;
    }

    from = shown.value;
    target = next;
    startedAt = performance.now();
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(tick);
});

watch(live, (on) => {
    if (on) {
        return;
    }

    cancelAnimationFrame(frame);
    frame = 0;
    const next = Number(props.value) || 0;
    shown.value = from = target = next;
});

function tick(now) {
    const progress = Math.min(1, (now - startedAt) / glideMs);
    const eased = 1 - (1 - progress) ** 3;
    shown.value = from + (target - from) * eased;

    if (progress < 1) {
        frame = requestAnimationFrame(tick);

        return;
    }

    frame = 0;
    shown.value = target;
}

onBeforeUnmount(() => cancelAnimationFrame(frame));
</script>

<template>
    <template v-if="split">{{ parts.value }} <span class="text-[11px] font-normal text-mute">{{ parts.unit }}</span></template>
    <template v-else>{{ formatRate(shown) }}</template>
</template>
