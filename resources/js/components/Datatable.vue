<script setup lang="ts">
import {
    computed,
    onBeforeUnmount,
    ref,
    watch,
} from 'vue';

import Button from 'primevue/button';
import Column from 'primevue/column';
import PrimeDataTable from 'primevue/datatable';
import InputText from 'primevue/inputtext';

import type {
    DataTablePageEvent,
    DataTableSortEvent,
} from 'primevue/datatable';

import type {
    DataTableAction,
    DataTableColumn,
    DataTableRow,
} from '@/types';

const props = withDefaults(
    defineProps<{
        data: DataTableRow[];
        columns: DataTableColumn[];
        actions?: DataTableAction[];
        dataKey?: string;
        title?: string;
        description?: string;
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
            {
                key: 'view',
                label: 'View',
                icon: 'pi pi-eye',
                severity: 'info',
            },
            {
                key: 'edit',
                label: 'Edit',
                icon: 'pi pi-pencil',
                severity: 'warn',
            },
            {
                key: 'delete',
                label: 'Delete',
                icon: 'pi pi-trash',
                severity: 'danger',
            },
        ],
        dataKey: 'id',
        title: '',
        description: '',
        loading: false,
        searchable: true,
        searchPlaceholder: 'Search...',
        emptyTitle: 'No records found',
        emptyDescription: 'Records will appear here.',
        emptyIcon: 'pi pi-inbox',
        paginator: true,
        rows: 10,
        rowsPerPageOptions: () => [10, 25, 50, 100],
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

    page: [
        event: {
            page: number;
            rows: number;
            first: number;
        },
    ];

    sort: [
        event: {
            sortField: string;
            sortOrder: number;
        },
    ];

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

            return String(value ?? '')
                .toLowerCase()
                .includes(keyword);
        }),
    );
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

function getNestedValue(
    row: DataTableRow,
    field: string,
): unknown {
    return field.split('.').reduce<unknown>((value, key) => {
        if (
            value !== null &&
            typeof value === 'object' &&
            key in value
        ) {
            return (value as Record<string, unknown>)[key];
        }

        return undefined;
    }, row);
}

function getCellValue(
    row: DataTableRow,
    column: DataTableColumn,
): string | number {
    const value = getNestedValue(row, column.field);

    if (column.format) {
        return column.format(value, row);
    }

    if (
        value === null ||
        value === undefined ||
        value === ''
    ) {
        return '—';
    }

    if (
        typeof value === 'string' ||
        typeof value === 'number'
    ) {
        return value;
    }

    return String(value);
}

/*
|--------------------------------------------------------------------------
| Action Helpers
|--------------------------------------------------------------------------
*/

function isActionVisible(
    action: DataTableAction,
    row: DataTableRow,
): boolean {
    return action.visible?.(row) ?? true;
}

function isActionDisabled(
    action: DataTableAction,
    row: DataTableRow,
): boolean {
    return action.disabled?.(row) ?? false;
}

function handleAction(
    action: DataTableAction,
    row: DataTableRow,
): void {
    emit('action', action.key, row);
}

/*
|--------------------------------------------------------------------------
| DataTable Events
|--------------------------------------------------------------------------
*/

