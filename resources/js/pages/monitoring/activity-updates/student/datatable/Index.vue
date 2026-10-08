<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import axios from 'axios';

import Button from 'primevue/button';

import Checkbox from 'primevue/checkbox';

import DatePicker from 'primevue/datepicker';

import Dialog from 'primevue/dialog';

import FileUpload from 'primevue/fileupload';

import Message from 'primevue/message';

import Select from 'primevue/select';

import PrimeTag from 'primevue/tag';

import Textarea from 'primevue/textarea';

import Toast from 'primevue/toast';

import { useToast } from 'primevue/usetoast';

import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import Datatable from '@/components/Datatable.vue';

import type { DataTableAction, DataTableColumn, DataTableRow } from '@/types';

defineOptions({
    inheritAttrs: false,

    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',

                href: '/student-dashboard',
            },

            {
                title: 'Activity Updates',

                href: '/monitoring/activity-updates/student/datatable',
            },
        ],
    },
});

type ActivityOption = {
    id: string;

    desc_activity: string;
};

type ActivityApiResponse = {
    data: DataTableRow[];

    meta: {
        currentPage: number;

        lastPage?: number;

        perPage: number;

        total: number;

        from?: number | null;

        to?: number | null;
    };
};

type ActivityOptionsResponse = {
    data?: ActivityOption[];
};

type FileUploadSelectEvent = {
    files: File[];
};

type FileUploadControl = {
    clear: () => void;
};

