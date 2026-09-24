<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import Message from 'primevue/message';
import PrimeTag from 'primevue/tag';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
} from 'vue';

import Datatable from '@/components/Datatable.vue';
import { dashboard } from '@/routes';

import type {
    DataTableAction,
    DataTableColumn,
    DataTableRow,
} from '@/types';

defineOptions({
    inheritAttrs: false,

    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
            {
                title: 'Alerts Setup',
                href: '/setup/alert-setup/datatable/index',
            },
        ],
    },
});

type ApiListResponse = {
    data: DataTableRow[];

    meta: {
        currentPage: number;
        perPage: number;
        total: number;
    };
};

type PageEvent = {
    page: number;
    rows: number;
    first: number;
};

type SortEvent = {
    sortField: string;
    sortOrder: number;
};

type Errors = Record<string, string>;

const DATATABLE_URL =
    '/api/v1/setup/alert-setup/datatable';

const API_BASE =
    '/api/v1/setup/alert-setup';

const toast = useToast();

const alerts =
    ref<DataTableRow[]>([]);

const loading = ref(false);
const saving = ref(false);
const errorMessage = ref('');

const totalRecords = ref(0);
const first = ref(0);
const perPage = ref(10);

const sortField =
    ref('as_of');

const sortDirection =
    ref<'asc' | 'desc'>(
        'desc',
    );

const dialogVisible =
    ref(false);

const formErrors =
    ref<Errors>({});

const form = reactive({
    id: '',
    as_of: '',
    alert_type: '',
    alert_type_label: '',
    inactive_days:
        null as number | null,
});

let requestController:
    | AbortController
    | null = null;

const columns: DataTableColumn[] = [
    {
        field: 'as_of',
        header: 'Date Updated',
        sortable: false,
        searchable: false,
        class: 'min-w-[180px]',
    },
    {
        field: 'alert_type_label',
        header: 'Alert Type',
        sortable: false,
        searchable: false,
        class: 'min-w-[320px]',
    },
    {
        field: 'inactive_days',
        header: 'Inactivity Days',
        sortable: false,
        searchable: false,
        class:
            'min-w-[180px] text-center',
        headerClass:
            'text-center',
        bodyClass:
            'text-center',
    },
];

const actions: DataTableAction[] = [
    {
        key: 'edit',
        label:
            'Edit inactivity days',
        icon: 'pi pi-pencil',
        severity: 'warn',
    },
];

const currentPage = computed(
    () =>
        Math.floor(
            first.value /
                perPage.value,
        ) + 1,
);

async function loadAlerts(
    page = 1,
): Promise<void> {
    requestController?.abort();

    const controller =
        new AbortController();

    requestController =
        controller;

    loading.value = true;
    errorMessage.value = '';

    try {
        const response =
            await axios.get<ApiListResponse>(
                DATATABLE_URL,
                {
                    signal:
                        controller.signal,

                    params: {
                        page,
                        per_page:
                            perPage.value,
                        sort_field:
                            sortField.value,
                        sort_direction:
                            sortDirection.value,
                    },

                    ...requestConfig(),
                },
            );

        alerts.value =
            response.data.data;

        totalRecords.value =
            response.data.meta.total;

        perPage.value =
            response.data.meta
                .perPage;

        first.value =
            (
                response.data.meta
                    .currentPage -
                1
            ) *
            response.data.meta
                .perPage;
    } catch (error: unknown) {
        if (isCanceled(error)) {
            return;
        }

        alerts.value = [];
        totalRecords.value = 0;

        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to load alert setup.',
            );
    } finally {
        if (
            requestController ===
            controller
        ) {
            loading.value = false;
        }
    }
}

function handlePage(
    event: PageEvent,
): void {
    perPage.value =
        event.rows;

    first.value =
        event.first;

    void loadAlerts(
        event.page + 1,
    );
}

function handleSort(
    event: SortEvent,
): void {
    sortField.value =
        event.sortField
        || 'as_of';

    sortDirection.value =
        event.sortOrder === -1
            ? 'desc'
            : 'asc';

    first.value = 0;

    void loadAlerts(1);
}

function handleAction(
    action: string,
    row: DataTableRow,
): void {
    if (action !== 'edit') {
        return;
    }

    openEdit(row);
}

