<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    domain: { type: String, default: '' },
    domains: { type: Array, default: () => [] },
    label: { type: String, default: '' },
});

const attempt = ref(0);
const publicSuffixes = new Set(['co.id', 'ac.id', 'go.id', 'or.id', 'sch.id', 'web.id', 'my.id', 'co.uk', 'com.au', 'com.br']);

function cleanHost(value) {
    return String(value || '')
        .replace(/^https?:\/\//, '')
        .split('/')[0]
        .replace(/\.$/, '')
        .toLowerCase();
}

function apexOf(host) {
    const parts = host.split('.').filter(Boolean);
    if (parts.length <= 2) {
        return host;
    }

    const tail = parts.slice(-2).join('.');

    return publicSuffixes.has(tail) ? parts.slice(-3).join('.') : parts.slice(-2).join('.');
}

const hosts = computed(() => {
    const list = [props.domain, ...(props.domains ?? [])].map(cleanHost).filter(Boolean);
    const apexes = list.map(apexOf);

    return [...new Set([...list, ...apexes])];
});

watch(hosts, () => {
    attempt.value = 0;
});

function iconSources(host) {
    const apex = apexOf(host);
    const urls = [
        `https://${host}/favicon.ico`,
        `https://${host}/apple-touch-icon.png`,
    ];
    if (apex !== host) {
        urls.push(`https://${apex}/favicon.ico`, `https://${apex}/apple-touch-icon.png`);
    }
    urls.push(`https://www.google.com/s2/favicons?domain=${encodeURIComponent(apex)}&sz=64`);

    return urls;
}

const sources = computed(() => [...new Set(hosts.value.flatMap(iconSources))]);
const src = computed(() => sources.value[attempt.value] || '');
const letter = computed(() => (props.label || hosts.value[0] || '?').slice(0, 1).toUpperCase());

function failed() {
    attempt.value += 1;
}
</script>

<template>
    <span class="flex size-7 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-tile text-[11px] font-semibold text-mist">
        <img v-if="src" :src="src" :alt="label || hosts[0]" class="size-full object-cover" referrerpolicy="no-referrer" @error="failed" />
        <span v-else>{{ letter }}</span>
    </span>
</template>
