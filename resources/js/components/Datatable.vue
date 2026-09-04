<script setup lang="ts">

import { computed, onBeforeUnmount, ref, watch } from 'vue';

import Button from 'primevue/button';

import Column from 'primevue/column';

import PrimeDataTable from 'primevue/datatable';

import InputText from 'primevue/inputtext';

import type { DataTableSortEvent } from 'primevue/datatable';

import type { DataTableAction, DataTableColumn, DataTableRow } from '@/types';

const props = withDefaults(

    defineProps<{

        data: DataTableRow[];

        columns: DataTableColumn[];

        actions?: DataTableAction[];

        dataKey?: string;

        title?: string;

        description?: string;

        headerIcon?: string;

        loading?: boolean;

        searchable?: boolean;

        searchPlaceholder?: string;

        emptyTitle?: string;

        emptyDescription?: string;

        emptyIcon?: string;

        paginator?: boolean;

        rows?: number;

        rowsPerPageOptions?: number[];

        scrollable?: boolean;

        scrollHeight?: string;

        tableMinWidth?: string;

        stripedRows?: boolean;

        showGridlines?: boolean;

        removableSort?: boolean;

        resizableColumns?: boolean;

        showActions?: boolean;

        actionsHeader?: string;

        actionsWidth?: string;

        /*

         * Server-side DataTable properties.

         */

        lazy?: boolean;

        totalRecords?: number;

        first?: number;

    }>(),

    {

        actions: () => [

            { key: 'view', label: 'View', icon: 'pi pi-eye', severity: 'info' },

            { key: 'edit', label: 'Edit', icon: 'pi pi-pencil', severity: 'warn' },

            { key: 'delete', label: 'Delete', icon: 'pi pi-trash', severity: 'danger' },

        ],

        dataKey: 'id',

        title: '',

        description: '',

        headerIcon: 'pi pi-table',

        loading: false,

        searchable: true,

        searchPlaceholder: 'Search...',

        emptyTitle: 'No records found',

        emptyDescription: 'Records will appear here.',

        emptyIcon: 'pi pi-inbox',

        paginator: true,

        rows: 10,

        rowsPerPageOptions: () => [10, 20, 50, 100],

        scrollable: true,

        scrollHeight: 'flex',

        tableMinWidth: '1200px',

        stripedRows: false,

        showGridlines: false,

        removableSort: true,

        resizableColumns: true,

        showActions: true,

        actionsHeader: 'Actions',

        actionsWidth: '150px',

        lazy: false,

        totalRecords: 0,

        first: 0,

    },

);

const emit = defineEmits<{

    action: [action: string, row: DataTableRow];

    rowClick: [row: DataTableRow];

    page: [event: { page: number; rows: number; first: number }];

    sort: [event: { sortField: string; sortOrder: number }];

    search: [value: string];

}>();

const search = ref('');

let searchTimeout: ReturnType<typeof setTimeout> | null = null;

/*

|--------------------------------------------------------------------------

| Search

|--------------------------------------------------------------------------

*/

const searchableFields = computed(() => {

    const explicitlySearchable = props.columns

        .filter((column) => column.searchable)

        .map((column) => column.field);

    if (explicitlySearchable.length > 0) {

        return explicitlySearchable;

    }

    return props.columns.map((column) => column.field);

});

const filteredData = computed(() => {

    /*

     * Laravel handles searching when lazy mode is active.

     */

    if (props.lazy) {

        return props.data;

    }

    const keyword = search.value.trim().toLowerCase();

    if (!keyword) {

        return props.data;

    }

    return props.data.filter((row) =>

        searchableFields.value.some((field) => {

            const value = getNestedValue(row, field);

            return String(value ?? '').toLowerCase().includes(keyword);

        }),

    );

});

const currentPage = computed(() =>

    props.rows > 0 ? Math.floor(props.first / props.rows) + 1 : 1,

);

const totalPages = computed(() =>

    props.rows > 0 ? Math.max(1, Math.ceil(props.totalRecords / props.rows)) : 1,

);

const firstRecord = computed(() =>

    props.totalRecords === 0 ? 0 : props.first + 1,

);

const lastRecord = computed(() =>

    Math.min(props.first + props.rows, props.totalRecords),

);

const visiblePages = computed(() => {

    const size = 5;

    let start = Math.max(1, currentPage.value - 2);

    const end = Math.min(totalPages.value, start + size - 1);

    start = Math.max(1, end - size + 1);

    return Array.from({ length: end - start + 1 }, (_, index) => start + index);

});

