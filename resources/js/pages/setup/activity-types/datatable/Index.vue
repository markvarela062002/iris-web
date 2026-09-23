<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import PrimeTag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import Toast from 'primevue/toast';
import ToggleSwitch from 'primevue/toggleswitch';
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
                title: 'Activity Types Setup',
                href: '/setup/activity-types',
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

type Errors =
    Record<string, string>;

const DATATABLE_URL =
    '/api/v1/setup/activity-types/datatable';

const API_BASE =
    '/api/v1/setup/activity-types';

const toast = useToast();

const activityTypes =
    ref<DataTableRow[]>([]);

const loading = ref(false);
const saving = ref(false);
const deleting = ref(false);

const errorMessage = ref('');

const totalRecords = ref(0);
const first = ref(0);
const perPage = ref(10);
const search = ref('');

const sortField =
    ref('code_activity');

const sortDirection =
    ref<'asc' | 'desc'>(
        'asc',
    );

const dialogVisible = ref(false);
const deleteVisible = ref(false);

const selectedActivityType =
    ref<DataTableRow | null>(
        null,
    );

const formErrors =
    ref<Errors>({});

const form = reactive({
    id: '',
    code_activity: '',
    desc_activity: '',
    activity_remarks: '',
    for_admin: true,
});

let requestController:
    | AbortController
    | null = null;

const columns: DataTableColumn[] = [
    {
        field: 'code_activity',
        header: 'Code',
        sortable: false,
        searchable: false,
        class: 'min-w-[180px]',
    },
    {
        field: 'desc_activity',
        header: 'Activity Name',
        sortable: false,
        searchable: false,
        class: 'min-w-[320px]',
    },
    {
        field: 'activity_remarks',
        header: 'Remarks',
        sortable: false,
        searchable: false,
        class:
            'min-w-[320px] whitespace-normal',
    },
    {
        field: 'for_admin',
        header: 'Admin Only',
        sortable: true,
        searchable: false,
        class: 'min-w-[140px]',
    },
];

const actions: DataTableAction[] = [
    {
        key: 'edit',
        label: 'Edit activity type',
        icon: 'pi pi-pencil',
        severity: 'warn',
    },
    {
        key: 'delete',
        label: 'Delete activity type',
        icon: 'pi pi-trash',
        severity: 'danger',
    },
];

const currentPage = computed(
    () =>
        Math.floor(
            first.value /
                perPage.value,
        ) + 1,
);

const dialogTitle = computed(
    () =>
        form.id
            ? 'Edit Activity Type'
            : 'Create Activity Type',
);

async function loadActivityTypes(
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
                        search:
                            search.value,
                        sort_field:
                            sortField.value,
                        sort_direction:
                            sortDirection.value,
                    },

                    ...requestConfig(),
                },
            );

        activityTypes.value =
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
        if (
            isCanceled(
                error,
            )
        ) {
            return;
        }

        activityTypes.value = [];
        totalRecords.value = 0;

        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to load activity types.',
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

    void loadActivityTypes(
        event.page + 1,
    );
}

function handleSort(
    event: SortEvent,
): void {
    sortField.value =
        event.sortField ||
        'code_activity';

    sortDirection.value =
        event.sortOrder === -1
            ? 'desc'
            : 'asc';

    first.value = 0;

    void loadActivityTypes(1);
}

function handleSearch(
    value: string,
): void {
    search.value = value;
    first.value = 0;

    void loadActivityTypes(1);
}

function handleAction(
    action: string,
    row: DataTableRow,
): void {
    if (action === 'edit') {
        openEdit(row);
        return;
    }

    if (action === 'delete') {
        selectedActivityType.value =
            row;

        deleteVisible.value =
            true;
    }
}

function openCreate(): void {
    resetForm();

    dialogVisible.value =
        true;
}

function openEdit(
    row: DataTableRow,
): void {
    formErrors.value = {};

    form.id =
        String(row.id ?? '');

    form.code_activity =
        String(
            row.code_activity ?? '',
        );

    form.desc_activity =
        String(
            row.desc_activity ?? '',
        );

    form.activity_remarks =
        String(
            row.activity_remarks ?? '',
        );

    form.for_admin =
        String(
            row.for_admin ?? 'N',
        ) === 'Y';

    dialogVisible.value =
        true;
}

