<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
} from 'vue';

import Datatable from '@/components/Datatable.vue';
import type {
    DataTableAction,
    DataTableColumn,
    DataTableRow,
} from '@/types';

type PracticalRow = DataTableRow & {
    id: string;
    title_assess: string;
    grade_system: string;
    from_date: string | null;
    due_date: string | null;
    date_taken: string | null;
    date_assessed: string | null;
    assessor: string;
    status: string;
    is_completed: boolean;
    is_pending: boolean;
};

type PracticalItem = {
    id: string;
    description: string;
    remarks: string;
    reference_file: {
        name: string;
        url: string;
    } | null;
    evidence_file: {
        name: string;
        url: string;
    } | null;
    points: number | null;
    maximum_points: number | null;
};

type PracticalDetail = {
    id: string;
    title: string;
    instructions: string;
    grade_system: string;
    from_date: string | null;
    due_date: string | null;
    date_taken: string | null;
    date_assessed: string | null;
    assessor: string;
    status: string;
    is_completed: boolean;
    is_pending: boolean;
    is_editable: boolean;
    items: PracticalItem[];
    reference_files: Array<{
        id: string;
        name: string;
        url: string;
    }>;
    result: null | {
        earned_points: number;
        maximum_points: number;
        percentage: number | null;
        passing_mark: number;
        remarks: string;
    };
};

const loading = ref(false);
const detailLoading = ref(false);
const savingItemId = ref<string | null>(null);
const submitting = ref(false);

const assessments = ref<PracticalRow[]>([]);
const selectedAssessment = ref<PracticalDetail | null>(null);

const detailVisible = ref(false);
const submitVisible = ref(false);

const successMessage = ref('');
const errorMessage = ref('');

const first = ref(0);
const rows = ref(10);
const totalRecords = ref(0);
const search = ref('');
const sortField = ref('due_date');
const sortOrder = ref(-1);

const remarks = reactive<Record<string, string>>({});
const pendingFiles = reactive<Record<string, File | null>>({});

const evidenceInputs =
    reactive<Record<string, HTMLInputElement | null>>({});

const evidencePreviewUrls =
    reactive<Record<string, string>>({});

const draggingEvidence =
    reactive<Record<string, boolean>>({});

const evidenceAccept =
    '.jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv';

const evidenceMaxSize = 20 * 1024 * 1024;

const allowedEvidenceExtensions = new Set([
    'jpg',
    'jpeg',
    'png',
    'gif',
    'webp',
    'pdf',
    'doc',
    'docx',
    'xls',
    'xlsx',
    'txt',
    'csv',
]);

const columns: DataTableColumn[] = [
    {
        field: 'title_assess',
        header: 'Assessment Details',
        sortable: false,
        searchable: true,
        class: 'min-w-[340px] whitespace-normal',
    },
    {
        field: 'due_date',
        header: 'Assessment Period',
        sortable: false,
        searchable: false,
        class: 'min-w-[230px]',
    },
    {
        field: 'assessor',
        header: 'Assessor',
        sortable: false,
        searchable: false,
        class: 'min-w-[180px]',
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
        key: 'view',
        label: 'Open practical assessment',
        icon: 'pi pi-pencil',
        severity: 'warn',
    },
];

const currentPage = computed(
    () => Math.floor(first.value / rows.value) + 1,
);

function statusSeverity(status: string) {
    if (status === 'Completed') {
        return 'success';
    }

    if (status === 'For Assessment') {
        return 'warn';
    }

    return 'info';
}

