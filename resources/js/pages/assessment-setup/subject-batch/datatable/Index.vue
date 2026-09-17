<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import Message from 'primevue/message';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Datatable from '@/components/Datatable.vue';
import { dashboard } from '@/routes';
import type { DataTableColumn, DataTableRow } from '@/types';

defineOptions({
    inheritAttrs: false,
    layout: { breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Subject Batch Update', href: '/assessment-setup/subject-batch' },
    ] },
});

type Company = { id: string; company: string };
type Course = { id: string; name_course: string };
type Values = { order_no: number | null; no_quest: number | null; passing_mark: number | null };
type Subject = DataTableRow & Values & { id: string; desc_topic: string; original: Values };
type Scope = { company_id: string; course_id: string };
const API = '/api/v1/assessment-setup/subject-batch';
const isAdministrator = ref(false);
const companies = ref<Company[]>([]);
const courses = ref<Course[]>([]);
const companyId = ref('');
const courseId = ref<string | null>(null);
const subjects = ref<Subject[]>([]);
const scope = ref<Scope | null>(null);
const loading = ref(false);
const packagesLoading = ref(false);
const saving = ref(false);
const initialized = ref(false);
const error = ref('');
const success = ref('');
const confirmVisible = ref(false);
const clearVisible = ref(false);
let packageRequest: AbortController | null = null;
let subjectRequest: AbortController | null = null;
let removeNavigationGuard: (() => void) | null = null;
const fields = ['order_no', 'no_quest', 'passing_mark'] as const;
const dirty = computed(() => subjects.value.some(row => fields.some(field => row[field] !== row.original[field])));
const totalQuestions = computed(() => subjects.value.reduce((total, row) => total + Number(row.no_quest ?? 0), 0));
const totalPassing = computed(() => subjects.value.reduce((total, row) => total + Number(row.passing_mark ?? 0), 0));
const packageName = computed(() => courses.value.find(course => course.id === scope.value?.course_id)?.name_course ?? 'the selected package');
const columns: DataTableColumn[] = [
    { field: 'order_no', header: 'Order No.', sortable: true, searchable: false, class: 'min-w-[150px]' },
    { field: 'desc_topic', header: 'Subject', sortable: true, searchable: true, class: 'min-w-[300px] whitespace-normal' },
    { field: 'no_quest', header: 'Question Count', sortable: true, searchable: false, class: 'min-w-[180px]' },
    { field: 'passing_mark', header: 'Passing Mark', sortable: true, searchable: false, class: 'min-w-[180px]' },
    { field: 'passing_pct', header: 'Passing %', sortable: false, searchable: false, class: 'min-w-[120px]' },
];

async function initialize(): Promise<void> {
    loading.value = true;
    error.value = '';
    try {
        const response = await axios.get<{ is_administrator: boolean; companies: Company[] }>(`${API}/options`, config());
        isAdministrator.value = response.data.is_administrator;
        companies.value = [{ id: '', company: 'Unassigned packages' }, ...response.data.companies];
        initialized.value = true;
        await loadPackages();
    } catch (caught: unknown) { error.value = message(caught, 'Unable to load batch update options.'); }
    finally { loading.value = false; }
}

async function loadPackages(): Promise<void> {
    if (dirty.value || saving.value) return;
    packageRequest?.abort();
    subjectRequest?.abort();
    resetSubjects();
    courseId.value = null;
    courses.value = [];
    const controller = new AbortController();
    packageRequest = controller;
    packagesLoading.value = true;
    try {
        const response = await axios.get<{ data: Course[] }>(`${API}/packages`, {
            ...config(), signal: controller.signal, params: { company_id: companyId.value },
        });
        courses.value = response.data.data;
    } catch (caught: unknown) {
        if (!axios.isCancel(caught)) error.value = message(caught, 'Unable to load exam packages.');
    } finally { if (packageRequest === controller) packagesLoading.value = false; }
}

function resetSubjects(): void {
    subjects.value = [];
    scope.value = null;
    error.value = '';
    success.value = '';
}
function changePackage(): void {
    subjectRequest?.abort();
    resetSubjects();
}
async function showSubjects(): Promise<void> {
    if (!courseId.value || dirty.value || saving.value) return;
    subjectRequest?.abort();
    const controller = new AbortController();
    subjectRequest = controller;
    loading.value = true;
    error.value = '';
    const selected = { company_id: companyId.value, course_id: courseId.value };
    try {
        const response = await axios.get<{ data: Array<Values & { id: string; desc_topic: string }> }>(
            `${API}/packages/${encodeURIComponent(selected.course_id)}/subjects`,
            { ...config(), signal: controller.signal, params: { company_id: selected.company_id } },
        );
        subjects.value = response.data.data.map(row => ({ ...row, original: {
            order_no: row.order_no, no_quest: row.no_quest, passing_mark: row.passing_mark,
        } }));
        scope.value = selected;
    } catch (caught: unknown) {
        if (!axios.isCancel(caught)) error.value = message(caught, 'Unable to load subjects.');
    } finally { if (subjectRequest === controller) loading.value = false; }
}

