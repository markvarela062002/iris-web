<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
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
                title: 'Exam Sessions',
                href: '/assessment-setup/exam-session',
            },
        ],
    },
});

type ExamSessionApiResponse = {
    data: DataTableRow[];
    meta: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
        from: number | null;
        to: number | null;
    };
};

type DataTablePageEvent = {
    page: number;
    rows: number;
    first: number;
};

type DataTableSortEvent = {
    sortField: string;
    sortOrder: number;
};

type ExamSessionForm = {
    id: string;
    session_code: string;
    date_from: string;
    date_to: string;
};

type FormErrors = Partial<Record<keyof ExamSessionForm, string>>;

const API_BASE = '/api/v1/assessment-setup/exam-sessions';
const DATATABLE_URL =
    '/api/v1/assessment-setup/datatable/exam-sessions';

const toast = useToast();
const examSessions = ref<DataTableRow[]>([]);
const loading = ref(false);
const saving = ref(false);
const deleting = ref(false);
const errorMessage = ref('');
const totalRecords = ref(0);
const first = ref(0);
const perPage = ref(10);
const search = ref('');
const sortField = ref('date_from');
const sortDirection = ref<'asc' | 'desc'>('desc');
const formDialogVisible = ref(false);
const deleteDialogVisible = ref(false);
const selectedSession = ref<DataTableRow | null>(null);
const formErrors = ref<FormErrors>({});

const form = reactive<ExamSessionForm>({
    id: '',
    session_code: '',
    date_from: '',
    date_to: '',
});

let requestController: AbortController | null = null;

const columns: DataTableColumn[] = [

    {
        field: 'session_code',
        header: 'Session Code',
        sortable: true,
        searchable: true,
        class: 'min-w-[240px]',
    },
    {
        field: 'date_from',
        header: 'Session Period',
        sortable: true,
        searchable: false,
        class: 'min-w-[250px]',
    },
    {
        field: 'status',
        header: 'Status',
        sortable: false,
        searchable: false,
        class: 'min-w-[150px]',
    },
];

const actions: DataTableAction[] = [
    {
        key: 'edit',
        label: 'Edit exam session',
        icon: 'pi pi-pencil',
        severity: 'warn',
    },
    {
        key: 'delete',
        label: 'Delete exam session',
        icon: 'pi pi-trash',
        severity: 'danger',
    },
];

const dialogTitle = computed(() => {
    return form.id
        ? 'Edit Exam Session'
        : 'Create Exam Session';
});

const currentPage = computed(() => {
    return Math.floor(first.value / perPage.value) + 1;
});

async function loadExamSessions(pageNumber = 1): Promise<void> {
    requestController?.abort();
    const controller = new AbortController();

    requestController = controller;
    loading.value = true;
    errorMessage.value = '';

    try {
        const response = await axios.get<ExamSessionApiResponse>(
            DATATABLE_URL,
            {
                signal: controller.signal,
                params: {
                    page: pageNumber,
                    per_page: perPage.value,
                    search: search.value,
                    sort_field: sortField.value,
                    sort_direction: sortDirection.value,
                },
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                withCredentials: true,
            },
        );

        examSessions.value = response.data.data;
        totalRecords.value = response.data.meta.total;
        perPage.value = response.data.meta.perPage;
        first.value =
            (response.data.meta.currentPage - 1) *
            response.data.meta.perPage;
    } catch (error: unknown) {
        if (
            axios.isCancel(error) ||
            (axios.isAxiosError(error) && error.code === 'ERR_CANCELED')
        ) {
            return;
        }

        examSessions.value = [];
        totalRecords.value = 0;
        errorMessage.value = getErrorMessage(
            error,
            'Unable to load exam sessions.',
        );
    } finally {
        if (requestController === controller) {
            loading.value = false;
        }
    }
}

function handlePage(event: DataTablePageEvent): void {
    perPage.value = event.rows;
    first.value = event.first;
    void loadExamSessions(event.page + 1);
}

function handleSort(event: DataTableSortEvent): void {
    sortField.value = event.sortField || 'date_from';
    sortDirection.value = event.sortOrder === -1 ? 'desc' : 'asc';
    first.value = 0;
    void loadExamSessions(1);
}

function handleSearch(value: string): void {
    search.value = value;
    first.value = 0;
    void loadExamSessions(1);
}

function handleAction(action: string, row: DataTableRow): void {
    if (action === 'edit') {
        openEditDialog(row);
        return;
    }

    if (action === 'delete') {
        selectedSession.value = row;
        deleteDialogVisible.value = true;
    }
}

function openCreateDialog(): void {
    resetForm();
    formDialogVisible.value = true;
}

function openEditDialog(row: DataTableRow): void {
    formErrors.value = {};
    form.id = String(row.id ?? '');
    form.session_code = String(row.session_code ?? '');
    form.date_from = normalizeDate(row.date_from);
    form.date_to = normalizeDate(row.date_to);
    formDialogVisible.value = true;
}