async function saveActivityType(): Promise<void> {
    formErrors.value = {};
    errorMessage.value = '';

    if (
        !form.code_activity.trim()
    ) {
        formErrors.value.code_activity =
            'Code is required.';
    }

    if (
        !form.desc_activity.trim()
    ) {
        formErrors.value.desc_activity =
            'Description is required.';
    }

    if (
        Object.keys(
            formErrors.value,
        ).length
    ) {
        return;
    }

    saving.value = true;

    const wasNew =
        !form.id;

    try {
        const payload = {
            code_activity:
                form.code_activity.trim(),
            desc_activity:
                form.desc_activity.trim(),
            activity_remarks:
                form.activity_remarks.trim()
                || null,
            for_admin:
                form.for_admin
                    ? 'Y'
                    : 'N',
        };

        const response =
            wasNew
                ? await axios.post<{
                      id: string;
                      message: string;
                  }>(
                      API_BASE,
                      payload,
                      requestConfig(),
                  )
                : await axios.put<{
                      id: string;
                      message: string;
                  }>(
                      `${API_BASE}/${encodeURIComponent(
                          form.id,
                      )}`,
                      payload,
                      requestConfig(),
                  );

        form.id =
            response.data.id;

        toast.add({
            severity: 'success',
            summary: wasNew
                ? 'Activity Type Created'
                : 'Activity Type Updated',
            detail:
                response.data.message,
            life: 4000,
        });

        await loadActivityTypes(
            wasNew
                ? 1
                : currentPage.value,
        );
    } catch (error: unknown) {
        formErrors.value =
            getValidationErrors(
                error,
            );

        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to save the activity type.',
            );
    } finally {
        saving.value = false;
    }
}

async function deleteActivityType(): Promise<void> {
    const id = String(
        selectedActivityType.value
            ?.id ?? '',
    );

    if (!id) {
        return;
    }

    deleting.value = true;
    errorMessage.value = '';

    try {
        const response =
            await axios.delete<{
                message: string;
            }>(
                `${API_BASE}/${encodeURIComponent(
                    id,
                )}`,
                requestConfig(),
            );

        deleteVisible.value =
            false;

        selectedActivityType.value =
            null;

        toast.add({
            severity: 'success',
            summary:
                'Activity Type Deleted',
            detail:
                response.data.message,
            life: 4000,
        });

        await reloadCurrentPage();
    } catch (error: unknown) {
        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to delete the activity type.',
            );
    } finally {
        deleting.value = false;
    }
}

function resetForm(): void {
    Object.assign(
        form,
        {
            id: '',
            code_activity: '',
            desc_activity: '',
            activity_remarks: '',
            for_admin: true,
        },
    );

    formErrors.value = {};
}

