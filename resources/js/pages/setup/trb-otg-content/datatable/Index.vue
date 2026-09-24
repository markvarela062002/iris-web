<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Card from 'primevue/card';
import Dialog from 'primevue/dialog';
import FileUpload from 'primevue/fileupload';
import Message from 'primevue/message';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import { computed, onMounted, ref, watch } from 'vue';

import { dashboard } from '@/routes';

defineOptions({
    inheritAttrs: false,

    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
            {
                title: 'TRB - OTG Content Setup',
                href: '/setup/trb-otg-content/datatable/index',
            },
        ],
    },
});

type TrbType = {
    id: string;
    desc_trb_type: string;
    prio: number | null;
    trb: string;
    dept: string;
};

type Task = {
    id: string;
    ref_no: string;
    description: string;
    prio: number | null;
    phase_no: string;
};

type Topic = {
    id: string;
    ref_no: string;
    description: string;
    prio: number | null;
    tasks: Task[];
};

type Competence = {
    id: string;
    ref_no: string;
    description: string;
    prio: number | null;
    topics: Topic[];
};

type TrbFunction = {
    id: string;
    code: string;
    description: string;
    prio: number | null;
    competences: Competence[];
};

type ContentResponse = {
    data: {
        trb_type: TrbType;
        functions: TrbFunction[];
        counts: {
            functions: number;
            competences: number;
            topics: number;
            tasks: number;
        };
    };
};

type ImportIssue = {
    sheet: string;
    row: number;
    message: string;
};

type ImportResponse = {
    message: string;
    processed: number;
    skipped: number;
    added: number;
    updated: number;
};

type FileUploadInstance = {
    clear: () => void;
};

type ValidationPayload = {
    message?: string;
    errors?: Record<string, string[]>;
    issues?: ImportIssue[];
    issue_count?: number;
};

const API_BASE =
    '/api/v1/setup/trb-otg-content';

const toast = useToast();

const trbTypes = ref<TrbType[]>([]);
const selectedTrbTypeId = ref<string | null>(null);
const selectedFile = ref<File | null>(null);
const fileUpload = ref<FileUploadInstance | null>(null);
const content = ref<ContentResponse['data'] | null>(null);

const loadingOptions = ref(false);
const loadingContent = ref(false);
const uploading = ref(false);
const deleting = ref(false);
const downloadingTemplate = ref(false);
const deleteDialogVisible = ref(false);

const errorMessage = ref('');
const importIssues = ref<ImportIssue[]>([]);

const selectedTrbType = computed(
    () =>
        trbTypes.value.find(
            (item) => item.id === selectedTrbTypeId.value,
        ) ?? null,
);

const hasContent = computed(
    () => Boolean(content.value && content.value.functions.length > 0),
);

function requestConfig() {
    return {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        withCredentials: true,
    };
}

async function loadOptions(): Promise<void> {
    loadingOptions.value = true;
    errorMessage.value = '';

    try {
        const response = await axios.get<{ data: TrbType[] }>(
            `${API_BASE}/options`,
            requestConfig(),
        );

        trbTypes.value = response.data.data;
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(
            error,
            'Unable to load TRB Types.',
        );
    } finally {
        loadingOptions.value = false;
    }
}

async function viewContent(): Promise<void> {
    errorMessage.value = '';
    importIssues.value = [];

    if (!selectedTrbTypeId.value) {
        errorMessage.value = 'TRB Type is required.';
        return;
    }

    loadingContent.value = true;

    try {
        const response = await axios.get<ContentResponse>(
            `${API_BASE}/${encodeURIComponent(selectedTrbTypeId.value)}/content`,
            requestConfig(),
        );

        content.value = response.data.data;
    } catch (error: unknown) {
        content.value = null;
        errorMessage.value = getErrorMessage(
            error,
            'Unable to load TRB content.',
        );
    } finally {
        loadingContent.value = false;
    }
}

function onFileSelect(event: { files?: File[] }): void {
    selectedFile.value = event.files?.[0] ?? null;
    errorMessage.value = '';
    importIssues.value = [];
}

function onFileClear(): void {
    selectedFile.value = null;
}

