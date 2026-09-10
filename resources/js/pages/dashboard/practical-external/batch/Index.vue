<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Select from 'primevue/select';
import PrimeTag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

import Datatable from '@/components/Datatable.vue';
import { dashboard } from '@/routes';
import type { DataTableAction, DataTableColumn, DataTableRow } from '@/types';

defineOptions({
    inheritAttrs: false,
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Practical Assessments - External', href: '/dashboard/practical-external' },
            { title: 'Batch Creation', href: '/dashboard/practical-external/batch' },
        ],
    },
});

type PageEvent = { page: number; rows: number; first: number };
type SortEvent = { sortField: string; sortOrder: number };
type AssessmentOption = { id: string; label: string };
type Examinee = {
    id?: string;
    assessment_id?: string;
    email: string;
    fname: string;
    mname: string;
    lname: string;
    name?: string;
    email_sent?: boolean;
};
type BatchDetails = {
    p_assess_h_id: string;
    from_date: string | null;
    due_date: string | null;
    assessor: string;
    remarks: string;
    examinees: Examinee[];
};

const API = '/api/v1/dashboard/practical-external/batches';
const batches = ref<DataTableRow[]>([]);
const assessments = ref<AssessmentOption[]>([]);
const examinees = ref<Examinee[]>([]);
const loading = ref(false);
const saving = ref(false);
const importing = ref(false);
const optionsLoading = ref(false);
const dialogVisible = ref(false);
const uploadDialogVisible = ref(false);
const viewMode = ref(false);
const totalRecords = ref(0);
const first = ref(0);
const rows = ref(10);
const search = ref('');
const sortField = ref('last_update');
const sortDirection = ref<'asc' | 'desc'>('desc');
const successMessage = ref('');
const errorMessage = ref('');
const importMessages = ref<string[]>([]);
const validationErrors = ref<Record<string, string[]>>({});
const selectedFile = ref<File | null>(null);
let requestController: AbortController | null = null;

const form = reactive({
    p_assess_h_id: '',
    from_date: '',
    due_date: '',
    assessor: '',
    remarks: '',
});

const examineeForm = reactive({ email: '', fname: '', mname: '', lname: '' });

const columns: DataTableColumn[] = [
    { field: 'last_update', header: 'Date Created', sortable: true, searchable: false, class: 'w-[190px] min-w-[190px]' },
    { field: 'title_assess', header: 'Practical Assessment', sortable: true, searchable: true, class: 'min-w-[320px]' },
    { field: 'assessor', header: 'Assessor', sortable: true, searchable: true, class: 'w-[240px] min-w-[240px]' },
    { field: 'validity', header: 'Validity', sortable: false, searchable: false, class: 'w-[270px] min-w-[270px]' },
    { field: 'examinee_count', header: 'Examinees', sortable: true, searchable: false, class: 'w-[130px] min-w-[130px] text-center', headerClass: '!text-center', bodyClass: '!text-center' },
];

const actions: DataTableAction[] = [
    { key: 'view', label: 'View batch', icon: 'pi pi-eye', severity: 'info' },
];

const emails = computed(() => new Set(examinees.value.map((item) => item.email.toLowerCase())));

async function loadBatches(page = 1): Promise<void> {
    requestController?.abort();
    const controller = new AbortController();
    requestController = controller;
    loading.value = true;
    errorMessage.value = '';

    try {
        const response = await axios.get(API, {
            signal: controller.signal,
            params: { page, per_page: rows.value, search: search.value, sort_field: sortField.value, sort_direction: sortDirection.value },
            withCredentials: true,
        });
        batches.value = response.data.data;
        totalRecords.value = response.data.meta.total;
        rows.value = response.data.meta.perPage;
        first.value = (response.data.meta.currentPage - 1) * response.data.meta.perPage;
    } catch (error: unknown) {
        if (axios.isCancel(error) || (axios.isAxiosError(error) && error.code === 'ERR_CANCELED')) return;
        batches.value = [];
        totalRecords.value = 0;
        errorMessage.value = getError(error, 'Unable to load Practical External batches.');
    } finally {
        if (requestController === controller) loading.value = false;
    }
}

async function loadOptions(): Promise<void> {
    if (assessments.value.length) return;
    optionsLoading.value = true;
    try {
        const response = await axios.get(`${API}/options`, { withCredentials: true });
        assessments.value = response.data.data.assessments;
    } catch (error: unknown) {
        errorMessage.value = getError(error, 'Unable to load Practical Assessment options.');
    } finally {
        optionsLoading.value = false;
    }
}

function openCreate(): void {
    resetForm();
    viewMode.value = false;
    dialogVisible.value = true;
    void loadOptions();
}

