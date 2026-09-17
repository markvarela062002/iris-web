<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Message from 'primevue/message';
import Select from 'primevue/select';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Datatable from '@/components/Datatable.vue';
import type { DataTableColumn, DataTableRow } from '@/types';

defineOptions({
    inheritAttrs: false,
    layout: { breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Exam Results Summary', href: '/assessment-reports/exam-results-summary' },
    ] },
});

type Filters = { rep_type: number; date_from: string; date_to: string; bs_course_id: string | null };
type Totals = {
    attempt_count: number; passed_count: number; failed_count: number; no_confirmation_count: number;
    passed_pct: number | null; failed_pct: number | null; no_confirmation_pct: number | null;
};
type Item = DataTableRow & {
    id: string; index: number; name_course: string;
    code_person?: string; student_name?: string; started?: string | null; exam_type?: string | null;
    remarks?: string; passing_pct?: number | null; grade_pct?: number | null;
    attempt_count?: number; passed_count?: number; failed_count?: number; no_confirmation_count?: number;
    passed_pct?: number | null; failed_pct?: number | null; no_confirmation_pct?: number | null;
};
type Report = {
    context: { rep_type: number; date_from: string; date_to: string; package_name: string; generated_at: string };
    items: Item[]; totals: Totals | null; warnings: string[]; message: string;
};
type PageEvent = { page: number; rows: number; first: number };
type SortEvent = { sortField: string; sortOrder: number };
const base = '/api/v1/assessment-reports/exam-results-summary';
const blank = (): Filters => ({ rep_type: 1, date_from: '', date_to: '', bs_course_id: null });
const filters = ref<Filters>(blank());
const reportTypes = [{ value: 1, label: 'Student List' }, { value: 2, label: 'Per Exam Package' }];
const packages = ref<{ id: string; name_course: string }[]>([]);
const report = ref<Report | null>(null);
const loading = ref(false);
const optionsLoading = ref(false);
const errorMessage = ref('');
const first = ref(0);
const perPage = ref(10);
const search = ref('');
const sortField = ref('index');
const sortOrder = ref(1);
const lifetime = new AbortController();
let reportRequest: AbortController | null = null;
let disposed = false;
// The generated report, not unsaved form selections, controls columns and totals.
const packageReport = computed(() => report.value?.context.rep_type === 2);
const percentageFields = ['passing_pct', 'grade_pct', 'passed_pct', 'failed_pct', 'no_confirmation_pct'];
const columns = computed<DataTableColumn[]>(() => packageReport.value ? [
    { field: 'index', header: 'No.', sortable: true },
    { field: 'name_course', header: 'Exam Package', sortable: true, class: 'min-w-[250px]' },
    { field: 'attempt_count', header: 'Completed Attempts', sortable: true },
    { field: 'passed_count', header: 'Passed', sortable: true },
    { field: 'failed_count', header: 'Failed', sortable: true },
    { field: 'no_confirmation_count', header: 'No Confirmation', sortable: true },
    { field: 'passed_pct', header: 'Passed %', sortable: true },
    { field: 'failed_pct', header: 'Failed %', sortable: true },
    { field: 'no_confirmation_pct', header: 'No Confirmation %', sortable: true },
] : [
    { field: 'index', header: 'No.', sortable: true },
    { field: 'student_name', header: 'Student Information', sortable: true, class: 'min-w-[250px]' },
    { field: 'name_course', header: 'Exam Taken', sortable: true, class: 'min-w-[250px]' },
    { field: 'started', header: 'Date Taken', sortable: true },
    { field: 'exam_type', header: 'Exam Type', sortable: true },
    { field: 'remarks', header: 'Remarks', sortable: true },
    { field: 'passing_pct', header: 'Passing Mark %', sortable: true },
    { field: 'grade_pct', header: 'Grade %', sortable: true },
]);
const filteredItems = computed(() => {
    const keyword = search.value.trim().toLowerCase();
    const items = (report.value?.items ?? []).filter(item => !keyword || [item.code_person, item.student_name, item.name_course, item.exam_type, item.remarks].some(value => String(value ?? '').toLowerCase().includes(keyword)));
    return [...items].sort((a, b) => {
        const x = a[sortField.value], y = b[sortField.value];
        if (x == null) return y == null ? a.index - b.index : 1;
        if (y == null) return -1;
        const difference = typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y));
        return difference * sortOrder.value || a.index - b.index;
    });
});
const pageItems = computed(() => filteredItems.value.slice(first.value, first.value + perPage.value));
function percentage(value: unknown, precision = 1): string { return typeof value === 'number' ? value.toFixed(precision) + '%' : '—'; }
function date(value: unknown): string {
    // Avoid browser timezone shifts for legacy local DATETIME values.
    const match = String(value ?? '').match(/^(\d{4})-(\d{2})-(\d{2})/);
    return match ? `${match[2]}/${match[3]}/${match[1]}` : '—';
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
async function generate(): Promise<void> {
    if (loading.value) return;
    if (!filters.value.date_from || !filters.value.date_to) { errorMessage.value = 'Date From and Date To are required.'; return; }
    if (filters.value.date_to < filters.value.date_from) { errorMessage.value = 'Date To must be on or after Date From.'; return; }
    reportRequest?.abort();
    const controller = new AbortController(); reportRequest = controller;
    loading.value = true; errorMessage.value = '';
    try {
        const { data } = await axios.post<Report>(base + '/generate', { ...filters.value }, { signal: controller.signal });
        if (!controller.signal.aborted && !disposed) { report.value = data; first.value = 0; search.value = ''; sortField.value = 'index'; sortOrder.value = 1; }
    } catch (error) { if (!axios.isCancel(error)) errorMessage.value = failure(error, 'Report generation failed.'); }
    finally { if (!controller.signal.aborted && !disposed) loading.value = false; }
}
function clear(): void {
    if (loading.value) return;
    filters.value = blank(); report.value = null; first.value = 0; search.value = ''; errorMessage.value = '';
}
function pageChanged(event: PageEvent): void { first.value = event.first; perPage.value = event.rows; }
function sortChanged(event: SortEvent): void { sortField.value = event.sortField || 'index'; sortOrder.value = event.sortOrder === -1 ? -1 : 1; first.value = 0; }
function searchChanged(value: string): void { search.value = value; first.value = 0; }
function download(): void {
    const current = report.value; if (!current || loading.value) return;
    const c = current.context;
    const lines: unknown[][] = [
        ['Exam Results Summary', c.rep_type === 1 ? 'Student List' : 'Per Exam Package'],
        ['Date From', c.date_from, 'Date To', c.date_to, 'Package', c.package_name],
        ['Generated At', c.generated_at], ...current.warnings.map(warning => ['Note', warning]),
    ];
    if (c.rep_type === 1) {
        lines.push(['No.', 'School ID No. (code_person)', 'Student Name', 'Exam Taken', 'Date Taken', 'Exam Type', 'Remarks', 'Passing Mark %', 'Grade %']);
        for (const item of current.items) {
            lines.push([item.index, item.code_person ? "'" + item.code_person : '', item.student_name, item.name_course, date(item.started), item.exam_type, item.remarks, item.passing_pct, item.grade_pct]);
        }
    } else {
        lines.push(['No.', 'Exam Package', 'Completed Attempts', 'Passed', 'Failed', 'No Confirmation', 'Passed %', 'Failed %', 'No Confirmation %']);
        for (const item of current.items) lines.push([item.index, item.name_course, item.attempt_count, item.passed_count, item.failed_count, item.no_confirmation_count, item.passed_pct, item.failed_pct, item.no_confirmation_pct]);
        const t = current.totals;
        if (t) lines.push(['', 'TOTAL', t.attempt_count, t.passed_count, t.failed_count, t.no_confirmation_count, t.passed_pct, t.failed_pct, t.no_confirmation_pct]);
    }
    const cell = (value: unknown): string => {
        let text = String(value ?? '');
        if (typeof value === 'string' && /^[\s\x00-\x1f]*[=+@-]/u.test(text)) text = "'" + text;
        return '"' + text.replace(/"/g, '""') + '"';
    };
    const csv = '\uFEFF' + lines.map(row => row.map(cell).join(',')).join('\r\n');
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
    const link = document.createElement('a'); link.href = url; link.download = 'exam-results-summary-' + Date.now() + '.csv';
    document.body.appendChild(link); link.click(); link.remove(); URL.revokeObjectURL(url);
}
onMounted(() => { void loadOptions(); });
onBeforeUnmount(() => { disposed = true; lifetime.abort(); reportRequest?.abort(); });
</script>

<template>
    <Head title="Exam Results Summary" />
    <div class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5">
        <Message v-if="errorMessage" severity="error">{{ errorMessage }}</Message>
        <Message severity="info">Select a report type and date range. Exam Package is optional. Reports are read-only; CSV opens in Excel without the legacy XLS template formatting.</Message>
        <form class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 md:grid-cols-2 xl:grid-cols-4" @submit.prevent="generate">
            <div class="space-y-2"><label for="summary-type" class="block text-sm font-semibold">Report Type *</label><Select id="summary-type" v-model="filters.rep_type" :options="reportTypes" option-label="label" option-value="value" :disabled="loading" class="w-full" /></div>
            <div class="space-y-2"><label for="summary-from" class="block text-sm font-semibold">Date From *</label><input id="summary-from" v-model="filters.date_from" type="date" required :disabled="loading" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5" /></div>
            <div class="space-y-2"><label for="summary-to" class="block text-sm font-semibold">Date To *</label><input id="summary-to" v-model="filters.date_to" type="date" required :min="filters.date_from || undefined" :disabled="loading" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5" /></div>
            <div class="space-y-2"><label for="summary-package" class="block text-sm font-semibold">Exam Package</label><Select id="summary-package" v-model="filters.bs_course_id" :options="packages" option-label="name_course" option-value="id" placeholder="All exam packages" filter show-clear :loading="optionsLoading" :disabled="loading" class="w-full" /></div>
            <div class="flex flex-wrap gap-2 md:col-span-2 xl:col-span-4"><Button type="submit" label="Generate Report" icon="pi pi-chart-line" severity="success" :loading="loading" /><Button type="button" label="Clear" icon="pi pi-times" severity="secondary" outlined :disabled="loading" @click="clear" /></div>
        </form>
        <template v-if="report">
            <Message severity="success">{{ report.message }}</Message>
            <div class="grid gap-2 rounded-xl bg-white p-4 text-sm md:grid-cols-2">
                <p><strong>Report:</strong> {{ report.context.rep_type === 1 ? 'Student List' : 'Per Exam Package' }}</p>
                <p><strong>Package:</strong> {{ report.context.package_name }}</p>
                <p><strong>Date Range:</strong> {{ date(report.context.date_from) }} – {{ date(report.context.date_to) }}</p>
                <p><strong>Generated:</strong> {{ report.context.generated_at }}</p>
            </div>
            <Message v-for="warning in report.warnings" :key="warning" severity="warn">{{ warning }}</Message>
        </template>
        <Datatable :key="report?.context.rep_type ?? 0" title="Exam Results Summary" description="Review student attempts or completed-exam outcomes by package." header-icon="pi pi-chart-line" table-min-width="1300px" search-placeholder="Search students, exam packages or remarks..." data-key="id" lazy
            :data="pageItems" :columns="columns" :actions="[]" :loading="loading" :total-records="filteredItems.length" :first="first" :rows="perPage" :rows-per-page-options="[10, 20, 50, 100]"
            :empty-title="report ? 'No matching records' : 'Generate an exam results summary'" empty-description="Choose the required fields and generate the report." @page="pageChanged" @sort="sortChanged" @search="searchChanged">
            <template #header-actions><Button label="Download Full CSV" icon="pi pi-download" severity="success" :disabled="!report || loading" @click="download" /></template>
            <template #cell-student_name="{ data }"><p class="font-semibold">{{ data.student_name || 'Unknown student' }}</p><p class="text-sm text-slate-500">{{ data.code_person || '—' }}</p></template>
            <template #cell-started="{ value }">{{ date(value) }}</template>
            <template #cell-remarks="{ value }"><span class="font-semibold" :class="value === 'PASSED' ? 'text-green-600' : 'text-red-600'">{{ value }}</span></template>
            <template v-for="field in percentageFields" :key="field" #[`cell-${field}`]="{ value }">{{ percentage(value) }}</template>
        </Datatable>
        <div v-if="report?.totals" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 text-sm md:grid-cols-4">
            <p><strong>Full Report Totals</strong><br />{{ report.totals.attempt_count }} completed attempts</p>
            <p><strong class="text-green-600">Passed</strong><br />{{ report.totals.passed_count }} · {{ percentage(report.totals.passed_pct, 2) }}</p>
            <p><strong class="text-red-600">Failed</strong><br />{{ report.totals.failed_count }} · {{ percentage(report.totals.failed_pct, 2) }}</p>
            <p><strong class="text-orange-600">No Confirmation</strong><br />{{ report.totals.no_confirmation_count }} · {{ percentage(report.totals.no_confirmation_pct, 2) }}</p>
        </div>
    </div>
</template>
