<script setup>
import PagedTable from './PagedTable.vue';
import SortHead from './SortHead.vue';

const props = defineProps({
    title: { type: String, default: '' },
    rows: { type: Array, default: () => [] },
    columns: { type: Array, required: true },
    rowKey: { type: Function, required: true },
    defaultSort: { type: String, required: true },
    placeholder: { type: String, default: 'Cari di tabel...' },
    empty: { type: String, default: 'Tidak ada data.' },
    minWidth: { type: String, default: 'min-w-[40rem]' },
    clickable: { type: Boolean, default: false },
    isActive: { type: Function, default: null },
});

const emit = defineEmits(['open']);

const searchText = (row) => props.columns.map((column) => (column.show ? column.show(row) : column.get(row))).join(' ');
const labelOf = (row) => String(props.columns[0]?.get(row) ?? '');
const show = (value) => (value === null || value === undefined || value === '' ? '–' : String(value));

function cellText(column, row) {
    return show(column.show ? column.show(row) : column.get(row));
}

function choose(row) {
    if (props.clickable) {
        emit('open', row);
    }
}
</script>

<template>
    <section class="noc-card overflow-x-auto">
        <div v-if="title" class="flex items-center justify-between gap-2 px-3 pt-3">
            <p class="text-[15px] font-semibold">{{ title }}</p>
            <span class="font-mono text-[11px] text-mute">{{ rows.length }}</span>
        </div>
        <PagedTable :rows="rows" :text="searchText" :label="labelOf" :columns="columns" :default-sort="defaultSort" :placeholder="placeholder">
            <template #default="{ rows: pageRows, sortOf }">
                <table class="w-full text-left text-sm" :class="minWidth">
                    <thead class="font-mono text-[10px] tracking-wide text-mute uppercase">
                        <tr>
                            <th v-for="column in columns" :key="column.id" class="group p-0 font-medium">
                                <SortHead :active="sortOf(column.id).active" :icon="sortOf(column.id).icon" @sort="sortOf(column.id).toggle()">
                                    {{ column.label }}
                                </SortHead>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(row, index) in pageRows"
                            :key="rowKey(row, index)"
                            :tabindex="clickable ? 0 : undefined"
                            class="border-t border-line"
                            :class="[clickable ? 'cursor-pointer hover:bg-hover' : '', isActive?.(row) ? 'bg-active' : '']"
                            @click="choose(row)"
                            @keydown.enter.prevent="choose(row)"
                        >
                            <td v-for="column in columns" :key="column.id" class="px-3 py-2" :class="column.class">
                                <slot :name="column.id" :row="row">{{ cellText(column, row) }}</slot>
                            </td>
                        </tr>
                        <tr v-if="pageRows.length === 0">
                            <td :colspan="columns.length" class="px-3 py-8 text-center font-mono text-[11px] text-mute">{{ empty }}</td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </PagedTable>
    </section>
</template>
