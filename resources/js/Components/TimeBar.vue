<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import MenuPanel from './MenuPanel.vue';
import { live, pinned, rangeMinutes, ranges, refreshId, refreshes, useBoardTime } from '../composables/useBoardTime';

const { selectRange, selectRefresh, setLive, shiftWindow, zoomOut, refreshNow } = useBoardTime();
const root = ref(null);
const rangeOpen = ref(false);
const refreshOpen = ref(false);
const query = ref('');

const timezone = computed(() => {
    const offset = -new Date().getTimezoneOffset();
    const sign = offset >= 0 ? '+' : '-';
    const abs = Math.abs(offset);
    const hours = String(Math.floor(abs / 60)).padStart(2, '0');
    const mins = String(abs % 60).padStart(2, '0');

    return `UTC${sign}${hours}:${mins}`;
});

const rangeLabel = computed(() => {
    if (pinned.value) {
        const format = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false });

        return `${format.format(pinned.value.from * 1000)} – ${format.format(pinned.value.to * 1000)}`;
    }

    return ranges.find((item) => item.minutes === rangeMinutes.value)?.label ?? 'Last 30 minutes';
});

const refreshLabel = computed(() => refreshes.find((item) => item.id === refreshId.value)?.label ?? 'Auto');

const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();
    if (!needle) {
        return ranges;
    }

    return ranges.filter((item) => item.label.toLowerCase().includes(needle));
});

const canZoomOut = computed(() => {
    const span = pinned.value
        ? Math.round((pinned.value.to - pinned.value.from) / 60)
        : rangeMinutes.value;

    return ranges.some((item) => item.minutes > span);
});

function toggleRange() {
    refreshOpen.value = false;
    rangeOpen.value = !rangeOpen.value;
    query.value = '';
}

function toggleRefresh() {
    rangeOpen.value = false;
    refreshOpen.value = !refreshOpen.value;
}

function chooseRange(minutes) {
    rangeOpen.value = false;
    selectRange(minutes);
}

function chooseRefresh(id) {
    refreshOpen.value = false;
    selectRefresh(id);
}

function toggleLive() {
    rangeOpen.value = false;
    refreshOpen.value = false;
    setLive(!live.value);
}

function onPointerDown(event) {
    if (!root.value?.contains(event.target)) {
        rangeOpen.value = false;
        refreshOpen.value = false;
    }
}

