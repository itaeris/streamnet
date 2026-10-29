<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    domain: { type: String, default: '' },
    label: { type: String, default: '' },
});

const failed = ref(false);

watch(() => props.domain, () => {
    failed.value = false;
});

const src = computed(() => (props.domain ? `/dns/logo?domain=${encodeURIComponent(props.domain)}` : ''));
const letter = computed(() => (props.label || props.domain || '?').slice(0, 1).toUpperCase());
</script>

<template>
    <span class="flex size-7 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-tile text-[11px] font-semibold text-mist">
        <img v-if="src && !failed" :src="src" :alt="label || domain" class="size-full object-cover" @error="failed = true" />
        <span v-else>{{ letter }}</span>
    </span>
</template>