function closeFormDialog(): void {
    if (!saving.value) {
        formDialogVisible.value = false;
        resetForm();
    }
}

function resetForm(): void {
    form.id = '';
    form.session_code = '';
    form.date_from = '';
    form.date_to = '';
    formErrors.value = {};
}

function validateForm(): boolean {
    const errors: FormErrors = {};

    if (!form.session_code.trim()) {
        errors.session_code = 'Session code is required.';
    }

    if (!form.date_from) {
        errors.date_from = 'Date from is required.';
    }

    if (!form.date_to) {
        errors.date_to = 'Date to is required.';
    } else if (form.date_from && form.date_to < form.date_from) {
        errors.date_to =
            'Date to must be the same as or later than date from.';
    }

    formErrors.value = errors;

    return Object.keys(errors).length === 0;
}

async function saveExamSession(): Promise<void> {
    if (!validateForm()) {
        return;
    }

    const wasEditing = form.id !== '';

    saving.value = true;
    formErrors.value = {};
    errorMessage.value = '';

    const payload = {
        session_code: form.session_code.trim(),
        date_from: form.date_from,
        date_to: form.date_to,
    };

    try {
        const response = form.id
            ? await axios.put<{ message: string }>(
                  `${API_BASE}/${encodeURIComponent(form.id)}`,
                  payload,
                  requestConfig(),
              )
            : await axios.post<{ message: string }>(
                  API_BASE,
                  payload,
                  requestConfig(),
              );

        toast.add({
            severity: 'success',
            summary: wasEditing ? 'Session Updated' : 'Session Created',
            detail: response.data.message,
            life: 4000,
        });

        formDialogVisible.value = false;
        resetForm();
        await loadExamSessions(wasEditing ? currentPage.value : 1);
    } catch (error: unknown) {
        formErrors.value = getValidationErrors(error);
        errorMessage.value = getErrorMessage(
            error,
            'Unable to save the exam session.',
        );
    } finally {
        saving.value = false;
    }
}

function closeDeleteDialog(): void {
    if (!deleting.value) {
        deleteDialogVisible.value = false;
        selectedSession.value = null;
    }
}

async function deleteExamSession(): Promise<void> {
    const id = String(selectedSession.value?.id ?? '').trim();

    if (!id) {
        errorMessage.value = 'The selected exam session ID is missing.';
        return;
    }

    deleting.value = true;
    errorMessage.value = '';

    try {
        const response = await axios.delete<{ message: string }>(
            `${API_BASE}/${encodeURIComponent(id)}`,
            requestConfig(),
        );

        toast.add({
            severity: 'success',
            summary: 'Session Deleted',
            detail: response.data.message,
            life: 4000,
        });

        deleteDialogVisible.value = false;
        selectedSession.value = null;
        await reloadCurrentPage();
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(
            error,
            'Unable to delete the exam session.',
        );
    } finally {
        deleting.value = false;
    }
}

async function reloadCurrentPage(): Promise<void> {
    await loadExamSessions(currentPage.value);

    if (examSessions.value.length === 0 && currentPage.value > 1) {
        await loadExamSessions(currentPage.value - 1);
    }
}

function requestConfig() {
    return {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        withCredentials: true,
    };
}

function normalizeDate(value: unknown): string {
    const raw = String(value ?? '').trim();
    return raw ? raw.slice(0, 10) : '';
}

