<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import Avatar from 'primevue/avatar';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Message from 'primevue/message';
import PrimeTag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import {
    computed,
    onBeforeUnmount,
    onMounted,
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
                title: 'Documents Verification',
                href: '/dashboard/uploaded-documents',
            },
        ],
    },
});

type UploadedFile = {
    name: string;
    label: string;
    url: string;
};

type DocumentApiResponse = {
    data: DataTableRow[];

    meta: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
        from: number | null;
        to: number | null;
    };

    links: {
        first: string | null;
        last: string | null;
        previous: string | null;
        next: string | null;
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

const documents = ref<DataTableRow[]>([]);

const loading = ref(false);
const actionLoading = ref(false);

const totalRecords = ref(0);
const first = ref(0);
const perPage = ref(10);
const search = ref('');

const sortField = ref('date_uploaded');
const sortDirection = ref<'asc' | 'desc'>(
    'desc',
);

const selectedDocument =
    ref<DataTableRow | null>(null);

const filesDialogVisible = ref(false);
const verifyDialogVisible = ref(false);
const reviseDialogVisible = ref(false);

const reviseRemarks = ref('');
const reviseError = ref('');

const successMessage = ref('');
const errorMessage = ref('');

let requestController:
    | AbortController
    | null = null;

/*
|--------------------------------------------------------------------------
| DataTable columns
|--------------------------------------------------------------------------
*/

const columns: DataTableColumn[] = [
    {
        field: 'fname',
        header: 'Student Information',
        sortable: false,
        searchable: true,
        frozen: true,
        alignFrozen: 'left',
        class: 'min-w-[320px]',
    },
    {
        field: 'file_desc',
        header: 'File Description',
        sortable: false,
        searchable: true,
        class: 'min-w-[260px] whitespace-normal',
    },
    {
        field: 'desc_requirement',
        header: 'Requirement Type',
        sortable: false,
        searchable: true,
        class: 'min-w-[260px] whitespace-normal',
    },
    {
        field: 'date_uploaded',
        header: 'Date Uploaded',
        sortable: true,
        searchable: false,
        class: 'min-w-[180px]',
    },
];

/*
|--------------------------------------------------------------------------
| DataTable actions
|--------------------------------------------------------------------------
*/

const actions: DataTableAction[] = [
    /*
     * Uploaded files are available.
     */
    {
        key: 'view-files',
        label: 'View or Download Files',
        icon: 'pi pi-download',
        severity: 'info',

        visible: (
            row: DataTableRow,
        ): boolean => {
            return (
                getUploadedFiles(row).length >
                0
            );
        },
    },

    /*
     * No uploaded files fallback.
     */
    {
        key: 'no-files',
        label: 'No Uploaded Files',
        icon: 'pi pi-download',
        severity: 'secondary',

        visible: (
            row: DataTableRow,
        ): boolean => {
            return (
                getUploadedFiles(row).length ===
                0
            );
        },

        disabled: (): boolean => true,
    },

    /*
     * Verify document.
     */
    {
        key: 'verify',
        label: 'Verify Document',
        icon: 'pi pi-check-circle',
        severity: 'success',
    },

    /*
     * Request revision.
     */
    {
        key: 'revise',
        label: 'Request Revision',
        icon: 'pi pi-undo',
        severity: 'warn',
    },
];

const currentPage = computed(() => {
    return (
        Math.floor(
            first.value / perPage.value,
        ) + 1
    );
});

const selectedStudentName = computed(() => {
    if (!selectedDocument.value) {
        return '';
    }

    return getStudentFullName(
        selectedDocument.value,
    );
});

const selectedFiles = computed<
    UploadedFile[]
>(() => {
    if (!selectedDocument.value) {
        return [];
    }

    return getUploadedFiles(
        selectedDocument.value,
    );
});

/*
|--------------------------------------------------------------------------
| Load documents
|--------------------------------------------------------------------------
*/

async function loadDocuments(
    pageNumber = 1,
): Promise<void> {
    requestController?.abort();

    const controller =
        new AbortController();

    requestController = controller;
    loading.value = true;
    errorMessage.value = '';

    try {
        const response =
            await axios.get<DocumentApiResponse>(
                '/api/v1/dashboard/datatable/uploaded-documents',
                {
                    signal:
                        controller.signal,

                    params: {
                        page: pageNumber,

                        per_page:
                            perPage.value,

                        search:
                            search.value,

                        sort_field:
                            sortField.value,

                        sort_direction:
                            sortDirection.value,
                    },

                    headers: {
                        Accept:
                            'application/json',

                        'X-Requested-With':
                            'XMLHttpRequest',
                    },

                    withCredentials: true,
                },
            );

        documents.value =
            response.data.data;

        totalRecords.value =
            response.data.meta.total;

        perPage.value =
            response.data.meta.perPage;

        first.value =
            (
                response.data.meta
                    .currentPage - 1
            ) *
            response.data.meta.perPage;
    } catch (error: unknown) {
        if (
            axios.isCancel(error) ||
            (
                axios.isAxiosError(
                    error,
                ) &&
                error.code ===
                    'ERR_CANCELED'
            )
        ) {
            return;
        }

        documents.value = [];
        totalRecords.value = 0;

        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to load uploaded documents.',
            );

        console.error(
            'Unable to load uploaded documents:',
            error,
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

/*
|--------------------------------------------------------------------------
| DataTable events
|--------------------------------------------------------------------------
*/

function handlePage(
    event: DataTablePageEvent,
): void {
    perPage.value = event.rows;
    first.value = event.first;

    void loadDocuments(
        event.page + 1,
    );
}

function handleSort(
    event: DataTableSortEvent,
): void {
    sortField.value =
        event.sortField ||
        'date_uploaded';

    sortDirection.value =
        event.sortOrder === -1
            ? 'desc'
            : 'asc';

    first.value = 0;

    void loadDocuments(1);
}

function handleSearch(
    value: string,
): void {
    search.value = value;
    first.value = 0;

    void loadDocuments(1);
}

/*
|--------------------------------------------------------------------------
| Navigation
|--------------------------------------------------------------------------
*/

function navigateToDashboard(): void {
    router.visit('/dashboard');
}

function navigateToDocumentList(): void {
    router.visit('/monitoring/uploaded-documents');
}

/*
|--------------------------------------------------------------------------
| Actions
|--------------------------------------------------------------------------
*/

function handleAction(
    action: string,
    document: DataTableRow,
): void {
    successMessage.value = '';
    errorMessage.value = '';

    /*
     * Ignore the disabled missing-file
     * fallback action.
     */
    if (action === 'no-files') {
        return;
    }

    selectedDocument.value =
        document;

    if (action === 'view-files') {
        if (
            getUploadedFiles(document)
                .length === 0
        ) {
            selectedDocument.value = null;

            return;
        }

        openUploadedFiles(document);

        return;
    }

    if (action === 'verify') {
        verifyDialogVisible.value =
            true;

        return;
    }

    if (action === 'revise') {
        reviseRemarks.value = '';
        reviseError.value = '';

        reviseDialogVisible.value =
            true;
    }
}

function openUploadedFiles(
    document: DataTableRow,
): void {
    const files =
        getUploadedFiles(document);

    if (files.length === 0) {
        selectedDocument.value = null;

        errorMessage.value =
            'This record does not have an uploaded file.';

        return;
    }

    if (files.length === 1) {
        window.open(
            files[0].url,
            '_blank',
            'noopener,noreferrer',
        );

        selectedDocument.value = null;

        return;
    }

    filesDialogVisible.value = true;
}

function closeFilesDialog(): void {
    filesDialogVisible.value = false;
    selectedDocument.value = null;
}

/*
|--------------------------------------------------------------------------
| Verify document
|--------------------------------------------------------------------------
*/

function closeVerifyDialog(): void {
    if (actionLoading.value) {
        return;
    }

    verifyDialogVisible.value = false;
    selectedDocument.value = null;
}

async function verifyDocument(): Promise<void> {
    const documentId =
        getSelectedDocumentId();

    if (!documentId) {
        errorMessage.value =
            'The selected document ID is missing.';

        return;
    }

    actionLoading.value = true;
    errorMessage.value = '';

    try {
        const response =
            await axios.patch<{
                message: string;
            }>(
                `/api/v1/dashboard/uploaded-documents/${encodeURIComponent(
                    documentId,
                )}/verify`,
                {},
                {
                    headers: {
                        Accept:
                            'application/json',

                        'X-Requested-With':
                            'XMLHttpRequest',
                    },

                    withCredentials: true,
                },
            );

        successMessage.value =
            response.data.message ||
            'The file has been validated.';

        verifyDialogVisible.value =
            false;

        selectedDocument.value = null;

        await reloadCurrentPage();
    } catch (error: unknown) {
        errorMessage.value =
            getErrorMessage(
                error,
                'Failed to validate the file.',
            );
    } finally {
        actionLoading.value = false;
    }
}

/*
|--------------------------------------------------------------------------
| Revise document
|--------------------------------------------------------------------------
*/

function closeReviseDialog(): void {
    if (actionLoading.value) {
        return;
    }

    reviseDialogVisible.value = false;
    reviseRemarks.value = '';
    reviseError.value = '';
    selectedDocument.value = null;
}

async function reviseDocument(): Promise<void> {
    reviseError.value = '';

    if (
        reviseRemarks.value.trim() === ''
    ) {
        reviseError.value =
            'Reason for revision is required.';

        return;
    }

    const documentId =
        getSelectedDocumentId();

    if (!documentId) {
        reviseError.value =
            'The selected document ID is missing.';

        return;
    }

    actionLoading.value = true;
    errorMessage.value = '';

    try {
        const response =
            await axios.patch<{
                message: string;
            }>(
                `/api/v1/dashboard/uploaded-documents/${encodeURIComponent(
                    documentId,
                )}/revise`,
                {
                    revise_remarks:
                        reviseRemarks.value.trim(),
                },
                {
                    headers: {
                        Accept:
                            'application/json',

                        'X-Requested-With':
                            'XMLHttpRequest',
                    },

                    withCredentials: true,
                },
            );

        successMessage.value =
            response.data.message ||
            'The submitted record has been saved.';

        reviseDialogVisible.value =
            false;

        reviseRemarks.value = '';
        reviseError.value = '';
        selectedDocument.value = null;

        await reloadCurrentPage();
    } catch (error: unknown) {
        const validationMessage =
            getValidationMessage(
                error,
                'revise_remarks',
            );

        reviseError.value =
            validationMessage ??
            getErrorMessage(
                error,
                'Failed to save the record.',
            );
    } finally {
        actionLoading.value = false;
    }
}

async function reloadCurrentPage(): Promise<void> {
    await loadDocuments(
        currentPage.value,
    );

    if (
        documents.value.length === 0 &&
        currentPage.value > 1
    ) {
        await loadDocuments(
            currentPage.value - 1,
        );
    }
}

/*
|--------------------------------------------------------------------------
| Student helpers
|--------------------------------------------------------------------------
*/

function getStudentFullName(
    document: DataTableRow,
): string {
    const lastName = String(
        document.lname ?? '',
    ).trim();

    const otherNames = [
        document.fname,
        document.mname,
    ]
        .filter((name) => {
            return (
                typeof name === 'string' &&
                name.trim() !== ''
            );
        })
        .map((name) => {
            return String(name).trim();
        })
        .join(' ');

    if (lastName && otherNames) {
        return `${lastName}, ${otherNames}`.toUpperCase();
    }

    return (
        lastName || otherNames
    ).toUpperCase();
}

function getStudentInitials(
    document: DataTableRow,
): string {
    const firstName = String(
        document.fname ?? '',
    ).trim();

    const lastName = String(
        document.lname ?? '',
    ).trim();

    const initials =
        `${firstName.charAt(0)}${lastName.charAt(0)}`;

    return (
        initials.toUpperCase() ||
        'ST'
    );
}

function getStudentAvatar(
    gender: unknown,
): string | null {
    const normalizedGender = String(
        gender ?? '',
    )
        .trim()
        .toUpperCase();

    if (
        normalizedGender === 'M' ||
        normalizedGender === 'MALE'
    ) {
        return '/images/male-cadet.png';
    }

    if (
        normalizedGender === 'F' ||
        normalizedGender === 'FEMALE'
    ) {
        return '/images/female-cadet.png';
    }

    return null;
}

function getSystemIdLabel(
    value: unknown,
): string {
    const systemId = String(
        value ?? '',
    ).trim();

    return systemId || 'No System ID';
}

function getSchoolIdLabel(
    value: unknown,
): string {
    const schoolId = String(
        value ?? '',
    ).trim();

    return schoolId || 'No School ID';
}

/*
|--------------------------------------------------------------------------
| File helpers
|--------------------------------------------------------------------------
*/

function getUploadedFiles(
    document: DataTableRow,
): UploadedFile[] {
    if (!Array.isArray(document.files)) {
        return [];
    }

    return document.files.flatMap(
        (candidate): UploadedFile[] => {
            if (
                candidate === null ||
                typeof candidate !==
                    'object'
            ) {
                return [];
            }

            const file =
                candidate as Record<
                    string,
                    unknown
                >;

            const name = String(
                file.name ?? '',
            ).trim();

            const url = String(
                file.url ?? '',
            ).trim();

            const label = String(
                file.label ??
                    'View or download file',
            ).trim();

            if (
                name === '' ||
                url === ''
            ) {
                return [];
            }

            return [
                {
                    name,
                    url,
                    label,
                },
            ];
        },
    );
}

function getUploadedFileIcon(
    filename: string,
): string {
    const extension =
        filename
            .split('.')
            .pop()
            ?.trim()
            .toLowerCase() ?? '';

    if (extension === 'pdf') {
        return 'pi pi-file-pdf';
    }

    if (
        extension === 'doc' ||
        extension === 'docx'
    ) {
        return 'pi pi-file-word';
    }

    if (
        extension === 'xls' ||
        extension === 'xlsx' ||
        extension === 'csv'
    ) {
        return 'pi pi-file-excel';
    }

    if (
        extension === 'jpg' ||
        extension === 'jpeg' ||
        extension === 'png' ||
        extension === 'gif' ||
        extension === 'webp'
    ) {
        return 'pi pi-image';
    }

    if (
        extension === 'zip' ||
        extension === 'rar' ||
        extension === '7z'
    ) {
        return 'pi pi-box';
    }

    return 'pi pi-file';
}

/*
|--------------------------------------------------------------------------
| Date helpers
|--------------------------------------------------------------------------
*/

function formatUploadedDate(
    dateValue: unknown,
    timeValue: unknown,
): string {
    const date = String(
        dateValue ?? '',
    ).trim();

    const time = String(
        timeValue ?? '',
    ).trim();

    if (
        !date ||
        date === '1970-01-01'
    ) {
        return '—';
    }

    const combinedValue = time
        ? `${date} ${time}`
        : date;

    const normalizedValue =
        combinedValue.replace(
            ' ',
            'T',
        );

    const parsedDate =
        new Date(normalizedValue);

    if (
        Number.isNaN(
            parsedDate.getTime(),
        )
    ) {
        return combinedValue;
    }

    return new Intl.DateTimeFormat(
        'en-PH',
        {
            timeZone:
                'Asia/Manila',

            month: 'short',
            day: 'numeric',
            year: 'numeric',

            hour: time
                ? '2-digit'
                : undefined,

            minute: time
                ? '2-digit'
                : undefined,

            hour12: true,
        },
    ).format(parsedDate);
}

/*
|--------------------------------------------------------------------------
| Response helpers
|--------------------------------------------------------------------------
*/

function getSelectedDocumentId(): string {
    return String(
        selectedDocument.value?.id ?? '',
    ).trim();
}

function getErrorMessage(
    error: unknown,
    fallback: string,
): string {
    if (!axios.isAxiosError(error)) {
        return fallback;
    }

    const responseData =
        error.response?.data as
            | {
                  message?: string;
              }
            | undefined;

    return (
        responseData?.message ||
        fallback
    );
}

function getValidationMessage(
    error: unknown,
    field: string,
): string | null {
    if (!axios.isAxiosError(error)) {
        return null;
    }

    const responseData =
        error.response?.data as
            | {
                  errors?: Record<
                      string,
                      string[]
                  >;
              }
            | undefined;

    return (
        responseData
            ?.errors?.[field]?.[0] ??
        null
    );
}

/*
|--------------------------------------------------------------------------
| Lifecycle
|--------------------------------------------------------------------------
*/

onMounted(() => {
    void loadDocuments(1);
});

onBeforeUnmount(() => {
    requestController?.abort();
});
</script>

<template>
    <Head title="Documents Verification" />

    <div
        class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5"
    >
        <!-- SUCCESS MESSAGE -->

        <Message
            v-if="successMessage"
            severity="success"
            closable
            @close="successMessage = ''"
        >
            {{ successMessage }}
        </Message>

        <!-- ERROR MESSAGE -->

        <Message
            v-if="errorMessage"
            severity="error"
            closable
            @close="errorMessage = ''"
        >
            {{ errorMessage }}
        </Message>

        <!-- DOCUMENTS DATATABLE -->

        <Datatable
    title="Documents Verification"
    description="Review, verify, or return uploaded student documents for revision."
    header-icon="pi pi-file-check"
    search-placeholder="Search uploaded documents..."
    empty-title="No documents to verify"
    empty-description="There are no uploaded documents waiting for verification."
    empty-icon="pi pi-file-check"
    table-min-width="1200px"
    actions-width="170px"
    actions-header="Actions"
    data-key="id"
    lazy
    :loading="loading"
    :data="documents"
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
            <!-- HEADER ACTIONS -->

            <template #header-actions>
                <Button
                    type="button"
                    label="Document Upload List"
                    icon="pi pi-list-check"
                    severity="info"
                    size="small"
                    @click="navigateToDocumentList"
                />
            </template>

            <!-- STUDENT INFORMATION -->

            <template #cell-fname="{ data }">
                <div
                    class="flex items-center gap-3"
                >
                    <Avatar
                        v-if="
                            getStudentAvatar(
                                data.gender,
                            )
                        "
                        :image="
                            getStudentAvatar(
                                data.gender,
                            ) ?? undefined
                        "
                        :aria-label="
                            getStudentFullName(
                                data,
                            )
                        "
                        shape="circle"
                        size="large"
                        class="shrink-0"
                    />

                    <Avatar
                        v-else
                        :label="
                            getStudentInitials(
                                data,
                            )
                        "
                        shape="circle"
                        size="large"
                        class="shrink-0 !bg-[#377EC0]/10 !text-xs !font-bold !text-[#377EC0]"
                    />

                    <div class="min-w-0">
                        <p
                            class="truncate font-semibold text-slate-700"
                        >
                            {{
                                getStudentFullName(
                                    data,
                                ) || '—'
                            }}
                        </p>

                        <div
    class="mt-1 flex flex-nowrap items-center gap-1.5"
>
    <PrimeTag
        :value="
            getSchoolIdLabel(
                data.school_id_no,
            )
        "
        severity="info"
        icon="pi pi-id-card"
        rounded
        class="shrink-0 !whitespace-nowrap !px-2 !py-0.5 !text-xs !font-semibold"
    />
</div>
                    </div>
                </div>
            </template>

            <!-- FILE DESCRIPTION -->

            <template
                #cell-file_desc="{ value }"
            >
                <div
                    class="flex min-w-0 items-start gap-2"
                >
                    <i
                        class="pi pi-file mt-0.5 shrink-0 text-[#377EC0]"
                    ></i>

                    <span
                        class="min-w-0 font-medium break-words whitespace-normal text-slate-700"
                    >
                        {{ value || '—' }}
                    </span>
                </div>
            </template>

            <!-- REQUIREMENT TYPE -->

            <template
                #cell-desc_requirement="{
                    value,
                }"
            >
                <div
                    class="flex min-w-0 items-start gap-2"
                >
                    <i
                        class="pi pi-list-check mt-0.5 shrink-0 text-emerald-500"
                    ></i>

                    <span
                        class="min-w-0 font-medium break-words whitespace-normal text-slate-700"
                    >
                        {{ value || '—' }}
                    </span>
                </div>
            </template>

            <!-- DATE UPLOADED -->

            <template
                #cell-date_uploaded="{ data }"
            >
                <div
                    class="flex items-center gap-2"
                >
                    <i
                        class="pi pi-clock text-lg text-yellow-500"
                    ></i>

                    <span
                        class="whitespace-nowrap text-sm font-medium text-slate-600"
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
        </Datatable>

        <!-- MULTIPLE FILES DIALOG -->

        <Dialog
            v-model:visible="
                filesDialogVisible
            "
            modal
            header="Uploaded Files"
            class="w-[min(92vw,560px)]"
            @hide="closeFilesDialog"
        >
            <div class="space-y-3">
                <Button
                    v-for="(
                        file,
                        index
                    ) in selectedFiles"
                    :key="
                        `${file.name}-${index}`
                    "
                    as="a"
                    :href="file.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    severity="danger"
                    variant="outlined"
                    class="!flex !w-full !justify-start !gap-3 !rounded-xl !p-3"
                >
                    <i
                        :class="[
                            getUploadedFileIcon(
                                file.name,
                            ),
                            'shrink-0 text-lg',
                        ]"
                    ></i>

                    <span
                        class="min-w-0 text-left"
                    >
                        <span
                            class="block text-xs font-semibold"
                        >
                            Document
                            {{ index + 1 }}
                        </span>

                        <span
                            class="block truncate font-semibold"
                        >
                            {{ file.name }}
                        </span>
                    </span>

                    <i
                        class="pi pi-external-link ml-auto shrink-0"
                    ></i>
                </Button>
            </div>
        </Dialog>

        <!-- VERIFY DIALOG -->

        <Dialog
            v-model:visible="
                verifyDialogVisible
            "
            modal
            header="Notice of Verification"
            :closable="!actionLoading"
            :dismissable-mask="
                !actionLoading
            "
            class="w-[min(92vw,560px)]"
        >
            <div class="space-y-4">
                <Message
                    severity="info"
                    :closable="false"
                >
                    By marking the submitted
                    information and documents from
                    the student as
                    <strong>verified</strong>, I
                    confirm their accuracy,
                    truthfulness, and authenticity
                    based solely on the information
                    available to me.
                </Message>

                <div
                    v-if="selectedDocument"
                    class="rounded-xl border border-slate-200 p-4"
                >
                    <p
                        class="text-xs font-semibold text-slate-400 uppercase"
                    >
                        Student
                    </p>

                    <p
                        class="mt-1 font-semibold text-slate-700"
                    >
                        {{
                            selectedStudentName ||
                            '—'
                        }}
                    </p>

                    <p
                        class="mt-3 text-xs font-semibold text-slate-400 uppercase"
                    >
                        Document
                    </p>

                    <p
                        class="mt-1 text-sm text-slate-600"
                    >
                        {{
                            selectedDocument.file_desc ||
                            '—'
                        }}
                    </p>
                </div>
            </div>

            <template #footer>
                <Button
                    type="button"
                    label="Cancel"
                    icon="pi pi-times"
                    severity="secondary"
                    variant="outlined"
                    :disabled="actionLoading"
                    @click="closeVerifyDialog"
                />

                <Button
                    type="button"
                    label="Confirm"
                    icon="pi pi-check"
                    severity="success"
                    :loading="actionLoading"
                    :disabled="actionLoading"
                    @click="verifyDocument"
                />
            </template>
        </Dialog>

        <!-- REVISION DIALOG -->

        <Dialog
            v-model:visible="
                reviseDialogVisible
            "
            modal
            header="Revision Details"
            :closable="!actionLoading"
            :dismissable-mask="
                !actionLoading
            "
            class="w-[min(92vw,560px)]"
        >
            <div class="space-y-4">
                <div
                    v-if="selectedDocument"
                    class="rounded-xl border border-slate-200 bg-slate-50 p-4"
                >
                    <p
                        class="text-xs font-semibold text-slate-400 uppercase"
                    >
                        Student
                    </p>

                    <p
                        class="mt-1 font-semibold text-slate-700"
                    >
                        {{
                            selectedStudentName ||
                            '—'
                        }}
                    </p>

                    <p
                        class="mt-3 text-xs font-semibold text-slate-400 uppercase"
                    >
                        Document
                    </p>

                    <p
                        class="mt-1 text-sm text-slate-600"
                    >
                        {{
                            selectedDocument.file_desc ||
                            '—'
                        }}
                    </p>
                </div>

                <div>
                    <label
                        for="revise_remarks"
                        class="mb-2 block text-sm font-semibold text-slate-700"
                    >
                        Reason for revision

                        <span
                            class="text-red-500"
                        >
                            *
                        </span>
                    </label>

                    <Textarea
                        id="revise_remarks"
                        v-model="reviseRemarks"
                        rows="5"
                        auto-resize
                        fluid
                        placeholder="Enter the reason this document must be revised..."
                        :invalid="
                            Boolean(
                                reviseError,
                            )
                        "
                        :disabled="
                            actionLoading
                        "
                        @input="
                            reviseError = ''
                        "
                    />

                    <Message
                        v-if="reviseError"
                        severity="error"
                        variant="simple"
                        size="small"
                        class="mt-2"
                    >
                        {{ reviseError }}
                    </Message>
                </div>
            </div>

            <template #footer>
                <Button
                    type="button"
                    label="Cancel"
                    icon="pi pi-times"
                    severity="secondary"
                    variant="outlined"
                    :disabled="actionLoading"
                    @click="closeReviseDialog"
                />

                <Button
                    type="button"
                    label="Submit"
                    icon="pi pi-send"
                    severity="warn"
                    :loading="actionLoading"
                    :disabled="actionLoading"
                    @click="reviseDocument"
                />
            </template>
        </Dialog>
    </div>
</template>