function percentage(mark: unknown, count: unknown): string {
    return Number(count) > 0 ? `${Math.round((Number(mark) / Number(count)) * 100)}%` : '—';
}
function rowIssue(row: Subject): string {
    if (row.order_no === null || !Number.isInteger(row.order_no) || row.order_no < 0 || row.order_no > 1000000) return 'Enter a non-negative integer order number (maximum 1,000,000).';
    if (row.no_quest === null || !Number.isInteger(row.no_quest) || row.no_quest < 0 || row.no_quest > 100000) return 'Enter a non-negative integer question count (maximum 100,000).';
    if (row.passing_mark === null || !Number.isFinite(row.passing_mark) || row.passing_mark < 0 || row.passing_mark > row.no_quest) return 'Passing mark must be between zero and the question count.';
    return '';
}
function confirmUpdate(): void {
    error.value = '';
    const invalid = subjects.value.find(row => rowIssue(row));
    if (invalid) { error.value = `${invalid.desc_topic}: ${rowIssue(invalid)}`; return; }
    confirmVisible.value = true;
}
async function saveBatch(): Promise<void> {
    if (!scope.value || saving.value) return;
    saving.value = true;
    error.value = '';
    success.value = '';
    try {
        const response = await axios.patch<{ message: string }>(
            `${API}/packages/${encodeURIComponent(scope.value.course_id)}/subjects`,
            { company_id: scope.value.company_id, confirmed: true,
                subjects: subjects.value.map(row => ({ id: row.id, order_no: row.order_no,
                    no_quest: row.no_quest, passing_mark: row.passing_mark, original: row.original })) }, config(),
        );
        subjects.value.forEach(row => { row.original = {
            order_no: row.order_no, no_quest: row.no_quest, passing_mark: row.passing_mark,
        }; });
        success.value = response.data.message;
        subjects.value.sort((a, b) => Number(a.order_no) - Number(b.order_no));
    } catch (caught: unknown) {
        error.value = message(caught, 'Unable to save the batch. Reload to verify the database state before retrying if the request status is uncertain.');
    } finally { saving.value = false; confirmVisible.value = false; }
}
function requestClear(): void { if (dirty.value) clearVisible.value = true; else clear(); }
function clear(): void {
    subjectRequest?.abort();
    resetSubjects();
    courseId.value = null;
    clearVisible.value = false;
}
function config(): { headers: Record<string, string> } { return { headers: { Accept: 'application/json' } }; }
function message(caught: unknown, fallback: string): string {
    if (!axios.isAxiosError(caught)) return fallback;
    const body = caught.response?.data;
    const first = Object.values(body?.errors ?? {})[0];
    return Array.isArray(first) ? String(first[0]) : String(body?.message ?? fallback);
}
function beforeUnload(event: BeforeUnloadEvent): void {
    if (dirty.value || saving.value) { event.preventDefault(); event.returnValue = ''; }
}
onMounted(() => {
    void initialize();
    window.addEventListener('beforeunload', beforeUnload);
    removeNavigationGuard = router.on('before', event => {
        if ((dirty.value || saving.value) && !window.confirm('This batch has unsaved edits or a save in progress. Leave this page?')) event.preventDefault();
    });
});
onBeforeUnmount(() => {
    packageRequest?.abort(); subjectRequest?.abort(); removeNavigationGuard?.();
    window.removeEventListener('beforeunload', beforeUnload);
});
</script>

