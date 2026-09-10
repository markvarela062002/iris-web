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
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import Paginator from 'primevue/paginator';
import ProgressSpinner from 'primevue/progressspinner';
import Select from 'primevue/select';

import type { DataTableSortEvent } from 'primevue/datatable';

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
        headerIcon: 'pi pi-table',

        loading: false,

        searchable: true,
        searchPlaceholder: 'Search...',

        emptyTitle: 'No records found',
        emptyDescription: 'Records will appear here.',
        emptyIcon: 'pi pi-inbox',

        paginator: true,
        rows: 10,
        rowsPerPageOptions: () => [
            10,
            20,
            50,
            100,
        ],

        scrollable: true,
        scrollHeight: 'flex',
        tableMinWidth: '1200px',

        stripedRows: false,
        showGridlines: false,
        removableSort: true,
        resizableColumns: true,

        showActions: true,
        actionsHeader: 'Actions',
        actionsWidth: '170px',

        lazy: false,
        totalRecords: 0,
        first: 0,
    },
);

const emit = defineEmits<{
    action: [
        action: string,
        row: DataTableRow,
    ];

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

    search: [
        value: string,
    ];
}>();

const search = ref('');

let searchTimeout:
    | ReturnType<typeof setTimeout>
    | null = null;

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

const searchableFields = computed(() => {
    const explicitlySearchable = props.columns
        .filter((column) => {
            return column.searchable;
        })
        .map((column) => {
            return column.field;
        });

    if (explicitlySearchable.length > 0) {
        return explicitlySearchable;
    }

    return props.columns.map((column) => {
        return column.field;
    });
});

const filteredData = computed(() => {
    /*
     * Laravel handles searching when lazy mode is enabled.
     */
    if (props.lazy) {
        return props.data;
    }

    const keyword = search.value
        .trim()
        .toLowerCase();

    if (!keyword) {
        return props.data;
    }

    return props.data.filter((row) => {
        return searchableFields.value.some(
            (field) => {
                const value = getNestedValue(
                    row,
                    field,
                );

                return String(value ?? '')
                    .toLowerCase()
                    .includes(keyword);
            },
        );
    });
});

const displayedData = computed(() => {
    if (
        props.lazy ||
        !props.paginator
    ) {
        return filteredData.value;
    }

    return filteredData.value.slice(
        props.first,
        props.first + props.rows,
    );
});

const effectiveTotalRecords = computed(() => {
    if (props.lazy) {
        return props.totalRecords;
    }

    return filteredData.value.length;
});

watch(search, (value) => {
    /*
     * Reset client-side pagination after searching.
     */
    if (!props.lazy) {
        if (props.first !== 0) {
            emit('page', {
                page: 0,
                rows: props.rows,
                first: 0,
            });
        }

        return;
    }

    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }

    searchTimeout = setTimeout(() => {
        emit('search', value.trim());
    }, 400);
});

function clearSearch(): void {
    search.value = '';
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

const firstRecord = computed(() => {
    if (effectiveTotalRecords.value === 0) {
        return 0;
    }

    return props.first + 1;
});

const lastRecord = computed(() => {
    return Math.min(
        props.first + props.rows,
        effectiveTotalRecords.value,
    );
});

function handlePage(event: {
    page: number;
    rows: number;
    first: number;
}): void {
    emit('page', {
        page: event.page,
        rows: event.rows,
        first: event.first,
    });
}

function changeRows(
    value: number | null,
): void {
    if (
        value === null ||
        !props.rowsPerPageOptions.includes(value)
    ) {
        return;
    }

    emit('page', {
        page: 0,
        rows: value,
        first: 0,
    });
}

/*
|--------------------------------------------------------------------------
| Cell helpers
|--------------------------------------------------------------------------
*/

function getNestedValue(
    row: DataTableRow,
    field: string,
): unknown {
    return field
        .split('.')
        .reduce<unknown>(
            (value, key) => {
                if (
                    value !== null &&
                    typeof value === 'object' &&
                    key in value
                ) {
                    return (
                        value as Record<
                            string,
                            unknown
                        >
                    )[key];
                }

                return undefined;
            },
            row,
        );
}

function getCellValue(
    row: DataTableRow,
    column: DataTableColumn,
): string | number {
    const value = getNestedValue(
        row,
        column.field,
    );

    if (column.format) {
        return column.format(
            value,
            row,
        );
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
| Action helpers
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
    emit(
        'action',
        action.key,
        row,
    );
}

/*
|--------------------------------------------------------------------------
| Sorting
|--------------------------------------------------------------------------
*/

function handleSort(
    event: DataTableSortEvent,
): void {
    /*
     * PrimeVue permits sortField to be a callback.
     * Laravel requires a string column name.
     */
    if (typeof event.sortField !== 'string') {
        return;
    }

    emit('sort', {
        sortField: event.sortField,
        sortOrder:
            event.sortOrder === -1
                ? -1
                : 1,
    });
}

/*
|--------------------------------------------------------------------------
| Lifecycle
|--------------------------------------------------------------------------
*/

onBeforeUnmount(() => {
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }
});
</script>

<template>
    <section
        class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"
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
                v-if="
                    title ||
                    description ||
                    $slots.header
                "
                class="flex min-w-0 items-center gap-4"
            >
                <div
                    v-if="headerIcon"
                    class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-blue-500 shadow-md shadow-blue-500/20"
                >
                    <i
                        :class="[
                            headerIcon,
                            '!text-[2rem] !font-bold !leading-none !text-white',
                        ]"
                    ></i>
                </div>

                <div class="min-w-0">
                    <slot name="header">
                        <h2
                            v-if="title"
                            class="truncate !text-xl !font-bold !leading-tight !text-[#21365A]"
                        >
                            {{ title }}
                        </h2>

                        <p
                            v-if="description"
                            class="mt-1 !text-sm !leading-relaxed !text-slate-500"
                        >
                            {{ description }}
                        </p>
                    </slot>
                </div>
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
                    <IconField>
                        <InputIcon
                            class="pi pi-search"
                        />

                        <InputText
                            v-model="search"
                            :placeholder="searchPlaceholder"
                            class="h-10 w-full !rounded-lg !border-slate-300 !bg-white !pr-10 !text-sm !text-slate-900"
                        />
                    </IconField>

                    <Button
                        v-if="search"
                        type="button"
                        icon="pi pi-times"
                        icon-only
                        rounded
                        variant="text"
                        severity="secondary"
                        aria-label="Clear search"
                        title="Clear search"
                        class="!absolute !top-1/2 !right-1 !size-8 !-translate-y-1/2 !p-0"
                        @click="clearSearch"
                    />
                </div>
            </div>
        </div>

        <!-- DATATABLE -->

        <div class="min-h-0 flex-1">
            <PrimeDataTable
                :value="displayedData"
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
                class="universal-datatable
                       [&_.p-datatable-tbody>tr]:cursor-default
                       [&_.p-datatable-tbody>tr>td]:transition-colors
                       [&_.p-datatable-tbody>tr>td]:duration-150
                       [&_.p-datatable-tbody>tr:hover>td]:!bg-blue-50"
                :table-style="{
                    minWidth: tableMinWidth,
                }"
                @sort="handleSort"
            >
                <!-- DYNAMIC COLUMNS -->

                <Column
                    v-for="column in columns"
                    :key="column.field"
                    :field="column.field"
                    :header="column.header"
                    :sortable="column.sortable"
                    :frozen="column.frozen"
                    :align-frozen="
                        column.alignFrozen
                    "
                    :class="column.class"
                    :header-class="
                        column.headerClass
                    "
                    :body-class="
                        column.bodyClass
                    "
                >
                    <template
                        #body="{ data: row }"
                    >
                        <slot
                            :name="`cell-${column.field}`"
                            :data="row"
                            :value="
                                getNestedValue(
                                    row,
                                    column.field,
                                )
                            "
                            :column="column"
                        >
                            {{
                                getCellValue(
                                    row,
                                    column,
                                )
                            }}
                        </slot>
                    </template>
                </Column>

               <!-- ACTIONS COLUMN -->
