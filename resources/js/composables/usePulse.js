import { inject, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { acceptsPulse, live, syncLivePage } from './useBoardTime';
import { getEcho } from '../echo';
import '../bootstrap';

function isNewer(next, current) {
    if (!current?.sampled_at || !next?.sampled_at) {
        return true;
    }

    const nextMs = Date.parse(next.sampled_at);
    const currentMs = Date.parse(current.sampled_at);

    if (Number.isNaN(nextMs) || Number.isNaN(currentMs)) {
        return true;
    }

    return nextMs >= currentMs;
}

function unwrap(event) {
    if (event && typeof event === 'object' && 'online' in event) {
        return event;
    }

    return event?.payload ?? event;
}

export const pulseKey = Symbol('board-pulse');

export function useBoardPulse() {
    return inject(pulseKey, ref(null));
}

export function usePulse(getInitial) {
    const page = usePage();
    const read = () => (typeof getInitial === 'function' ? getInitial() : getInitial);
    const pulse = ref(read());
    const connected = ref(false);

    watch(read, (next) => {
        if (next && isNewer(next, pulse.value)) {
            pulse.value = next;
        }
    });

    watch(() => page.props.currentSessionId, () => {
        pulse.value = read() ?? null;
    });

    onMounted(() => {
        const channel = getEcho().private('mikrotik');

        channel.subscribed(() => {
            connected.value = true;
        });

        channel.error(() => {
            connected.value = false;
        });

        channel.listen('.pulse', (event) => {
            const data = unwrap(event);
            if (!data) {
                return;
            }

            const current = page.props.currentSessionId;
            const foreign = current && data.session_id && Number(data.session_id) !== Number(current);
            if (foreign) {
                if (live.value && page.url.split('?')[0] === '/pengaturan') {
                    syncLivePage();
                }
                return;
            }

            if (!acceptsPulse()) {
                return;
            }

            pulse.value = data;
            if (live.value) {
                syncLivePage();
            }
        });
    });

    onBeforeUnmount(() => {
        getEcho().leave('mikrotik');
        connected.value = false;
    });

    return { pulse, connected };
}
