<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import MenuPanel from './MenuPanel.vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    options: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue']);
const open = ref(false);
const root = ref(null);

const current = computed(() => props.options.find((option) => option.value === props.modelValue)?.label || props.modelValue || 'Pilih');

function choose(value) {
    emit('update:modelValue', value);
    open.value = false;
}

function onPointerDown(event) {
    if (!root.value?.contains(event.target)) {
        open.value = false;
    }
}

function onKey(event) {
    if (event.key === 'Escape') {
        open.value = false;
    }
}

onMounted(() => {
    document.addEventListener('pointerdown', onPointerDown);
    document.addEventListener('keydown', onKey);
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onPointerDown);
    document.removeEventListener('keydown', onKey);
});
</script>

<template>
    <div ref="root" class="relative mt-1 min-w-48">
        <button
            type="button"
            class="flex h-9 w-full items-center justify-between gap-2 rounded-lg border border-edge bg-sink px-3 text-left font-mono text-sm text-paper"
            :aria-expanded="open"
            @click="open = !open"
        >
            <span class="truncate">{{ current }}</span>
            <Icon class="shrink-0 text-[18px] text-mute transition-transform duration-200" :class="open ? 'rotate-180' : ''" name="expand_more" />
        </button>
        <MenuPanel :open="open" class="absolute z-30 mt-1 max-h-64 w-full origin-top overflow-y-auto rounded-lg border border-edge bg-menu py-1 shadow-xl">
            <button
                v-for="option in options"
                :key="option.value"
                type="button"
                class="block w-full truncate px-3 py-2 text-left font-mono text-sm hover:bg-hover"
                :class="option.value === modelValue ? 'text-cyan' : 'text-paper'"
                @click="choose(option.value)"
            >
                {{ option.label }}
            </button>
        </MenuPanel>
    </div>
</template>
