<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Select from 'primevue/select';
import PrimeTag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

import Datatable from '@/components/Datatable.vue';
import { dashboard } from '@/routes';
import type { DataTableAction, DataTableColumn, DataTableRow } from '@/types';

defineOptions({
    inheritAttrs: false,
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            {
                title: 'Practical Assessment Setup',
                href: '/assessment-setup/practical-assessment-setup',
            },
        ],
    },
});

type ListResponse = {
    data: DataTableRow[];
    meta: { currentPage: number; perPage: number; total: number };
};
type PageEvent = { page: number; rows: number; first: number };
type SortEvent = { sortField: string; sortOrder: number };
type Rubric = { id: string; rubrics_name: string };
type AssessmentItem = {
    id: string;
    item_d: string;
    filename_d: string;
    file_url: string | null;
    point_d: number;
    prio: number;
};
type Attachment = {
    id: string;
    assess_h_file: string;
    file_url: string | null;
};
type Errors = Record<string, string>;

const API_BASE = '/api/v1/assessment-setup/practical-assessments';
const DATATABLE_URL =
    '/api/v1/assessment-setup/datatable/practical-assessments';

const toast = useToast();
const assessments = ref<DataTableRow[]>([]);
const rubrics = ref<Rubric[]>([]);
const items = ref<AssessmentItem[]>([]);
const attachments = ref<Attachment[]>([]);
const loading = ref(false);
const saving = ref(false);
const childLoading = ref(false);
const errorMessage = ref('');
const totalRecords = ref(0);
const first = ref(0);
const perPage = ref(10);
const search = ref('');
const sortField = ref('title_assess');
const sortDirection = ref<'asc' | 'desc'>('asc');
const assessmentDialogVisible = ref(false);
const assessmentDeleteVisible = ref(false);
const itemDialogVisible = ref(false);
const itemDeleteVisible = ref(false);
const attachmentDeleteVisible = ref(false);
const selectedAssessment = ref<DataTableRow | null>(null);
const selectedItem = ref<AssessmentItem | null>(null);
const selectedAttachment = ref<Attachment | null>(null);
const assessmentErrors = ref<Errors>({});
const itemErrors = ref<Errors>({});
const itemFile = ref<File | null>(null);
const attachmentFile = ref<File | null>(null);
let requestController: AbortController | null = null;

const form = reactive({
    id: '',
    title_assess: '',
    instruction_assess: '',
    grade_system: '',
    rubrics_id: '',
    passing_mark: null as number | null,
});

const itemForm = reactive({
    id: '',
    item_d: '',
    filename_d: '',
    file_url: '' as string | null,
    point_d: null as number | null,
    prio: null as number | null,
});

const gradingSystems = ['Checklist', 'Points', 'Rubrics'];
const columns: DataTableColumn[] = [
    { field: 'title_assess', header: 'Title', sortable: true, searchable: true, class: 'min-w-[300px]' },
    { field: 'grade_system', header: 'Grading System', sortable: true, searchable: true, class: 'min-w-[180px]' },
    { field: 'rubrics_name', header: 'Rubric', sortable: false, searchable: true, class: 'min-w-[220px]' },
    { field: 'total_items', header: 'Items', sortable: false, searchable: false, class: 'min-w-[110px]' },
    { field: 'passing_mark', header: 'Passing Mark', sortable: true, searchable: false, class: 'min-w-[160px]' },
    { field: 'total_attachments', header: 'Files', sortable: false, searchable: false, class: 'min-w-[110px]' },
];
const actions: DataTableAction[] = [
    { key: 'edit', label: 'Edit assessment', icon: 'pi pi-pencil', severity: 'warn' },
    { key: 'delete', label: 'Delete assessment', icon: 'pi pi-trash', severity: 'danger' },
];

const currentPage = computed(() => Math.floor(first.value / perPage.value) + 1);
const dialogTitle = computed(() =>
    form.id ? 'Edit Practical Assessment' : 'Create Practical Assessment',
);
const itemDialogTitle = computed(() =>
    itemForm.id ? 'Edit Assessment Item' : 'Add Assessment Item',
);

function handleGradingSystemChange(): void {
    if (form.grade_system !== 'Rubrics') form.rubrics_id = '';
    form.passing_mark = form.grade_system === 'Checklist' ? 100 : 75;
}

