<script setup>
import { computed } from 'vue';
import { AnimatePresence, motion } from 'motion-v';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    open: { type: Boolean, default: false },
    inline: { type: Boolean, default: false },
});

const hidden = computed(() => (props.inline
    ? { opacity: 0, height: 0 }
    : { opacity: 0, y: -6, scale: 0.96 }));
const shown = computed(() => (props.inline
    ? { opacity: 1, height: 'auto' }
    : { opacity: 1, y: 0, scale: 1 }));
const tween = { duration: 0.2, ease: [0.16, 1, 0.3, 1] };
</script>

<template>
    <AnimatePresence>
        <motion.div
            v-if="open"
            key="menu"
            v-bind="$attrs"
            :class="inline ? 'overflow-hidden' : ''"
            :initial="hidden"
            :animate="shown"
            :exit="hidden"
            :transition="tween"
        >
            <slot />
        </motion.div>
    </AnimatePresence>
</template>