function formatDate(value: unknown): string {
    const raw = normalizeDate(value);

    if (!raw) {
        return '—';
    }

    const date = new Date(`${raw}T00:00:00`);

    return new Intl.DateTimeFormat('en-PH', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(date);
}

function getStatusSeverity(status: unknown) {
    switch (String(status)) {
        case 'Active':
            return 'info';
        case 'Upcoming':
            return 'warn';
        case 'Completed':
            return 'success';
        default:
            return 'secondary';
    }
}

function getErrorMessage(error: unknown, fallback: string): string {
    if (!axios.isAxiosError(error)) {
        return fallback;
    }

    const data = error.response?.data as { message?: string } | undefined;
    return data?.message || fallback;
}

function getValidationErrors(error: unknown): FormErrors {
    if (!axios.isAxiosError(error)) {
        return {};
    }

    const data = error.response?.data as
        | { errors?: Record<string, string[]> }
        | undefined;

    const errors: FormErrors = {};

    for (const field of ['session_code', 'date_from', 'date_to'] as const) {
        const message = data?.errors?.[field]?.[0];
        if (message) {
            errors[field] = message;
        }
    }

    return errors;
}

onMounted(() => {
    void loadExamSessions(1);
});

onBeforeUnmount(() => {
    requestController?.abort();
});
</script>

<template>
    <Head title="Exam Sessions" />
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
            title="Exam Sessions"
            description="Create and manage examination session schedules."
            header-icon="pi pi-calendar"
            search-placeholder="Search session code..."
            empty-title="No exam sessions found"
            empty-description="Create an exam session to get started."
            empty-icon="pi pi-calendar"
            table-min-width="950px"
            actions-header="Actions"
            actions-width="130px"
            data-key="id"
            lazy
            :loading="loading"
            :data="examSessions"
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
                    type="button"
                    label="Create Exam Session"
                    icon="pi pi-plus"
                    severity="success"
                    size="small"
                    @click="openCreateDialog"
                />
            </template>

            <template #cell-session_code="{ value }">
                <div class="flex items-center gap-2">
                    <i class="pi pi-calendar text-[#377EC0]"></i>
                    <span class="font-semibold text-slate-700">
                        {{ value || '—' }}
                    </span>
                </div>
            </template>

            <template #cell-date_from="{ data }">
                <div class="space-y-2">
                    <div class="flex items-center gap-2">
                        <PrimeTag
                            value="From"
                            severity="success"
                            class="w-14 !justify-center !px-2 !py-1 !text-xs !font-semibold"
                        />

                        <span
                            class="whitespace-nowrap text-sm font-medium text-slate-600"
                        >
                            {{ formatDate(data.date_from) }}
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <PrimeTag
                            value="To"
                            severity="danger"
                            class="w-14 !justify-center !px-2 !py-1 !text-xs !font-semibold"
                        />

                        <span
                            class="whitespace-nowrap text-sm font-medium text-slate-600"
                        >
                            {{ formatDate(data.date_to) }}
                        </span>
                    </div>
                </div>
            </template>

            <template #cell-status="{ value }">
                <PrimeTag
                    :value="String(value || 'Unknown')"
                    :severity="getStatusSeverity(value)"
                    rounded
                />
            </template>
        </Datatable>

        <Dialog
            v-model:visible="formDialogVisible"
            modal
            :header="dialogTitle"
            :closable="!saving"
            :dismissable-mask="!saving"
            class="w-[min(92vw,620px)]"
        >
            <div class="grid gap-5 py-2 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label
                        for="session_code"
                        class="mb-2 block text-sm font-semibold text-slate-700"
                    >
                        Session Code
                    </label>
                    <InputText
                        id="session_code"
                        v-model="form.session_code"
                        class="w-full"
                        :invalid="Boolean(formErrors.session_code)"
                        maxlength="255"
                        autocomplete="off"
                    />
                    <small
                        v-if="formErrors.session_code"
                        class="mt-1 block text-red-500"
                    >
                        {{ formErrors.session_code }}
                    </small>
                </div>

                <div>
                    <label
                        for="date_from"
                        class="mb-2 block text-sm font-semibold text-slate-700"
                    >
                        Date From
                    </label>
                    <input
                        id="date_from"
                        v-model="form.date_from"
                        type="date"
                        class="h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-700 outline-none focus:border-[#377EC0] focus:ring-2 focus:ring-[#377EC0]/20"
                        :class="{ 'border-red-500': formErrors.date_from }"
                    />
                    <small
                        v-if="formErrors.date_from"
                        class="mt-1 block text-red-500"
                    >
                        {{ formErrors.date_from }}
                    </small>
                </div>

                <div>
                    <label
                        for="date_to"
                        class="mb-2 block text-sm font-semibold text-slate-700"
                    >
                        Date To
                    </label>
                    <input
                        id="date_to"
                        v-model="form.date_to"
                        type="date"
                        :min="form.date_from || undefined"
                        class="h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-700 outline-none focus:border-[#377EC0] focus:ring-2 focus:ring-[#377EC0]/20"
                        :class="{ 'border-red-500': formErrors.date_to }"
                    />
                    <small
                        v-if="formErrors.date_to"
                        class="mt-1 block text-red-500"
                    >
                        {{ formErrors.date_to }}
                    </small>
                </div>
            </div>

            <template #footer>
                <Button
                    type="button"
                    label="Cancel"
                    icon="pi pi-times"
                    severity="secondary"
                    outlined
                    :disabled="saving"
                    @click="closeFormDialog"
                />
                <Button
                    type="button"
                    label="Save"
                    icon="pi pi-save"
                    severity="success"
                    :loading="saving"
                    :disabled="saving"
                    @click="saveExamSession"
                />
            </template>
        </Dialog>

        <Dialog
            v-model:visible="deleteDialogVisible"
            modal
            header="Delete Exam Session"
            :closable="!deleting"
            :dismissable-mask="!deleting"
            class="w-[min(92vw,520px)]"
        >
            <Message severity="warn" :closable="false">
                Are you sure you want to delete
                <strong>{{ selectedSession?.session_code || 'this exam session' }}</strong>?
                This action cannot be undone.
            </Message>

            <template #footer>
                <Button
                    type="button"
                    label="Cancel"
                    icon="pi pi-times"
                    severity="secondary"
                    outlined
                    :disabled="deleting"
                    @click="closeDeleteDialog"
                />
                <Button
                    type="button"
                    label="Delete"
                    icon="pi pi-trash"
                    severity="danger"
                    :loading="deleting"
                    :disabled="deleting"
                    @click="deleteExamSession"
                />
            </template>
        </Dialog>
    </div>
</template>
