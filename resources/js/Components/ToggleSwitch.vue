<script setup>
defineProps({
    modelValue: { type: Boolean, default: false },
    label: { type: String, default: '' },
    hint: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="modelValue"
        class="group flex w-fit items-center gap-3 text-left"
        @click="emit('update:modelValue', !modelValue)"
    >
        <span class="relative h-5 w-9 shrink-0 rounded-full transition-colors duration-150" :class="modelValue ? 'bg-cyan-2' : 'bg-tile group-hover:bg-hover'">
            <span class="absolute top-0.5 size-4 rounded-full bg-white shadow-sm ring-1 ring-black/10 transition-all duration-150" :class="modelValue ? 'left-[18px]' : 'left-0.5'" />
        </span>
        <span class="min-w-0">
            <span class="block text-sm text-paper">{{ label }}</span>
            <span v-if="hint" class="block text-[12px] text-mute">{{ hint }}</span>
        </span>
    </button>
</template>
