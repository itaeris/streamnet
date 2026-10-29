<script setup>
defineProps({
    active: { type: Boolean, default: false },
    icon: { type: String, default: 'arrow_downward' },
});

defineEmits(['sort']);
</script>

<template>
    <button type="button" class="inline-flex w-full items-center gap-0.5 px-3 py-2 text-left hover:text-paper" @click="$emit('sort')">
        <slot />
        <svg
            viewBox="0 0 10 10"
            class="size-2 shrink-0"
            :class="[active ? 'opacity-80' : 'opacity-0 group-hover:opacity-50', icon === 'arrow_upward' ? 'rotate-180' : '']"
            aria-hidden="true"
        >
            <path d="M1.5 3.5 L5 7 L8.5 3.5" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </button>
</template>
