<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    text: { type: Function, required: true },
    label: { type: Function, required: true },
    time: { type: Function, default: null },
    value: { type: Function, default: null },
    extraSorts: { type: Array, default: () => [] },
    columns: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Cari di tabel...' },
    defaultSort: { type: String, default: 'latest' },
});

const search = ref('');
const sort = ref(props.defaultSort);
const sortDir = ref('desc');
const pageSize = ref(100);
const page = ref(1);
const sizes = [5, 20, 50, 100];
const sorts = computed(() => [
    { id: 'az', label: 'A–Z' },
    { id: 'za', label: 'Z–A' },
    { id: 'latest', label: 'Terbaru' },
    { id: 'oldest', label: 'Terlama' },
    ...props.extraSorts,
]);

const prepared = computed(() => {
    const needle = search.value.trim().toLowerCase();
    const items = props.rows.map((row, index) => ({ row, index })).filter((item) => {
        if (!needle) {
            return true;
        }

        return String(props.text(item.row) ?? '').toLowerCase().includes(needle);
    });

    items.sort((left, right) => {
        const column = props.columns.find((item) => item.id === sort.value);
        if (column?.get) {
            const leftValue = column.get(left.row);
            const rightValue = column.get(right.row);
            let delta = 0;
            if (column.type === 'number') {
                const leftNumber = Number(leftValue);
                const rightNumber = Number(rightValue);
                const leftMissing = !Number.isFinite(leftNumber);
                const rightMissing = !Number.isFinite(rightNumber);
                if (leftMissing || rightMissing) {
                    if (leftMissing && rightMissing) {
                        return 0;
                    }

                    return leftMissing ? 1 : -1;
                }

                delta = leftNumber - rightNumber;
            } else {
                delta = String(leftValue ?? '').localeCompare(String(rightValue ?? ''), 'en', { numeric: true, sensitivity: 'base' });
            }

            return sortDir.value === 'asc' ? delta : -delta;
        }

        const ranked = props.extraSorts.find((option) => option.id === sort.value);
        const read = ranked?.value || props.value;
        if (ranked && read) {
            const delta = (Number(read(left.row)) || 0) - (Number(read(right.row)) || 0);

            return ranked.direction === 'asc' ? delta : -delta;
        }

        if (sort.value === 'latest' || sort.value === 'oldest') {
            const leftTime = props.time ? props.time(left.row) : -left.index;
            const rightTime = props.time ? props.time(right.row) : -right.index;
            const delta = leftTime - rightTime;

            return sort.value === 'latest' ? -delta : delta;
        }

        const delta = String(props.label(left.row) ?? '').localeCompare(String(props.label(right.row) ?? ''), 'en', {
            numeric: true,
            sensitivity: 'base',
        });

        return sort.value === 'za' ? -delta : delta;
    });

    return items.map((item) => item.row);
});

const pageCount = computed(() => Math.max(1, Math.ceil(prepared.value.length / pageSize.value)));
const currentPage = computed(() => Math.min(page.value, pageCount.value));
const visibleRows = computed(() => {
    const start = (currentPage.value - 1) * pageSize.value;

    return prepared.value.slice(start, start + pageSize.value);
});

watch([search, sort, sortDir, pageSize], () => {
    page.value = 1;
});

function shift(step) {
    page.value = Math.min(pageCount.value, Math.max(1, currentPage.value + step));
}

function sortOf(id) {
    const column = props.columns.find((item) => item.id === id);
    if (!column) {
        return { active: false, icon: 'arrow_downward', toggle() {} };
    }

    const active = sort.value === column.id;
    const descending = active ? sortDir.value === 'desc' : column.first !== 'asc';

    return {
        active,
        icon: descending ? 'arrow_downward' : 'arrow_upward',
        toggle() {
            if (!active) {
                sort.value = column.id;
                sortDir.value = column.first === 'asc' ? 'asc' : 'desc';

                return;
            }

            sortDir.value = sortDir.value === 'desc' ? 'asc' : 'desc';
        },
    };
}
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center gap-2 px-3 py-2">
            <label class="relative min-w-48 flex-1">
                <span class="material-symbols-outlined pointer-events-none absolute top-1.5 left-2 text-[16px] text-mute">search</span>
                <input
                    v-model="search"
                    type="search"
                    :placeholder="placeholder"
                    class="h-8 w-full rounded-full bg-sink pr-3 pl-8 font-mono text-[11px] text-paper outline-none placeholder:text-mute focus:bg-well"
                >
            </label>
            <div v-if="columns.length === 0" class="flex gap-1 rounded-full bg-well p-1">
                <button
                    v-for="option in sorts"
                    :key="option.id"
                    type="button"
                    class="rounded-full px-2.5 py-1 font-mono text-[11px]"
                    :class="sort === option.id ? 'bg-cyan-2 font-semibold text-[#003640]' : 'text-mist'"
                    v-tip="option.title || ''"
                    @click="sort = option.id"
                >
                    {{ option.label }}
                </button>
            </div>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <div class="flex gap-1 rounded-full bg-well p-1">
                    <button
                        v-for="size in sizes"
                        :key="size"
                        type="button"
                        class="rounded-full px-2.5 py-1 font-mono text-[11px]"
                        :class="pageSize === size ? 'bg-cyan-2 font-semibold text-[#003640]' : 'text-mist'"
                        @click="pageSize = size"
                    >
                        {{ size }}
                    </button>
                </div>
                <button type="button" class="p-1 text-mist disabled:opacity-30" :disabled="currentPage <= 1" @click="shift(-1)">
                    <span class="material-symbols-outlined text-[18px]">chevron_left</span>
                </button>
                <span class="font-mono text-[11px] text-mist">{{ currentPage }} / {{ pageCount }}</span>
                <button type="button" class="p-1 text-mist disabled:opacity-30" :disabled="currentPage >= pageCount" @click="shift(1)">
                    <span class="material-symbols-outlined text-[18px]">chevron_right</span>
                </button>
                <span class="font-mono text-[11px] text-mute">{{ prepared.length }}</span>
            </div>
        </div>
        <slot :rows="visibleRows" :sort-of="sortOf" />
    </div>
</template>
