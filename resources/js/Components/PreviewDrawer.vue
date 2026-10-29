<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import LiveRate from './LiveRate.vue';
import TrafficChart from './TrafficChart.vue';
import { live } from '../composables/useBoardTime';
import { useLeaseAlias } from '../composables/useLeaseAlias';
import { usePreview } from '../composables/usePreview';
import { pushPoint } from '../lib/time';

const { preview, close } = usePreview();
const { saving, error, saveLeaseAlias } = useLeaseAlias();
const draft = ref('');
const editingAlias = ref(false);
const panel = ref(null);
const root = ref(null);
const tabId = ref('');
const series = ref([]);
let armedKey = null;
let seriesKey = '';

watch(preview, (value) => {
    if (!value) {
        return;
    }

    if (panel.value?.key !== value.key) {
        tabId.value = value.tabs?.[0]?.id ?? '';
        draft.value = value.alias?.value || '';
        editingAlias.value = false;
    }

    panel.value = value;
    rememberRate(value);
});

function rememberRate(value) {
    if (!value?.stream) {
        return;
    }

    if (seriesKey !== value.key) {
        seriesKey = value.key;
        series.value = Array.isArray(value.stream.points) ? [...value.stream.points] : [];
    }

    const parsed = value.stream.at ? Math.floor(Date.parse(value.stream.at) / 1000) : Math.floor(Date.now() / 1000);
    const ts = Number.isFinite(parsed) ? parsed : Math.floor(Date.now() / 1000);
    const span = live.value ? 31 * 1440 : 5;
    series.value = pushPoint(series.value, {
        ts,
        rx: Number(value.stream.rx) || 0,
        tx: Number(value.stream.tx) || 0,
    }, span, null, null);
}

const tabs = computed(() => {
    const item = panel.value;
    if (!item) {
        return [];
    }

    if (item.tabs?.length) {
        return item.tabs;
    }

    return [{
        id: 'overview',
        icon: item.icon || 'info',
        label: item.eyebrow || 'Detail',
        hero: true,
        sections: item.fields?.length ? [{ rows: item.fields }] : [],
        body: item.body || '',
    }];
});

const active = computed(() => tabs.value.find((tab) => tab.id === tabId.value) ?? tabs.value[0] ?? null);

function onTransitionEnd(event) {
    if (event.propertyName !== 'width' || event.target !== event.currentTarget) {
        return;
    }

    if (!preview.value) {
        panel.value = null;
        tabId.value = '';
    }
}

function onKey(event) {
    if (event.key !== 'Escape') {
        return;
    }

    const tag = event.target?.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA') {
        if (editingAlias.value && panel.value?.alias?.value) {
            draft.value = panel.value.alias.value;
            editingAlias.value = false;
        }

        return;
    }

    close();
}

function submitAlias(comment) {
    const id = panel.value?.alias?.routerId;
    if (!id) {
        return;
    }

    saveLeaseAlias(id, comment, (stored) => {
        draft.value = stored;
        editingAlias.value = false;
    });
}

function startAliasEdit() {
    draft.value = panel.value?.alias?.value || '';
    editingAlias.value = true;
}

function onPointerDown(event) {
    if (!preview.value || root.value?.contains(event.target)) {
        armedKey = null;

        return;
    }

    armedKey = preview.value.key ?? true;
}

function onClick(event) {
    if (armedKey === null) {
        return;
    }

    const key = armedKey;
    armedKey = null;

    if (root.value?.contains(event.target)) {
        return;
    }

    if ((preview.value?.key ?? true) === key) {
        close();
    }
}

function toneClass(tone) {
    return {
        ok: 'text-ok',
        warn: 'text-warn',
        danger: 'text-danger',
        cyan: 'text-cyan',
        mute: 'text-mute',
    }[tone] || 'text-paper';
}