<template>
    <Head title="Subject Batch Update" />
    <div class="flex flex-1 flex-col gap-4 bg-[#F8FAFC] p-4 lg:p-5">
        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <div class="mb-5 flex items-center gap-3">
                <div class="relative flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-gradient-to-br from-[#123A63] to-[#377EC0] text-white shadow-lg shadow-[#377EC0]/20">
                    <div class="pointer-events-none absolute -top-3 -right-3 size-8 rounded-full bg-white/15"></div>
                    <i class="pi pi-sliders-h relative z-10 !text-[1.65rem] !leading-none !text-white"></i>
                </div>
                <div><h1 class="text-xl font-bold text-[#21365A]">Subject Batch Update</h1>
                    <p class="text-sm text-slate-500">Edit subject order, exam question count, and passing mark together.</p></div>
            </div>
            <div class="flex flex-wrap items-end gap-4">
                <div v-if="isAdministrator" class="min-w-60 flex-1"><label class="mb-2 block text-sm font-semibold">School / Company</label>
                    <Select v-model="companyId" :options="companies" option-label="company" option-value="id" filter
                        :disabled="loading || packagesLoading || saving || dirty" class="w-full" @change="loadPackages" /></div>
                <div class="min-w-60 flex-1"><label class="mb-2 block text-sm font-semibold">Exam Package *</label>
                    <Select v-model="courseId" :options="courses" option-label="name_course" option-value="id" filter
                        placeholder="Select a package" :loading="packagesLoading" :disabled="!initialized || loading || saving || dirty || packagesLoading"
                        class="w-full" @change="changePackage" /></div>
                <Button label="Show Subjects" icon="pi pi-search" :loading="loading" :disabled="!courseId || saving || dirty || packagesLoading" @click="showSubjects" />
                <Button label="Clear" icon="pi pi-times" severity="secondary" outlined :disabled="saving || loading" @click="requestClear" />
            </div>
            <p v-if="dirty" class="mt-3 text-sm text-amber-700">Save or clear your edits before changing the package or company.</p>
        </section>
        <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
        <Message v-if="success" severity="success" :closable="false">{{ success }}</Message>
        <Message v-if="!scope" severity="info" :closable="false">Select an exam package, then click Show Subjects.</Message>
        <template v-if="scope">
            <Datatable :key="scope.course_id" title="Package Subjects" description="Question Count is the configured exam item count—not the number of questions in the bank."
                header-icon="pi pi-sliders-h" search-placeholder="Search subjects..." empty-title="No subjects found"
                empty-description="Select another exam package." table-min-width="1000px" data-key="id"
                :data="subjects" :columns="columns" :actions="[]" :loading="saving" :rows="10"
                :rows-per-page-options="[10, 20, 50, 100]">
                <template #header-actions><Button label="Update Batch" icon="pi pi-save" :loading="saving"
                    :disabled="!dirty || !subjects.length" @click="confirmUpdate" /></template>
                <template #cell-order_no="{ data }"><InputNumber v-model="data.order_no" :min="0" :max="1000000" :use-grouping="false"
                    :disabled="saving" input-class="w-24" :aria-label="`Order number for ${data.desc_topic}`" /></template>
                <template #cell-desc_topic="{ data }"><div class="font-medium">{{ data.desc_topic }}</div>
                    <small v-if="rowIssue(data)" class="text-red-600">{{ rowIssue(data) }}</small></template>
                <template #cell-no_quest="{ data }"><InputNumber v-model="data.no_quest" :min="0" :max="100000" :use-grouping="false"
                    :disabled="saving" input-class="w-28" :aria-label="`Question count for ${data.desc_topic}`" /></template>
                <template #cell-passing_mark="{ data }"><InputNumber v-model="data.passing_mark" :min="0" :max="100000" :max-fraction-digits="2"
                    :use-grouping="false" :disabled="saving" input-class="w-28" :aria-label="`Passing mark for ${data.desc_topic}`" /></template>
                <template #cell-passing_pct="{ data }"><Tag :value="percentage(data.passing_mark, data.no_quest)" severity="info" /></template>
            </Datatable>
            <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white p-4">
                <div><p class="font-semibold text-[#21365A]">Package Totals</p>
                    <p class="text-sm text-slate-500">All {{ subjects.length }} subjects, including hidden pages and search results.</p></div>
                <div class="flex flex-wrap gap-3"><Tag :value="`Questions: ${totalQuestions}`" severity="info" />
                    <Tag :value="`Passing Mark: ${totalPassing.toLocaleString(undefined, { maximumFractionDigits: 2 })}`" severity="success" />
                    <Tag :value="`Passing: ${percentage(totalPassing, totalQuestions)}`" severity="warn" /></div>
            </section>
        </template>
    </div>
    <Dialog v-model:visible="confirmVisible" modal header="Confirm Subject Batch Update" :closable="!saving" :close-on-escape="!saving" class="w-[min(520px,94vw)]">
        <p>Save the edited values for all subjects in {{ packageName }}? This includes subjects outside the current page or search results.</p>
        <template #footer><Button label="Cancel" text severity="secondary" :disabled="saving" @click="confirmVisible = false" />
            <Button label="Save Batch" icon="pi pi-save" :loading="saving" @click="saveBatch" /></template>
    </Dialog>
    <Dialog v-model:visible="clearVisible" modal header="Discard Unsaved Edits" class="w-[min(460px,94vw)]">
        <p>Discard your unsaved subject edits and clear the selected package?</p>
        <template #footer><Button label="Cancel" text severity="secondary" @click="clearVisible = false" />
            <Button label="Discard Edits" severity="danger" @click="clear" /></template>
    </Dialog>
</template>