function formatDate(value: string | null) {
    if (!value) {
        return 'Not set';
    }

    const rawValue = String(value).trim();

    if (
        rawValue.startsWith('0000-00-00') ||
        rawValue.startsWith('1970-01-01')
    ) {
        return 'Not set';
    }

    const normalized = rawValue.includes('T')
        ? rawValue
        : rawValue.replace(' ', 'T');

    const date = new Date(normalized);

    if (Number.isNaN(date.getTime())) {
        return rawValue;
    }

    return new Intl.DateTimeFormat('en-PH', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(date);
}

function extractError(
    error: unknown,
    fallback: string,
) {
    if (axios.isAxiosError(error)) {
        const data = error.response?.data as
            | {
                  message?: string;
                  errors?: Record<string, string[]>;
              }
            | undefined;

        const validation = data?.errors
            ? Object.values(data.errors).flat()[0]
            : null;

        return validation || data?.message || fallback;
    }

    return fallback;
}

async function loadAssessments() {
    loading.value = true;
    errorMessage.value = '';

    try {
        const response = await axios.get(
            '/api/v1/student/practical-internal',
            {
                params: {
                    page: currentPage.value,
                    per_page: rows.value,
                    search: search.value,
                    sort_field: sortField.value,
                    sort_direction:
                        sortOrder.value === 1
                            ? 'asc'
                            : 'desc',
                },
            },
        );

        assessments.value =
            response.data.data ?? [];

        totalRecords.value =
            response.data.meta?.total ?? 0;
    } catch (error) {
        errorMessage.value = extractError(
            error,
            'Unable to load your practical assessments.',
        );
    } finally {
        loading.value = false;
    }
}

async function openAssessment(
    row: PracticalRow,
) {
    detailVisible.value = true;
    detailLoading.value = true;
    selectedAssessment.value = null;
    errorMessage.value = '';

    try {
        const response = await axios.get(
            `/api/v1/student/practical-internal/${row.id}`,
        );

        selectedAssessment.value =
            response.data.data;

        for (
            const item of
            selectedAssessment.value?.items ?? []
        ) {
            remarks[item.id] =
                item.remarks ?? '';

            clearPendingEvidence(item.id);
        }
    } catch (error) {
        detailVisible.value = false;

        errorMessage.value = extractError(
            error,
            'Unable to open this practical assessment.',
        );
    } finally {
        detailLoading.value = false;
    }
}

function handleAction(
    action: string,
    row: DataTableRow,
): void {
    if (action !== 'view') {
        return;
    }

    void openAssessment(
        row as PracticalRow,
    );
}

function handlePage(event: {
    first: number;
    rows: number;
}) {
    first.value = event.first;
    rows.value = event.rows;

    void loadAssessments();
}

function handleSort(event: {
    sortField?: string;
    sortOrder?: number;
}) {
    sortField.value =
        event.sortField || 'due_date';

    sortOrder.value =
        event.sortOrder || -1;

    first.value = 0;

    void loadAssessments();
}

function handleSearch(value: string) {
    search.value = value;
    first.value = 0;

    void loadAssessments();
}

function setEvidenceInput(
    itemId: string,
    element: unknown,
) {
    evidenceInputs[itemId] =
        element instanceof HTMLInputElement
            ? element
            : null;
}

function openEvidencePicker(
    itemId: string,
) {
    if (
        savingItemId.value === itemId
    ) {
        return;
    }

    evidenceInputs[itemId]?.click();
}

function clearEvidencePreview(
    itemId: string,
) {
    const url =
        evidencePreviewUrls[itemId];

    if (!url) {
        return;
    }

    URL.revokeObjectURL(url);

    delete evidencePreviewUrls[
        itemId
    ];
}

function getFileExtension(
    file: File,
) {
    return (
        file.name
            .split('.')
            .pop()
            ?.toLowerCase() ?? ''
    );
}

function isImageFile(
    file: File | null | undefined,
) {
    if (!file) {
        return false;
    }

    const extension =
        getFileExtension(file);

    return (
        file.type.startsWith(
            'image/',
        ) ||
        [
            'jpg',
            'jpeg',
            'png',
            'gif',
            'webp',
        ].includes(extension)
    );
}

function selectedEvidenceKind(
    file: File | null | undefined,
) {
    if (!file) {
        return 'File';
    }

    const extension =
        getFileExtension(file);

    if (isImageFile(file)) {
        return 'Image';
    }

    if (extension === 'pdf') {
        return 'PDF';
    }

    if (
        ['doc', 'docx'].includes(
            extension,
        )
    ) {
        return 'Document';
    }

    if (
        ['xls', 'xlsx'].includes(
            extension,
        )
    ) {
        return 'Spreadsheet';
    }

    if (extension === 'csv') {
        return 'CSV';
    }

    if (extension === 'txt') {
        return 'Text file';
    }

    return 'File';
}

function selectedEvidenceIcon(
    file: File | null | undefined,
) {
    if (!file) {
        return 'pi pi-file';
    }

    const extension =
        getFileExtension(file);

    if (isImageFile(file)) {
        return 'pi pi-image';
    }

    if (extension === 'pdf') {
        return 'pi pi-file-pdf';
    }

    if (
        ['xls', 'xlsx', 'csv'].includes(
            extension,
        )
    ) {
        return 'pi pi-file-excel';
    }

    if (
        ['doc', 'docx', 'txt'].includes(
            extension,
        )
    ) {
        return 'pi pi-file';
    }

    return 'pi pi-paperclip';
}

function selectedEvidenceSize(
    file: File | null | undefined,
) {
    if (!file) {
        return '';
    }

    const kb = file.size / 1024;

    if (kb < 1024) {
        return `${kb.toFixed(1)} KB`;
    }

    return `${(
        kb / 1024
    ).toFixed(2)} MB`;
}

function applyEvidenceFile(
    itemId: string,
    file: File | null,
) {
    if (!file) {
        return;
    }

    const extension =
        getFileExtension(file);

    if (
        !allowedEvidenceExtensions.has(
            extension,
        )
    ) {
        errorMessage.value =
            'Unsupported evidence file type.';

        return;
    }

    if (
        file.size >
        evidenceMaxSize
    ) {
        errorMessage.value =
            'Evidence files must not exceed 20 MB.';

        return;
    }

    errorMessage.value = '';

    clearEvidencePreview(itemId);

    pendingFiles[itemId] =
        file;

    if (isImageFile(file)) {
        evidencePreviewUrls[itemId] =
            URL.createObjectURL(file);
    }
}

function handleEvidenceInput(
    itemId: string,
    event: Event,
) {
    const input =
        event.target as HTMLInputElement;

    applyEvidenceFile(
        itemId,
        input.files?.[0] ?? null,
    );
}

function handleEvidenceDragOver(
    itemId: string,
    event: DragEvent,
) {
    if (
        savingItemId.value === itemId
    ) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();

    draggingEvidence[itemId] =
        true;

    if (event.dataTransfer) {
        event.dataTransfer.dropEffect =
            'copy';
    }
}

function handleEvidenceDragLeave(
    itemId: string,
    event: DragEvent,
) {
    event.preventDefault();
    event.stopPropagation();

    draggingEvidence[itemId] =
        false;
}

function handleEvidenceDrop(
    itemId: string,
    event: DragEvent,
) {
    event.preventDefault();
    event.stopPropagation();

    draggingEvidence[itemId] =
        false;

    if (
        savingItemId.value === itemId
    ) {
        return;
    }

    applyEvidenceFile(
        itemId,
        event.dataTransfer
            ?.files?.[0] ?? null,
    );
}

function clearPendingEvidence(
    itemId: string,
) {
    clearEvidencePreview(itemId);

    pendingFiles[itemId] = null;

    const input =
        evidenceInputs[itemId];

    if (input) {
        input.value = '';
    }

    draggingEvidence[itemId] =
        false;
}

async function saveItem(
    item: PracticalItem,
) {
    if (
        !selectedAssessment.value
            ?.is_editable
    ) {
        return;
    }

    savingItemId.value =
        item.id;

    errorMessage.value = '';
    successMessage.value = '';

    try {
        const form =
            new FormData();

        form.append(
            'remarks',
            remarks[item.id] ?? '',
        );

        if (
            pendingFiles[item.id]
        ) {
            form.append(
                'evidence',
                pendingFiles[
                    item.id
                ] as File,
            );
        }

        await axios.post(
            `/api/v1/student/practical-internal/${selectedAssessment.value.id}/items/${item.id}`,
            form,
        );

        successMessage.value =
            'Item saved.';

        clearPendingEvidence(
            item.id,
        );

        await openAssessment({
            id:
                selectedAssessment
                    .value.id,
        } as PracticalRow);
    } catch (error) {
        errorMessage.value =
            extractError(
                error,
                'Unable to save this item.',
            );
    } finally {
        savingItemId.value =
            null;
    }
}

async function submitAssessment() {
    if (
        !selectedAssessment.value
            ?.is_editable
    ) {
        return;
    }

    submitting.value = true;
    errorMessage.value = '';
    successMessage.value = '';

    try {
        await axios.post(
            `/api/v1/student/practical-internal/${selectedAssessment.value.id}/submit`,
        );

        submitVisible.value =
            false;

        detailVisible.value =
            false;

        successMessage.value =
            'Practical assessment submitted for grading.';

        await loadAssessments();
    } catch (error) {
        errorMessage.value =
            extractError(
                error,
                'Unable to submit this practical assessment.',
            );
    } finally {
        submitting.value =
            false;
    }
}

onMounted(() => {
    void loadAssessments();
});

onBeforeUnmount(() => {
    for (
        const itemId of
        Object.keys(
            evidencePreviewUrls,
        )
    ) {
        clearEvidencePreview(
            itemId,
        );
    }
});
</script>

<template>
    <Head
        title="Practical Assessments"
    />

    <div
        class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5"
    >
        <Message
            v-if="successMessage"
            severity="success"
            closable
            @close="
                successMessage = ''
            "
        >
            {{ successMessage }}
        </Message>

        <Message
            v-if="errorMessage"
            severity="error"
            closable
            @close="
                errorMessage = ''
            "
        >
            {{ errorMessage }}
        </Message>

        <Datatable
            title="Practical Assessments"
            description="Open your assigned practical assessments, complete the required items, and submit them for grading."
            header-icon="pi pi-clipboard"
            search-placeholder="Search practical assessments..."
            empty-title="No practical assessments"
            empty-description="You do not have an assigned practical assessment yet."
            empty-icon="pi pi-clipboard"
            table-min-width="1000px"
            actions-header="Actions"
            actions-width="90px"
            data-key="id"
            lazy
            :loading="loading"
            :data="assessments"
            :columns="columns"
            :actions="actions"
            :total-records="
                totalRecords
            "
            :first="first"
            :rows="rows"
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
            <template
                #cell-title_assess="{
                    data,
                }"
            >
                <div
                    class="min-w-0 space-y-2"
                >
                    <div
                        class="flex items-start gap-2"
                    >
                        <i
                            class="pi pi-clipboard mt-0.5 shrink-0 text-blue-500"
                        ></i>

                        <p
                            class="font-semibold break-words whitespace-normal text-slate-700"
                        >
                            {{
                                data.title_assess ||
                                'No assessment title'
                            }}
                        </p>
                    </div>
                </div>
            </template>

            <template
                #cell-due_date="{
                    data,
                }"
            >
                <div
                    class="space-y-2"
                >
                    <div
                        class="flex items-center gap-2"
                    >
                        <Tag
                            value="Started"
                            severity="success"
                            class="w-16 !justify-center !px-2 !py-1 !text-xs !font-semibold"
                        />

                        <span
                            class="whitespace-nowrap text-sm font-medium text-slate-600"
                        >
                            {{
                                formatDate(
                                    data.from_date,
                                )
                            }}
                        </span>
                    </div>

                    <div
                        class="flex items-center gap-2"
                    >
                        <Tag
                            value="Ended"
                            severity="danger"
                            class="w-16 !justify-center !px-2 !py-1 !text-xs !font-semibold"
                        />

                        <span
                            class="whitespace-nowrap text-sm font-medium text-slate-600"
                        >
                            {{
                                formatDate(
                                    data.due_date,
                                )
                            }}
                        </span>
                    </div>
                </div>
            </template>

            <template
                #cell-assessor="{
                    data,
                }"
            >
                <div
                    class="flex items-center gap-2"
                >
                    <i
                        class="pi pi-user shrink-0 text-blue-500"
                    ></i>

                    <span
                        class="font-semibold text-slate-700"
                    >
                        {{
                            data.assessor ||
                            'Not assigned'
                        }}
                    </span>
                </div>
            </template>

            <template
                #cell-status="{
                    data,
                }"
            >
                <Tag
                    :value="
                        data.status
                    "
                    :severity="
                        statusSeverity(
                            data.status,
                        )
                    "
                    rounded
                />
            </template>
        </Datatable>
    </div>

    <Dialog
        v-model:visible="
            detailVisible
        "
        modal
        header="Practical Assessment"
        class="w-[96vw] max-w-6xl"
        :draggable="false"
    >
        <div
            v-if="detailLoading"
            class="py-12 text-center text-slate-500"
        >
            <i class="pi pi-spin pi-spinner mr-2"></i>
            Loading practical
            assessment...
        </div>

        <div
            v-else-if="
                selectedAssessment
            "
            class="space-y-5"
        >
            <div
                class="rounded-xl border border-slate-200 bg-slate-50 p-4"
            >
                <div
                    class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between"
                >
                    <div
                        class="min-w-0"
                    >
                        <div
                            class="flex items-start gap-2"
                        >
                            <i
                                class="pi pi-clipboard mt-1 shrink-0 text-blue-500"
                            ></i>

                            <h2
                                class="text-xl font-semibold break-words text-slate-900"
                            >
                                {{
                                    selectedAssessment.title
                                }}
                            </h2>
                        </div>
                    </div>

                    <Tag
                        :value="
                            selectedAssessment.status
                        "
                        :severity="
                            statusSeverity(
                                selectedAssessment.status,
                            )
                        "
                        rounded
                    />
                </div>

                <div
                    class="mt-4 grid gap-4 border-t border-slate-200 pt-4 sm:grid-cols-2 lg:grid-cols-4"
                >
                    <div>
                        <p
                            class="mb-2 text-xs font-semibold tracking-wide text-slate-400 uppercase"
                        >
                            Assessment
                            Period
                        </p>

                        <div
                            class="space-y-2"
                        >
                            <div
                                class="flex items-center gap-2"
                            >
                                <Tag
                                    value="Started"
                                    severity="success"
                                    class="w-16 !justify-center !px-2 !py-1 !text-xs !font-semibold"
                                />

                                <span
                                    class="text-sm font-semibold whitespace-nowrap text-slate-700"
                                >
                                    {{
                                        formatDate(
                                            selectedAssessment.from_date,
                                        )
                                    }}
                                </span>
                            </div>

                            <div
                                class="flex items-center gap-2"
                            >
                                <Tag
                                    value="Ended"
                                    severity="danger"
                                    class="w-16 !justify-center !px-2 !py-1 !text-xs !font-semibold"
                                />

                                <span
                                    class="text-sm font-semibold whitespace-nowrap text-slate-700"
                                >
                                    {{
                                        formatDate(
                                            selectedAssessment.due_date,
                                        )
                                    }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div>
                        <p
                            class="text-xs font-semibold tracking-wide text-slate-400 uppercase"
                        >
                            Submitted
                        </p>

                        <p
                            class="mt-2 text-sm font-semibold text-slate-700"
                        >
                            {{
                                formatDate(
                                    selectedAssessment.date_taken,
                                )
                            }}
                        </p>
                    </div>

                    <div>
                        <p
                            class="text-xs font-semibold tracking-wide text-slate-400 uppercase"
                        >
                            Assessed
                        </p>

                        <p
                            class="mt-2 text-sm font-semibold text-slate-700"
                        >
                            {{
                                formatDate(
                                    selectedAssessment.date_assessed,
                                )
                            }}
                        </p>
                    </div>

                    <div>
                        <p
                            class="text-xs font-semibold tracking-wide text-slate-400 uppercase"
                        >
                            Assessor
                        </p>

                        <p
                            class="mt-2 flex items-center gap-2 text-sm font-semibold text-slate-700"
                        >
                            <i
                                class="pi pi-user text-blue-500"
                            ></i>

                            {{
                                selectedAssessment.assessor ||
                                'Not assigned'
                            }}
                        </p>
                    </div>
                </div>
            </div>

            <Message
                v-if="
                    selectedAssessment.is_pending
                "
                severity="warn"
                :closable="false"
            >
                This assessment is
                locked while waiting
                for assessor grading.
            </Message>

            <Message
                v-if="
                    selectedAssessment.is_completed
                "
                severity="success"
                :closable="false"
            >
                This assessment has
                been graded.
            </Message>

            <div
                v-if="
                    selectedAssessment.instructions
                "
                class="rounded-xl border border-slate-200 bg-white p-4"
            >
                <h3
                    class="font-semibold text-slate-900"
                >
                    Instructions
                </h3>

                <p
                    class="mt-2 whitespace-pre-line text-sm text-slate-700"
                >
                    {{
                        selectedAssessment.instructions
                    }}
                </p>
            </div>

            <div
                v-if="
                    selectedAssessment.reference_files.length
                "
                class="rounded-xl border border-slate-200 bg-white p-4"
            >
                <h3
                    class="font-semibold text-slate-900"
                >
                    Reference Files
                </h3>

                <div
                    class="mt-3 flex flex-wrap gap-2"
                >
                    <Button
                        v-for="file in selectedAssessment.reference_files"
                        :key="file.id"
                        as="a"
                        :href="file.url"
                        target="_blank"
                        rel="noopener"
                        label="View Reference"
                        icon="pi pi-external-link"
                        severity="secondary"
                        outlined
                        size="small"
                    />
                </div>
            </div>

            <div
                v-if="
                    selectedAssessment.result
                "
                class="rounded-xl border border-slate-200 bg-white p-4"
            >
                <h3
                    class="font-semibold text-slate-900"
                >
                    Assessment Result
                </h3>

                <div
                    class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                >
                    <div
                        class="rounded-lg bg-slate-50 p-3"
                    >
                        <p
                            class="text-xs font-semibold tracking-wide text-slate-400 uppercase"
                        >
                            Score
                        </p>

                        <p
                            class="mt-1 text-lg font-semibold text-slate-800"
                        >
                            {{
                                selectedAssessment.result.earned_points
                            }}
                            /
                            {{
                                selectedAssessment.result.maximum_points
                            }}
                        </p>
                    </div>

                    <div
                        class="rounded-lg bg-slate-50 p-3"
                    >
                        <p
                            class="text-xs font-semibold tracking-wide text-slate-400 uppercase"
                        >
                            Percentage
                        </p>

                        <p
                            class="mt-1 text-lg font-semibold text-slate-800"
                        >
                            {{
                                selectedAssessment.result.percentage ??
                                0
                            }}%
                        </p>
                    </div>

                    <div
                        class="rounded-lg bg-slate-50 p-3"
                    >
                        <p
                            class="text-xs font-semibold tracking-wide text-slate-400 uppercase"
                        >
                            Passing Mark
                        </p>

                        <p
                            class="mt-1 text-lg font-semibold text-slate-800"
                        >
                            {{
                                selectedAssessment.result.passing_mark
                            }}%
                        </p>
                    </div>

                    <div
                        class="rounded-lg bg-slate-50 p-3"
                    >
                        <p
                            class="mb-1 text-xs font-semibold tracking-wide text-slate-400 uppercase"
                        >
                            Result
                        </p>

                        <Tag
                            :value="
                                selectedAssessment.result.remarks
                            "
                            :severity="
                                selectedAssessment.result.remarks ===
                                'PASS'
                                    ? 'success'
                                    : 'danger'
                            "
                            rounded
                        />
                    </div>
                </div>
            </div>

            <div
                class="space-y-4"
            >
                <div
                    v-for="(
                        item,
                        index
                    ) in selectedAssessment.items"
                    :key="item.id"
                    class="rounded-xl border border-slate-200 bg-white p-4"
                >
                    <div
                        class="flex items-start justify-between gap-3"
                    >
                        <div
                            class="min-w-0"
                        >
                            <p
                                class="text-xs font-semibold tracking-wide text-[#377EC0] uppercase"
                            >
                                Item
                                {{
                                    index +
                                    1
                                }}
                            </p>

                            <p
                                class="mt-1 font-semibold break-words whitespace-pre-line text-slate-800"
                            >
                                {{
                                    item.description
                                }}
                            </p>
                        </div>

                        <Tag
                            v-if="
                                selectedAssessment.is_completed
                            "
                            :value="`${item.points ?? 0} / ${item.maximum_points ?? 0}`"
                            severity="info"
                            rounded
                            class="shrink-0"
                        />
                    </div>

                    <div
                        v-if="
                            item.reference_file
                        "
                        class="mt-3"
                    >
                        <Button
                            as="a"
                            :href="
                                item.reference_file.url
                            "
                            target="_blank"
                            rel="noopener"
                            label="View Reference"
                            icon="pi pi-paperclip"
                            severity="secondary"
                            outlined
                            size="small"
                        />
                    </div>

                    <div
                        class="mt-4"
                    >
                        <label
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >
                            Answer /
                            Remarks
                        </label>

                        <Textarea
                            v-model="
                                remarks[
                                    item.id
                                ]
                            "
                            rows="4"
                            class="w-full"
                            :disabled="
                                !selectedAssessment.is_editable
                            "
                        />
                    </div>

                    <div
                        class="mt-4 space-y-3"
                    >
                        <div
                            v-if="
                                selectedAssessment.is_editable
                            "
                        >
                            <input
                                :ref="
                                    (
                                        element,
                                    ) =>
                                        setEvidenceInput(
                                            item.id,
                                            element,
                                        )
                                "
                                type="file"
                                :accept="
                                    evidenceAccept
                                "
                                class="hidden"
                                :disabled="
                                    savingItemId ===
                                    item.id
                                "
                                @change="
                                    handleEvidenceInput(
                                        item.id,
                                        $event,
                                    )
                                "
                            />

                            <button
                                v-if="
                                    !pendingFiles[
                                        item.id
                                    ]
                                "
                                type="button"
                                class="flex min-h-[190px] w-full cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed p-6 text-center transition disabled:cursor-not-allowed disabled:opacity-60"
                                :class="
                                    draggingEvidence[
                                        item.id
                                    ]
                                        ? 'border-blue-400 bg-blue-50'
                                        : 'border-slate-300 bg-slate-50 hover:border-blue-400 hover:bg-blue-50'
                                "
                                :disabled="
                                    savingItemId ===
                                    item.id
                                "
                                @click="
                                    openEvidencePicker(
                                        item.id,
                                    )
                                "
                                @dragover="
                                    handleEvidenceDragOver(
                                        item.id,
                                        $event,
                                    )
                                "
                                @dragenter="
                                    handleEvidenceDragOver(
                                        item.id,
                                        $event,
                                    )
                                "
                                @dragleave="
                                    handleEvidenceDragLeave(
                                        item.id,
                                        $event,
                                    )
                                "
                                @drop="
                                    handleEvidenceDrop(
                                        item.id,
                                        $event,
                                    )
                                "
                            >
                                <span
                                    class="mb-3 flex size-12 items-center justify-center rounded-xl bg-blue-100 text-blue-700"
                                >
                                    <i
                                        class="pi pi-cloud-upload text-xl"
                                    ></i>
                                </span>

                                <span
                                    class="font-semibold text-slate-800"
                                >
                                    Drag and drop
                                    evidence here
                                </span>

                                <span
                                    class="mt-1 text-sm text-slate-500"
                                >
                                    or choose a
                                    file from your
                                    computer
                                </span>

                                <span
                                    class="mt-4 inline-flex items-center gap-2 rounded-lg border border-blue-300 bg-white px-4 py-2 text-sm font-semibold text-blue-700"
                                >
                                    <i
                                        class="pi pi-folder-open"
                                    ></i>

                                    Choose Evidence
                                </span>

                                <span
                                    class="mt-3 text-xs text-slate-400"
                                >
                                    Images, PDF,
                                    documents,
                                    spreadsheets
                                    or text ·
                                    Maximum 20 MB
                                </span>
                            </button>

                            <div
                                v-else
                                class="w-full rounded-xl border border-emerald-200 bg-emerald-50 p-4"
                            >
                                <div
                                    class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div
                                        class="flex min-w-0 items-center gap-4"
                                    >
                                        <img
                                            v-if="
                                                isImageFile(
                                                    pendingFiles[
                                                        item.id
                                                    ],
                                                ) &&
                                                evidencePreviewUrls[
                                                    item.id
                                                ]
                                            "
                                            :src="
                                                evidencePreviewUrls[
                                                    item.id
                                                ]
                                            "
                                            alt="Selected evidence preview"
                                            class="h-24 w-24 shrink-0 rounded-lg border border-emerald-200 bg-white object-contain"
                                        />

                                        <div
                                            v-else
                                            class="flex size-14 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700"
                                        >
                                            <i
                                                :class="[
                                                    selectedEvidenceIcon(
                                                        pendingFiles[
                                                            item.id
                                                        ],
                                                    ),
                                                    'text-2xl',
                                                ]"
                                            ></i>
                                        </div>

                                        <div
                                            class="min-w-0"
                                        >
                                            <p
                                                class="text-sm font-semibold text-emerald-900"
                                            >
                                                {{
                                                    selectedEvidenceKind(
                                                        pendingFiles[
                                                            item.id
                                                        ],
                                                    )
                                                }}
                                                selected
                                            </p>

                                            <p
                                                class="mt-1 text-xs text-emerald-700"
                                            >
                                                {{
                                                    selectedEvidenceSize(
                                                        pendingFiles[
                                                            item.id
                                                        ],
                                                    )
                                                }}
                                                · Ready
                                                to save
                                            </p>
                                        </div>
                                    </div>

                                    <div
                                        class="flex shrink-0 flex-wrap items-center gap-2"
                                    >
                                        <Button
                                            type="button"
                                            label="Change File"
                                            icon="pi pi-refresh"
                                            severity="secondary"
                                            outlined
                                            size="small"
                                            :disabled="
                                                savingItemId ===
                                                item.id
                                            "
                                            @click="
                                                openEvidencePicker(
                                                    item.id,
                                                )
                                            "
                                        />

                                        <Button
                                            type="button"
                                            label="Remove"
                                            icon="pi pi-times"
                                            severity="secondary"
                                            text
                                            size="small"
                                            :disabled="
                                                savingItemId ===
                                                item.id
                                            "
                                            @click="
                                                clearPendingEvidence(
                                                    item.id,
                                                )
                                            "
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div
                            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <Button
                                v-if="
                                    item.evidence_file
                                "
                                as="a"
                                :href="
                                    item.evidence_file.url
                                "
                                target="_blank"
                                rel="noopener"
                                label="View Evidence"
                                icon="pi pi-external-link"
                                severity="info"
                                outlined
                                size="small"
                            />

                            <div
                                v-else
                                class="hidden sm:block"
                            ></div>

                            <Button
                                v-if="
                                    selectedAssessment.is_editable
                                "
                                label="Save Item"
                                icon="pi pi-save"
                                severity="success"
                                :loading="
                                    savingItemId ===
                                    item.id
                                "
                                :disabled="
                                    savingItemId !==
                                        null &&
                                    savingItemId !==
                                        item.id
                                "
                                @click="
                                    saveItem(
                                        item,
                                    )
                                "
                            />
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-if="
                    selectedAssessment.is_editable
                "
                class="flex justify-end border-t border-slate-200 pt-4"
            >
                <Button
                    label="Submit for Grading"
                    icon="pi pi-send"
                    severity="success"
                    :disabled="
                        savingItemId !==
                        null
                    "
                    @click="
                        submitVisible =
                            true
                    "
                />
            </div>
        </div>
    </Dialog>

    <Dialog
        v-model:visible="
            submitVisible
        "
        modal
        header="Submit Practical Assessment"
        class="w-[94vw] max-w-md"
    >
        <p
            class="text-sm leading-6 text-slate-700"
        >
            Submit this practical
            assessment for grading.
            After submission, your
            answers and evidence will
            be locked while your
            assessor reviews them.
        </p>

        <template #footer>
            <Button
                label="Cancel"
                icon="pi pi-times"
                severity="secondary"
                outlined
                :disabled="
                    submitting
                "
                @click="
                    submitVisible =
                        false
                "
            />

            <Button
                label="Submit"
                icon="pi pi-send"
                severity="success"
                :loading="
                    submitting
                "
                :disabled="
                    submitting
                "
                @click="
                    submitAssessment
                "
            />
        </template>
    </Dialog>
</template>