<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Select from 'primevue/select';
import PrimeTag from 'primevue/tag';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

import Datatable from '@/components/Datatable.vue';
import { dashboard } from '@/routes';
import type { DataTableAction, DataTableColumn, DataTableRow } from '@/types';

defineOptions({
    inheritAttrs: false,
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Practical Assessments - Internal', href: '/dashboard/practical-internal' },
            { title: 'Batch Creation', href: '/dashboard/practical-internal/batch' },
        ],
    },
});

type PageEvent = { page: number; rows: number; first: number };
type SortEvent = { sortField: string; sortOrder: number };
type AssessmentOption = { id: string; label: string; grade_system?: string; passing_mark?: number };
type Student = {
    id: string;
    school_id_no: string;
    code_person: string;
    name: string;
    email: string;
    dept: string;
    gender?: unknown;
    email_sent?: boolean;
};
type BatchDetails = {
    id: string;
    p_assess_h_id: string;
    title_assess: string;
    from_date: string;
    due_date: string;
    assessor: string;
    students: Student[];
};

const API = '/api/v1/dashboard/practical-internal/batches';
const batches = ref<DataTableRow[]>([]);
const assessments = ref<AssessmentOption[]>([]);
const studentResults = ref<Student[]>([]);
const selectedStudents = ref<Student[]>([]);
const loading = ref(false);
const saving = ref(false);
const optionsLoading = ref(false);
const studentLoading = ref(false);
const dialogVisible = ref(false);
const viewMode = ref(false);
const totalRecords = ref(0);
const first = ref(0);
const rows = ref(10);
const search = ref('');
const sortField = ref('due_date');
const sortDirection = ref<'asc' | 'desc'>('desc');
const studentSearch = ref('');
const successMessage = ref('');
const errorMessage = ref('');
const validationErrors = ref<Record<string, string[]>>({});
let listController: AbortController | null = null;
let studentController: AbortController | null = null;
let studentTimer: ReturnType<typeof setTimeout> | null = null;

const form = reactive({
    p_assess_h_id: '',
    from_date: '',
    due_date: '',
    assessor: '',
});

const columns: DataTableColumn[] = [
    { field: 'last_update', header: 'Date Created', sortable: true, searchable: false, class: 'w-[190px] min-w-[190px]' },
    { field: 'title_assess', header: 'Practical Assessment', sortable: true, searchable: true, class: 'min-w-[320px]' },
    { field: 'assessor', header: 'Assessor', sortable: true, searchable: true, class: 'w-[240px] min-w-[240px]' },
    { field: 'assessment_period', header: 'Assessment Period', sortable: false, searchable: false, class: 'w-[260px] min-w-[260px]' },
    { field: 'examinee_count', header: 'Examinees', sortable: true, searchable: false, class: 'w-[130px] min-w-[130px] text-center', headerClass: '!text-center', bodyClass: '!text-center' },
];

const actions: DataTableAction[] = [
    { key: 'view', label: 'View batch', icon: 'pi pi-eye', severity: 'info' },
];

const selectedIds = computed(() => new Set(selectedStudents.value.map((student) => student.id)));