function onKey(event) {
    if (event.key === 'Escape') {
        rangeOpen.value = false;
        refreshOpen.value = false;
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
    <div ref="root" class="flex items-center gap-1">
        <div class="relative" :class="live ? 'opacity-40' : ''">
        <div class="flex items-center rounded-full bg-well p-0.5">
            <button type="button" class="hidden rounded-full p-1 text-mist hover:bg-hover hover:text-paper disabled:opacity-30 lg:inline-flex" v-tip="'Mundur satu rentang'" :disabled="live" @click="shiftWindow(-1)">
                <Icon class="text-[16px]" name="chevron_left" />
            </button>
            <button type="button" class="flex max-w-44 items-center gap-1 rounded-full px-2 py-1 text-mist hover:bg-hover hover:text-paper disabled:hover:bg-transparent" :aria-expanded="rangeOpen" :disabled="live" v-tip="live ? 'Live mengabaikan rentang waktu' : 'Rentang waktu'" @click="toggleRange">
                <Icon class="text-[15px]" name="schedule" />
                <span class="truncate font-mono text-[11px]">{{ rangeLabel }}</span>
                <Icon class="text-[16px] transition-transform duration-200" :class="rangeOpen ? 'rotate-180' : ''" name="expand_more" />
            </button>
            <button type="button" class="hidden rounded-full p-1 text-mist hover:bg-hover hover:text-paper disabled:opacity-30 lg:inline-flex" v-tip="'Maju satu rentang'" :disabled="live || !pinned" @click="shiftWindow(1)">
                <Icon class="text-[16px]" name="chevron_right" />
            </button>
            <button type="button" class="hidden rounded-full p-1 text-mist hover:bg-hover hover:text-paper disabled:opacity-30 lg:inline-flex" v-tip="'Perlebar rentang'" :disabled="live || !canZoomOut" @click="zoomOut">
                <Icon class="text-[16px]" name="zoom_out" />
            </button>
        </div>

        <MenuPanel :open="rangeOpen" class="absolute top-full right-0 z-40 mt-1 w-64 origin-top-right overflow-hidden rounded-lg border border-edge bg-menu shadow-xl">
            <label class="flex items-center gap-2 border-b border-edge px-3 py-2">
                <Icon class="text-[16px] text-mute" name="search" />
                <input v-model="query" type="search" placeholder="Search quick ranges" class="w-full bg-transparent font-mono text-xs text-paper outline-none placeholder:text-mute">
            </label>
            <div class="max-h-72 overflow-y-auto py-1">
                <button
                    v-for="item in filtered"
                    :key="item.minutes"
                    type="button"
                    class="block w-full border-l-2 px-3 py-2 text-left text-sm hover:bg-hover"
                    :class="!pinned && item.minutes === rangeMinutes ? 'border-cyan bg-hover text-paper' : 'border-transparent text-mist'"
                    @click="chooseRange(item.minutes)"
                >
                    {{ item.label }}
                </button>
                <p v-if="!filtered.length" class="px-3 py-2 font-mono text-[11px] text-mute">Tidak ada rentang.</p>
            </div>
            <p class="border-t border-edge px-3 py-2 font-mono text-[11px] text-mute">{{ timezone }}</p>
        </MenuPanel>
        </div>

        <button
            type="button"
            role="switch"
            :aria-checked="live"
            class="flex h-9 items-center gap-1.5 rounded-full bg-well p-0.5 pl-2"
            v-tip="'Live mengabaikan interval'"
            @click="toggleLive"
        >
            <span class="font-mono text-[11px] leading-none" :class="live ? 'text-ok' : 'text-mute'">Live</span>
            <span class="relative h-5 w-9 rounded-full transition" :class="live ? 'bg-ok' : 'bg-tile'">
                <span class="absolute top-0.5 size-4 rounded-full bg-white shadow-sm ring-1 ring-black/10 transition-all" :class="live ? 'left-[18px]' : 'left-0.5'" />
            </span>
        </button>

        <div class="relative" :class="live ? 'opacity-40' : ''">
        <div class="flex items-center rounded-full bg-well p-0.5">
            <button type="button" class="inline-flex items-center rounded-full p-1 text-mist hover:bg-hover hover:text-paper disabled:hover:bg-transparent" v-tip="'Refresh'" :disabled="live" @click="refreshNow">
                <Icon class="text-[16px]" name="refresh" />
            </button>
            <button type="button" class="flex items-center gap-0.5 rounded-full px-1.5 py-1 font-mono text-[11px] text-mist hover:bg-hover hover:text-paper disabled:hover:bg-transparent" :aria-expanded="refreshOpen" :disabled="live" @click="toggleRefresh">
                {{ refreshLabel }}
                <Icon class="text-[16px] transition-transform duration-200" :class="refreshOpen ? 'rotate-180' : ''" name="expand_more" />
            </button>
        </div>

        <MenuPanel :open="refreshOpen" class="absolute top-full right-0 z-40 mt-1 w-36 origin-top-right overflow-hidden rounded-lg border border-edge bg-menu py-1 shadow-xl">
            <button
                v-for="item in refreshes"
                :key="item.id"
                type="button"
                class="block w-full px-3 py-1.5 text-left font-mono text-sm hover:bg-hover"
                :class="item.id === refreshId ? 'bg-active text-paper' : 'text-mist'"
                @click="chooseRefresh(item.id)"
            >
                {{ item.label }}
            </button>
        </MenuPanel>
        </div>
    </div>
</template>
