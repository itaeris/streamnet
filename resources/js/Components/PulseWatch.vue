<script setup>
import { watch } from 'vue';

const props = defineProps({
    pulse: { type: Object, default: null },
});

const emit = defineEmits(['pulse']);

watch(() => props.pulse, (value) => {
    if (value) {
        emit('pulse', value);
    }
}, { deep: true });
</script>

<template>
    <span class="hidden" />
</template>