watch(search, (value) => {

    if (!props.lazy) {

        return;

    }

    if (searchTimeout) {

        clearTimeout(searchTimeout);

    }

    searchTimeout = setTimeout(() => {

        emit('search', value.trim());

    }, 400);

});

onBeforeUnmount(() => {

    if (searchTimeout) {

        clearTimeout(searchTimeout);

    }

});

/*

|--------------------------------------------------------------------------

| Cell Helpers

|--------------------------------------------------------------------------

*/

function getNestedValue(row: DataTableRow, field: string): unknown {

    return field.split('.').reduce<unknown>((value, key) => {

        if (value !== null && typeof value === 'object' && key in value) {

            return (value as Record<string, unknown>)[key];

        }

        return undefined;

    }, row);

}

function getCellValue(row: DataTableRow, column: DataTableColumn): string | number {

    const value = getNestedValue(row, column.field);

    if (column.format) {

        return column.format(value, row);

    }

    if (value === null || value === undefined || value === '') {

        return '—';

    }

    if (typeof value === 'string' || typeof value === 'number') {

        return value;

    }

    return String(value);

}

/*

|--------------------------------------------------------------------------

| Action Helpers

|--------------------------------------------------------------------------

*/

function isActionVisible(action: DataTableAction, row: DataTableRow): boolean {

    return action.visible?.(row) ?? true;

}

function isActionDisabled(action: DataTableAction, row: DataTableRow): boolean {

    return action.disabled?.(row) ?? false;

}

function handleAction(action: DataTableAction, row: DataTableRow): void {

    emit('action', action.key, row);

}

/*

|--------------------------------------------------------------------------

| DataTable Events

|--------------------------------------------------------------------------

*/

function handlePage(event: { page: number; rows: number; first: number }): void {

    emit('page', {

        page: event.page,

        rows: event.rows,

        first: event.first,

    });

}

function goToPage(pageNumber: number): void {

    const page = Math.min(Math.max(pageNumber, 1), totalPages.value);

    emit('page', {

        page: page - 1,

        rows: props.rows,

        first: (page - 1) * props.rows,

    });

}

function changeRows(event: Event): void {

    const rows = Number((event.target as HTMLSelectElement).value);

    if (!props.rowsPerPageOptions.includes(rows)) {

        return;

    }

    emit('page', {

        page: 0,

        rows,

        first: 0,

    });

}

function handleSort(event: DataTableSortEvent): void {

    /*

     * PrimeVue permits sortField to be a callback.

     * The Laravel endpoint requires a string column name.

     */

    if (typeof event.sortField !== 'string') {

        return;

    }

    emit('sort', {

        sortField: event.sortField,

        sortOrder: event.sortOrder === -1 ? -1 : 1,

    });

}

</script>