async function loadAssessments(page = 1): Promise<void> {
    requestController?.abort();
    const controller = new AbortController();
    requestController = controller;
    loading.value = true;
    errorMessage.value = '';
    try {
        const response = await axios.get<ListResponse>(DATATABLE_URL, {
            signal: controller.signal,
            params: {
                page,
                per_page: perPage.value,
                search: search.value,
                sort_field: sortField.value,
                sort_direction: sortDirection.value,
            },
            ...requestConfig(),
        });
        assessments.value = response.data.data;
        totalRecords.value = response.data.meta.total;
        perPage.value = response.data.meta.perPage;
        first.value =
            (response.data.meta.currentPage - 1) * response.data.meta.perPage;
    } catch (error: unknown) {
        if (isCanceled(error)) return;
        assessments.value = [];
        totalRecords.value = 0;
        errorMessage.value = getErrorMessage(error, 'Unable to load practical assessments.');
    } finally {
        if (requestController === controller) loading.value = false;
    }
}

async function loadOptions(): Promise<void> {
    try {
        const response = await axios.get<{ data: { rubrics: Rubric[] } }>(
            `${API_BASE}/options`,
            requestConfig(),
        );
        rubrics.value = response.data.data.rubrics;
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(error, 'Unable to load rubric options.');
    }
}

function handlePage(event: PageEvent): void {
    perPage.value = event.rows;
    first.value = event.first;
    void loadAssessments(event.page + 1);
}
function handleSort(event: SortEvent): void {
    sortField.value = event.sortField || 'title_assess';
    sortDirection.value = event.sortOrder === -1 ? 'desc' : 'asc';
    first.value = 0;
    void loadAssessments(1);
}
function handleSearch(value: string): void {
    search.value = value;
    first.value = 0;
    void loadAssessments(1);
}
function handleAction(action: string, row: DataTableRow): void {
    if (action === 'edit') openEditAssessment(row);
    if (action === 'delete') {
        selectedAssessment.value = row;
        assessmentDeleteVisible.value = true;
    }
}

function openCreateAssessment(): void {
    resetAssessment();
    items.value = [];
    attachments.value = [];
    assessmentDialogVisible.value = true;
}
function openEditAssessment(row: DataTableRow): void {
    form.id = String(row.id ?? '');
    form.title_assess = String(row.title_assess ?? '');
    form.instruction_assess = String(row.instruction_assess ?? '');
    form.grade_system = String(row.grade_system ?? '');
    form.rubrics_id = String(row.rubrics_id ?? '');
    form.passing_mark = Number(row.passing_mark ?? 0);
    assessmentErrors.value = {};
    assessmentDialogVisible.value = true;
    void loadChildren();
}

async function saveAssessment(): Promise<void> {
    assessmentErrors.value = {};
    if (!form.title_assess.trim()) assessmentErrors.value.title_assess = 'Title is required.';
    if (!form.grade_system) assessmentErrors.value.grade_system = 'Grading system is required.';
    if (form.grade_system === 'Rubrics' && !form.rubrics_id) assessmentErrors.value.rubrics_id = 'Rubric is required.';
    if (form.passing_mark === null) assessmentErrors.value.passing_mark = 'Passing mark is required.';
    if (Object.keys(assessmentErrors.value).length) return;

    const wasNew = !form.id;
    saving.value = true;
    try {
        const payload = {
            title_assess: form.title_assess.trim(),
            instruction_assess: form.instruction_assess.trim(),
            grade_system: form.grade_system,
            rubrics_id: form.grade_system === 'Rubrics' ? form.rubrics_id : null,
            passing_mark: form.passing_mark,
        };
        const response = wasNew
            ? await axios.post<{ id: string; message: string }>(API_BASE, payload, requestConfig())
            : await axios.put<{ id: string; message: string }>(`${API_BASE}/${encodeURIComponent(form.id)}`, payload, requestConfig());
        form.id = response.data.id;
        notify('success', wasNew ? 'Assessment Created' : 'Assessment Updated', response.data.message);
        await loadAssessments(wasNew ? 1 : currentPage.value);
        await loadChildren();
    } catch (error: unknown) {
        assessmentErrors.value = getValidationErrors(error);
        errorMessage.value = getErrorMessage(error, 'Unable to save the assessment.');
    } finally {
        saving.value = false;
    }
}

