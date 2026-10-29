import { ref } from 'vue';

const preview = ref(null);

export function usePreview() {
    function open(item) {
        preview.value = item;
    }

    function close() {
        preview.value = null;
    }

    function isOpen(key) {
        return preview.value?.key === key;
    }

    return { preview, open, close, isOpen };
}