function openEdit(
    row: DataTableRow,
): void {
    formErrors.value = {};

    form.id =
        String(row.id ?? '');

    form.as_of =
        String(row.as_of ?? '');

    form.alert_type =
        String(
            row.alert_type ?? '',
        );

    form.alert_type_label =
        String(
            row.alert_type_label
            ?? '',
        );

    const days = Number(
        row.inactive_days
        ?? 0,
    );

    form.inactive_days =
        Number.isFinite(days)
            ? days
            : null;

    dialogVisible.value =
        true;
}

async function saveAlert(): Promise<void> {
    formErrors.value = {};
    errorMessage.value = '';

    if (
        form.inactive_days === null
        ||
        form.inactive_days < 1
    ) {
        formErrors.value
            .inactive_days =
            'Inactivity Days must be at least 1.';

        return;
    }

    if (!form.id) {
        return;
    }

    saving.value = true;

    try {
        const response =
            await axios.put<{
                message: string;
            }>(
                `${API_BASE}/${encodeURIComponent(
                    form.id,
                )}`,
                {
                    inactive_days:
                        form.inactive_days,
                },
                requestConfig(),
            );

        toast.add({
            severity: 'success',
            summary:
                'Alert Setup Updated',
            detail:
                response.data.message,
            life: 4000,
        });

        dialogVisible.value =
            false;

        await loadAlerts(
            currentPage.value,
        );
    } catch (error: unknown) {
        formErrors.value =
            getValidationErrors(
                error,
            );

        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to update alert setup.',
            );
    } finally {
        saving.value = false;
    }
}

function formatDate(
    value: unknown,
): string {
    const raw = String(
        value ?? '',
    ).trim();

    if (!raw) {
        return '—';
    }

    const parts =
        raw.split('-');

    if (parts.length !== 3) {
        return raw;
    }

    const year =
        Number(parts[0]);

    const month =
        Number(parts[1]);

    const day =
        Number(parts[2]);

    if (
        !year
        || !month
        || !day
    ) {
        return raw;
    }

    return new Intl.DateTimeFormat(
        'en-US',
        {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        },
    ).format(
        new Date(
            year,
            month - 1,
            day,
        ),
    );
}

function alertIcon(
    alertType: unknown,
): string {
    switch (
        String(
            alertType ?? '',
        )
    ) {
        case 'person_activity':
            return 'pi pi-bolt';

        case 'file_upload':
            return 'pi pi-upload';

        case 'person_task':
            return 'pi pi-book';

        case 'person_journal':
            return 'pi pi-calendar';

        default:
            return 'pi pi-bell';
    }
}

function requestConfig() {
    return {
        headers: {
            Accept:
                'application/json',
            'X-Requested-With':
                'XMLHttpRequest',
        },

        withCredentials: true,
    };
}

function isCanceled(
    error: unknown,
): boolean {
    return (
        axios.isCancel(error)
        ||
        (
            axios.isAxiosError(
                error,
            )
            &&
            error.code ===
                'ERR_CANCELED'
        )
    );
}

function getErrorMessage(
    error: unknown,
    fallback: string,
): string {
    if (
        !axios.isAxiosError(
            error,
        )
    ) {
        return fallback;
    }

    return (
        (
            error.response
                ?.data as
                | {
                      message?: string;
                  }
                | undefined
        )?.message
        ||
        fallback
    );
}

function getValidationErrors(
    error: unknown,
): Errors {
    if (
        !axios.isAxiosError(
            error,
        )
    ) {
        return {};
    }

    const source =
        (
            error.response
                ?.data as
                | {
                      errors?: Record<
                          string,
                          string[]
                      >;
                  }
                | undefined
        )?.errors
        ?? {};

    return Object.fromEntries(
        Object.entries(
            source,
        ).map(
            ([
                key,
                messages,
            ]) => [
                key,
                messages[0]
                ?? 'Invalid value.',
            ],
        ),
    );
}

onMounted(
    () =>
        void loadAlerts(1),
);

onBeforeUnmount(
    () =>
        requestController?.abort(),
);
</script>

