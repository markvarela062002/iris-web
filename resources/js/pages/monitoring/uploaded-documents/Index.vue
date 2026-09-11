<script setup lang="ts">
import {
    Head,
    router,
} from '@inertiajs/vue3';
import axios from 'axios';
import Avatar from 'primevue/avatar';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Message from 'primevue/message';
import PrimeTag from 'primevue/tag';
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

/*
|--------------------------------------------------------------------------
| Page configuration
|--------------------------------------------------------------------------
*/

defineOptions({
    inheritAttrs: false,

    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
            {
                title: 'Uploaded Documents',
                href: '/monitoring/uploaded-documents',
            },
        ],
    },
});

/*
|--------------------------------------------------------------------------
| API response types
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| Page state
|--------------------------------------------------------------------------
*/

const documents = ref<DataTableRow[]>([]);

const loading = ref(false);

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

const errorMessage = ref('');

let requestController: AbortController | null =
    null;

/*
|--------------------------------------------------------------------------
| Datatable columns
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
        class: 'w-[360px] min-w-[360px]',
    },
    {
        field: 'desc_requirement',
        header: 'Requirement Type',
        sortable: false,
        searchable: false,
        class: 'w-[300px] min-w-[300px] whitespace-normal',
    },
    {
        field: 'date_uploaded',
        header: 'Date Uploaded',
        sortable: false,
        searchable: false,
        class: 'w-[220px] min-w-[220px]',
    },
    {
        field: 'sto_validated',
        header: 'Verified',
        sortable: true,
        searchable: false,
        class: 'w-[150px] min-w-[150px]',
    },
    {
        field: 'revise_remarks',
        header: 'Remarks',
        sortable: false,
        searchable: false,
        class: 'w-[260px] min-w-[260px]',
    },
];

/*
|--------------------------------------------------------------------------
| Datatable actions
|--------------------------------------------------------------------------
*/

const actions: DataTableAction[] = [
    {
        key: 'view-files',
        label: 'View or download file',
        icon: 'pi pi-download',
        severity: 'info',

        visible: (row) => {
            return (
                getUploadedFiles(row).length > 0
            );
        },
    },
    {
        key: 'no-files',
        label: 'No uploaded file',
        icon: 'pi pi-download',
        severity: 'secondary',

        visible: (row) => {
            return (
                getUploadedFiles(row).length === 0
            );
        },
    },
];

