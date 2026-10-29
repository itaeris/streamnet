<script setup>
import { watch } from 'vue';
import { usePreview } from '../composables/usePreview';

const props = defineProps({
    value: { type: Object, default: null },
});

const { open, preview } = usePreview();

watch(() => props.value, (next) => {
    if (!next || preview.value?.key !== next.key) {
        return;
    }

    if (JSON.stringify(preview.value) === JSON.stringify(next)) {
        return;
    }

    open(next);
});
</script>

<template>
    <span class="hidden" />
</template>