async function openView(row: DataTableRow): Promise<void> {
    resetForm();
    viewMode.value = true;
    dialogVisible.value = true;
    try {
        const response = await axios.get(`${API}/${encodeURIComponent(String(row.id))}`, { withCredentials: true });
        const batch = response.data.data as BatchDetails;
        form.p_assess_h_id = batch.p_assess_h_id;
        form.from_date = batch.from_date ?? '';
        form.due_date = batch.due_date ?? '';
        form.assessor = batch.assessor;
        form.remarks = batch.remarks;
        examinees.value = batch.examinees;
    } catch (error: unknown) {
        dialogVisible.value = false;
        errorMessage.value = getError(error, 'Unable to load the selected batch.');
    }
}

function addExaminee(): void {
    errorMessage.value = '';
    const email = examineeForm.email.trim().toLowerCase();
    if (!/^\S+@\S+\.\S+$/.test(email)) {
        errorMessage.value = 'Enter a valid examinee email address.';
        return;
    }
    if (email.length > 50) {
        errorMessage.value = 'The email address cannot exceed 50 characters.';
        return;
    }
    if (emails.value.has(email)) {
        errorMessage.value = 'That examinee is already in this batch.';
        return;
    }
    if (examinees.value.length >= 40) {
        errorMessage.value = 'Only 40 examinees may be added to one batch.';
        return;
    }

    examinees.value.push({
        email,
        fname: cleanName(examineeForm.fname),
        mname: cleanName(examineeForm.mname),
        lname: cleanName(examineeForm.lname),
    });
    clearExamineeForm();
}

function removeExaminee(email: string): void {
    if (viewMode.value) return;
    examinees.value = examinees.value.filter((item) => item.email !== email);
}

function selectUpload(event: Event): void {
    selectedFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}

async function importExcel(): Promise<void> {
    if (!selectedFile.value) {
        importMessages.value = ['Select an Excel file first.'];
        return;
    }
    importing.value = true;
    importMessages.value = [];
    const body = new FormData();
    body.append('file', selectedFile.value);

    try {
        const response = await axios.post(`${API}/import`, body, {
            headers: { 'Content-Type': 'multipart/form-data', Accept: 'application/json' },
            withCredentials: true,
        });
        const imported = response.data.data as Examinee[];
        let added = 0;
        let skipped = 0;

        for (const item of imported) {
            if (examinees.value.length >= 40 || emails.value.has(item.email.toLowerCase())) {
                skipped++;
                continue;
            }
            examinees.value.push(item);
            added++;
        }

        importMessages.value = [
            `${added} examinee(s) added.`,
            `${skipped + Number(response.data.meta.rejected ?? 0)} row(s) skipped.`,
            ...(response.data.meta.errors ?? []),
        ];
        if (added > 0) uploadDialogVisible.value = false;
    } catch (error: unknown) {
        importMessages.value = [getError(error, 'Unable to import the Excel file.')];
    } finally {
        importing.value = false;
    }
}

async function saveBatch(): Promise<void> {
    validationErrors.value = {};
    errorMessage.value = '';
    if (!examinees.value.length) {
        errorMessage.value = 'Add at least one examinee before saving the batch.';
        return;
    }

    saving.value = true;
    try {
        const response = await axios.post(API, {
            ...form,
            from_date: form.from_date || null,
            due_date: form.due_date || null,
            assessor: form.assessor.trim().toUpperCase(),
            examinees: examinees.value.map(({ email, fname, mname, lname }) => ({ email, fname, mname, lname })),
        }, { withCredentials: true, headers: { Accept: 'application/json' } });

        successMessage.value = response.data.message || 'Practical External batch created successfully.';
        dialogVisible.value = false;
        resetForm();
        first.value = 0;
        await loadBatches(1);
    } catch (error: unknown) {
        if (axios.isAxiosError(error) && error.response?.status === 422) {
            validationErrors.value = error.response.data.errors ?? {};
        }
        errorMessage.value = getError(error, 'Unable to create the Practical External batch.');
    } finally {
        saving.value = false;
    }
}

function resetForm(): void {
    form.p_assess_h_id = '';
    form.from_date = '';
    form.due_date = '';
    form.assessor = '';
    form.remarks = '';
    examinees.value = [];
    validationErrors.value = {};
    importMessages.value = [];
    selectedFile.value = null;
    clearExamineeForm();
}

function clearExamineeForm(): void {
    examineeForm.email = '';
    examineeForm.fname = '';
    examineeForm.mname = '';
    examineeForm.lname = '';
}