/*
|--------------------------------------------------------------------------
| Computed values
|--------------------------------------------------------------------------
*/

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
                '/api/v1/monitoring/datatable/uploaded-documents',
                {
                    signal: controller.signal,

                    params: {
                        page: pageNumber,
                        per_page: perPage.value,
                        search: search.value,
                        sort_field:
                            sortField.value,
                        sort_direction:
                            sortDirection.value,
                        monitoring: true,
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
                response.data.meta.currentPage -
                1
            ) *
            response.data.meta.perPage;
    } catch (error: unknown) {
        if (
            axios.isCancel(error) ||
            (
                axios.isAxiosError(error) &&
                error.code ===
                    'ERR_CANCELED'
            )
        ) {
            return;
        }

        documents.value = [];
        totalRecords.value = 0;

        errorMessage.value =
            'Unable to load uploaded documents.';

        console.error(
            'Unable to load uploaded documents:',
            error,
        );
    } finally {
        if (
            requestController === controller
        ) {
            loading.value = false;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Datatable events
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
| Actions
|--------------------------------------------------------------------------
*/

function handleAction(
    action: string,
    document: DataTableRow,
): void {
    errorMessage.value = '';

    if (action === 'no-files') {
        return;
    }

    if (action === 'view-files') {
        openUploadedFiles(document);
    }
}

function openUploadedFiles(
    document: DataTableRow,
): void {
    const files =
        getUploadedFiles(document);

    if (files.length === 0) {
        errorMessage.value =
            'This record does not have an uploaded file.';

        return;
    }

    /*
     * If there is only one file,
     * open it immediately.
     */
    if (files.length === 1) {
        window.open(
            files[0].url,
            '_blank',
            'noopener,noreferrer',
        );

        return;
    }

    /*
     * Multiple files:
     * show the files inside a dialog.
     */
    selectedDocument.value =
        document;

    filesDialogVisible.value = true;
}

function closeFilesDialog(): void {
    filesDialogVisible.value = false;
    selectedDocument.value = null;
}

/*
|--------------------------------------------------------------------------
| Navigation
|--------------------------------------------------------------------------
*/

function navigateToVerification(): void {
    router.visit(
        '/dashboard/uploaded-documents',
    );
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

    return initials.toUpperCase() || 'ST';
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
| Status helpers
|--------------------------------------------------------------------------
*/

function isVerified(
    document: DataTableRow,
): boolean {
    return String(
        document.sto_validated ?? '',
    )
        .trim()
        .toUpperCase() === 'Y';
}

function hasRevisionRemarks(
    document: DataTableRow,
): boolean {
    const remarks = String(
        document.revise_remarks ?? '',
    ).trim();

    return (
        remarks !== '' &&
        remarks !== '-'
    );
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
                typeof candidate !== 'object'
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
                    label,
                    url,
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
            timeZone: 'Asia/Manila',
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
    <Head title="Uploaded Documents" />

    <div
        class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5"
    >
        <!-- ERROR MESSAGE -->

        <Message
            v-if="errorMessage"
            severity="error"
            closable
            @close="errorMessage = ''"
        >
            {{ errorMessage }}
        </Message>

        <!-- UPLOADED DOCUMENTS TABLE -->

        <Datatable
            title="Uploaded Documents"
            description="Monitor student uploaded documents and their verification status."
            header-icon="pi pi-file"
            search-placeholder="Search uploaded documents..."
            empty-title="No uploaded documents found"
            empty-description="No matching uploaded document records were found."
            empty-icon="pi pi-file"
            table-min-width="1300px"
            data-key="id"
            lazy
            :loading="loading"
            :data="documents"
            :columns="columns"
            :actions="actions"
            :total-records="totalRecords"
            :first="first"
            :rows="perPage"
            :rows-per-page-options="[
                10,
                20,
                50,
                100,
            ]"
            @page="handlePage"
            @sort="handleSort"
            @search="handleSearch"
            @action="handleAction"
        >
            <!-- HEADER ACTIONS -->

            <template #header-actions>
                <Button
                    type="button"
                    label="Documents Verification"
                    icon="pi pi-file-check"
                    severity="info"
                    size="small"
                    @click="
                        navigateToVerification
                    "
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
                            class="mt-1 flex flex-wrap items-center gap-1.5"
                        >
                            <PrimeTag
                                :value="
                                    getSchoolIdLabel(
                                        data.school_id_no,
                                    )
                                "
                                severity="info"
                                icon="pi pi-id-card"
                                class="!px-2 !py-0.5 !text-xs !font-semibold"
                            />
                        </div>
                    </div>
                </div>
            </template>

            <!-- REQUIREMENT TYPE -->

            <template
                #cell-desc_requirement="{
                    value,
                }"
            >
                <div
                    class="flex min-w-0 items-start gap-2 whitespace-normal"
                >
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

            <!-- UPLOADED DATE -->

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

            <!-- VERIFIED STATUS -->

            <template
                #cell-sto_validated="{ data }"
            >
                <PrimeTag
                    v-if="isVerified(data)"
                    value="Verified"
                    severity="success"
                    icon="pi pi-check-circle"
                />

                <PrimeTag
                    v-else-if="
                        hasRevisionRemarks(data)
                    "
                    value="Revise"
                    severity="danger"
                    icon="pi pi-undo"
                />

                <PrimeTag
                    v-else
                    value="Pending"
                    severity="warn"
                    icon="pi pi-clock"
                />
            </template>

            <!-- REMARKS -->

            <template
                #cell-revise_remarks="{ value }"
            >
                <span
                    v-if="
                        value &&
                        String(value).trim() !== '-'
                    "
                    class="text-sm font-medium text-slate-700"
                >
                    {{ value }}
                </span>

                <span
                    v-else
                    class="text-slate-400"
                >
                    —
                </span>
            </template>
        </Datatable>

        <!-- MULTIPLE FILES DIALOG -->

        <Dialog
            v-model:visible="filesDialogVisible"
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
    </div>
</template>