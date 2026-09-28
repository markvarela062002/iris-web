<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import axios from 'axios';

import Button from 'primevue/button';

import Checkbox from 'primevue/checkbox';

import Dialog from 'primevue/dialog';

import FileUpload from 'primevue/fileupload';

import InputText from 'primevue/inputtext';

import Message from 'primevue/message';

import Select from 'primevue/select';

import PrimeTag from 'primevue/tag';

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
                title: 'Uploaded Documents',

                href: '/monitoring/uploaded-documents/student',
            },
        ],
    },
});

type UploadedFile = {
    id: string;

    name: string;

    label: string;

    url: string | null;

    order_no?: string | number | null;
};

type RequirementOption = {
    id: string;

    code_requirement?: string | null;

    desc_requirement: string;

    prio?: string | number | null;

    template?: string | null;

    template_url?: string | null;
};

type DocumentApiResponse = {
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

type RequirementResponse = {
    data?: RequirementOption[];
};

type FileUploadSelectEvent = {
    files: File[];
};

type FileUploadControl = {
    clear: () => void;
};

type SelectedAttachment = {
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

const API = '/api/v1/student/uploaded-documents';

const MAX_ATTACHMENT_SIZE = 25 * 1024 * 1024;

const ACCEPTED_ATTACHMENT_STRING =
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

const documents = ref<DataTableRow[]>([]);

const requirements = ref<RequirementOption[]>([]);

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

const statusOptions = [
    'All',
    'Verified',
    'Pending',
    'Draft',
    'For Revision',
] as const;

const statusFilter = ref<(typeof statusOptions)[number]>('All');

const sortField = ref('last_update');

const sortDirection = ref<'asc' | 'desc'>('desc');

const uploadDialogVisible = ref(false);

const filesDialogVisible = ref(false);

const deleteDialogVisible = ref(false);

const selectedDocument = ref<DataTableRow | null>(null);

const viewedDocument = ref<DataTableRow | null>(null);

const fileDescription = ref('');

const requirementId = ref<string | null>(null);

const authenticityConfirmed = ref(false);

const selectedFiles = ref<SelectedAttachment[]>([]);

const removeExistingFileIds = ref<string[]>([]);

const fileUpload = ref<FileUploadControl | null>(null);

const previewErrors = ref<Record<string, boolean>>({});

let requestController: AbortController | null = null;

const columns: DataTableColumn[] = [
    {
        field: 'file_desc',

        header: 'File Description',

        sortable: false,

        searchable: true,

        class: 'w-[300px] min-w-[300px] whitespace-normal',
    },

    {
        field: 'desc_requirement',

        header: 'Requirement Type',

        sortable: false,

        searchable: true,

        class: 'w-[300px] min-w-[300px] whitespace-normal',
    },

    {
        field: 'date_uploaded',

        header: 'Date Uploaded',

        sortable: true,

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
        key: 'view-files',

        label: 'View or download file',

        icon: 'pi pi-download',

        severity: 'info',

        visible: (row) => {
            return getDownloadableFiles(row).length > 0;
        },
    },

    {
        key: 'no-files',
        disabled: () => true,

        label: 'No uploaded file',

        icon: 'pi pi-download',

        severity: 'secondary',

        visible: (row) => {
            return getDownloadableFiles(row).length === 0;
        },
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
        key: 'edit-verified',
        label: 'Verified documents cannot be edited',
        icon: 'pi pi-pencil',
        severity: 'secondary',
        visible: (row) => statusLabel(row) === 'Verified',
        disabled: () => true,
    },
    {
        key: 'delete-verified',
        label: 'Verified documents cannot be deleted',
        icon: 'pi pi-trash',
        severity: 'secondary',
        visible: (row) => statusLabel(row) === 'Verified',
        disabled: () => true,
    },
];

const selectedRequirement = computed((): RequirementOption | null => {
    if (!requirementId.value) {
        return null;
    }

    return (
        requirements.value.find(
            (requirement) => requirement.id === requirementId.value,
        ) ?? null
    );
});

const existingFiles = computed<UploadedFile[]>(() => {
    if (!selectedDocument.value) {
        return [];
    }

    return getUploadedFiles(selectedDocument.value);
});

const visibleExistingFiles = computed<UploadedFile[]>(() => {
    const removed = new Set(removeExistingFileIds.value);

    return existingFiles.value.filter((file) => !removed.has(file.id));
});

const viewedFiles = computed<UploadedFile[]>(() => {
    if (!viewedDocument.value) {
        return [];
    }

    return getDownloadableFiles(viewedDocument.value);
});

const isEditing = computed(() => selectedDocument.value !== null);

const hasAnySubmissionFile = computed(
    () =>
        visibleExistingFiles.value.length > 0 || selectedFiles.value.length > 0,
);

const submitDisabled = computed(
    () =>
        saving.value ||
        !requirementId.value ||
        fileDescription.value.trim() === '' ||
        !authenticityConfirmed.value ||
        !hasAnySubmissionFile.value,
);

function getUploadedFiles(row: DataTableRow): UploadedFile[] {
    if (!Array.isArray(row.files)) {
        return [];
    }

    return row.files.flatMap((candidate): UploadedFile[] => {
        if (candidate === null || typeof candidate !== 'object') {
            return [];
        }

        const file = candidate as Record<string, unknown>;

        const id = String(file.id ?? '').trim();

        const name = String(file.name ?? '').trim();

        const url = String(file.url ?? '').trim();

        if (!id || !name) {
            return [];
        }

        return [
            {
                id,

                name,

                label: String(file.label ?? name).trim(),

                url: url !== '' ? url : null,

                order_no: file.order_no as string | number | null | undefined,
            },
        ];
    });
}

function getDownloadableFiles(row: DataTableRow): UploadedFile[] {
    return getUploadedFiles(row).filter((file) => {
        return typeof file.url === 'string' && file.url.trim() !== '';
    });
}

function statusLabel(row: DataTableRow): string {
    const status = String(row.status ?? '').trim();

    if (status) {
        return status;
    }

    if (
        String(row.sto_validated ?? '')
            .trim()

            .toUpperCase() === 'Y'
    ) {
        return 'Verified';
    }

    const remarks = String(row.revise_remarks ?? '').trim();

    const forApp = String(row.for_app ?? '')
        .trim()

        .toUpperCase();

    if (remarks !== '' && forApp !== 'Y') {
        return 'For Revision';
    }

    if (forApp === 'Y') {
        return 'Pending';
    }

    return 'Draft';
}

function statusSeverity(
    row: DataTableRow,
): 'success' | 'danger' | 'warn' | 'secondary' {
    const status = statusLabel(row);

    if (status === 'Verified') {
        return 'success';
    }

    if (status === 'For Revision') {
        return 'danger';
    }

    if (status === 'Pending') {
        return 'warn';
    }

    return 'secondary';
}

function statusIcon(row: DataTableRow): string {
    const status = statusLabel(row);

    if (status === 'Verified') {
        return 'pi pi-check-circle';
    }

    if (status === 'For Revision') {
        return 'pi pi-undo';
    }

    if (status === 'Pending') {
        return 'pi pi-clock';
    }

    return 'pi pi-file-edit';
}

function canEdit(row: DataTableRow): boolean {
    if (typeof row.can_edit === 'boolean') {
        return row.can_edit;
    }

    return (
        String(row.sto_validated ?? '')
            .trim()

            .toUpperCase() !== 'Y'
    );
}

function canDelete(row: DataTableRow): boolean {
    if (typeof row.can_delete === 'boolean') {
        return row.can_delete;
    }

    return canEdit(row);
}

function formatUploadedDate(
    dateValue: unknown,

    timeValue: unknown,
): string {
    const date = String(dateValue ?? '').trim();

    const time = String(timeValue ?? '').trim();

    if (!date || date === '1970-01-01') {
        return '—';
    }

    const value = time ? `${date} ${time}` : date;

    const parsed = new Date(
        value.replace(
            ' ',

            'T',
        ),
    );

    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat(
        'en-PH',

        {
            timeZone: 'Asia/Manila',

            month: 'short',

            day: 'numeric',

            year: 'numeric',

            hour: time ? '2-digit' : undefined,

            minute: time ? '2-digit' : undefined,

            hour12: true,
        },
    ).format(parsed);
}

function currentPageNumber(): number {
    return (
        Math.floor(
            first.value /
                Math.max(
                    perPage.value,

                    1,
                ),
        ) + 1
    );
}

function errorText(
    error: unknown,

    fallback: string,
): string {
    if (axios.isAxiosError(error)) {
        const responseData = error.response?.data as
            | {
                  message?: string;

                  errors?: Record<string, string[]>;
              }
            | undefined;

        if (responseData?.errors && typeof responseData.errors === 'object') {
            const firstError = Object.values(responseData.errors).flat()[0];

            if (firstError) {
                return firstError;
            }
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

async function loadDocuments(pageNumber = 1): Promise<void> {
    requestController?.abort();

    const controller = new AbortController();

    requestController = controller;

    loading.value = true;

    errorMessage.value = '';

    try {
        const response = await axios.get<DocumentApiResponse>(
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

        documents.value = Array.isArray(response.data.data)
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

        documents.value = [];

        totalRecords.value = 0;

        errorMessage.value = errorText(
            error,

            'Unable to load your uploaded documents.',
        );
    } finally {
        if (requestController === controller) {
            loading.value = false;
        }
    }
}

async function loadRequirements(): Promise<void> {
    if (loadingOptions.value || requirements.value.length > 0) {
        return;
    }

    loadingOptions.value = true;

    try {
        const response = await axios.get<RequirementResponse>(
            `${API}/options`,

            {
                withCredentials: true,

                headers: {
                    Accept: 'application/json',

                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );

        requirements.value = Array.isArray(response.data.data)
            ? response.data.data
            : [];
    } catch (error: unknown) {
        formError.value = errorText(
            error,

            'Unable to load requirement types.',
        );
    } finally {
        loadingOptions.value = false;
    }
}

function handlePage(event: PageEvent): void {
    perPage.value = event.rows;

    first.value = event.first;

    void loadDocuments(event.page + 1);
}

function handleSort(event: SortEvent): void {
    sortField.value = event.sortField || 'last_update';

    sortDirection.value = event.sortOrder === -1 ? 'desc' : 'asc';

    first.value = 0;

    void loadDocuments(1);
}

function handleSearch(value: string): void {
    search.value = value;

    first.value = 0;

    void loadDocuments(1);
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

    void loadDocuments(1);
}

function handleAction(
    action: string,

    row: DataTableRow,
): void {
    errorMessage.value = '';

    if (action === 'no-files') {
        return;
    }

    if (action === 'view-files') {
        openUploadedFiles(row);

        return;
    }

    if (action === 'edit') {
        openEditDialog(row);

        return;
    }

    if (action === 'delete') {
        selectedDocument.value = row;

        deleteDialogVisible.value = true;
    }
}

function openUploadedFiles(row: DataTableRow): void {
    const files = getDownloadableFiles(row);

    if (files.length === 0) {
        errorMessage.value = 'This record does not have an uploaded file.';

        return;
    }

    /*



     * Keep the student behavior identical to the



     * administrator Uploaded Documents page:



     *



     * 1 file  -> open immediately in a new tab.



     * 2+ files -> show the Uploaded Files dialog.



     */

    if (files.length === 1) {
        window.open(
            files[0].url as string,

            '_blank',

            'noopener,noreferrer',
        );

        return;
    }

    viewedDocument.value = row;

    filesDialogVisible.value = true;
}

function closeFilesDialog(): void {
    filesDialogVisible.value = false;

    viewedDocument.value = null;
}

function clearSelectedFiles(): void {
    for (const attachment of selectedFiles.value) {
        if (attachment.previewUrl) {
            URL.revokeObjectURL(attachment.previewUrl);
        }
    }

    selectedFiles.value = [];

    fileUpload.value?.clear();
}

function resetForm(): void {
    clearSelectedFiles();

    fileDescription.value = '';

    requirementId.value = null;

    authenticityConfirmed.value = false;

    removeExistingFileIds.value = [];

    previewErrors.value = {};

    formError.value = '';
}

async function openCreateDialog(): Promise<void> {
    selectedDocument.value = null;

    resetForm();

    uploadDialogVisible.value = true;

    await loadRequirements();
}

async function openEditDialog(row: DataTableRow): Promise<void> {
    if (!canEdit(row)) {
        return;
    }

    selectedDocument.value = row;

    resetForm();

    fileDescription.value = String(row.file_desc ?? '').trim();

    requirementId.value = String(row.requirement_id ?? '').trim() || null;

    uploadDialogVisible.value = true;

    await loadRequirements();
}

function closeUploadDialog(): void {
    if (saving.value) {
        return;
    }

    uploadDialogVisible.value = false;

    selectedDocument.value = null;

    resetForm();
}

function attachmentKey(file: File): string {
    return [file.name, file.size, file.lastModified].join(':');
}

function onFilesSelected(event: FileUploadSelectEvent): void {
    const existingKeys = new Set(
        selectedFiles.value.map((attachment) => attachment.key),
    );

    const rejected: string[] = [];

    for (const file of Array.from(event.files ?? [])) {
        const extension =
            file.name

                .split('.')

                .pop()

                ?.toLowerCase() ?? '';

        if (!ACCEPTED_EXTENSIONS.has(extension)) {
            rejected.push(`${file.name}: unsupported file type`);

            continue;
        }

        if (file.size > MAX_ATTACHMENT_SIZE) {
            rejected.push(`${file.name}: exceeds 25 MB`);

            continue;
        }

        const key = attachmentKey(file);

        if (existingKeys.has(key)) {
            continue;
        }

        existingKeys.add(key);

        const isImage =
            file.type

                .toLowerCase()

                .startsWith('image/') ||
            /\.(jpg|jpeg|png|gif|bmp|webp)$/i.test(file.name);

        selectedFiles.value.push({
            key,

            file,

            isImage,

            previewUrl: isImage ? URL.createObjectURL(file) : null,
        });
    }

    fileUpload.value?.clear();

    if (rejected.length > 0) {
        formError.value = rejected.join('. ');
    } else {
        formError.value = '';
    }
}

function removeNewFile(attachment: SelectedAttachment): void {
    clearPreviewError(`new:${attachment.key}`);

    if (attachment.previewUrl) {
        URL.revokeObjectURL(attachment.previewUrl);
    }

    selectedFiles.value = selectedFiles.value.filter(
        (item) => item.key !== attachment.key,
    );
}

function removeExistingFile(file: UploadedFile): void {
    if (removeExistingFileIds.value.includes(file.id)) {
        return;
    }

    removeExistingFileIds.value = [...removeExistingFileIds.value, file.id];
}

function restoreExistingFiles(): void {
    removeExistingFileIds.value = [];
}

async function submitDocument(): Promise<void> {
    if (submitDisabled.value) {
        if (!hasAnySubmissionFile.value) {
            formError.value = 'Attach at least one document.';
        }

        return;
    }

    saving.value = true;

    formError.value = '';

    try {
        const formData = new FormData();

        formData.append(
            'requirement_id',

            requirementId.value ?? '',
        );

        formData.append(
            'file_desc',

            fileDescription.value.trim(),
        );

        formData.append(
            'confirm_authenticity',

            authenticityConfirmed.value ? '1' : '0',
        );

        for (const attachment of selectedFiles.value) {
            formData.append(
                'files[]',

                attachment.file,
            );
        }

        for (const fileId of removeExistingFileIds.value) {
            formData.append(
                'remove_file_ids[]',

                fileId,
            );
        }

        const endpoint = selectedDocument.value
            ? `${API}/${encodeURIComponent(
                  String(selectedDocument.value.id ?? ''),
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
                ? 'Document resubmitted'
                : 'Document uploaded',

            detail: String(
                response.data?.message ?? 'Document saved successfully.',
            ),

            life: 3500,
        });

        const wasEditing = selectedDocument.value !== null;

        uploadDialogVisible.value = false;

        selectedDocument.value = null;

        resetForm();

        if (wasEditing) {
            await loadDocuments(currentPageNumber());
        } else {
            first.value = 0;

            await loadDocuments(1);
        }
    } catch (error: unknown) {
        formError.value = errorText(
            error,

            'Unable to save the document.',
        );
    } finally {
        saving.value = false;
    }
}

async function deleteDocument(): Promise<void> {
    if (deleting.value || !selectedDocument.value) {
        return;
    }

    const id = String(selectedDocument.value.id ?? '').trim();

    if (!id) {
        return;
    }

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

            summary: 'Document deleted',

            detail: String(
                response.data?.message ?? 'Document deleted successfully.',
            ),

            life: 3000,
        });

        deleteDialogVisible.value = false;

        selectedDocument.value = null;

        await loadDocuments(currentPageNumber());

        if (documents.value.length === 0 && currentPageNumber() > 1) {
            first.value = Math.max(
                0,

                first.value - perPage.value,
            );

            await loadDocuments(currentPageNumber());
        }
    } catch (error: unknown) {
        errorMessage.value = errorText(
            error,

            'Unable to delete the document.',
        );
    } finally {
        deleting.value = false;
    }
}

function openFile(file: UploadedFile): void {
    if (!file.url) {
        errorMessage.value = 'The selected file is unavailable.';

        return;
    }

    window.open(
        file.url,

        '_blank',

        'noopener,noreferrer',
    );
}

function isPreviewableImage(filename: string): boolean {
    return /\.(jpg|jpeg|png|gif|bmp|webp)$/i.test(filename);
}

function hasPreviewError(key: string): boolean {
    return previewErrors.value[key] === true;
}

function markPreviewError(key: string): void {
    previewErrors.value = {
        ...previewErrors.value,

        [key]: true,
    };
}

function clearPreviewError(key: string): void {
    if (!previewErrors.value[key]) {
        return;
    }

    const next = {
        ...previewErrors.value,
    };

    delete next[key];

    previewErrors.value = next;
}

function getExistingPreviewUrl(file: UploadedFile): string | null {
    const previewKey = `existing:${file.id}`;

    if (
        !file.url ||
        !isPreviewableImage(file.name) ||
        hasPreviewError(previewKey)
    ) {
        return null;
    }

    return file.url;
}

function getFileIcon(filename: string): string {
    const extension =
        filename

            .split('.')

            .pop()

            ?.trim()

            .toLowerCase() ?? '';

    if (extension === 'pdf') {
        return 'pi pi-file-pdf';
    }

    if (extension === 'doc' || extension === 'docx') {
        return 'pi pi-file-word';
    }

    if (extension === 'xls' || extension === 'xlsx' || extension === 'csv') {
        return 'pi pi-file-excel';
    }

    if (
        extension === 'jpg' ||
        extension === 'jpeg' ||
        extension === 'png' ||
        extension === 'gif' ||
        extension === 'bmp' ||
        extension === 'webp'
    ) {
        return 'pi pi-image';
    }

    return 'pi pi-file';
}

onMounted(() => {
    void loadDocuments(1);
});

onBeforeUnmount(() => {
    requestController?.abort();

    clearSelectedFiles();
});
</script>

<template>
    <Head title="Documents Uploading" />

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
            title="Documents Uploading"
            description="Upload your required documents and monitor their verification status."
            header-icon="pi pi-file-arrow-up"
            search-placeholder="Search your uploaded documents..."
            empty-title="No uploaded documents"
            empty-description="Upload your first document to submit it Pending."
            empty-icon="pi pi-file-arrow-up"
            table-min-width="1280px"
            data-key="id"
            lazy
            :loading="loading"
            :data="documents"
            :columns="columns"
            :column-filters="{
                status: { value: statusFilter, options: statusOptions },
            }"
            :actions="actions"
            :total-records="totalRecords"
            :first="first"
            :rows="perPage"
            :rows-per-page-options="[
                10,

                20,

                50,
            ]"
            @page="handlePage"
            @sort="handleSort"
            @search="handleSearch"
            @filter="handleColumnFilter"
            @action="handleAction"
        >
            <template #header-actions>
                <Button
                    type="button"
                    label="Upload Document"
                    icon="pi pi-plus"
                    severity="success"
                    size="small"
                    @click="openCreateDialog"
                />
            </template>

            <template #cell-file_desc="{ value }">
                <div class="flex min-w-0 items-start gap-2 whitespace-normal">
                    <i class="pi pi-file mt-0.5 shrink-0 text-[#377EC0]"></i>

                    <span
                        class="min-w-0 font-semibold break-words whitespace-normal text-slate-700"
                    >
                        {{ value || 'Uploaded Document' }}
                    </span>
                </div>
            </template>

            <template #cell-desc_requirement="{ value }">
                <div class="flex min-w-0 items-start gap-2 whitespace-normal">
                    <i
                        class="pi pi-list-check mt-0.5 shrink-0 text-green-500"
                    ></i>

                    <span
                        class="min-w-0 font-medium break-words whitespace-normal text-slate-700"
                    >
                        {{ value || '—' }}
                    </span>
                </div>
            </template>

            <template #cell-date_uploaded="{ data }">
                <div class="flex items-center gap-2">
                    <i class="pi pi-clock text-yellow-500"></i>

                    <span
                        class="text-sm font-medium whitespace-nowrap text-slate-600"
                    >
                        {{
                            formatUploadedDate(
                                data.date_uploaded,

                                data.time_uploaded,
                            )
                        }}
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

            <template
                #cell-revise_remarks="{
                    value,

                    data,
                }"
            >
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

                <span v-else class="text-slate-400"> — </span>
            </template>
        </Datatable>
    </div>

    <Dialog
        v-model:visible="uploadDialogVisible"
        modal
        :header="isEditing ? 'Edit / Resubmit Document' : 'Upload Document'"
        :style="{
            width: 'min(760px, 95vw)',
        }"
        :draggable="false"
        :closable="!saving"
        @hide="!saving && closeUploadDialog()"
    >
        <div class="space-y-5">
            <Message v-if="formError" severity="error" :closable="false">
                {{ formError }}
            </Message>

            <Message
                v-if="
                    selectedDocument &&
                    statusLabel(selectedDocument) === 'For Revision' &&
                    selectedDocument.revise_remarks
                "
                severity="warn"
                :closable="false"
            >
                <div>
                    <p class="font-semibold">Revision requested</p>

                    <p class="mt-1 text-sm">
                        {{ selectedDocument.revise_remarks }}
                    </p>
                </div>
            </Message>

            <div>
                <label
                    class="mb-1.5 block text-sm font-semibold text-slate-700"
                >
                    Requirement Type

                    <span class="text-red-500"> * </span>
                </label>

                <Select
                    v-model="requirementId"
                    :options="requirements"
                    option-label="desc_requirement"
                    option-value="id"
                    filter
                    class="w-full"
                    placeholder="Select requirement type"
                    :loading="loadingOptions"
                    :disabled="saving || loadingOptions"
                />

                <Button
                    v-if="selectedRequirement?.template_url"
                    as="a"
                    :href="selectedRequirement.template_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    label="Open sample template"
                    icon="pi pi-external-link"
                    severity="info"
                    text
                    size="small"
                    class="mt-2 !px-0"
                />
            </div>

            <div>
                <label
                    class="mb-1.5 block text-sm font-semibold text-slate-700"
                >
                    File Description

                    <span class="text-red-500"> * </span>
                </label>

                <InputText
                    v-model="fileDescription"
                    maxlength="100"
                    class="w-full"
                    placeholder="Enter file description"
                    :disabled="saving"
                />
            </div>

            <div>
                <div class="mb-2 flex items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-slate-700">
                            Attachments

                            <span class="text-red-500"> * </span>
                        </p>

                        <p class="mt-0.5 text-xs text-slate-500">
                            Maximum 10 MB per requirement.
                        </p>
                    </div>

                    <FileUpload
                        ref="fileUpload"
                        name="files[]"
                        mode="advanced"
                        multiple
                        custom-upload
                        :auto="false"
                        :accept="ACCEPTED_ATTACHMENT_STRING"
                        :max-file-size="MAX_ATTACHMENT_SIZE"
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
                        @select="onFilesSelected"
                    >
                        <template #header="{ chooseCallback }">
                            <Button
                                type="button"
                                label="Add Files"
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
                    v-if="
                        visibleExistingFiles.length > 0 ||
                        selectedFiles.length > 0
                    "
                    class="grid grid-cols-2 gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-3 sm:grid-cols-3 md:grid-cols-4"
                >
                    <div
                        v-for="file in visibleExistingFiles"
                        :key="`existing-${file.id}`"
                        class="group relative aspect-[4/3] overflow-hidden rounded-xl border border-slate-200 bg-white"
                    >
                        <button
                            type="button"
                            class="flex h-full w-full items-center justify-center bg-white"
                            :disabled="!file.url"
                            :aria-label="
                                file.url
                                    ? 'Open uploaded attachment'
                                    : 'Attachment preview unavailable'
                            "
                            @click="openFile(file)"
                        >
                            <img
                                v-if="getExistingPreviewUrl(file)"
                                :src="getExistingPreviewUrl(file) ?? undefined"
                                alt="Uploaded attachment preview"
                                class="h-full w-full object-contain"
                                @error="markPreviewError(`existing:${file.id}`)"
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
                            aria-label="Remove existing attachment"
                            class="!absolute !top-2 !right-2 !h-8 !w-8"
                            @click="removeExistingFile(file)"
                        />
                    </div>

                    <div
                        v-for="attachment in selectedFiles"
                        :key="attachment.key"
                        class="relative aspect-[4/3] overflow-hidden rounded-xl border border-slate-200 bg-white"
                    >
                        <img
                            v-if="
                                attachment.isImage &&
                                attachment.previewUrl &&
                                !hasPreviewError(`new:${attachment.key}`)
                            "
                            :src="attachment.previewUrl"
                            alt="Selected attachment preview"
                            class="h-full w-full object-contain"
                            @error="markPreviewError(`new:${attachment.key}`)"
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
                            aria-label="Remove selected attachment"
                            class="!absolute !top-2 !right-2 !h-8 !w-8"
                            @click="removeNewFile(attachment)"
                        />
                    </div>
                </div>

                <Message
                    v-if="!hasAnySubmissionFile"
                    severity="warn"
                    :closable="false"
                    class="mt-2"
                >
                    At least one document attachment is required.
                </Message>

                <Button
                    v-if="removeExistingFileIds.length > 0"
                    type="button"
                    label="Restore removed files"
                    icon="pi pi-refresh"
                    severity="secondary"
                    text
                    size="small"
                    class="mt-2"
                    :disabled="saving"
                    @click="restoreExistingFiles"
                />
            </div>

            <div class="rounded-2xl border border-blue-100 bg-blue-50/60 p-4">
                <p class="font-bold text-slate-800">
                    Notice of Authenticity and Responsibility
                </p>

                <p class="mt-2 text-sm leading-6 text-slate-600">
                    By submitting this information, documents and images, you
                    confirm that they are true and correct to the best of your
                    knowledge. You assume full responsibility for their accuracy
                    and any discrepancies may lead to consequences under
                    relevant laws. Ensure all data provided is accurate and
                    authentic.
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
                @click="closeUploadDialog"
            />

            <Button
                type="button"
                :label="isEditing ? 'Save & Resubmit' : 'Submit Document'"
                icon="pi pi-check"
                severity="success"
                :loading="saving"
                :disabled="submitDisabled"
                @click="submitDocument"
            />
        </template>
    </Dialog>

    <Dialog
        v-model:visible="filesDialogVisible"
        modal
        header="Uploaded Files"
        class="w-[min(92vw,560px)]"
        @hide="closeFilesDialog"
    >
        <div class="space-y-3">
            <Button
                v-for="(file, index) in viewedFiles"
                :key="`${file.name}-${index}`"
                as="a"
                :href="file.url ?? undefined"
                target="_blank"
                rel="noopener noreferrer"
                severity="danger"
                variant="outlined"
                class="!flex !w-full !justify-start !gap-3 !rounded-xl !p-3"
            >
                <i :class="[getFileIcon(file.name), 'shrink-0 text-lg']"></i>

                <span class="min-w-0 text-left">
                    <span class="block text-xs font-semibold">
                        Document

                        {{ index + 1 }}
                    </span>

                    <span class="block truncate font-semibold">
                        {{ file.name }}
                    </span>
                </span>

                <i class="pi pi-external-link ml-auto shrink-0"></i>
            </Button>
        </div>
    </Dialog>

    <Dialog
        v-model:visible="deleteDialogVisible"
        modal
        header="Delete Document"
        :style="{
            width: 'min(460px, 94vw)',
        }"
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
                    Delete this document?
                </p>

                <p class="mt-1 text-sm leading-5 text-slate-500">
                    This removes the submission and its uploaded attachments.
                    Verified documents cannot be deleted.
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
            />

            <Button
                type="button"
                label="Delete"
                icon="pi pi-trash"
                severity="danger"
                :loading="deleting"
                @click="deleteDocument"
            />
        </template>
    </Dialog>
</template>
