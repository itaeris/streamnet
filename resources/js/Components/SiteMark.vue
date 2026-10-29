<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    domain: { type: String, default: '' },
    label: { type: String, default: '' },
});

const attempt = ref(0);

watch(() => props.domain, () => {
    attempt.value = 0;
});

const host = computed(() => String(props.domain || '').replace(/^https?:\/\//, '').split('/')[0].replace(/\.$/, ''));
const sources = computed(() => (host.value ? [`https://${host.value}/favicon.ico`, `https://${host.value}/apple-touch-icon.png`] : []));
const src = computed(() => sources.value[attempt.value] || '');
const letter = computed(() => (props.label || host.value || '?').slice(0, 1).toUpperCase());

function failed() {
    attempt.value += 1;
}
</script>

<template>
    <span class="flex size-7 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-tile text-[11px] font-semibold text-mist">
        <img v-if="src" :src="src" :alt="label || host" class="size-full object-cover" referrerpolicy="no-referrer" @error="failed" />
        <span v-else>{{ letter }}</span>
    </span>
</template>
