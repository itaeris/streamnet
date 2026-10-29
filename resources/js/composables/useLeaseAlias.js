import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const aliases = ref({});
const saving = ref(false);
const error = ref('');

export function aliasOf(lease) {
    const id = lease?.router_id;
    if (id && Object.prototype.hasOwnProperty.call(aliases.value, id)) {
        return aliases.value[id];
    }

    return lease?.comment || '';
}

export function saveLeaseAlias(routerId, comment, onSaved) {
    if (!routerId || saving.value) {
        return;
    }

    const stored = String(comment ?? '').trim();
    saving.value = true;
    error.value = '';
    router.put('/dhcp/alias', { router_id: routerId, comment: stored }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            aliases.value = { ...aliases.value, [routerId]: stored };
            onSaved?.(stored);
        },
        onError: (errors) => {
            error.value = errors.comment || 'Router menolak perubahan alias.';
        },
        onFinish: () => {
            saving.value = false;
        },
    });
}

export function useLeaseAlias() {
    return { saving, error, aliasOf, saveLeaseAlias };
}