async function deleteAssessment(): Promise<void> {
    const id = String(selectedAssessment.value?.id ?? '');
    if (!id) return;
    saving.value = true;
    try {
        const response = await axios.delete<{ message: string }>(`${API_BASE}/${encodeURIComponent(id)}`, requestConfig());
        assessmentDeleteVisible.value = false;
        selectedAssessment.value = null;
        notify('success', 'Assessment Deleted', response.data.message);
        await reloadCurrentPage();
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(error, 'Unable to delete the assessment.');
    } finally {
        saving.value = false;
    }
}

async function loadChildren(): Promise<void> {
    if (!form.id) return;
    childLoading.value = true;
    try {
        const [itemsResponse, attachmentsResponse] = await Promise.all([
            axios.get<{ data: AssessmentItem[] }>(`${API_BASE}/${encodeURIComponent(form.id)}/items`, requestConfig()),
            axios.get<{ data: Attachment[] }>(`${API_BASE}/${encodeURIComponent(form.id)}/attachments`, requestConfig()),
        ]);
        items.value = itemsResponse.data.data;
        attachments.value = attachmentsResponse.data.data;
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(error, 'Unable to load assessment details.');
    } finally {
        childLoading.value = false;
    }
}

function openNewItem(): void {
    resetItem();
    itemDialogVisible.value = true;
}
function openEditItem(item: AssessmentItem): void {
    Object.assign(itemForm, {
        id: item.id,
        item_d: item.item_d,
        filename_d: item.filename_d || '',
        file_url: item.file_url || '',
        point_d: Number(item.point_d),
        prio: Number(item.prio),
    });
    itemFile.value = null;
    itemErrors.value = {};
    itemDialogVisible.value = true;
}

async function saveItem(): Promise<void> {
    if (!form.id) return;
    itemErrors.value = {};
    if (!itemForm.item_d.trim()) itemErrors.value.item_d = 'Description is required.';
    if (itemForm.prio === null) itemErrors.value.prio = 'Order number is required.';
    if (form.grade_system === 'Points' && itemForm.point_d === null) itemErrors.value.point_d = 'Maximum score is required.';
    if (Object.keys(itemErrors.value).length) return;

    childLoading.value = true;
    try {
        if (itemFile.value) {
            const upload = new FormData();
            upload.append('file', itemFile.value);
            const uploaded = await axios.post<{ filename: string; file_url: string }>(
                `${API_BASE}/item-files`,
                upload,
                multipartConfig(),
            );
            itemForm.filename_d = uploaded.data.filename;
            itemForm.file_url = uploaded.data.file_url;
        }
        const base = `${API_BASE}/${encodeURIComponent(form.id)}/items`;
        const payload = {
            item_d: itemForm.item_d.trim(),
            filename_d: itemForm.filename_d,
            point_d: itemForm.point_d ?? 0,
            prio: itemForm.prio,
        };
        const response = itemForm.id
            ? await axios.put<{ message: string }>(`${base}/${encodeURIComponent(itemForm.id)}`, payload, requestConfig())
            : await axios.post<{ message: string }>(base, payload, requestConfig());
        notify('success', itemForm.id ? 'Item Updated' : 'Item Added', response.data.message);
        itemDialogVisible.value = false;
        resetItem();
        await loadChildren();
        await loadAssessments(currentPage.value);
    } catch (error: unknown) {
        itemErrors.value = getValidationErrors(error);
        errorMessage.value = getErrorMessage(error, 'Unable to save the assessment item.');
    } finally {
        childLoading.value = false;
    }
}

async function deleteItem(): Promise<void> {
    if (!form.id || !selectedItem.value?.id) return;
    childLoading.value = true;
    try {
        const response = await axios.delete<{ message: string }>(
            `${API_BASE}/${encodeURIComponent(form.id)}/items/${encodeURIComponent(selectedItem.value.id)}`,
            requestConfig(),
        );
        itemDeleteVisible.value = false;
        selectedItem.value = null;
        notify('success', 'Item Deleted', response.data.message);
        await loadChildren();
        await loadAssessments(currentPage.value);
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(error, 'Unable to delete the item.');
    } finally {
        childLoading.value = false;
    }
}

async function uploadAttachment(): Promise<void> {
    if (!form.id || !attachmentFile.value) return;
    childLoading.value = true;
    try {
        const data = new FormData();
        data.append('file', attachmentFile.value);
        const response = await axios.post<{ message: string }>(
            `${API_BASE}/${encodeURIComponent(form.id)}/attachments`,
            data,
            multipartConfig(),
        );
        attachmentFile.value = null;
        notify('success', 'File Uploaded', response.data.message);
        await loadChildren();
        await loadAssessments(currentPage.value);
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(error, 'Unable to upload the attachment.');
    } finally {
        childLoading.value = false;
    }
}