function handlePage(event: DataTablePageEvent): void {
    emit('page', {
        page: event.page,
        rows: event.rows,
        first: event.first,
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
    <section
        class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"
    >
        <!-- TABLE HEADER -->
        <div
            v-if="
                title ||
                description ||
                searchable ||
                $slots.header ||
                $slots['header-actions']
            "
            class="flex flex-col gap-4 border-b border-slate-200 bg-white px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <!-- TITLE -->
            <div
                v-if="title || description || $slots.header"
                class="min-w-0"
            >
                <slot name="header">
                    <h2
                        v-if="title"
                        class="text-lg font-bold text-[#21365A]"
                    >
                        {{ title }}
                    </h2>

                    <p
                        v-if="description"
                        class="mt-1 text-sm text-slate-500"
                    >
                        {{ description }}
                    </p>
                </slot>
            </div>

            <!-- HEADER ACTIONS AND SEARCH -->
            <div
                class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row sm:items-center"
            >
                <slot name="header-actions" />

                <div
                    v-if="searchable"
                    class="relative w-full sm:w-72"
                >
                    <i
                        class="pi pi-search absolute top-1/2 left-3 z-10 -translate-y-1/2 text-sm text-slate-400"
                    />

                    <InputText
                        v-model="search"
                        :placeholder="searchPlaceholder"
                        class="h-10 w-full !rounded-lg !border-slate-300 !bg-white pl-9 text-sm !text-slate-900"
                    />
                </div>
            </div>
        </div>

        <!-- PRIMEVUE DATATABLE -->
        <PrimeDataTable
            :value="filteredData"
            :loading="loading"
            :data-key="dataKey"
            :lazy="lazy"
            :total-records="totalRecords"
            :first="first"
            :paginator="paginator"
            :rows="rows"
            :rows-per-page-options="rowsPerPageOptions"
            :scrollable="scrollable"
            :scroll-height="scrollHeight"
            :striped-rows="stripedRows"
            :show-gridlines="showGridlines"
            :removable-sort="removableSort"
            :resizable-columns="resizableColumns"
            column-resize-mode="fit"
            paginator-template="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink RowsPerPageDropdown CurrentPageReport"
            current-page-report-template="Showing {first} to {last} of {totalRecords} records"
            class="universal-datatable"
            :table-style="{
                minWidth: tableMinWidth,
            }"
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
                :style="{
                    minWidth: actionsWidth,
                }"
                header-class="text-center"
                body-class="text-center"
            >
                <template #body="{ data: row }">
                    <slot
                        name="actions"
                        :data="row"
                        :actions="actions"
                    >
                        <div
                            class="flex items-center justify-center gap-1"
                        >
                            <template
                                v-for="action in actions"
                                :key="action.key"
                            >
                                <Button
                                    v-if="isActionVisible(action, row)"
                                    type="button"
                                    :icon="action.icon"
                                    :severity="
                                        action.severity ?? 'secondary'
                                    "
                                    :disabled="
                                        isActionDisabled(action, row)
                                    "
                                    text
                                    rounded
                                    :aria-label="action.label"
                                    :title="action.label"
                                    @click.stop="
                                        handleAction(action, row)
                                    "
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
                        <i
                            :class="[
                                emptyIcon,
                                'text-4xl text-slate-300',
                            ]"
                        />

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
                <div
                    class="flex items-center justify-center gap-3 py-12 text-slate-500"
                >
                    <i class="pi pi-spin pi-spinner text-xl" />
                    <span>Loading records...</span>
                </div>
            </template>
        </PrimeDataTable>
    </section>
</template>

<style scoped>
:deep(.universal-datatable) {
    background: #ffffff;
    color: #334155;
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

:deep(.universal-datatable .p-paginator) {
    gap: 0.25rem;
    border-width: 1px 0 0;
    border-style: solid;
    border-color: #e2e8f0;
    background: #ffffff !important;
    padding: 0.75rem 1rem;
    color: #64748b;
}

:deep(.universal-datatable .p-paginator-page),
:deep(.universal-datatable .p-paginator-first),
:deep(.universal-datatable .p-paginator-prev),
:deep(.universal-datatable .p-paginator-next),
:deep(.universal-datatable .p-paginator-last) {
    min-width: 2rem;
    height: 2rem;
    border-radius: 0.5rem;
}

:deep(.universal-datatable .p-paginator-page-selected) {
    background: #377ec0 !important;
    color: #ffffff !important;
}

:deep(.universal-datatable .p-datatable-loading-overlay) {
    background: rgb(255 255 255 / 80%) !important;
}
</style>