<template>
    <Head title="Alerts Setup" />

    <Toast position="top-right" />

    <div
        class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5"
    >
        <Message
            v-if="errorMessage"
            severity="error"
            closable
            @close="errorMessage = ''"
        >
            {{ errorMessage }}
        </Message>

        <Datatable
            title="Alerts Setup"
            description="Manage inactivity thresholds for the four fixed IRIS-SAM alert types."
            header-icon="pi pi-bell"
            empty-title="No alert setup records found"
            empty-description="The selected school does not have configured alert rules."
            empty-icon="pi pi-bell"
            table-min-width="900px"
            actions-header="Actions"
            actions-width="100px"
            data-key="id"
            lazy
            :loading="loading"
            :data="alerts"
            :columns="columns"
            :actions="actions"
            :total-records="totalRecords"
            :first="first"
            :rows="perPage"
            :rows-per-page-options="[10, 20, 50, 100]"
            @page="handlePage"
            @sort="handleSort"
            @action="handleAction"
        >
            <template #cell-as_of="{ value }">
                <div
                    class="flex items-center gap-2"
                >
                    <i
                        class="pi pi-calendar shrink-0 text-blue-500"
                    ></i>

                    <span
                        class="font-medium text-slate-700"
                    >
                        {{ formatDate(value) }}
                    </span>
                </div>
            </template>

            <template
                #cell-alert_type_label="{ data }"
            >
                <div
                    class="flex items-center gap-2"
                >
                    <i
                        :class="[
                            alertIcon(
                                data.alert_type,
                            ),
                            'shrink-0 text-green-500',
                        ]"
                    ></i>

                    <span
                        class="font-semibold text-slate-700"
                    >
                        {{
                            data.alert_type_label
                            || '—'
                        }}
                    </span>
                </div>
            </template>

            <template
                #cell-inactive_days="{ value }"
            >
                <PrimeTag
                    :value="`${value ?? 0} days`"
                    :severity="
                        Number(value) <= 7
                            ? 'warn'
                            : Number(value) <= 30
                              ? 'info'
                              : 'secondary'
                    "
                    icon="pi pi-clock"
                    rounded
                />
            </template>
        </Datatable>

        <Dialog
            v-model:visible="dialogVisible"
            modal
            header="Edit Alert Setup"
            :closable="!saving"
            class="w-[min(94vw,620px)]"
        >
            <div
                class="grid gap-4"
            >
                <div>
                    <label
                        class="mb-2 block text-sm font-semibold text-slate-700"
                    >
                        Alert Type
                    </label>

                    <div
                        class="flex min-h-[42px] items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2"
                    >
                        <i
                            :class="[
                                alertIcon(
                                    form.alert_type,
                                ),
                                'shrink-0 text-green-500',
                            ]"
                        ></i>

                        <span
                            class="font-semibold text-slate-700"
                        >
                            {{
                                form.alert_type_label
                                || '—'
                            }}
                        </span>
                    </div>
                </div>

                <div>
                    <label
                        class="mb-2 block text-sm font-semibold text-slate-700"
                    >
                        As Of
                    </label>

                    <div
                        class="flex min-h-[42px] items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2"
                    >
                        <i
                            class="pi pi-calendar shrink-0 text-blue-500"
                        ></i>

                        <span
                            class="font-medium text-slate-700"
                        >
                            {{
                                formatDate(
                                    form.as_of,
                                )
                            }}
                        </span>
                    </div>
                </div>

                <div>
                    <label
                        class="mb-2 block text-sm font-semibold text-slate-700"
                    >
                        Inactivity Days
                        <span
                            class="text-red-500"
                        >
                            *
                        </span>
                    </label>

                    <InputNumber
                        v-model="form.inactive_days"
                        class="w-full"
                        :min="1"
                        :use-grouping="false"
                        :invalid="
                            Boolean(
                                formErrors.inactive_days,
                            )
                        "
                        :disabled="saving"
                    />

                    <small
                        v-if="
                            formErrors.inactive_days
                        "
                        class="mt-1 block text-red-500"
                    >
                        {{
                            formErrors.inactive_days
                        }}
                    </small>
                </div>

                <div
                    class="rounded-xl border border-blue-200 bg-blue-50 p-3 text-sm text-blue-700"
                >
                    Only the inactivity threshold can be changed.
                    Alert Type and Date Updated are fixed system values.
                </div>
            </div>

            <template #footer>
                <Button
                    label="Cancel"
                    icon="pi pi-times"
                    severity="secondary"
                    outlined
                    :disabled="saving"
                    @click="
                        dialogVisible =
                            false
                    "
                />

                <Button
                    label="Save Changes"
                    icon="pi pi-save"
                    severity="success"
                    :loading="saving"
                    @click="saveAlert"
                />
            </template>
        </Dialog>
    </div>
</template>