async function loadBatches(page = 1): Promise<void> {
    listController?.abort();
    const controller = new AbortController();
    listController = controller;
    loading.value = true;
    errorMessage.value = '';

    try {
        const response = await axios.get(API, {
            signal: controller.signal,
            params: {
                page,
                per_page: rows.value,
                search: search.value,
                sort_field: sortField.value,
                sort_direction: sortDirection.value,
            },
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
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
        errorMessage.value = getError(error, 'Unable to load Practical Internal batches.');
    } finally {
        if (listController === controller) loading.value = false;
    }
}

async function loadOptions(): Promise<void> {
    if (assessments.value.length > 0) return;
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

async function searchStudents(): Promise<void> {
    const term = studentSearch.value.trim();
    if (term.length < 2 || viewMode.value) {
        studentResults.value = [];
        return;
    }

    studentController?.abort();
    const controller = new AbortController();
    studentController = controller;
    studentLoading.value = true;

    try {
        const response = await axios.get(`${API}/students`, {
            signal: controller.signal,
            params: { search: term },
            withCredentials: true,
        });
        studentResults.value = response.data.data;
    } catch (error: unknown) {
        if (axios.isCancel(error) || (axios.isAxiosError(error) && error.code === 'ERR_CANCELED')) return;
        errorMessage.value = getError(error, 'Unable to search students.');
    } finally {
        if (studentController === controller) studentLoading.value = false;
    }
}

watch(studentSearch, () => {
    if (studentTimer) clearTimeout(studentTimer);
    studentTimer = setTimeout(() => void searchStudents(), 350);
});

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
        form.from_date = batch.from_date;
        form.due_date = batch.due_date;
        form.assessor = batch.assessor;
        selectedStudents.value = batch.students;
    } catch (error: unknown) {
        dialogVisible.value = false;
        errorMessage.value = getError(error, 'Unable to load the selected batch.');
    }
}

function addStudent(student: Student): void {
    if (selectedIds.value.has(student.id)) return;
    if (selectedStudents.value.length >= 40) {
        errorMessage.value = 'Only 40 students may be added to one batch.';
        return;
    }
    selectedStudents.value.push(student);
    studentSearch.value = '';
    studentResults.value = [];
}

function removeStudent(id: string): void {
    if (viewMode.value) return;
    selectedStudents.value = selectedStudents.value.filter((student) => student.id !== id);
}

async function saveBatch(): Promise<void> {
    validationErrors.value = {};
    errorMessage.value = '';

    if (selectedStudents.value.length === 0) {
        errorMessage.value = 'Add at least one student before saving the batch.';
        return;
    }

    saving.value = true;
    try {
        const response = await axios.post(API, {
            ...form,
            assessor: form.assessor.trim().toUpperCase(),
            students: selectedStudents.value.map((student) => ({ id: student.id })),
        }, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            withCredentials: true,
        });

        successMessage.value = response.data.message || 'Practical Internal batch created successfully.';
        dialogVisible.value = false;
        resetForm();
        first.value = 0;
        await loadBatches(1);
    } catch (error: unknown) {
        if (axios.isAxiosError(error) && error.response?.status === 422) {
            validationErrors.value = error.response.data.errors ?? {};
        }
        errorMessage.value = getError(error, 'Unable to create the Practical Internal batch.');
    } finally {
        saving.value = false;
    }
}

function resetForm(): void {
    form.p_assess_h_id = '';
    form.from_date = '';
    form.due_date = '';
    form.assessor = '';
    selectedStudents.value = [];
    studentResults.value = [];
    studentSearch.value = '';
    validationErrors.value = {};
}

function handlePage(event: PageEvent): void {
    rows.value = event.rows;
    first.value = event.first;
    void loadBatches(event.page + 1);
}

function handleSort(event: SortEvent): void {
    sortField.value = event.sortField || 'due_date';
    sortDirection.value = event.sortOrder === -1 ? 'desc' : 'asc';
    first.value = 0;
    void loadBatches(1);
}

function handleSearch(value: string): void {
    search.value = value;
    first.value = 0;
    void loadBatches(1);
}

function handleAction(action: string, row: DataTableRow): void {
    if (action === 'view') void openView(row);
}

function formatDate(value: unknown): string {
    const text = String(value ?? '').trim();
    if (!text) return '—';
    const date = new Date(`${text.slice(0, 10)}T00:00:00`);
    if (Number.isNaN(date.getTime())) return text;
    return new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }).format(date);
}