<template>

    <section class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">

        <!-- TABLE HEADER -->

        <div

            v-if="title || description || searchable || $slots.header || $slots['header-actions']"

            class="flex flex-col gap-4 border-b border-slate-200 bg-white px-5 py-4 sm:flex-row sm:items-center sm:justify-between"

        >

            <!-- TITLE -->

                <div

                    v-if="title || description || $slots.header"

                    class="flex min-w-0 items-center gap-3"

                >

                    <div

                        v-if="headerIcon"

                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-blue-500 text-white"

                    >

                        <i :class="[headerIcon, 'text-2xl']"></i>

                    </div>

                    <div class="min-w-0">

                        <slot name="header">

                            <h2 v-if="title" class="text-lg font-bold text-[#21365A]">

                                {{ title }}

                            </h2>

                            <p v-if="description" class="mt-1 text-sm text-slate-500">

                                {{ description }}

                            </p>

                        </slot>

                    </div>

                </div>

            <!-- HEADER ACTIONS AND SEARCH -->

            <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row sm:items-center">

                <slot name="header-actions" />

                    <div v-if="searchable" class="relative w-full sm:w-72">

                        <i

                            class="pi pi-search search-icon absolute top-1/2 left-3 z-10 -translate-y-1/2 text-sm text-slate-400"

                        ></i>

                        <InputText

                            v-model="search"

                            :placeholder="searchPlaceholder"

                            class="search-input h-10 w-full !rounded-lg !border-slate-300 !bg-white !pr-10 text-sm !text-slate-900"

                        />

                        <button

                            v-if="search"

                            type="button"

                            class="absolute top-1/2 right-3 z-10 -translate-y-1/2 text-slate-400 transition-colors hover:text-slate-700"

                            aria-label="Clear search"

                            title="Clear search"

                            @click="search = ''"

                        >

                            <i class="pi pi-times text-sm"></i>

                        </button>

                    </div>

            </div>

        </div>

        <!-- SCROLLABLE TABLE REGION -->

        <div class="min-h-0 flex-1">

            <PrimeDataTable

                :value="filteredData"

                :loading="loading"

                :data-key="dataKey"

                :lazy="lazy"

                :paginator="false"

                :rows="rows"

                :scrollable="scrollable"

                :scroll-height="scrollHeight"

                :striped-rows="stripedRows"

                :show-gridlines="showGridlines"

                :removable-sort="removableSort"

                :resizable-columns="resizableColumns"

                column-resize-mode="fit"

                class="universal-datatable"

                :table-style="{ minWidth: tableMinWidth }"

                @page="handlePage"

                @sort="handleSort"

                @row-click="emit('rowClick', $event.data)"

            >

                <!-- DYNAMIC COLUMNS -->

                <Column

                    v-for="column in columns"

                    :key="column.field"

                    :field="column.field"

                    :header="column.header"

                    :sortable="column.sortable"

                    :frozen="column.frozen"

                    :align-frozen="column.alignFrozen"

                    :class="column.class"

                    :header-class="column.headerClass"

                    :body-class="column.bodyClass"

                >

                    <template #body="{ data: row }">

                        <slot

                            :name="`cell-${column.field}`"

                            :data="row"

                            :value="getNestedValue(row, column.field)"

                            :column="column"

                        >

                            {{ getCellValue(row, column) }}

                        </slot>

                    </template>

                </Column>

                <!-- ACTIONS COLUMN -->

                <Column

                    v-if="showActions"

                    :header="actionsHeader"

                    frozen

                    align-frozen="right"

                    :style="{ minWidth: actionsWidth }"

                    header-class="text-center"

                    body-class="text-center"

                >

                    <template #body="{ data: row }">

                        <slot name="actions" :data="row" :actions="actions">

                            <div class="flex items-center justify-center gap-1">

                                <template v-for="action in actions" :key="action.key">

                                    <Button

                                        v-if="isActionVisible(action, row)"

                                        type="button"

                                        :icon="action.icon"

                                        :severity="action.severity ?? 'secondary'"

                                        :disabled="isActionDisabled(action, row)"

                                        text

                                        rounded

                                        :aria-label="action.label"

                                        :title="action.label"

                                        @click.stop="handleAction(action, row)"

                                    />

                                </template>

                            </div>

                        </slot>

                    </template>

                </Column>

                <!-- EMPTY STATE -->

                <template #empty>

                    <slot name="empty">

                        <div class="py-12 text-center">

                            <i :class="[emptyIcon, 'text-4xl text-slate-300']"></i>

                            <p class="mt-3 font-semibold text-slate-600">

                                {{ emptyTitle }}

                            </p>

                            <p class="mt-1 text-sm text-slate-400">

                                {{ emptyDescription }}

                            </p>

                        </div>

                    </slot>

                </template>

                <!-- LOADING STATE -->

                <template #loading>

                    <div class="flex items-center justify-center gap-3 py-12 text-slate-500">

                        <i class="pi pi-spin pi-spinner text-xl"></i>

                        <span>Loading records...</span>

                    </div>

                </template>

            </PrimeDataTable>

        </div>

<!-- PAGINATOR (fixed sibling, always visible — not inside the scroll region) -->

<div v-if="paginator" class="responsive-paginator">

    <div class="responsive-paginator__summary">

        Showing {{ firstRecord }} to {{ lastRecord }} of {{ totalRecords }} records

    </div>

    <div class="responsive-paginator__pagination">

        <button

            type="button"

            class="responsive-paginator__button"

            :disabled="currentPage === 1"

            aria-label="First page"

            @click="goToPage(1)"

        >

            <i class="pi pi-angle-double-left"></i>

        </button>

        <button

            type="button"

            class="responsive-paginator__button"

            :disabled="currentPage === 1"

            aria-label="Previous page"

            @click="goToPage(currentPage - 1)"

        >

            <i class="pi pi-angle-left"></i>

        </button>

        <button

            v-for="pageNumber in visiblePages"

            :key="pageNumber"

            type="button"

            class="responsive-paginator__button"

            :class="{ 'responsive-paginator__button--active': pageNumber === currentPage }"

            @click="goToPage(pageNumber)"

        >

            {{ pageNumber }}

        </button>

        <button

            type="button"

            class="responsive-paginator__button"

            :disabled="currentPage === totalPages"

            aria-label="Next page"

            @click="goToPage(currentPage + 1)"

        >

            <i class="pi pi-angle-right"></i>

        </button>

        <button

            type="button"

            class="responsive-paginator__button"

            :disabled="currentPage === totalPages"

            aria-label="Last page"

            @click="goToPage(totalPages)"

        >

            <i class="pi pi-angle-double-right"></i>

        </button>

    </div>

    <label class="responsive-paginator__rows">

        <span>Rows</span>

        <select

            :value="rows"

            class="responsive-paginator__select"

            aria-label="Rows per page"

            @change="changeRows"

        >

            <option v-for="option in rowsPerPageOptions" :key="option" :value="option">

                {{ option }}

            </option>

        </select>

    </label>