async function uploadContent(): Promise<void> {
    errorMessage.value = '';
    importIssues.value = [];

    if (!selectedTrbTypeId.value) {
        errorMessage.value = 'TRB Type is required.';
        return;
    }

    if (!selectedFile.value) {
        errorMessage.value = 'Choose an XLS or XLSX file to upload.';
        return;
    }

    uploading.value = true;

    try {
        const payload = new FormData();
        payload.append('trb_type_id', selectedTrbTypeId.value);
        payload.append('file', selectedFile.value);

        const response = await axios.post<ImportResponse>(
            `${API_BASE}/import`,
            payload,
            requestConfig(),
        );

        toast.add({
            severity: 'success',
            summary: 'TRB Content Uploaded',
            detail: `${response.data.added} new record(s), ${response.data.updated} updated.`,
            life: 5000,
        });

        clearFile();
        await viewContent();
    } catch (error: unknown) {
        const payload = getValidationPayload(error);
        importIssues.value = payload.issues ?? [];
        errorMessage.value =
            payload.message ||
            firstValidationError(payload) ||
            'Unable to upload TRB content.';
    } finally {
        uploading.value = false;
    }
}

async function downloadTemplate(): Promise<void> {
    downloadingTemplate.value = true;
    errorMessage.value = '';

    try {
        const response = await axios.get(
            `${API_BASE}/template`,
            {
                responseType: 'blob',
                ...requestConfig(),
            },
        );

        const url = URL.createObjectURL(response.data);
        const anchor = document.createElement('a');

        anchor.href = url;
        anchor.download = 'trb_template.xlsx';
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        URL.revokeObjectURL(url);
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(
            error,
            'Unable to download the TRB template.',
        );
    } finally {
        downloadingTemplate.value = false;
    }
}

function requestDelete(): void {
    errorMessage.value = '';

    if (!selectedTrbTypeId.value) {
        errorMessage.value = 'TRB Type is required.';
        return;
    }

    deleteDialogVisible.value = true;
}

async function deleteContent(): Promise<void> {
    if (!selectedTrbTypeId.value) {
        return;
    }

    deleting.value = true;
    errorMessage.value = '';

    try {
        const response = await axios.delete<{ message: string }>(
            `${API_BASE}/${encodeURIComponent(selectedTrbTypeId.value)}`,
            requestConfig(),
        );

        toast.add({
            severity: 'success',
            summary: 'TRB Content Deleted',
            detail: response.data.message,
            life: 4000,
        });

        deleteDialogVisible.value = false;
        content.value = null;
        await viewContent();
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(
            error,
            'Unable to delete TRB content.',
        );
    } finally {
        deleting.value = false;
    }
}

function clearFile(): void {
    selectedFile.value = null;
    fileUpload.value?.clear();
}

function clearPage(): void {
    selectedTrbTypeId.value = null;
    content.value = null;
    errorMessage.value = '';
    importIssues.value = [];
    clearFile();
}

function typeTagSeverity(
    type: TrbType | null,
): 'success' | 'info' | 'secondary' {
    if (!type) {
        return 'secondary';
    }

    if (type.trb.toUpperCase() === 'ISF') {
        return 'success';
    }

    if (type.trb.toUpperCase() === 'GMET') {
        return 'info';
    }

    return 'secondary';
}

function getValidationPayload(error: unknown): ValidationPayload {
    if (!axios.isAxiosError(error)) {
        return {};
    }

    return (error.response?.data as ValidationPayload | undefined) ?? {};
}

function firstValidationError(payload: ValidationPayload): string {
    if (!payload.errors) {
        return '';
    }

    for (const messages of Object.values(payload.errors)) {
        if (messages[0]) {
            return messages[0];
        }
    }

    return '';
}

function getErrorMessage(error: unknown, fallback: string): string {
    if (!axios.isAxiosError(error)) {
        return fallback;
    }

    return (
        (error.response?.data as { message?: string } | undefined)?.message ||
        fallback
    );
}

watch(selectedTrbTypeId, () => {
    content.value = null;
    errorMessage.value = '';
    importIssues.value = [];
    clearFile();
});

onMounted(() => void loadOptions());
</script>