function formatDateTime(value: unknown): string {
    const text = String(value ?? '').trim();
    if (!text) return '—';
    const date = new Date(text.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return text;
    return new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(date);
}

function initials(student: Student): string {
    const parts = student.name.replace(',', '').split(/\s+/).filter(Boolean);
    return `${parts[0]?.[0] ?? ''}${parts[1]?.[0] ?? ''}`.toUpperCase() || 'ST';
}

function firstError(field: string): string {
    return validationErrors.value[field]?.[0] ?? '';
}

function getError(error: unknown, fallback: string): string {
    if (!axios.isAxiosError(error)) return fallback;
    return error.response?.data?.message || fallback;
}

onMounted(() => void loadBatches(1));
onBeforeUnmount(() => {
    listController?.abort();
    studentController?.abort();
    if (studentTimer) clearTimeout(studentTimer);
});
</script>

<template>
    <Head title="Practical Internal Batch Creation" />

    <div class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5">
        <Message v-if="successMessage" severity="success" closable @close="successMessage = ''">
            {{ successMessage }}
        </Message>
        <Message v-if="errorMessage" severity="error" closable @close="errorMessage = ''">
            {{ errorMessage }}
        </Message>

        <Datatable
            title="Practical Assessments - Internal Batch Creation"
            description="Create and review Practical Assessment batches for enrolled students."
            header-icon="pi pi-clipboard"
            search-placeholder="Search Practical Internal batches..."
            empty-title="No Practical Internal batches found"
            empty-description="Create a batch to schedule a Practical Assessment for enrolled students."
            empty-icon="pi pi-clipboard"
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
                <Button type="button" label="Create Batch" icon="pi pi-plus" severity="success" size="small" @click="openCreate" />
            </template>

            <template #cell-last_update="{ value }">
                <div class="flex items-center gap-2 text-sm font-medium text-slate-600">
                    <i class="pi pi-calendar text-blue-500"></i>
                    <span>{{ formatDateTime(value) }}</span>
                </div>
            </template>

            <template #cell-title_assess="{ data }">
                <div class="flex items-center gap-2 font-semibold text-slate-700">
                    <i class="pi pi-clipboard text-violet-500"></i>
                    <span>{{ data.title_assess }}</span>
                </div>
            </template>

            <template #cell-assessor="{ value }">
                <div class="flex items-center gap-2 font-semibold text-slate-700">
                    <i class="pi pi-user text-blue-500"></i>
                    <span>{{ value || 'NO ASSESSOR' }}</span>
                </div>
            </template>

            <template #cell-assessment_period="{ data }">
                <div class="space-y-1 text-sm">
                    <p class="font-medium text-slate-700">{{ formatDate(data.from_date) }}</p>
                    <p class="text-slate-500">until {{ formatDate(data.due_date) }}</p>
                </div>
            </template>

            <template #cell-examinee_count="{ value }">
                <PrimeTag :value="String(value ?? 0)" severity="info" icon="pi pi-users" rounded />
            </template>
        </Datatable>

        <Dialog
            v-model:visible="dialogVisible"
            modal
            :header="viewMode ? 'Practical Internal Batch Details' : 'Create Practical Internal Batch'"
            :closable="!saving"
            :dismissable-mask="!saving"
            class="w-[min(96vw,1050px)]"
        >
            <div class="grid gap-5 lg:grid-cols-2">
                <section class="space-y-4 rounded-xl border border-slate-200 p-4">
                    <h3 class="font-semibold text-slate-700">Assessment Details</h3>

                    <div>
                        <label class="mb-2 block text-sm font-semibold text-slate-700">Practical Assessment *</label>
                        <Select
                            v-model="form.p_assess_h_id"
                            :options="assessments"
                            option-label="label"
                            option-value="id"
                            placeholder="Select Practical Assessment"
                            fluid
                            filter
                            :loading="optionsLoading"
                            :disabled="viewMode || saving"
                            :invalid="Boolean(firstError('p_assess_h_id'))"
                        />
                        <small v-if="firstError('p_assess_h_id')" class="mt-1 block text-red-500">{{ firstError('p_assess_h_id') }}</small>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="from_date" class="mb-2 block text-sm font-semibold text-slate-700">From Date *</label>
                            <input id="from_date" v-model="form.from_date" type="date" :disabled="viewMode || saving" class="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-700 outline-none focus:border-blue-500 disabled:bg-slate-100" />
                            <small v-if="firstError('from_date')" class="mt-1 block text-red-500">{{ firstError('from_date') }}</small>
                        </div>
                        <div>
                            <label for="due_date" class="mb-2 block text-sm font-semibold text-slate-700">Due Date *</label>
                            <input id="due_date" v-model="form.due_date" type="date" :min="form.from_date || undefined" :disabled="viewMode || saving" class="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-700 outline-none focus:border-blue-500 disabled:bg-slate-100" />
                            <small v-if="firstError('due_date')" class="mt-1 block text-red-500">{{ firstError('due_date') }}</small>
                        </div>
                    </div>

                    <div>
                        <label for="assessor" class="mb-2 block text-sm font-semibold text-slate-700">Assessor</label>
                        <InputText id="assessor" v-model="form.assessor" fluid placeholder="Enter assessor name" :disabled="viewMode || saving" class="uppercase" />
                        <small v-if="firstError('assessor')" class="mt-1 block text-red-500">{{ firstError('assessor') }}</small>
                    </div>
                </section>

                <section class="space-y-4 rounded-xl border border-slate-200 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="font-semibold text-slate-700">Students</h3>
                        <PrimeTag :value="`${selectedStudents.length} / 40`" severity="info" rounded />
                    </div>

                    <div v-if="!viewMode" class="relative">
                        <span class="pi pi-search absolute top-1/2 left-3 z-10 -translate-y-1/2 text-slate-400"></span>
                        <InputText v-model="studentSearch" fluid placeholder="Search name, school ID, code, or email..." :disabled="saving || selectedStudents.length >= 40" class="!pl-10" />

                        <div v-if="studentLoading" class="mt-2 text-sm text-slate-500">Searching students...</div>
                        <div v-else-if="studentResults.length" class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-xl">
                            <button
                                v-for="student in studentResults"
                                :key="student.id"
                                type="button"
                                :disabled="selectedIds.has(student.id)"
                                class="flex w-full items-center gap-3 border-b border-slate-100 px-3 py-3 text-left last:border-0 hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="addStudent(student)"
                            >
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-600">{{ initials(student) }}</span>
                                <span class="min-w-0">
                                    <span class="block truncate font-semibold text-slate-700">{{ student.name }}</span>
                                    <span class="block truncate text-xs text-slate-500">{{ student.school_id_no || student.code_person || 'No School ID' }} · {{ student.email || 'No email' }}</span>
                                </span>
                            </button>
                        </div>
                    </div>

                    <div class="max-h-[360px] space-y-2 overflow-y-auto pr-1">
                        <div v-if="selectedStudents.length === 0" class="rounded-lg border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">No students added.</div>
                        <div v-for="student in selectedStudents" :key="student.id" class="flex items-center gap-3 rounded-lg border border-slate-200 p-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-600">{{ initials(student) }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-semibold text-slate-700">{{ student.name }}</p>
                                <div class="mt-1 flex flex-wrap gap-1.5">
                                    <PrimeTag :value="student.school_id_no || student.code_person || 'No School ID'" icon="pi pi-id-card" severity="info" rounded />
                                    <PrimeTag v-if="student.email" :value="student.email" icon="pi pi-envelope" severity="secondary" rounded />
                                    <PrimeTag v-if="viewMode" :value="student.email_sent ? 'Email Sent' : 'Email Not Sent'" :severity="student.email_sent ? 'success' : 'danger'" rounded />
                                </div>
                            </div>
                            <Button v-if="!viewMode" type="button" icon="pi pi-times" severity="danger" rounded text aria-label="Remove student" :disabled="saving" @click="removeStudent(student.id)" />
                        </div>
                    </div>
                    <small v-if="firstError('students')" class="block text-red-500">{{ firstError('students') }}</small>
                </section>
            </div>

            <template #footer>
                <Button type="button" :label="viewMode ? 'Close' : 'Cancel'" icon="pi pi-times" severity="secondary" variant="outlined" :disabled="saving" @click="dialogVisible = false" />
                <Button v-if="!viewMode" type="button" label="Create Batch" icon="pi pi-check" severity="success" :loading="saving" :disabled="saving" @click="saveBatch" />
            </template>
        </Dialog>
    </div>
</template>