function pillClass(tone) {
    return {
        ok: 'bg-ok/15 text-ok',
        warn: 'bg-warn/15 text-warn',
        danger: 'bg-danger/15 text-danger',
        cyan: 'bg-cyan/15 text-cyan',
        mute: 'bg-active text-mute',
    }[tone] || 'bg-active text-mist';
}

onMounted(() => {
    window.addEventListener('keydown', onKey);
    document.addEventListener('pointerdown', onPointerDown);
    document.addEventListener('click', onClick);
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    document.removeEventListener('pointerdown', onPointerDown);
    document.removeEventListener('click', onClick);
});
</script>

<template>
    <aside
        ref="root"
        class="h-full shrink-0 overflow-hidden transition-[width] duration-200 ease-out"
        :class="preview ? 'w-96 max-w-[85vw] border-l border-edge' : 'w-0 border-0'"
        @transitionend="onTransitionEnd"
    >
        <div v-if="panel" class="flex h-full w-96 max-w-[85vw] flex-col">
            <header class="relative flex h-12 shrink-0 items-center justify-center px-12">
                <h2 class="truncate text-sm font-medium">{{ panel.title }}</h2>
                <button type="button" class="absolute right-3 rounded-md p-1 text-mute hover:text-paper" v-tip="'Tutup'" @click="close">
                    <Icon class="text-[18px]" name="close" />
                </button>
            </header>

            <div v-if="tabs.length > 1" class="shrink-0 px-3 pb-3">
                <div class="flex rounded-xl bg-well p-1" role="tablist">
                    <button
                        v-for="tab in tabs"
                        :key="tab.id"
                        type="button"
                        role="tab"
                        :aria-selected="active?.id === tab.id"
                        class="flex h-9 flex-1 items-center justify-center rounded-lg transition-colors duration-150"
                        :class="active?.id === tab.id ? 'bg-panel text-cyan shadow-sm ring-1 ring-edge' : 'text-mute hover:bg-hover hover:text-paper'"
                        v-tip="tab.label"
                        @click="tabId = tab.id"
                    >
                        <Icon class="text-[19px]" :name="tab.icon" />
                    </button>
                </div>
            </div>

            <div v-if="active" class="min-h-0 flex-1 space-y-3 overflow-y-auto px-3 pb-6">
                <section v-if="active.hero" class="rounded-xl border border-edge bg-menu p-3">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-tile text-mist">
                            <Icon class="text-[22px]" :name="panel.icon || 'info'" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ panel.title }}</p>
                            <p v-if="panel.subtitle" class="truncate font-mono text-[11px] text-mist">{{ panel.subtitle }}</p>
                            <p v-if="panel.detail" class="truncate font-mono text-[11px] text-mute">{{ panel.detail }}</p>
                        </div>
                        <span
                            v-if="panel.status"
                            class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                            :class="pillClass(panel.status.tone)"
                        >
                            {{ panel.status.label }}
                        </span>
                    </div>
                </section>

                <section v-if="panel.alias && active?.id === 'overview'" class="rounded-xl border border-edge bg-menu px-3 py-3">
                    <p class="text-[11px] text-mute">Alias</p>
                    <div v-if="panel.alias.value && !editingAlias" class="group mt-1 flex items-center justify-between gap-2">
                        <p class="min-w-0 truncate text-sm font-medium">{{ panel.alias.value }}</p>
                        <button type="button" class="shrink-0 rounded p-0.5 text-mute opacity-0 transition-opacity group-hover:opacity-100 hover:text-paper" v-tip="'Edit'" @click="startAliasEdit">
                            <Icon class="text-[12px]" name="edit" />
                        </button>
                    </div>
                    <form v-else class="mt-2 flex items-center gap-1.5" @submit.prevent="submitAlias(draft)">
                        <label class="sr-only" for="lease-alias">Alias</label>
                        <input
                            id="lease-alias"
                            v-model="draft"
                            data-alias-input
                            type="text"
                            maxlength="128"
                            placeholder="Alias"
                            class="h-8 min-w-0 flex-1 rounded-lg bg-sink px-2.5 text-sm outline-none"
                        >
                        <button type="submit" class="h-8 shrink-0 rounded-lg bg-cyan-2 px-2.5 text-[12px] font-semibold text-[#003640]" :disabled="saving">Simpan</button>
                        <button v-if="panel.alias.value" type="button" class="h-8 shrink-0 px-1 text-[12px] text-mute hover:text-danger" :disabled="saving" @click="submitAlias('')">Hapus</button>
                    </form>
                    <p v-if="error" class="mt-1.5 text-[11px] text-danger">{{ error }}</p>
                </section>

                <section v-if="active.metrics?.length" class="grid gap-2 rounded-xl border border-edge bg-menu px-3 py-3" :class="active.metrics.length > 2 ? 'grid-cols-3' : 'grid-cols-2'">
                    <div v-for="metric in active.metrics" :key="metric.label" class="min-w-0">
                        <p class="truncate text-[11px] text-mute">{{ metric.label }}</p>
                        <p class="mt-1 truncate text-sm font-semibold" :class="toneClass(metric.tone)">{{ metric.value }}</p>
                    </div>
                </section>

                <section v-if="panel?.stream && active?.id === 'overview'" class="rounded-xl border border-edge bg-menu px-3 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-[11px] text-mute">Bandwidth</p>
                        <p class="font-mono text-[10px] text-mute">{{ live ? 'Live' : '5 menit' }}</p>
                    </div>
                    <div class="mt-2 flex items-end justify-between gap-3">
                        <p class="font-mono text-sm text-cyan">↓ RX <LiveRate :key="`${panel.key}-rx`" :value="panel.stream.rx" /></p>
                        <p class="font-mono text-sm text-ok">↑ TX <LiveRate :key="`${panel.key}-tx`" :value="panel.stream.tx" /></p>
                    </div>
                    <TrafficChart :key="panel.key" class="mt-2" :points="series" axis height-class="h-32" />
                    <p v-if="panel.stream.flows != null" class="mt-1 font-mono text-[10px] text-mute">{{ panel.stream.flows }} koneksi aktif</p>
                </section>

                <section v-if="active.meter" class="rounded-xl border border-edge bg-menu px-3 py-3">
                    <div class="flex items-center justify-between gap-3 text-[11px]">
                        <span class="text-cyan">RX {{ active.meter.rx }}</span>
                        <span class="text-ok">TX {{ active.meter.tx }}</span>
                    </div>
                    <div class="mt-2 flex h-1.5 overflow-hidden rounded-full bg-hover">
                        <div class="h-full bg-cyan" :style="{ width: active.meter.rxShare }" />
                        <div class="h-full bg-ok" :style="{ width: active.meter.txShare }" />
                    </div>
                </section>

                <section
                    v-for="(section, index) in active.sections || []"
                    :key="section.title || index"
                    class="overflow-hidden rounded-xl border border-edge bg-menu"
                >
                    <p v-if="section.title" class="px-3 pt-3 text-sm font-medium">{{ section.title }}</p>
                    <dl>
                        <div
                            v-for="row in section.rows"
                            :key="row.label"
                            class="flex items-start justify-between gap-4 px-3 py-2.5"
                        >
                            <dt class="text-[13px] text-mist">{{ row.label }}</dt>
                            <dd
                                class="max-w-[58%] text-right text-[13px] font-medium break-words"
                                :class="[toneClass(row.tone), row.mono === false ? 'font-sans' : 'font-mono']"
                            >
                                {{ row.value }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section v-if="active.body" class="rounded-xl border border-edge bg-menu px-3 py-3">
                    <p class="text-sm leading-relaxed break-words whitespace-pre-wrap">{{ active.body }}</p>
                </section>
            </div>
        </div>
    </aside>
</template>