async function reloadCurrentPage(): Promise<void> {
    await loadActivityTypes(
        currentPage.value,
    );

    if (
        !activityTypes.value.length
        &&
        currentPage.value > 1
    ) {
        await loadActivityTypes(
            currentPage.value - 1,
        );
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
        void loadActivityTypes(
            1,
        ),
);

onBeforeUnmount(
    () =>
        requestController?.abort(),
);
</script>

<template>
    <Head title="Activity Types Setup" />

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
            title="Activity Types Setup"
            description="Create and maintain the activity types used throughout IRIS-SAM."
            header-icon="pi pi-list-check"
            search-placeholder="Search code, description, or remarks..."
            empty-title="No activity types found"
            empty-description="Create an activity type to get started."
            empty-icon="pi pi-list-check"
            table-min-width="1100px"
            actions-header="Actions"
            actions-width="130px"
            data-key="id"
            lazy
            :loading="loading"
            :data="activityTypes"
            :columns="columns"
            :actions="actions"
            :total-records="totalRecords"
            :first="first"
            :rows="perPage"
            :rows-per-page-options="[10, 20, 50, 100]"
            @page="handlePage"
            @sort="handleSort"
            @search="handleSearch"
            @action="handleAction"
        >
            <template #header-actions>
                <Button
                    label="Create Activity Type"
                    icon="pi pi-plus"
                    severity="success"
                    size="small"
                    @click="openCreate"
                />
            </template>

            <template #cell-code_activity="{ value }">
                
                <span
                    class="font-semibold text-slate-700"
                >
                    {{ value || '—' }}
                </span>
            </template>

            <template #cell-desc_activity="{ value }">
                <div class="flex items-start gap-2">
                    <i
                        class="pi pi-list-check mt-0.5 shrink-0 text-green-500"
                    ></i>

                    <span
                        class="font-medium text-slate-700"
                    >
                        {{ value || '—' }}
                    </span>
                </div>
            </template>

            <template #cell-activity_remarks="{ value }">
                <span
                    class="whitespace-normal text-sm text-slate-600"
                >
                
                    {{ value || '—' }}
                </span>
            </template>

            <template #cell-for_admin="{ value }">
                <PrimeTag
                    :value="
                        value === 'Y'
                            ? 'Yes'
                            : 'No'
                    "
                    :severity="
                        value === 'Y'
                            ? 'warn'
                            : 'secondary'
                    "
                    :icon="
                        value === 'Y'
                            ? 'pi pi-lock'
                            : 'pi pi-users'
                    "
                    rounded
                />
            </template>
        </Datatable>

        <Dialog
            v-model:visible="dialogVisible"
            modal
            :header="dialogTitle"
            :closable="!saving"
            class="w-[min(96vw,820px)]"
        >
            <div
                class="grid gap-4 sm:grid-cols-2"
            >
                <div>
                    <label
                        class="mb-2 block text-sm font-semibold text-slate-700"
                    >
                        Code
                        <span
                            class="text-red-500"
                        >
                            *
                        </span>
                    </label>

                    <InputText
                        v-model="form.code_activity"
                        class="w-full"
                        maxlength="10"
                        :invalid="
                            Boolean(
                                formErrors.code_activity,
                            )
                        "
                        :disabled="saving"
                    />

                    <small
                        v-if="
                            formErrors.code_activity
                        "
                        class="text-red-500"
                    >
                        {{
                            formErrors.code_activity
                        }}
                    </small>
                </div>

                <div>
                    <label
                        class="mb-2 block text-sm font-semibold text-slate-700"
                    >
                        Activity Name
                        <span
                            class="text-red-500"
                        >
                            *
                        </span>
                    </label>

                    <InputText
                        v-model="form.desc_activity"
                        class="w-full"
                        maxlength="100"
                        :invalid="
                            Boolean(
                                formErrors.desc_activity,
                            )
                        "
                        :disabled="saving"
                    />

                    <small
                        v-if="
                            formErrors.desc_activity
                        "
                        class="text-red-500"
                    >
                        {{
                            formErrors.desc_activity
                        }}
                    </small>
                </div>

                <div
                    class="sm:col-span-2"
                >
                    <label
                        class="mb-2 block text-sm font-semibold text-slate-700"
                    >
                        Remarks
                    </label>

                    <Textarea
                        v-model="form.activity_remarks"
                        rows="4"
                        auto-resize
                        class="w-full"
                        :invalid="
                            Boolean(
                                formErrors.activity_remarks,
                            )
                        "
                        :disabled="saving"
                    />

                    <small
                        v-if="
                            formErrors.activity_remarks
                        "
                        class="text-red-500"
                    >
                        {{
                            formErrors.activity_remarks
                        }}
                    </small>
                </div>

                <div
                    class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 sm:col-span-2"
                >
                    <ToggleSwitch
                        v-model="form.for_admin"
                        input-id="activity-for-admin"
                        :disabled="saving"
                    />

                    <div>
                        <label
                            for="activity-for-admin"
                            class="cursor-pointer font-semibold text-slate-700"
                        >
                            For Admin Only
                        </label>

                        <p
                            class="mt-0.5 text-xs text-slate-500"
                        >
                            When enabled, this activity type is restricted to administrator use.
                        </p>
                    </div>
                </div>
            </div>

            <template #footer>
                <Button
                    label="Close"
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
                    label="Save"
                    icon="pi pi-save"
                    severity="success"
                    :loading="saving"
                    @click="saveActivityType"
                />
            </template>
        </Dialog>

        <Dialog
            v-model:visible="deleteVisible"
            modal
            header="Delete Activity Type"
            class="w-[min(92vw,520px)]"
        >
                <div
                    class="w-full rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-700"
                >
                Are you sure you want to delete
                <strong>
                    {{
                        selectedActivityType?.desc_activity
                        ||
                        selectedActivityType?.code_activity
                        ||
                        'this activity type'
                    }}
                </strong>
                ?
            </div>

            <template #footer>
                <Button
                    label="Cancel"
                    severity="secondary"
                    outlined
                    :disabled="deleting"
                    @click="
                        deleteVisible =
                            false
                    "
                />

                <Button
                    label="Delete"
                    icon="pi pi-trash"
                    severity="danger"
                    :loading="deleting"
                    @click="deleteActivityType"
                />
            </template>
        </Dialog>
    </div>
</template>