async function deleteAttachment(): Promise<void> {
    if (!form.id || !selectedAttachment.value?.id) return;
    childLoading.value = true;
    try {
        const response = await axios.delete<{ message: string }>(
            `${API_BASE}/${encodeURIComponent(form.id)}/attachments/${encodeURIComponent(selectedAttachment.value.id)}`,
            requestConfig(),
        );
        attachmentDeleteVisible.value = false;
        selectedAttachment.value = null;
        notify('success', 'Attachment Removed', response.data.message);
        await loadChildren();
        await loadAssessments(currentPage.value);
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(error, 'Unable to remove the attachment.');
    } finally {
        childLoading.value = false;
    }
}

function onItemFile(event: Event): void {
    itemFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}
function onAttachmentFile(event: Event): void {
    attachmentFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}
function resetAssessment(): void {
    Object.assign(form, { id: '', title_assess: '', instruction_assess: '', grade_system: '', rubrics_id: '', passing_mark: null });
    assessmentErrors.value = {};
}
function resetItem(): void {
    Object.assign(itemForm, { id: '', item_d: '', filename_d: '', file_url: '', point_d: null, prio: null });
    itemFile.value = null;
    itemErrors.value = {};
}
async function reloadCurrentPage(): Promise<void> {
    await loadAssessments(currentPage.value);
    if (!assessments.value.length && currentPage.value > 1) await loadAssessments(currentPage.value - 1);
}
function requestConfig() {
    return { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, withCredentials: true };
}
function multipartConfig() {
    return { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, withCredentials: true };
}
function isCanceled(error: unknown): boolean {
    return axios.isCancel(error) || (axios.isAxiosError(error) && error.code === 'ERR_CANCELED');
}
function getErrorMessage(error: unknown, fallback: string): string {
    if (!axios.isAxiosError(error)) return fallback;
    return (error.response?.data as { message?: string } | undefined)?.message || fallback;
}
function getValidationErrors(error: unknown): Errors {
    if (!axios.isAxiosError(error)) return {};
    const errors = (error.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors ?? {};
    return Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, value[0] ?? 'Invalid value.']));
}
function notify(severity: 'success' | 'warn', summary: string, detail: string): void {
    toast.add({ severity, summary, detail, life: 4000 });
}

onMounted(() => {
    void Promise.all([loadAssessments(1), loadOptions()]);
});
onBeforeUnmount(() => requestController?.abort());
</script>