</div>

    </section>

</template>

<style scoped>

:deep(.universal-datatable) {

    background: #ffffff;

    color: #334155;

    }

    .search-icon {

    pointer-events: none;

    }

    :deep(.search-input) {

        padding-left: 2.25rem !important;

    }

:deep(.universal-datatable .p-datatable-thead > tr > th),

:deep(.universal-datatable .p-datatable-header-cell) {

    padding: 0.9rem 1rem;

    border-width: 0 0 1px;

    border-style: solid;

    border-color: #dbe3ec;

    background: #ffffff !important;

    color: #334155 !important;

    font-size: 0.875rem;

    font-weight: 700;

    text-align: left;

    white-space: normal;

}

:deep(.universal-datatable .p-datatable-column-title) {

    line-height: 1.3;

}

:deep(.universal-datatable .p-datatable-sort-icon) {

    width: 0.8rem;

    height: 0.8rem;

    color: #94a3b8;

}

:deep(.universal-datatable .p-datatable-tbody > tr) {

    background: #ffffff !important;

    color: #334155;

}

:deep(.universal-datatable .p-datatable-tbody > tr > td) {

    padding: 0.8rem 1rem;

    border-width: 0 0 1px;

    border-style: solid;

    border-color: #e2e8f0;

    background: #ffffff !important;

    color: #334155 !important;

    font-size: 0.875rem;

    vertical-align: middle;

}

:deep(.universal-datatable .p-datatable-tbody > tr:hover > td) {

    background: #f8fafc !important;

}

:deep(.universal-datatable .p-datatable-frozen-column) {

    background: #ffffff !important;

}

:deep(.universal-datatable .p-datatable-header) {

    border: 0;

    background: #ffffff;

}

:deep(.universal-datatable table) {

    border-collapse: collapse;

}

:deep(.universal-datatable .p-datatable-table-container) {

    border: 0;

    box-shadow: none;

}

:deep(.universal-datatable .p-datatable-loading-overlay) {

    background: rgb(255 255 255 / 80%) !important;

}

.responsive-paginator {

    display: flex;

    width: 100%;

    min-height: 4rem;

    flex-shrink: 0;

    flex-wrap: wrap;

    align-items: center;

    justify-content: space-between;

    gap: 0.75rem;

    border-top: 1px solid #e2e8f0;

    background: #ffffff;

    padding: 0.75rem 1rem;

    color: #64748b;

}

.responsive-paginator__summary {

    flex: 1 1 0;

    font-size: 0.875rem;

    font-weight: 500;

}

.responsive-paginator__pagination {

    display: flex;

    flex: 1 1 auto;

    flex-wrap: wrap;

    align-items: center;

    justify-content: center;

    gap: 0.375rem;

}

.responsive-paginator__rows {

    display: flex;

    flex: 1 1 0;

    align-items: center;

    justify-content: flex-end;

    gap: 0.375rem;

    font-size: 0.875rem;

    font-weight: 600;

}

.responsive-paginator__select {

    height: 2rem;

    border: 1px solid #cbd5e1;

    border-radius: 0.5rem;

    background: #ffffff;

    padding: 0 0.5rem;

    color: #334155;

}

.responsive-paginator__button {

    min-width: 2rem;

    height: 2rem;

    border: 1px solid #e2e8f0;

    border-radius: 0.5rem;

    background: #ffffff;

    color: #475569;

    font-size: 0.875rem;

    font-weight: 600;

    transition: 150ms ease;

}

.responsive-paginator__button:hover:not(:disabled) {

    border-color: #377ec0;

    color: #377ec0;

}

.responsive-paginator__button:disabled {

    cursor: not-allowed;

    opacity: 0.4;

}

.responsive-paginator__button--active {

    border-color: #377ec0;

    background: #377ec0;

    color: #ffffff;

}

@media (max-width: 640px) {

    .responsive-paginator {

        align-items: stretch;

        flex-direction: column;

    }

    .responsive-paginator__summary {

        order: 1;

        text-align: center;

    }

    .responsive-paginator__pagination {

        order: 2;

    }

    .responsive-paginator__rows {

        order: 3;

        justify-content: center;

    }

}

</style>
