<script setup>
defineProps({
    modelValue: { type: String, required: true },
    options: { type: Array, required: true },
});

const emit = defineEmits(['update:modelValue']);

const idOf = (option) => (typeof option === 'string' ? option : option.id);
const labelOf = (option) => (typeof option === 'string' ? option : option.label);
</script>

<template>
    <div class="inline-flex max-w-full overflow-x-auto rounded-xl bg-well p-1" role="tablist">
        <button
            v-for="option in options"
            :key="idOf(option)"
            type="button"
            role="tab"
            :aria-selected="modelValue === idOf(option)"
            class="h-8 shrink-0 rounded-lg px-3.5 text-[12px] whitespace-nowrap capitalize transition-colors duration-150"
            :class="modelValue === idOf(option) ? 'bg-panel font-semibold text-cyan shadow-sm ring-1 ring-edge' : 'text-mute hover:bg-hover hover:text-paper'"
            @click="emit('update:modelValue', idOf(option))"
        >
            {{ labelOf(option) }}
        </button>
    </div>
</template>