<Column
    v-if="
        showActions &&
        actions.length > 0
    "
    :header="actionsHeader"
    frozen
    align-frozen="right"
    :style="{
        minWidth: actionsWidth,
    }"
    header-class="!text-left"
    body-class="!text-left"
>
    <template #body="{ data: row }">
        <slot
            name="actions"
            :data="row"
            :actions="actions"
        >
            <div
                class="flex w-full items-center justify-start gap-2"
            >
                <template
                    v-for="action in actions"
                    :key="action.key"
                >
                    <Button
                        v-if="
                            isActionVisible(
                                action,
                                row,
                            )
                        "
                        type="button"
                        :icon="action.icon"
                        icon-only
                        rounded
                        raised
                        :severity="
                            action.severity ??
                            undefined
                        "
                        :disabled="
                            isActionDisabled(
                                action,
                                row,
                            )
                        "
                        :aria-label="
                            action.label
                        "
                        :title="
                            action.label
                        "
                        class="!size-10 !min-h-10 !min-w-10 !shrink-0 !p-0"
                        @click.stop="
                            handleAction(
                                action,
                                row,
                            )
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
                            ></i>

                            <p
                                class="mt-3 font-semibold text-slate-600"
                            >
                                {{ emptyTitle }}
                            </p>

                            <p
                                class="mt-1 text-sm text-slate-400"
                            >
                                {{
                                    emptyDescription
                                }}
                            </p>
                        </div>
                    </slot>
                </template>

                <!-- LOADING STATE -->

                <template #loading>
                    <div
                        class="flex items-center justify-center gap-3 py-12 text-slate-500"
                    >
                        <ProgressSpinner
                            stroke-width="5"
                            class="!size-7"
                        />

                        <span>
                            Loading records...
                        </span>
                    </div>
                </template>
            </PrimeDataTable>
        </div>

        <!-- PRIMEVUE PAGINATION -->

        <div
            v-if="paginator"
            class="flex min-h-16 w-full shrink-0 flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-white px-4 py-3 text-slate-500"
        >
            <!-- RECORD SUMMARY -->

            <div
                class="min-w-0 flex-1 text-sm font-medium"
            >
                Showing {{ firstRecord }} to
                {{ lastRecord }} of
                {{ effectiveTotalRecords }}
                records
            </div>

            <!-- PAGINATOR -->

            <Paginator
                :first="first"
                :rows="rows"
                :total-records="
                    effectiveTotalRecords
                "
                template="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink"
                class="!min-w-0 !flex-1 !border-0 !bg-transparent !p-0"
                @page="handlePage"
            />

            <!-- ROWS PER PAGE -->

            <div
                class="flex min-w-0 flex-1 items-center justify-end gap-2"
            >
                <label
                    :for="`${dataKey}-rows`"
                    class="text-sm font-semibold text-slate-600"
                >
                    Rows
                </label>

                <Select
                    :input-id="
                        `${dataKey}-rows`
                    "
                    :model-value="rows"
                    :options="
                        rowsPerPageOptions
                    "
                    aria-label="Rows per page"
                    class="w-24"
                    @update:model-value="
                        changeRows
                    "
                />
            </div>
        </div>
    </section>
</template>