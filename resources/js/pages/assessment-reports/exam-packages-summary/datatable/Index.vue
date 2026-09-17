<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Message from 'primevue/message';
import Select from 'primevue/select';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import Datatable from '@/components/Datatable.vue';
import type { DataTableColumn, DataTableRow } from '@/types';

defineOptions({ inheritAttrs: false, layout: { breadcrumbs: [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Exam Packages Summary', href: '/assessment-reports/exam-packages-summary' },
] } });
type Filters = { rep_type: number; bs_course_id: string | null; bs_topic_id: string | null };
type PackageRow = DataTableRow & { id: string; index: number; code_course: string; name_course: string; question_count: number; passing_mark: number; passing_pct: number; randomize: string };
type SubjectRow = DataTableRow & { id: string; index: number; bs_course_id: string; code_course: string; name_course: string; desc_topic: string; order_no: string | number | null; no_quest: number; passing_mark: number; active_count: number; bank_count: number };
type Report = { items: PackageRow[]; subjects: SubjectRow[]; context: { rep_type: number; package_name: string; subject_name: string; generated_at: string }; warnings: string[]; message: string };
type PageEvent = { page: number; rows: number; first: number };
type SortEvent = { sortField: string; sortOrder: number };
type TableState = { first: number; rows: number; search: string; field: string; order: number };
const base = '/api/v1/assessment-reports/exam-packages-summary';
const blank = (): Filters => ({ rep_type: 1, bs_course_id: null, bs_topic_id: null });
const filters = ref<Filters>(blank());
const types = [{ value: 1, label: 'Summary' }, { value: 2, label: 'Detailed' }];
const packages = ref<{ id: string; name_course: string }[]>([]);
const subjects = ref<{ id: string; label: string }[]>([]);
const report = ref<Report | null>(null);
const loading = ref(false);
const optionsLoading = ref(false);
const subjectsLoading = ref(false);
const errorMessage = ref('');
const packageState = reactive<TableState>({ first: 0, rows: 10, search: '', field: 'index', order: 1 });
const subjectState = reactive<TableState>({ first: 0, rows: 10, search: '', field: 'index', order: 1 });
const lifetime = new AbortController();
let subjectRequest: AbortController | null = null;
let reportRequest: AbortController | null = null;
let disposed = false;
const packageColumns: DataTableColumn[] = [
    { field: 'index', header: 'No.', sortable: true },
    { field: 'code_course', header: 'Code', sortable: true },
    { field: 'name_course', header: 'Exam Package', sortable: true, class: 'min-w-[300px]' },
    { field: 'question_count', header: 'Configured Questions', sortable: true },
    { field: 'passing_mark', header: 'Passing Mark', sortable: true },
    { field: 'passing_pct', header: 'Passing %', sortable: true },
    { field: 'randomize', header: 'Random', sortable: true },
];
const subjectColumns: DataTableColumn[] = [
    { field: 'index', header: 'No.', sortable: true },
    { field: 'name_course', header: 'Exam Package', sortable: true, class: 'min-w-[220px]' },
    { field: 'desc_topic', header: 'Subject', sortable: true, class: 'min-w-[300px]' },
    { field: 'no_quest', header: 'Configured Items', sortable: true },
    { field: 'passing_mark', header: 'Passing Mark', sortable: true },
    { field: 'active_count', header: 'Active on Question Bank', sortable: true },
    { field: 'bank_count', header: 'Total on Question Bank', sortable: true },
];
function filtered<T extends DataTableRow & { index: number }>(rows: T[], state: TableState, fields: string[]): T[] {
    const keyword = state.search.trim().toLowerCase();
    return rows.filter(row => !keyword || fields.some(field => String(row[field] ?? '').toLowerCase().includes(keyword))).sort((a, b) => {
        const x = a[state.field], y = b[state.field];
        if (x == null) return y == null ? a.index - b.index : 1;
        if (y == null) return -1;
        const difference = typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y));
        return difference * state.order || a.index - b.index;
    });
}
const packageItems = computed(() => filtered(report.value?.items ?? [], packageState, ['code_course', 'name_course', 'randomize']));
const subjectItems = computed(() => filtered(report.value?.subjects ?? [], subjectState, ['code_course', 'name_course', 'desc_topic']));
const packagePage = computed(() => packageItems.value.slice(packageState.first, packageState.first + packageState.rows));
const subjectPage = computed(() => subjectItems.value.slice(subjectState.first, subjectState.first + subjectState.rows));
function page(state: TableState, event: PageEvent): void { state.first = event.first; state.rows = event.rows; }
function sort(state: TableState, event: SortEvent): void { state.field = event.sortField || 'index'; state.order = event.sortOrder === -1 ? -1 : 1; state.first = 0; }
function search(state: TableState, value: string): void { state.search = value; state.first = 0; }
function resetTables(): void {
    for (const state of [packageState, subjectState]) { state.first = 0; state.search = ''; state.field = 'index'; state.order = 1; }
}
function failure(error: unknown, fallback: string): string {
    if (!axios.isAxiosError(error)) return fallback;
    const body = error.response?.data;
    const validation = body?.errors ? Object.values(body.errors).flat()[0] : null;
    return typeof validation === 'string' ? validation : body?.message || fallback;
}
async function loadOptions(): Promise<void> {
    optionsLoading.value = true;
    try {
        const { data } = await axios.get(base + '/options', { signal: lifetime.signal });
        packages.value = data.packages;
    } catch (error) { if (!axios.isCancel(error)) errorMessage.value = failure(error, 'Could not load exam packages.'); }
    finally { if (!disposed) optionsLoading.value = false; }
}
async function loadSubjects(): Promise<void> {
    subjectRequest?.abort(); subjects.value = []; filters.value.bs_topic_id = null;
    const controller = new AbortController(); subjectRequest = controller; subjectsLoading.value = true;
    try {
        const { data } = await axios.get(base + '/subjects', { params: { bs_course_id: filters.value.bs_course_id }, signal: controller.signal });
        if (!controller.signal.aborted && !disposed) subjects.value = data.data;
    } catch (error) { if (!axios.isCancel(error)) errorMessage.value = failure(error, 'Could not load subjects.'); }
    finally { if (!controller.signal.aborted && !disposed) subjectsLoading.value = false; }
}
async function generate(): Promise<void> {
    if (loading.value) return;
    reportRequest?.abort(); const controller = new AbortController(); reportRequest = controller;
    loading.value = true; errorMessage.value = '';
    try {
        const { data } = await axios.post<Report>(base + '/generate', { ...filters.value }, { signal: controller.signal });
        if (!controller.signal.aborted && !disposed) { report.value = data; resetTables(); }
    } catch (error) { if (!axios.isCancel(error)) errorMessage.value = failure(error, 'Report generation failed.'); }
    finally { if (!controller.signal.aborted && !disposed) loading.value = false; }
}
function clear(): void {
    if (loading.value) return;
    filters.value = blank(); report.value = null; errorMessage.value = ''; resetTables(); void loadSubjects();
}
function download(): void {
    const current = report.value; if (!current || loading.value) return;
    const c = current.context;
    const lines: unknown[][] = [
        ['Exam Packages Summary', c.rep_type === 1 ? 'Summary' : 'Detailed'],
        ['Package Filter', c.package_name, 'Subject Filter', c.subject_name, 'Generated At', c.generated_at],
        ...current.warnings.map(warning => ['Note', warning]),
        ['Package No.', 'Code', 'Name', 'Configured Questions', 'Passing Mark', 'Passing %', 'Random'],
    ];
    for (const item of current.items) {
        lines.push([item.index, item.code_course, item.name_course, item.question_count, item.passing_mark, item.passing_pct + '%', item.randomize]);
    }
    if (c.rep_type === 2) {
        lines.push([], ['Subject No.', 'Package Code', 'Package Name', 'Subject', 'Configured Items', 'Passing Mark', 'Active on Question Bank', 'Total on Question Bank']);
        for (const item of current.subjects) lines.push([item.index, item.code_course, item.name_course, item.desc_topic, item.no_quest, item.passing_mark, item.active_count, item.bank_count]);
    }
    const cell = (value: unknown): string => {
        let text = String(value ?? '');
        if (typeof value === 'string' && /^[\s\x00-\x1f]*[=+@-]/u.test(text)) text = "'" + text;
        return '"' + text.replace(/"/g, '""') + '"';
    };
    const csv = '\uFEFF' + lines.map(row => row.map(cell).join(',')).join('\r\n');
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
    const link = document.createElement('a'); link.href = url; link.download = 'exam-packages-summary-' + Date.now() + '.csv';
    document.body.appendChild(link); link.click(); link.remove(); URL.revokeObjectURL(url);
}
onMounted(() => { void loadOptions(); void loadSubjects(); });
onBeforeUnmount(() => { disposed = true; lifetime.abort(); subjectRequest?.abort(); reportRequest?.abort(); });
</script>

<template>
    <Head title="Exam Packages Summary" />
    <div class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5">
        <Message v-if="errorMessage" severity="error">{{ errorMessage }}</Message>
        <Message severity="info">Select Summary or Detailed. Package and Subject are optional. Subject filtering affects detailed rows only; package totals remain unchanged. CSV opens in Excel without the legacy XLS formatting.</Message>
        <form class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 md:grid-cols-3" @submit.prevent="generate">
            <div class="space-y-2"><label for="packages-type" class="block text-sm font-semibold">Report Type *</label><Select id="packages-type" v-model="filters.rep_type" :options="types" option-label="label" option-value="value" :disabled="loading" class="w-full" /></div>
            <div class="space-y-2"><label for="packages-package" class="block text-sm font-semibold">Exam Package</label><Select id="packages-package" v-model="filters.bs_course_id" :options="packages" option-label="name_course" option-value="id" placeholder="All exam packages" filter show-clear :loading="optionsLoading" :disabled="loading" class="w-full" @change="loadSubjects" /></div>
            <div class="space-y-2"><label for="packages-subject" class="block text-sm font-semibold">Subject (Detailed Only)</label><Select id="packages-subject" v-model="filters.bs_topic_id" :options="subjects" option-label="label" option-value="id" placeholder="All subjects" filter show-clear :loading="subjectsLoading" :disabled="loading || subjectsLoading || filters.rep_type !== 2" class="w-full" /></div>
            <div class="flex flex-wrap gap-2 md:col-span-3"><Button type="submit" label="Generate Report" icon="pi pi-chart-line" severity="success" :loading="loading" /><Button type="button" label="Clear" icon="pi pi-times" severity="secondary" outlined :disabled="loading" @click="clear" /></div>
        </form>
        <template v-if="report">
            <Message severity="success">{{ report.message }}</Message>
            <div class="grid gap-2 rounded-xl bg-white p-4 text-sm md:grid-cols-2">
                <p><strong>Report:</strong> {{ report.context.rep_type === 1 ? 'Summary' : 'Detailed' }}</p><p><strong>Package:</strong> {{ report.context.package_name }}</p>
                <p><strong>Subject:</strong> {{ report.context.rep_type === 2 ? report.context.subject_name : 'Not applied in Summary' }}</p><p><strong>Generated:</strong> {{ report.context.generated_at }}</p>
            </div>
            <Message v-for="warning in report.warnings" :key="warning" severity="warn">{{ warning }}</Message>
        </template>
        <Datatable title="Exam Packages Summary" description="Configured package item totals, passing marks and randomization." header-icon="pi pi-chart-line" table-min-width="1100px" search-placeholder="Search package codes or names..." data-key="id" lazy
            :data="packagePage" :columns="packageColumns" :actions="[]" :loading="loading" :total-records="packageItems.length" :first="packageState.first" :rows="packageState.rows" :rows-per-page-options="[10, 20, 50, 100]"
            :empty-title="report ? 'No matching exam packages' : 'Generate an exam packages report'" empty-description="Select the report type and generate the report."
            @page="page(packageState, $event)" @sort="sort(packageState, $event)" @search="search(packageState, $event)">
            <template #header-actions><Button label="Download Full CSV" icon="pi pi-download" severity="success" :disabled="!report || loading" @click="download" /></template>
            <template #cell-passing_pct="{ value }">{{ value }}%</template>
        </Datatable>
        <Datatable v-if="report?.context.rep_type === 2" title="Subject Details" description="Configured subject items compared with active and total question-bank counts." header-icon="pi pi-list" table-min-width="1200px" search-placeholder="Search subjects or package names..." data-key="id" lazy
            :data="subjectPage" :columns="subjectColumns" :actions="[]" :loading="loading" :total-records="subjectItems.length" :first="subjectState.first" :rows="subjectState.rows" :rows-per-page-options="[10, 20, 50, 100]"
            empty-title="No matching subjects" empty-description="Try another subject or exam package filter."
            @page="page(subjectState, $event)" @sort="sort(subjectState, $event)" @search="search(subjectState, $event)">
            <template #cell-desc_topic="{ value }"><div class="whitespace-pre-wrap">{{ value }}</div></template>
        </Datatable>
    </div>
</template>