<template>
    <Head title="Practical Assessment Setup" />
    <Toast position="top-right" />

    <div class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5">
        <Message v-if="errorMessage" severity="error" closable @close="errorMessage = ''">{{ errorMessage }}</Message>

        <Datatable
            title="Practical Assessment Setup"
            description="Create practical assessments, items, rubrics, and supporting files."
            header-icon="pi pi-clipboard"
            search-placeholder="Search assessments..."
            empty-title="No practical assessments found"
            empty-description="Create a practical assessment to get started."
            empty-icon="pi pi-clipboard"
            table-min-width="1400px"
            actions-header="Actions"
            actions-width="130px"
            data-key="id"
            lazy
            :loading="loading"
            :data="assessments"
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
                <Button label="Create Practical Assessment" icon="pi pi-plus" severity="success" size="small" @click="openCreateAssessment" />
            </template>
            <template #cell-title_assess="{ value }"><span class="font-semibold text-slate-700">{{ value || '—' }}</span></template>
            <template #cell-grade_system="{ value }"><PrimeTag :value="String(value || '—')" :severity="value === 'Points' ? 'info' : value === 'Rubrics' ? 'warn' : 'success'" rounded /></template>
            <template #cell-rubrics_name="{ value }">{{ value || '—' }}</template>
            <template #cell-total_items="{ value }"><PrimeTag :value="String(value ?? 0)" severity="info" rounded /></template>
            <template #cell-passing_mark="{ value }"><span class="font-semibold">{{ value ?? 0 }}%</span></template>
            <template #cell-total_attachments="{ value }"><PrimeTag :value="String(value ?? 0)" severity="secondary" icon="pi pi-paperclip" rounded /></template>
        </Datatable>

        <Dialog v-model:visible="assessmentDialogVisible" modal :header="dialogTitle" :closable="!saving" class="w-[min(97vw,1100px)]">
            <div class="grid gap-4 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label class="mb-2 block text-sm font-semibold">Title</label>
                    <InputText v-model="form.title_assess" class="w-full" :invalid="Boolean(assessmentErrors.title_assess)" />
                    <small v-if="assessmentErrors.title_assess" class="text-red-500">{{ assessmentErrors.title_assess }}</small>
                </div>
                <div class="md:col-span-2">
                    <label class="mb-2 block text-sm font-semibold">Instructions</label>
                    <Textarea v-model="form.instruction_assess" rows="4" class="w-full" auto-resize />
                </div>
                <div>
                    <label class="mb-2 block text-sm font-semibold">Grading System</label>
                    <Select v-model="form.grade_system" :options="gradingSystems" placeholder="Select grading system" class="w-full" @change="handleGradingSystemChange" />
                    <small v-if="assessmentErrors.grade_system" class="text-red-500">{{ assessmentErrors.grade_system }}</small>
                </div>
                <div v-if="form.grade_system === 'Rubrics'">
                    <label class="mb-2 block text-sm font-semibold">Rubric</label>
                    <Select v-model="form.rubrics_id" :options="rubrics" option-label="rubrics_name" option-value="id" placeholder="Select rubric" filter class="w-full" />
                    <small v-if="assessmentErrors.rubrics_id" class="text-red-500">{{ assessmentErrors.rubrics_id }}</small>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-semibold">Passing Mark (%)</label>
                    <InputNumber v-model="form.passing_mark" :min="0" :max="100" :use-grouping="false" class="w-full" />
                    <small v-if="assessmentErrors.passing_mark" class="text-red-500">{{ assessmentErrors.passing_mark }}</small>
                </div>
            </div>

            <div class="mt-6 border-t border-slate-200 pt-5">
                <div class="mb-3 flex items-center justify-between">
                    <div><h3 class="font-bold text-slate-800">Attachments</h3><p class="text-sm text-slate-500">PDF, PNG, JPG, or JPEG up to 10 MB.</p></div>
                    <div class="flex items-center gap-2">
                        <input type="file" accept=".pdf,.png,.jpg,.jpeg" :disabled="!form.id || childLoading" class="max-w-[260px] text-sm" @change="onAttachmentFile" />
                        <Button label="Upload" icon="pi pi-upload" size="small" :disabled="!form.id || !attachmentFile" :loading="childLoading" @click="uploadAttachment" />
                    </div>
                </div>
                <Message v-if="!form.id" severity="info" :closable="false">Save the assessment before adding files or items.</Message>
                <div v-else class="flex flex-wrap gap-2">
                    <div v-for="file in attachments" :key="file.id" class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                        <a v-if="file.file_url" :href="file.file_url" target="_blank" rel="noopener noreferrer" class="text-sm font-medium text-[#377EC0]">{{ file.assess_h_file }}</a>
                        <span v-else>{{ file.assess_h_file }}</span>
                        <Button icon="pi pi-trash" severity="danger" text rounded size="small" @click="selectedAttachment = file; attachmentDeleteVisible = true" />
                    </div>
                    <span v-if="!attachments.length" class="text-sm text-slate-500">No attachments.</span>
                </div>
            </div>

            <div class="mt-6 border-t border-slate-200 pt-5">
                <div class="mb-3 flex items-center justify-between">
                    <div><h3 class="font-bold text-slate-800">Assessment Items</h3><p class="text-sm text-slate-500">Items are displayed according to their order number.</p></div>
                    <Button label="Add Item" icon="pi pi-plus" size="small" :disabled="!form.id" @click="openNewItem" />
                </div>
                <div v-if="form.id" class="overflow-x-auto rounded-xl border border-slate-200">
                    <table class="w-full min-w-[850px] text-left text-sm">
                        <thead class="bg-slate-50"><tr><th class="px-4 py-3">Item</th><th class="px-4 py-3">File</th><th class="px-4 py-3 text-center">Max. Score</th><th class="px-4 py-3 text-center">Order</th><th class="px-4 py-3 text-center">Actions</th></tr></thead>
                        <tbody>
                            <tr v-if="childLoading"><td colspan="5" class="px-4 py-6 text-center">Loading...</td></tr>
                            <tr v-else-if="!items.length"><td colspan="5" class="px-4 py-6 text-center text-slate-500">No assessment items.</td></tr>
                            <tr v-for="item in items" :key="item.id" class="border-t border-slate-200">
                                <td class="px-4 py-3 font-medium">{{ item.item_d }}</td>
                                <td class="px-4 py-3"><a v-if="item.file_url" :href="item.file_url" target="_blank" rel="noopener noreferrer" class="text-[#377EC0]">View file</a><span v-else>—</span></td>
                                <td class="px-4 py-3 text-center">{{ item.point_d }}</td><td class="px-4 py-3 text-center">{{ item.prio }}</td>
                                <td class="px-4 py-3"><div class="flex justify-center gap-2"><Button icon="pi pi-pencil" severity="warn" rounded size="small" @click="openEditItem(item)" /><Button icon="pi pi-trash" severity="danger" rounded size="small" @click="selectedItem = item; itemDeleteVisible = true" /></div></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <template #footer><Button label="Close" severity="secondary" outlined @click="assessmentDialogVisible = false" /><Button label="Save" icon="pi pi-save" severity="success" :loading="saving" @click="saveAssessment" /></template>
        </Dialog>

        <Dialog v-model:visible="itemDialogVisible" modal :header="itemDialogTitle" class="w-[min(94vw,700px)]">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><label class="mb-2 block text-sm font-semibold">Description</label><Textarea v-model="itemForm.item_d" rows="3" class="w-full" /><small v-if="itemErrors.item_d" class="text-red-500">{{ itemErrors.item_d }}</small></div>
                <div><label class="mb-2 block text-sm font-semibold">Maximum Score</label><InputNumber v-model="itemForm.point_d" :min="0" :use-grouping="false" class="w-full" :disabled="form.grade_system !== 'Points'" /><small v-if="itemErrors.point_d" class="text-red-500">{{ itemErrors.point_d }}</small></div>
                <div><label class="mb-2 block text-sm font-semibold">Order No.</label><InputNumber v-model="itemForm.prio" :min="1" :use-grouping="false" class="w-full" /><small v-if="itemErrors.prio" class="text-red-500">{{ itemErrors.prio }}</small></div>
                <div class="sm:col-span-2"><label class="mb-2 block text-sm font-semibold">Supporting File</label><input type="file" accept=".pdf,.png,.jpg,.jpeg" class="w-full text-sm" @change="onItemFile" /><a v-if="itemForm.file_url" :href="itemForm.file_url" target="_blank" rel="noopener noreferrer" class="mt-2 block text-sm text-[#377EC0]">View current file</a><small v-if="itemErrors.file" class="text-red-500">{{ itemErrors.file }}</small></div>
            </div>
            <template #footer><Button label="Cancel" severity="secondary" outlined @click="itemDialogVisible = false" /><Button label="Save" icon="pi pi-save" severity="success" :loading="childLoading" @click="saveItem" /></template>
        </Dialog>

        <Dialog v-model:visible="assessmentDeleteVisible" modal header="Delete Practical Assessment" class="w-[min(92vw,520px)]"><Message severity="warn" :closable="false">Delete <strong>{{ selectedAssessment?.title_assess || 'this assessment' }}</strong>? Remove all items and attachments first.</Message><template #footer><Button label="Cancel" severity="secondary" outlined @click="assessmentDeleteVisible = false" /><Button label="Delete" icon="pi pi-trash" severity="danger" :loading="saving" @click="deleteAssessment" /></template></Dialog>
        <Dialog v-model:visible="itemDeleteVisible" modal header="Delete Assessment Item" class="w-[min(92vw,520px)]"><Message severity="warn" :closable="false">Delete this assessment item?</Message><template #footer><Button label="Cancel" severity="secondary" outlined @click="itemDeleteVisible = false" /><Button label="Delete" icon="pi pi-trash" severity="danger" :loading="childLoading" @click="deleteItem" /></template></Dialog>
        <Dialog v-model:visible="attachmentDeleteVisible" modal header="Remove Attachment" class="w-[min(92vw,520px)]"><Message severity="warn" :closable="false">Remove <strong>{{ selectedAttachment?.assess_h_file || 'this attachment' }}</strong> from the assessment?</Message><template #footer><Button label="Cancel" severity="secondary" outlined @click="attachmentDeleteVisible = false" /><Button label="Remove" icon="pi pi-trash" severity="danger" :loading="childLoading" @click="deleteAttachment" /></template></Dialog>
    </div>
</template>