type SelectedEvidence = {
    key: string;

    file: File;

    isImage: boolean;

    previewUrl: string | null;
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

const API = '/api/v1/student/activity-updates';

const MAX_FILE_SIZE = 20 * 1024 * 1024;

const ACCEPTED_FILE_STRING =
    '.pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.bmp,.webp,.xls,.xlsx,.ppt,.pptx,.txt,.csv';

const ACCEPTED_EXTENSIONS = new Set([
    'pdf',

    'doc',

    'docx',

    'jpg',

    'jpeg',

    'png',

    'gif',

    'bmp',

    'webp',

    'xls',

    'xlsx',

    'ppt',

    'pptx',

    'txt',

    'csv',
]);

const toast = useToast();

const activities = ref<DataTableRow[]>([]);

const activityOptions = ref<ActivityOption[]>([]);

const loading = ref(false);

const loadingOptions = ref(false);

const saving = ref(false);

const deleting = ref(false);

const errorMessage = ref('');

const formError = ref('');

const totalRecords = ref(0);

const first = ref(0);

const perPage = ref(10);

const search = ref('');

const statusOptions = ['All', 'Verified', 'Pending', 'For Revision'] as const;

const statusFilter = ref<(typeof statusOptions)[number]>('All');

const sortField = ref('last_update');

const sortDirection = ref<'asc' | 'desc'>('desc');

const activityDialogVisible = ref(false);

const deleteDialogVisible = ref(false);

const selectedActivity = ref<DataTableRow | null>(null);

const activityId = ref<string | null>(null);

const activityDateRange = ref<Date[] | null>(null);

const selectedStartDate = computed<Date | null>(
    () => activityDateRange.value?.[0] ?? null,
);

const selectedEndDate = computed<Date | null>(
    () => activityDateRange.value?.[1] ?? null,
);

const remarks = ref('');

const authenticityConfirmed = ref(false);

const selectedEvidence = ref<SelectedEvidence | null>(null);

const removeExistingEvidence = ref(false);

const previewError = ref(false);

const fileUpload = ref<FileUploadControl | null>(null);

let requestController: AbortController | null = null;

const columns: DataTableColumn[] = [
    {
        field: 'desc_activity',

        header: 'Activity',

        sortable: false,

        searchable: false,

        class: 'w-[300px] min-w-[300px] whitespace-normal',
    },

    {
        field: 'start_date',

        header: 'Activity Period',

        sortable: false,

        searchable: false,

        class: 'w-[300px] min-w-[300px]',
    },

    {
        field: 'last_update',

        header: 'Date Submitted',

        sortable: false,

        searchable: false,

        class: 'w-[210px] min-w-[210px]',
    },

    {
        field: 'status',

        header: 'Status',

        sortable: false,

        searchable: false,

        class: 'w-[170px] min-w-[170px]',
    },

    {
        field: 'revise_remarks',

        header: 'Remarks',

        sortable: false,

        searchable: false,

        class: 'w-[300px] min-w-[300px] whitespace-normal',
    },
];

const actions: DataTableAction[] = [
    {
        key: 'view-file',

        label: 'View or download file',

        icon: 'pi pi-download',

        severity: 'info',

        visible: (row) => hasUploadedFile(row),
    },

    {
        key: 'no-file',
        disabled: () => true,

        label: 'No uploaded file',

        icon: 'pi pi-download',

        severity: 'secondary',

        visible: (row) => !hasUploadedFile(row),
    },

    {
        key: 'edit',

        label: 'Edit / Resubmit',

        icon: 'pi pi-pencil',

        severity: 'warn',

        visible: (row) => canEdit(row),
    },

    {
        key: 'delete',

        label: 'Delete',

        icon: 'pi pi-trash',

        severity: 'danger',

        visible: (row) => canDelete(row),
    },

    {
        key: 'edit-unavailable',
        label: 'Editing is unavailable',
        icon: 'pi pi-pencil',
        severity: 'secondary',
        visible: (row) => !canEdit(row),
        disabled: () => true,
    },
    {
        key: 'delete-unavailable',
        label: 'Deleting is unavailable',
        icon: 'pi pi-trash',
        severity: 'secondary',
        visible: (row) => !canDelete(row),
        disabled: () => true,
    },
];

const isEditing = computed(() => selectedActivity.value !== null);

const existingFilename = computed(() =>
    String(selectedActivity.value?.filename ?? '').trim(),
);

const existingFileUrl = computed(() =>
    String(selectedActivity.value?.file_url ?? '').trim(),
);

const hasExistingEvidence = computed(
    () => existingFilename.value !== '' && !removeExistingEvidence.value,
);

const hasEvidence = computed(
    () => hasExistingEvidence.value || selectedEvidence.value !== null,
);

const submitDisabled = computed(
    () =>
        saving.value ||
        !activityId.value ||
        !selectedStartDate.value ||
        !selectedEndDate.value ||
        !authenticityConfirmed.value ||
        !hasEvidence.value,
);

function statusLabel(row: DataTableRow): string {
    const status = String(row.status ?? '').trim();

    if (status) return status;

    if (
        String(row.sto_validated ?? '')
            .trim()

            .toUpperCase() === 'Y'
    ) {
        return 'Verified';
    }

    const revision = String(row.revise_remarks ?? '').trim();

    const forApp = String(row.for_app ?? '')
        .trim()

        .toUpperCase();

    if (revision !== '' && forApp !== 'Y') {
        return 'For Revision';
    }

    if (forApp === 'Y') return 'Pending';

    return 'Draft';
}

function statusSeverity(
    row: DataTableRow,
): 'success' | 'danger' | 'warn' | 'secondary' {
    const status = statusLabel(row);

    if (status === 'Verified') return 'success';

    if (status === 'For Revision') return 'danger';

    if (status === 'Pending') return 'warn';

    return 'secondary';
}

function statusIcon(row: DataTableRow): string {
    const status = statusLabel(row);

    if (status === 'Verified') return 'pi pi-check-circle';

    if (status === 'For Revision') return 'pi pi-undo';

    if (status === 'Pending') return 'pi pi-clock';

    return 'pi pi-file-edit';
}

function canEdit(row: DataTableRow): boolean {
    if (typeof row.can_edit === 'boolean') return row.can_edit;

    return (
        String(row.sto_validated ?? '')
            .trim()

            .toUpperCase() !== 'Y'
    );
}

function canDelete(row: DataTableRow): boolean {
    if (typeof row.can_delete === 'boolean') return row.can_delete;

    return canEdit(row);
}

function hasUploadedFile(row: DataTableRow): boolean {
    return String(row.file_url ?? '').trim() !== '';
}

function isImageFilename(filename: string): boolean {
    return /\.(jpg|jpeg|png|gif|bmp|webp)$/i.test(filename);
}

function formatDate(value: unknown): string {
    if (!value || value === '1970-01-01') return '—';

    const raw = String(value).trim();

    const parsed = new Date(`${raw}T00:00:00`);

    if (Number.isNaN(parsed.getTime())) return raw;

    return new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',

        month: 'short',

        day: 'numeric',

        year: 'numeric',
    }).format(parsed);
}