function handlePage(event: PageEvent): void { rows.value = event.rows; first.value = event.first; void loadBatches(event.page + 1); }
function handleSort(event: SortEvent): void { sortField.value = event.sortField || 'last_update'; sortDirection.value = event.sortOrder === -1 ? 'desc' : 'asc'; first.value = 0; void loadBatches(1); }
function handleSearch(value: string): void { search.value = value; first.value = 0; void loadBatches(1); }
function handleAction(action: string, row: DataTableRow): void { if (action === 'view') void openView(row); }
function cleanName(value: string): string { return value.replaceAll("'", '').trim(); }
function displayName(item: Examinee): string { return item.name || [item.lname ? `${item.lname},` : '', item.fname, item.mname].filter(Boolean).join(' ').toUpperCase() || 'EXAMINEE'; }
function initials(item: Examinee): string { return `${item.fname?.[0] ?? ''}${item.lname?.[0] ?? ''}`.toUpperCase() || 'EX'; }
function firstError(field: string): string { return validationErrors.value[field]?.[0] ?? ''; }
function getError(error: unknown, fallback: string): string { return axios.isAxiosError(error) ? error.response?.data?.message || fallback : fallback; }
function formatDate(value: unknown): string { const text = String(value ?? ''); if (!text) return ''; const date = new Date(`${text.slice(0, 10)}T00:00:00`); return Number.isNaN(date.getTime()) ? text : new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }).format(date); }
function formatDateTime(value: unknown): string { const text = String(value ?? ''); if (!text) return '—'; const date = new Date(text.replace(' ', 'T')); return Number.isNaN(date.getTime()) ? text : new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(date); }
function validity(data: DataTableRow): string { const from = formatDate(data.from_date); const due = formatDate(data.due_date); if (from && due) return `${from} until ${due}`; if (from) return `Starts on ${from}`; if (due) return `Due on ${due}`; return 'Anytime'; }

onMounted(() => void loadBatches(1));
onBeforeUnmount(() => requestController?.abort());
</script>

