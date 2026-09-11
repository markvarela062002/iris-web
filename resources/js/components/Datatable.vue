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
        class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-xl shadow-slate-200/40"
    >
        <!-- Table Header -->

        <div
            v-if="
                title ||
                description ||
                searchable ||
                $slots.header ||
                $slots['header-actions']
            "
            class="relative overflow-hidden border-b border-slate-200/80 bg-gradient-to-r from-slate-50 via-white to-blue-50/50 px-5 py-5 lg:px-6"
        >
            <!-- Header Decoration -->

            <div
                class="pointer-events-none absolute -top-16 -right-12 size-40 rounded-full bg-blue-400/[0.07] blur-2xl"
            ></div>

            <div
                class="relative z-10 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <!-- Title -->

                <div
                    v-if="
                        title ||
                        description ||
                        $slots.header
                    "
                    class="flex min-w-0 items-center gap-4"
                >
                    <!-- Header Icon -->

                    <div
                        v-if="headerIcon"
                        class="relative flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-gradient-to-br from-[#123A63] to-[#377EC0] text-white shadow-lg shadow-[#377EC0]/20"
                    >
                        <div
                            class="pointer-events-none absolute -top-3 -right-3 size-8 rounded-full bg-white/15"
                        ></div>

                        <i
                            :class="[
                                headerIcon,
                                'relative z-10 !text-[1.65rem] !leading-none !text-white',
                            ]"
                        ></i>
                    </div>

                    <!-- Header Text -->

                    <div class="min-w-0">
                        <slot name="header">
                            <div
                                class="mb-1 flex items-center gap-2"
                            >
                                <span
                                    class="size-1.5 shrink-0 rounded-full bg-emerald-500"
                                ></span>

                                <span
                                    class="text-[10px] font-bold tracking-[0.14em] text-[#377EC0] uppercase"
                                >
                                    Records Management
                                </span>
                            </div>

                            <h2
                                v-if="title"
                                class="text-xl leading-tight font-bold tracking-tight text-[#21365A]"
                            >
                                {{ title }}
                            </h2>

                            <p
                                v-if="description"
                                class="mt-1 max-w-3xl text-sm leading-relaxed text-slate-500"
                            >
                                {{ description }}
                            </p>
                        </slot>
                    </div>
                </div>

                <!-- Header Actions and Search -->

                <div
                    class="flex w-full shrink-0 flex-col gap-3 sm:w-auto sm:flex-row sm:items-center"
                >
                    <slot name="header-actions" />

                    <!-- Search -->

                    <div
                        v-if="searchable"
                        class="relative w-full sm:w-72 lg:w-80"
                    >
                        <IconField>
                            <InputIcon
                                class="pi pi-search !text-slate-400"
                            />

                            <InputText
                                v-model="search"
                                :placeholder="
                                    searchPlaceholder
                                "
                                class="h-11 w-full !rounded-xl !border-slate-200 !bg-white !pr-11 !text-sm !text-slate-700 shadow-sm transition-all placeholder:!text-slate-400 hover:!border-slate-300 focus:!border-[#377EC0] focus:!ring-4 focus:!ring-[#377EC0]/10"
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
                            class="!absolute !top-1/2 !right-1.5 !size-8 !-translate-y-1/2 !p-0"
                            @click="clearSearch"
                        />
                    </div>
                </div>
            </div>
        </div>

        <!-- DataTable -->

        <div
            class="min-h-0 flex-1 bg-white"
        >
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
                :removable-sort="
                    removableSort
                "
                :resizable-columns="
                    resizableColumns
                "
                column-resize-mode="fit"
                :table-style="{
                    minWidth: tableMinWidth,
                }"
                class="
                    universal-datatable
                    [&_.p-datatable-table]:!border-separate
                    [&_.p-datatable-table]:!border-spacing-0
                    [&_.p-datatable-thead>tr>th]:!border-x-0
                    [&_.p-datatable-thead>tr>th]:!border-t-0
                    [&_.p-datatable-thead>tr>th]:!border-b
                    [&_.p-datatable-thead>tr>th]:!border-slate-200
                    [&_.p-datatable-thead>tr>th]:!bg-slate-50/80
                    [&_.p-datatable-thead>tr>th]:!px-4
                    [&_.p-datatable-thead>tr>th]:!py-3.5
                    [&_.p-datatable-thead>tr>th]:!text-xs
                    [&_.p-datatable-thead>tr>th]:!font-bold
                    [&_.p-datatable-thead>tr>th]:!tracking-wide
                    [&_.p-datatable-thead>tr>th]:!text-slate-600
                    [&_.p-datatable-thead>tr>th]:!uppercase
                    [&_.p-datatable-tbody>tr]:cursor-default
                    [&_.p-datatable-tbody>tr>td]:!border-x-0
                    [&_.p-datatable-tbody>tr>td]:!border-b
                    [&_.p-datatable-tbody>tr>td]:!border-slate-100
                    [&_.p-datatable-tbody>tr>td]:!bg-white
                    [&_.p-datatable-tbody>tr>td]:!px-4
                    [&_.p-datatable-tbody>tr>td]:!py-4
                    [&_.p-datatable-tbody>tr>td]:transition-colors
                    [&_.p-datatable-tbody>tr>td]:duration-200
                    [&_.p-datatable-tbody>tr:hover>td]:!bg-blue-50/60
                "
                @sort="handleSort"
            >
                <!-- Dynamic Columns -->

                <Column
                    v-for="column in columns"
                    :key="column.field"
                    :field="column.field"
                    :header="column.header"
                    :sortable="
                        column.sortable
                    "
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

                <!-- Actions Column -->

                <Column
                    v-if="
                        showActions &&
                        actions.length > 0
                    "
                    :header="actionsHeader"
                    frozen
                    align-frozen="right"
                    :style="{
                        minWidth:
                            actionsWidth,
                    }"
                    header-class="!text-left"
                    body-class="!text-left"
                >
                    <template
                        #body="{ data: row }"
                    >
                        <slot
                            name="actions"
                            :data="row"
                            :actions="
                                actions
                            "
                        >
                            <div
                                class="flex w-full items-center justify-start gap-2"
                            >
                                <template
                                    v-for="action in actions"
                                    :key="
                                        action.key
                                    "
                                >
                                    <Button
                                        v-if="
                                            isActionVisible(
                                                action,
                                                row,
                                            )
                                        "
                                        type="button"
                                        :icon="
                                            action.icon
                                        "
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
                                        class="!size-10 !min-h-10 !min-w-10 !shrink-0 !p-0 transition-all duration-200 enabled:hover:!-translate-y-0.5 enabled:hover:!shadow-lg disabled:!cursor-not-allowed disabled:!opacity-40"
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

                <!-- Empty State -->

                <template #empty>
                    <slot name="empty">
                        <div
                            class="flex min-h-72 flex-col items-center justify-center px-6 py-14 text-center"
                        >
                            <div
                                class="relative flex size-20 items-center justify-center rounded-3xl bg-gradient-to-br from-slate-50 to-blue-50 ring-1 ring-slate-200"
                            >
                                <div
                                    class="absolute -top-1 -right-1 size-4 rounded-full bg-blue-100"
                                ></div>

                                <i
                                    :class="[
                                        emptyIcon,
                                        'text-3xl text-[#377EC0]',
                                    ]"
                                ></i>
                            </div>

                            <p
                                class="mt-5 text-base font-bold text-slate-700"
                            >
                                {{
                                    emptyTitle
                                }}
                            </p>

                            <p
                                class="mt-1.5 max-w-sm text-sm leading-6 text-slate-400"
                            >
                                {{
                                    emptyDescription
                                }}
                            </p>
                        </div>
                    </slot>
                </template>

                <!-- Loading State -->

                <template #loading>
                    <div
                        class="flex min-h-72 flex-col items-center justify-center gap-4 text-slate-500"
                    >
                        <div
                            class="flex size-14 items-center justify-center rounded-2xl bg-blue-50"
                        >
                            <ProgressSpinner
                                stroke-width="5"
                                class="!size-7"
                            />
                        </div>

                        <div class="text-center">
                            <p
                                class="text-sm font-semibold text-slate-600"
                            >
                                Loading records
                            </p>

                            <p
                                class="mt-1 text-xs text-slate-400"
                            >
                                Please wait a
                                moment...
                            </p>
                        </div>
                    </div>
                </template>
            </PrimeDataTable>
        </div>

        <!-- Pagination -->

        <div
            v-if="paginator"
            class="flex min-h-[72px] w-full shrink-0 flex-wrap items-center justify-between gap-4 border-t border-slate-200/80 bg-gradient-to-r from-slate-50/80 via-white to-slate-50/80 px-5 py-3"
        >
            <!-- Record Summary -->

            <div
                class="min-w-0 flex-1 text-sm font-medium text-slate-500"
            >
                Showing

                <span
                    class="font-bold text-slate-700"
                >
                    {{ firstRecord }}
                </span>

                to

                <span
                    class="font-bold text-slate-700"
                >
                    {{ lastRecord }}
                </span>

                of

                <span
                    class="font-bold text-[#377EC0]"
                >
                    {{
                        effectiveTotalRecords
                    }}
                </span>

                records
            </div>

            <!-- Paginator -->

            <Paginator
                :first="first"
                :rows="rows"
                :total-records="
                    effectiveTotalRecords
                "
                template="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink"
                class="
                    !min-w-0
                    !flex-1
                    !border-0
                    !bg-transparent
                    !p-0
                    [&_.p-paginator-page]:!size-9
                    [&_.p-paginator-page]:!min-w-9
                    [&_.p-paginator-page]:!rounded-xl
                    [&_.p-paginator-page-selected]:!bg-[#377EC0]
                    [&_.p-paginator-page-selected]:!text-white
                    [&_.p-paginator-first]:!rounded-xl
                    [&_.p-paginator-prev]:!rounded-xl
                    [&_.p-paginator-next]:!rounded-xl
                    [&_.p-paginator-last]:!rounded-xl
                "
                @page="handlePage"
            />

            <!-- Rows Per Page -->

            <div
                class="flex min-w-0 flex-1 items-center justify-end gap-2"
            >
                <label
                    :for="`${dataKey}-rows`"
                    class="text-xs font-bold tracking-wide text-slate-500 uppercase"
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
                    class="w-24 !rounded-xl"
                    @update:model-value="
                        changeRows
                    "
                />
            </div>
        </div>
    </section>
</template>