function formatDateTime(value: unknown): string {
    const raw = String(value ?? '').trim();

    if (raw === '' || raw.startsWith('1970-01-01')) {
        return '—';
    }

    const parsed = new Date(raw.replace(' ', 'T'));

    if (Number.isNaN(parsed.getTime())) {
        return raw;
    }

    return new Intl.DateTimeFormat(
        'en-PH',

        {
            timeZone: 'Asia/Manila',

            month: 'short',

            day: 'numeric',

            year: 'numeric',

            hour: '2-digit',

            minute: '2-digit',

            hour12: true,
        },
    ).format(parsed);
}

function dateForApi(value: Date): string {
    const year = value.getFullYear();

    const month = String(value.getMonth() + 1).padStart(2, '0');

    const day = String(value.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function parseApiDate(value: unknown): Date | null {
    const raw = String(value ?? '').trim();

    if (!/^\d{4}-\d{2}-\d{2}$/.test(raw)) return null;

    const [year, month, day] = raw.split('-').map(Number);

    return new Date(year, month - 1, day);
}

function currentPageNumber(): number {
    return Math.floor(first.value / Math.max(perPage.value, 1)) + 1;
}

function errorText(error: unknown, fallback: string): string {
    if (axios.isAxiosError(error)) {
        const responseData = error.response?.data as
            | {
                  message?: string;

                  errors?: Record<string, string[]>;
              }
            | undefined;

        if (responseData?.errors) {
            const firstError = Object.values(responseData.errors).flat()[0];

            if (firstError) return firstError;
        }

        if (
            typeof responseData?.message === 'string' &&
            responseData.message.trim() !== ''
        ) {
            return responseData.message;
        }
    }

    return error instanceof Error ? error.message : fallback;
}

async function loadActivities(pageNumber = 1): Promise<void> {
    requestController?.abort();

    const controller = new AbortController();

    requestController = controller;

    loading.value = true;

    errorMessage.value = '';

    try {
        const response = await axios.get<ActivityApiResponse>(
            API,

            {
                signal: controller.signal,

                withCredentials: true,

                params: {
                    page: pageNumber,

                    per_page: perPage.value,

                    search: search.value,

                    ...(statusFilter.value !== 'All'
                        ? { status: statusFilter.value }
                        : {}),

                    sort_field: sortField.value,

                    sort_direction: sortDirection.value,
                },

                headers: {
                    Accept: 'application/json',

                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );

        activities.value = Array.isArray(response.data.data)
            ? response.data.data
            : [];

        totalRecords.value = Number(response.data.meta?.total ?? 0);

        perPage.value = Number(response.data.meta?.perPage ?? perPage.value);

        first.value =
            (Number(response.data.meta?.currentPage ?? 1) - 1) * perPage.value;
    } catch (error: unknown) {
        if (
            axios.isCancel(error) ||
            (axios.isAxiosError(error) && error.code === 'ERR_CANCELED')
        ) {
            return;
        }

        activities.value = [];

        totalRecords.value = 0;

        errorMessage.value = errorText(
            error,

            'Unable to load your activity updates.',
        );
    } finally {
        if (requestController === controller) {
            loading.value = false;
        }
    }
}

async function loadOptions(): Promise<void> {
    if (loadingOptions.value || activityOptions.value.length > 0) {
        return;
    }

    loadingOptions.value = true;

    try {
        const response = await axios.get<ActivityOptionsResponse>(
            `${API}/options`,

            {
                withCredentials: true,

                headers: {
                    Accept: 'application/json',

                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );

        activityOptions.value = Array.isArray(response.data.data)
            ? response.data.data
            : [];
    } catch (error: unknown) {
        formError.value = errorText(
            error,

            'Unable to load activity types.',
        );
    } finally {
        loadingOptions.value = false;
    }
}

function handlePage(event: PageEvent): void {
    perPage.value = event.rows;

    first.value = event.first;

    void loadActivities(event.page + 1);
}

function handleSort(event: SortEvent): void {
    sortField.value = event.sortField || 'last_update';

    sortDirection.value = event.sortOrder === -1 ? 'desc' : 'asc';

    first.value = 0;

    void loadActivities(1);
}

function handleSearch(value: string): void {
    search.value = value;

    first.value = 0;

    void loadActivities(1);
}

function handleColumnFilter(field: string, value: string): void {
    if (
        field !== 'status' ||
        !statusOptions.includes(value as (typeof statusOptions)[number])
    ) {
        return;
    }

    statusFilter.value = value as (typeof statusOptions)[number];

    first.value = 0;

    void loadActivities(1);
}

function handleAction(action: string, row: DataTableRow): void {
    errorMessage.value = '';

    if (action === 'no-file') return;

    if (action === 'view-file') {
        openUploadedFile(row);

        return;
    }

    if (action === 'edit') {
        void openEditDialog(row);

        return;
    }

    if (action === 'delete') {
        selectedActivity.value = row;

        deleteDialogVisible.value = true;
    }
}

function openUploadedFile(row: DataTableRow): void {
    const fileUrl = String(row.file_url ?? '').trim();

    if (!fileUrl) {
        errorMessage.value = 'This activity does not have an uploaded file.';

        return;
    }

    window.open(
        fileUrl,

        '_blank',

        'noopener,noreferrer',
    );
}

function openExistingEvidence(): void {
    if (!existingFileUrl.value) return;

    window.open(
        existingFileUrl.value,

        '_blank',

        'noopener,noreferrer',
    );
}

function clearSelectedEvidence(): void {
    if (selectedEvidence.value?.previewUrl) {
        URL.revokeObjectURL(selectedEvidence.value.previewUrl);
    }

    selectedEvidence.value = null;

    previewError.value = false;

    fileUpload.value?.clear();
}

function resetForm(): void {
    clearSelectedEvidence();

    activityId.value = null;

    remarks.value = '';

    authenticityConfirmed.value = false;

    removeExistingEvidence.value = false;

    formError.value = '';
}

async function openCreateDialog(): Promise<void> {
    selectedActivity.value = null;

    resetForm();

    activityDialogVisible.value = true;

    await loadOptions();
}

async function openEditDialog(row: DataTableRow): Promise<void> {
    if (!canEdit(row)) return;

    selectedActivity.value = row;

    resetForm();

    activityId.value = String(row.activity_id ?? '').trim() || null;

    remarks.value = String(row.remarks ?? '').trim();

    activityDialogVisible.value = true;

    await loadOptions();
}

function closeActivityDialog(): void {
    if (saving.value) return;

    activityDialogVisible.value = false;

    selectedActivity.value = null;

    resetForm();
}

function onFileSelected(event: FileUploadSelectEvent): void {
    const file = Array.from(event.files ?? [])[0];

    fileUpload.value?.clear();

    if (!file) return;

    const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

    if (!ACCEPTED_EXTENSIONS.has(extension)) {
        formError.value = `${file.name}: unsupported file type.`;

        return;
    }

    if (file.size > MAX_FILE_SIZE) {
        formError.value = `${file.name}: exceeds 20 MB.`;

        return;
    }

    clearSelectedEvidence();

    const isImage =
        file.type.toLowerCase().startsWith('image/') ||
        isImageFilename(file.name);

    selectedEvidence.value = {
        key: [file.name, file.size, file.lastModified].join(':'),

        file,

        isImage,

        previewUrl: isImage ? URL.createObjectURL(file) : null,
    };

    removeExistingEvidence.value = true;

    formError.value = '';
}

function removeNewEvidence(): void {
    clearSelectedEvidence();

    removeExistingEvidence.value = false;
}

function removeExistingFile(): void {
    removeExistingEvidence.value = true;
}

function restoreExistingFile(): void {
    removeExistingEvidence.value = false;
}

async function submitActivity(): Promise<void> {
    if (submitDisabled.value) {
        if (!hasEvidence.value) {
            formError.value = 'Attach activity evidence before submitting.';
        }

        return;
    }

    if (!selectedStartDate.value || !selectedEndDate.value) return;

    if (selectedEndDate.value < selectedStartDate.value) {
        formError.value =
            'The end date must be after or equal to the start date.';

        return;
    }

    saving.value = true;

    formError.value = '';

    try {
        const formData = new FormData();

        formData.append('activity_id', activityId.value ?? '');

        formData.append('start_date', dateForApi(selectedStartDate.value));

        formData.append('end_date', dateForApi(selectedEndDate.value));

        formData.append('remarks', remarks.value.trim());

        formData.append(
            'confirm_authenticity',

            authenticityConfirmed.value ? '1' : '0',
        );

        if (selectedEvidence.value) {
            formData.append(
                'file',

                selectedEvidence.value.file,
            );
        }

        if (removeExistingEvidence.value) {
            formData.append('remove_file', '1');
        }

        const endpoint = selectedActivity.value
            ? `${API}/${encodeURIComponent(
                  String(selectedActivity.value.id ?? ''),
              )}`
            : API;

        const response = await axios.post(
            endpoint,

            formData,

            {
                withCredentials: true,

                headers: {
                    Accept: 'application/json',

                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );

        toast.add({
            severity: 'success',

            summary: isEditing.value
                ? 'Activity resubmitted'
                : 'Activity submitted',

            detail: String(
                response.data?.message ?? 'Activity saved successfully.',
            ),

            life: 3500,
        });

        const wasEditing = selectedActivity.value !== null;

        activityDialogVisible.value = false;

        selectedActivity.value = null;

        resetForm();

        if (wasEditing) {
            await loadActivities(currentPageNumber());
        } else {
            first.value = 0;

            await loadActivities(1);
        }
    } catch (error: unknown) {
        formError.value = errorText(
            error,

            'Unable to save the activity.',
        );
    } finally {
        saving.value = false;
    }
}

async function deleteActivity(): Promise<void> {
    if (deleting.value || !selectedActivity.value) return;

    const id = String(selectedActivity.value.id ?? '').trim();

    if (!id) return;

    deleting.value = true;

    try {
        const response = await axios.delete(
            `${API}/${encodeURIComponent(id)}`,

            {
                withCredentials: true,

                headers: {
                    Accept: 'application/json',

                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );

        toast.add({
            severity: 'success',

            summary: 'Activity deleted',

            detail: String(
                response.data?.message ?? 'Activity deleted successfully.',
            ),

            life: 3000,
        });

        deleteDialogVisible.value = false;

        selectedActivity.value = null;

        await loadActivities(currentPageNumber());

        if (activities.value.length === 0 && currentPageNumber() > 1) {
            first.value = Math.max(
                0,

                first.value - perPage.value,
            );

            await loadActivities(currentPageNumber());
        }
    } catch (error: unknown) {
        errorMessage.value = errorText(
            error,

            'Unable to delete the activity.',
        );
    } finally {
        deleting.value = false;
    }
}

onMounted(() => {
    void loadActivities(1);
});

onBeforeUnmount(() => {
    requestController?.abort();

    clearSelectedEvidence();
});
</script>

<template>
    <Head title="Activity Updates" />

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
            title="Activity Updates"
            description="Submit your activity records and monitor their verification status."
            header-icon="pi pi-list-check"
            search-placeholder="Search your activity updates..."
            empty-title="No activity updates"
            empty-description="Add your first activity to submit it Pending."
            empty-icon="pi pi-list-check"
            table-min-width="1280px"
            data-key="id"
            lazy
            :loading="loading"
            :data="activities"
            :columns="columns"
            :column-filters="{
                status: { value: statusFilter, options: statusOptions },
            }"
            :actions="actions"
            :total-records="totalRecords"
            :first="first"
            :rows="perPage"
            :rows-per-page-options="[10, 20, 50]"
            @page="handlePage"
            @sort="handleSort"
            @search="handleSearch"
            @filter="handleColumnFilter"
            @action="handleAction"
        >
            <template #header-actions>
                <Button
                    type="button"
                    label="Add Activity"
                    icon="pi pi-plus"
                    severity="success"
                    size="small"
                    @click="openCreateDialog"
                />
            </template>

            <template #cell-desc_activity="{ value }">
                <div class="flex min-w-0 items-start gap-2 whitespace-normal">
                    <i
                        class="pi pi-list-check mt-0.5 shrink-0 text-green-500"
                    ></i>

                    <span
                        class="min-w-0 font-semibold break-words whitespace-normal text-slate-700"
                    >
                        {{ value || 'Activity' }}
                    </span>
                </div>
            </template>

            <template #cell-start_date="{ data }">
                <div class="space-y-2">
                    <div class="flex items-center gap-2">
                        <PrimeTag
                            value="Started"
                            severity="success"
                            class="w-16 !justify-center !px-2 !py-1 !text-xs !font-semibold"
                        />

                        <span
                            class="text-sm font-medium whitespace-nowrap text-slate-600"
                        >
                            {{ formatDate(data.start_date) }}
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <PrimeTag
                            value="Ended"
                            severity="danger"
                            class="w-16 !justify-center !px-2 !py-1 !text-xs !font-semibold"
                        />

                        <span
                            class="text-sm font-medium whitespace-nowrap text-slate-600"
                        >
                            {{ formatDate(data.end_date) }}
                        </span>
                    </div>
                </div>
            </template>

            <template #cell-last_update="{ value }">
                <div class="flex items-center gap-2">
                    <i class="pi pi-clock text-yellow-500"></i>

                    <span
                        class="text-sm font-medium whitespace-nowrap text-slate-600"
                    >
                        {{ formatDateTime(value) }}
                    </span>
                </div>
            </template>

            <template #cell-status="{ data }">
                <PrimeTag
                    :value="statusLabel(data)"
                    :severity="statusSeverity(data)"
                    :icon="statusIcon(data)"
                />
            </template>

            <template #cell-revise_remarks="{ value, data }">
                <div
                    v-if="value && String(value).trim()"
                    class="rounded-xl border border-red-100 bg-red-50 px-3 py-2"
                >
                    <p
                        class="text-[11px] font-bold tracking-wide text-red-500 uppercase"
                    >
                        {{
                            statusLabel(data) === 'For Revision'
                                ? 'Revision Remarks'
                                : 'Previous Remarks'
                        }}
                    </p>

                    <p
                        class="mt-1 text-sm font-medium whitespace-normal text-slate-700"
                    >
                        {{ value }}
                    </p>
                </div>

                <span v-else class="text-slate-400">—</span>
            </template>
        </Datatable>
    </div>

    <Dialog
        v-model:visible="activityDialogVisible"
        modal
        :header="isEditing ? 'Edit / Resubmit Activity' : 'Add Activity'"
        :style="{ width: 'min(760px, 95vw)' }"
        :draggable="false"
        :closable="!saving"
        @hide="!saving && closeActivityDialog()"
    >
        <div class="space-y-5">
            <Message v-if="formError" severity="error" :closable="false">
                {{ formError }}
            </Message>

            <Message
                v-if="
                    selectedActivity &&
                    statusLabel(selectedActivity) === 'For Revision' &&
                    selectedActivity.revise_remarks
                "
                severity="warn"
                :closable="false"
            >
                <div>
                    <p class="font-semibold">Revision requested</p>

                    <p class="mt-1 text-sm">
                        {{ selectedActivity.revise_remarks }}
                    </p>
                </div>
            </Message>

            <div>
                <label
                    class="mb-1.5 block text-sm font-semibold text-slate-700"
                >
                    Activity

                    <span class="text-red-500">*</span>
                </label>

                <Select
                    v-model="activityId"
                    :options="activityOptions"
                    option-label="desc_activity"
                    option-value="id"
                    filter
                    class="w-full"
                    placeholder="Select activity"
                    :loading="loadingOptions"
                    :disabled="saving || loadingOptions"
                />
            </div>

            <div class="grid gap-4 sm:grid-cols-1">
                <div>
                    <label
                        class="mb-1.5 block text-sm font-semibold text-slate-700"
                    >
                        Date Range

                        <span class="text-red-500">*</span>
                    </label>

                    <DatePicker
                        id="student-report-date-range"
                        v-model="activityDateRange"
                        selection-mode="range"
                        date-format="M d, yy"
                        show-icon
                        show-button-bar
                        :manual-input="false"
                        :disabled="loading"
                        class="w-full"
                        input-class="w-full"
                        placeholder="Select start and end date"
                    />
                </div>
            </div>

            <div>
                <label
                    class="mb-1.5 block text-sm font-semibold text-slate-700"
                >
                    Remarks
                </label>

                <Textarea
                    v-model="remarks"
                    rows="4"
                    maxlength="5000"
                    auto-resize
                    class="w-full"
                    placeholder="Enter activity remarks (optional)"
                    :disabled="saving"
                />
            </div>

            <div>
                <div class="mb-2 flex items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-slate-700">
                            Attachments

                            <span class="text-red-500">*</span>
                        </p>

                        <p class="mt-0.5 text-xs text-slate-500">
                            Maximum 20 MB. One evidence file per activity.
                        </p>
                    </div>

                    <FileUpload
                        ref="fileUpload"
                        name="file"
                        mode="advanced"
                        custom-upload
                        :auto="false"
                        :accept="ACCEPTED_FILE_STRING"
                        :max-file-size="MAX_FILE_SIZE"
                        :show-upload-button="false"
                        :show-cancel-button="false"
                        :disabled="saving"
                        :pt="{
                            root: {
                                class: '!border-0 !bg-transparent',
                            },

                            header: {
                                class: '!border-0 !bg-transparent !p-0',
                            },

                            content: {
                                class: '!hidden',
                            },
                        }"
                        @select="onFileSelected"
                    >
                        <template #header="{ chooseCallback }">
                            <Button
                                type="button"
                                label="Add File"
                                icon="pi pi-paperclip"
                                severity="info"
                                outlined
                                size="small"
                                :disabled="saving"
                                @click="chooseCallback()"
                            />
                        </template>

                        <template #content />

                        <template #empty />
                    </FileUpload>
                </div>

                <div
                    v-if="hasExistingEvidence || selectedEvidence"
                    class="grid grid-cols-2 gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-3 sm:grid-cols-3 md:grid-cols-4"
                >
                    <div
                        v-if="hasExistingEvidence"
                        class="relative aspect-[4/3] overflow-hidden rounded-xl border border-slate-200 bg-white"
                    >
                        <button
                            type="button"
                            class="flex h-full w-full items-center justify-center bg-white"
                            :disabled="!existingFileUrl"
                            aria-label="Open existing activity evidence"
                            @click="openExistingEvidence"
                        >
                            <img
                                v-if="
                                    existingFileUrl &&
                                    isImageFilename(existingFilename) &&
                                    !previewError
                                "
                                :src="existingFileUrl"
                                alt="Activity evidence preview"
                                class="h-full w-full object-contain"
                                @error="previewError = true"
                            />

                            <i
                                v-else
                                class="pi pi-file-pdf text-5xl text-red-500"
                            ></i>
                        </button>

                        <Button
                            type="button"
                            icon="pi pi-times"
                            severity="danger"
                            rounded
                            size="small"
                            :disabled="saving"
                            aria-label="Replace existing activity evidence"
                            class="!absolute !top-2 !right-2 !h-8 !w-8"
                            @click="removeExistingFile"
                        />
                    </div>

                    <div
                        v-if="selectedEvidence"
                        class="relative aspect-[4/3] overflow-hidden rounded-xl border border-slate-200 bg-white"
                    >
                        <img
                            v-if="
                                selectedEvidence.isImage &&
                                selectedEvidence.previewUrl &&
                                !previewError
                            "
                            :src="selectedEvidence.previewUrl"
                            alt="Selected activity evidence preview"
                            class="h-full w-full object-contain"
                            @error="previewError = true"
                        />

                        <div
                            v-else
                            class="flex h-full w-full items-center justify-center bg-white"
                        >
                            <i class="pi pi-file-pdf text-5xl text-red-500"></i>
                        </div>

                        <Button
                            type="button"
                            icon="pi pi-times"
                            severity="danger"
                            rounded
                            size="small"
                            :disabled="saving"
                            aria-label="Remove selected activity evidence"
                            class="!absolute !top-2 !right-2 !h-8 !w-8"
                            @click="removeNewEvidence"
                        />
                    </div>
                </div>

                <Message
                    v-if="!hasEvidence"
                    severity="warn"
                    :closable="false"
                    class="mt-2"
                >
                    One activity evidence file is required.
                </Message>

                <Button
                    v-if="
                        removeExistingEvidence &&
                        existingFilename &&
                        !selectedEvidence
                    "
                    type="button"
                    label="Restore existing file"
                    icon="pi pi-refresh"
                    severity="secondary"
                    text
                    size="small"
                    class="mt-2"
                    :disabled="saving"
                    @click="restoreExistingFile"
                />
            </div>

            <div class="rounded-2xl border border-blue-100 bg-blue-50/60 p-4">
                <p class="font-bold text-slate-800">
                    Notice of Authenticity and Responsibility
                </p>

                <p class="mt-2 text-sm leading-6 text-slate-600">
                    By submitting this activity information, evidence,
                    documents, and images, you confirm that they are true and
                    correct to the best of your knowledge. You assume full
                    responsibility for their accuracy and any discrepancies may
                    lead to consequences under relevant laws. Ensure all data
                    provided is accurate and authentic.
                </p>

                <label
                    class="mt-3 flex cursor-pointer items-start gap-2 text-sm font-medium text-slate-700"
                >
                    <Checkbox
                        v-model="authenticityConfirmed"
                        binary
                        :disabled="saving"
                    />

                    <span> I have read and understood the notice above. </span>
                </label>
            </div>
        </div>

        <template #footer>
            <Button
                type="button"
                label="Cancel"
                severity="secondary"
                outlined
                :disabled="saving"
                @click="closeActivityDialog"
            />

            <Button
                type="button"
                :label="isEditing ? 'Save & Resubmit' : 'Submit Activity'"
                icon="pi pi-check"
                severity="success"
                :loading="saving"
                :disabled="submitDisabled"
                @click="submitActivity"
            />
        </template>
    </Dialog>

    <Dialog
        v-model:visible="deleteDialogVisible"
        modal
        header="Delete Activity"
        :style="{ width: 'min(460px, 94vw)' }"
        :draggable="false"
        :closable="!deleting"
    >
        <div class="flex items-start gap-3">
            <div
                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-red-50"
            >
                <i class="pi pi-trash text-lg text-red-500"></i>
            </div>

            <div>
                <p class="font-semibold text-slate-800">
                    Delete this activity?
                </p>

                <p class="mt-1 text-sm leading-5 text-slate-500">
                    This removes the activity submission and its evidence file.
                    Verified activities cannot be deleted.
                </p>
            </div>
        </div>

        <template #footer>
            <Button
                type="button"
                label="Cancel"
                severity="secondary"
                outlined
                :disabled="deleting"
                @click="deleteDialogVisible = false"
                :draggable="false"
            />

            <Button
                type="button"
                label="Delete"
                icon="pi pi-trash"
                severity="danger"
                :loading="deleting"
                @click="deleteActivity"
            />
        </template>
    </Dialog>
</template>