<template>
    <Head title="Practical External Batch Creation" />
    <div class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5">
        <Message v-if="successMessage" severity="success" closable @close="successMessage = ''">{{ successMessage }}</Message>
        <Message v-if="errorMessage" severity="error" closable @close="errorMessage = ''">{{ errorMessage }}</Message>

        <Datatable
            title="Practical Assessments - External Batch Creation"
            description="Create and review Practical Assessment batches for external examinees."
            header-icon="pi pi-wrench"
            search-placeholder="Search Practical External batches..."
            empty-title="No Practical External batches found"
            empty-description="Create a batch to schedule external Practical Assessments."
            empty-icon="pi pi-wrench"
            table-min-width="1250px"
            actions-width="110px"
            data-key="id"
            lazy
            :loading="loading"
            :data="batches"
            :columns="columns"
            :actions="actions"
            :total-records="totalRecords"
            :first="first"
            :rows="rows"
            :rows-per-page-options="[10, 20, 50, 100]"
            @page="handlePage"
            @sort="handleSort"
            @search="handleSearch"
            @action="handleAction"
        >
            <template #header-actions>
                <Button label="Create Batch" icon="pi pi-plus" severity="success" size="small" @click="openCreate" />
            </template>
            <template #cell-last_update="{ value }"><span class="text-sm font-medium text-slate-600"><i class="pi pi-calendar mr-2 text-blue-500"></i>{{ formatDateTime(value) }}</span></template>
            <template #cell-title_assess="{ data }"><span class="font-semibold text-slate-700"><i class="pi pi-wrench mr-2 text-purple-500"></i>{{ data.title_assess }}</span></template>
            <template #cell-assessor="{ value }"><span class="font-semibold text-slate-700"><i class="pi pi-user mr-2 text-blue-500"></i>{{ value || 'NO ASSESSOR' }}</span></template>
            <template #cell-validity="{ data }"><span class="text-sm font-medium text-slate-600">{{ validity(data) }}</span></template>
            <template #cell-examinee_count="{ value }"><PrimeTag :value="String(value ?? 0)" severity="info" icon="pi pi-users" rounded /></template>
        </Datatable>

        <Dialog v-model:visible="dialogVisible" modal :header="viewMode ? 'Practical External Batch Details' : 'Create Practical External Batch'" :closable="!saving" :dismissable-mask="!saving" class="w-[min(96vw,1100px)]">
            <div class="grid gap-5 lg:grid-cols-2">
                <section class="space-y-4 rounded-xl border border-slate-200 p-4">
                    <h3 class="font-semibold text-slate-700">Assessment Details</h3>
                    <div>
                        <label class="mb-2 block text-sm font-semibold text-slate-700">Practical Assessment *</label>
                        <Select v-model="form.p_assess_h_id" :options="assessments" option-label="label" option-value="id" placeholder="Select Practical Assessment" filter fluid :loading="optionsLoading" :disabled="viewMode || saving" :invalid="Boolean(firstError('p_assess_h_id'))" />
                        <small v-if="firstError('p_assess_h_id')" class="text-red-500">{{ firstError('p_assess_h_id') }}</small>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="mb-2 block text-sm font-semibold text-slate-700">From Date</label><input v-model="form.from_date" type="date" :disabled="viewMode || saving" class="h-11 w-full rounded-md border border-slate-300 px-3 text-sm disabled:bg-slate-100" /></div>
                        <div><label class="mb-2 block text-sm font-semibold text-slate-700">Due Date</label><input v-model="form.due_date" type="date" :min="form.from_date || undefined" :disabled="viewMode || saving" class="h-11 w-full rounded-md border border-slate-300 px-3 text-sm disabled:bg-slate-100" /><small v-if="firstError('due_date')" class="text-red-500">{{ firstError('due_date') }}</small></div>
                    </div>
                    <div><label class="mb-2 block text-sm font-semibold text-slate-700">Assessor</label><InputText v-model="form.assessor" fluid placeholder="First, Middle, Last" class="uppercase" :disabled="viewMode || saving" /></div>
                    <div><label class="mb-2 block text-sm font-semibold text-slate-700">Remarks</label><Textarea v-model="form.remarks" rows="3" fluid auto-resize :disabled="viewMode || saving" /></div>
                </section>

                <section class="space-y-4 rounded-xl border border-slate-200 p-4">
                    <div class="flex items-center justify-between"><h3 class="font-semibold text-slate-700">Examinees</h3><PrimeTag :value="`${examinees.length} / 40`" severity="info" rounded /></div>
                    <div v-if="!viewMode" class="space-y-3 rounded-lg bg-slate-50 p-3">
                        <div class="grid gap-2 sm:grid-cols-2"><InputText v-model="examineeForm.email" placeholder="Email *" fluid /><InputText v-model="examineeForm.lname" placeholder="Last Name" fluid /><InputText v-model="examineeForm.fname" placeholder="First Name" fluid /><InputText v-model="examineeForm.mname" placeholder="Middle Name" fluid /></div>
                        <div class="flex flex-wrap gap-2"><Button label="Add Examinee" icon="pi pi-plus" severity="success" size="small" :disabled="saving || examinees.length >= 40" @click="addExaminee" /><Button label="Import Excel" icon="pi pi-file-excel" severity="warn" size="small" :disabled="saving || examinees.length >= 40" @click="uploadDialogVisible = true" /></div>
                    </div>
                    <div class="max-h-[380px] space-y-2 overflow-y-auto pr-1">
                        <div v-if="!examinees.length" class="rounded-lg border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">No examinees added.</div>
                        <div v-for="item in examinees" :key="item.email" class="flex items-center gap-3 rounded-lg border border-slate-200 p-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-purple-50 text-xs font-bold text-purple-600">{{ initials(item) }}</span>
                            <div class="min-w-0 flex-1"><p class="truncate font-semibold text-slate-700">{{ displayName(item) }}</p><div class="mt-1 flex flex-wrap gap-1.5"><PrimeTag :value="item.email" icon="pi pi-envelope" severity="info" rounded /><PrimeTag v-if="viewMode" :value="item.email_sent ? 'Email Sent' : 'Email Not Sent'" :severity="item.email_sent ? 'success' : 'danger'" rounded /></div></div>
                            <Button v-if="!viewMode" icon="pi pi-times" severity="danger" rounded text aria-label="Remove examinee" @click="removeExaminee(item.email)" />
                        </div>
                    </div>
                    <small v-if="firstError('examinees')" class="text-red-500">{{ firstError('examinees') }}</small>
                </section>
            </div>
            <template #footer><Button :label="viewMode ? 'Close' : 'Cancel'" icon="pi pi-times" severity="secondary" variant="outlined" :disabled="saving" @click="dialogVisible = false" /><Button v-if="!viewMode" label="Create Batch" icon="pi pi-check" severity="success" :loading="saving" @click="saveBatch" /></template>
        </Dialog>

        <Dialog v-model:visible="uploadDialogVisible" modal header="Import Examinees from Excel" class="w-[min(92vw,560px)]">
            <div class="space-y-4">
                <Message severity="info" :closable="false">Columns must be: Email, Last Name, First Name, Middle Name. The first row is treated as the heading.</Message>
                <input type="file" accept=".xls,.xlsx" class="block w-full rounded-lg border border-slate-300 p-3 text-sm" @change="selectUpload" />
                <div v-if="importMessages.length" class="space-y-1 rounded-lg bg-slate-50 p-3 text-sm"><p v-for="message in importMessages" :key="message">{{ message }}</p></div>
            </div>
            <template #footer><Button label="Cancel" severity="secondary" variant="outlined" :disabled="importing" @click="uploadDialogVisible = false" /><Button label="Import" icon="pi pi-upload" severity="warn" :loading="importing" @click="importExcel" /></template>
        </Dialog>
    </div>
</template>