<template>
    <Head title="TRB - OTG Content Setup" />

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

        <Card>
            <template #title>
                <div class="flex items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-600"
                    >
                        <i class="pi pi-book text-lg"></i>
                    </div>

                    <div>
                        <div class="text-lg font-semibold text-slate-800">
                            TRB - OTG Content Setup
                        </div>
                        <div class="mt-1 text-sm font-normal text-slate-500">
                            Upload and maintain the Function, Competence, Topic and Task hierarchy for each TRB Type.
                        </div>
                    </div>
                </div>
            </template>

            <template #content>
                <div class="grid gap-5">
                    <Message severity="info" :closable="false">
                        Select a TRB Type, choose the TRB workbook, then upload.
                        The workbook must follow the four-column TRB template.
                    </Message>

                    <div
                        class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]"
                    >
                        <div class="flex flex-col gap-2">
                            <label class="text-sm font-semibold text-slate-700">
                                TRB Type
                                <span class="text-red-500">*</span>
                            </label>

                            <Select
                                v-model="selectedTrbTypeId"
                                :options="trbTypes"
                                option-label="desc_trb_type"
                                option-value="id"
                                placeholder="Select TRB Type"
                                filter
                                class="w-full"
                                :loading="loadingOptions"
                                :disabled="uploading || deleting"
                            />
                        </div>

                        <div class="flex flex-col gap-2">
                            <label class="text-sm font-semibold text-slate-700">
                                File to Upload
                            </label>

                            <FileUpload
                                ref="fileUpload"
                                mode="basic"
                                name="file"
                                accept=".xls,.xlsx"
                                :max-file-size="100"
                                choose-label="Choose Excel File"
                                choose-icon="pi pi-file-excel"
                                custom-upload
                                :auto="false"
                                :disabled="uploading || deleting"
                                @select="onFileSelect"
                                @clear="onFileClear"
                            />

                            <div
                                v-if="selectedFile"
                                class="flex items-center gap-2 text-sm text-slate-600"
                            >
                                <i class="pi pi-file-excel text-green-500"></i>
                                <span class="break-all">
                                    {{ selectedFile.name }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="selectedTrbType"
                        class="flex flex-wrap items-center gap-2"
                    >
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button
                            label="Download TRB Template"
                            icon="pi pi-download"
                            severity="info"
                            outlined
                            :loading="downloadingTemplate"
                            :disabled="uploading || deleting"
                            @click="downloadTemplate"
                        />

                        <Button
                            label="Upload"
                            icon="pi pi-upload"
                            :loading="uploading"
                            :disabled="
                                !selectedTrbTypeId ||
                                !selectedFile ||
                                deleting
                            "
                            @click="uploadContent"
                        />

                        <Button
                            label="View"
                            icon="pi pi-eye"
                            severity="info"
                            :loading="loadingContent"
                            :disabled="
                                !selectedTrbTypeId ||
                                uploading ||
                                deleting
                            "
                            @click="viewContent"
                        />

                        <Button
                            label="Delete Content"
                            icon="pi pi-trash"
                            severity="danger"
                            outlined
                            :disabled="
                                !selectedTrbTypeId ||
                                uploading ||
                                deleting
                            "
                            @click="requestDelete"
                        />

                        <Button
                            label="Clear"
                            icon="pi pi-filter-slash"
                            severity="secondary"
                            outlined
                            :disabled="uploading || deleting"
                            @click="clearPage"
                        />
                    </div>

                    <div
                        v-if="importIssues.length > 0"
                        class="rounded-xl border border-red-200 bg-red-50 p-4"
                    >
                        <div class="mb-2 font-semibold text-red-700">
                            Workbook Issues
                        </div>

                        <div class="grid gap-2 text-sm text-red-700">
                            <div
                                v-for="(issue, index) in importIssues"
                                :key="`${issue.sheet}-${issue.row}-${index}`"
                            >
                                <strong>
                                    {{ issue.sheet }} row {{ issue.row }}:
                                </strong>
                                {{ issue.message }}
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </Card>

        <Card v-if="content || loadingContent">
            <template #title>
                <div
                    class="flex flex-wrap items-center justify-between gap-3"
                >
                    <div>
                        <div class="text-xl font-semibold text-slate-800">
                            {{
                                content?.trb_type.desc_trb_type ||
                                selectedTrbType?.desc_trb_type ||
                                'TRB Content'
                            }}
                        </div>
                        <div class="mt-1 text-sm font-normal text-slate-500">
                            Existing OTG content arranged in Function → Competence → Topic → Task hierarchy.
                        </div>
                    </div>

                    <div v-if="content" class="flex flex-wrap gap-2">
                        <Tag
                            :value="`${content.counts.functions} Functions`"
                            severity="contrast"
                            rounded
                        />
                        <Tag
                            :value="`${content.counts.competences} Competences`"
                            severity="success"
                            rounded
                        />
                        <Tag
                            :value="`${content.counts.topics} Topics`"
                            severity="info"
                            rounded
                        />
                        <Tag
                            :value="`${content.counts.tasks} Tasks`"
                            severity="secondary"
                            rounded
                        />
                    </div>
                </div>
            </template>

            <template #content>
                <div
                    v-if="loadingContent"
                    class="flex min-h-48 items-center justify-center text-slate-500"
                >
                    <i class="pi pi-spin pi-spinner mr-2"></i>
                    Loading TRB content...
                </div>

                <Message
                    v-else-if="!hasContent"
                    severity="secondary"
                    :closable="false"
                >
                    No record found for the selected TRB Type.
                </Message>

                <div v-else class="grid gap-5">
                    <section
                        v-for="trbFunction in content?.functions"
                        :key="trbFunction.id"
                        class="overflow-hidden rounded-xl border border-slate-200 bg-white"
                    >
                        <div
                            class="flex items-start gap-3 bg-slate-900 px-4 py-3 text-white"
                        >
                            <i class="pi pi-sitemap mt-0.5 shrink-0"></i>

                            <div class="min-w-0">
                                <div class="font-semibold">
                                    {{ trbFunction.description }}
                                </div>
                                <div
                                    v-if="trbFunction.code"
                                    class="mt-1 text-xs text-slate-300"
                                >
                                    {{ trbFunction.code }}
                                </div>
                            </div>
                        </div>

                        <div class="divide-y divide-slate-200">
                            <div
                                v-for="competence in trbFunction.competences"
                                :key="competence.id"
                            >
                                <div
                                    class="flex items-start gap-3 bg-emerald-50 px-4 py-3 text-slate-800"
                                >
                                    <div
                                        class="w-24 shrink-0 font-semibold text-emerald-700"
                                    >
                                        {{ competence.ref_no }}
                                    </div>
                                    <div class="font-medium">
                                        {{ competence.description }}
                                    </div>
                                </div>

                                <div
                                    v-for="topic in competence.topics"
                                    :key="topic.id"
                                    class="border-t border-slate-200"
                                >
                                    <div
                                        class="grid gap-2 bg-sky-50 px-4 py-3 md:grid-cols-[6rem_minmax(0,1fr)]"
                                    >
                                        <div
                                            class="font-semibold text-sky-700"
                                        >
                                            {{ topic.ref_no }}
                                        </div>
                                        <div
                                            class="font-medium text-slate-700"
                                        >
                                            {{ topic.description }}
                                        </div>
                                    </div>

                                    <div
                                        v-if="topic.tasks.length > 0"
                                        class="divide-y divide-slate-100"
                                    >
                                        <div
                                            v-for="task in topic.tasks"
                                            :key="task.id"
                                            class="grid gap-2 px-4 py-3 text-sm md:grid-cols-[6rem_minmax(0,1fr)]"
                                        >
                                            <div
                                                class="text-right font-semibold text-slate-500 md:pr-3"
                                            >
                                                {{ task.ref_no }}
                                            </div>
                                            <div
                                                class="whitespace-pre-line text-slate-700"
                                            >
                                                {{ task.description }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </template>
        </Card>

        <Dialog
            v-model:visible="deleteDialogVisible"
            modal
            header="Delete TRB Content"
            :closable="!deleting"
            class="w-[min(94vw,640px)]"
        >
            <div class="grid gap-4">
                <div
                    class="w-full rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-700"
                >
                    Delete all Function, Competence, Topic and Task content for
                    <strong>
                        {{ selectedTrbType?.desc_trb_type || 'this TRB Type' }}
                    </strong>
                    ?
                </div>

                <p class="text-sm text-slate-500">
                    The TRB Type itself will remain available. Only its uploaded OTG content will be removed.
                </p>
            </div>

            <template #footer>
                <Button
                    label="Cancel"
                    icon="pi pi-times"
                    severity="secondary"
                    outlined
                    :disabled="deleting"
                    @click="deleteDialogVisible = false"
                />

                <Button
                    label="Delete Content"
                    icon="pi pi-trash"
                    severity="danger"
                    :loading="deleting"
                    @click="deleteContent"
                />
            </template>
        </Dialog>
    </div>
</template>
