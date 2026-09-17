<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Message from 'primevue/message';
import Select from 'primevue/select';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import Datatable from '@/components/Datatable.vue';
import type { DataTableColumn, DataTableRow } from '@/types';

defineOptions({
    inheritAttrs: false,
    layout: { breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Item Analysis History', href: '/assessment-reports/item-analysis-history' },
    ] },
});

type History = DataTableRow & {
    id: string; date_analyzed: string | null; analyzed_by: string | null;
    session_code: string | null; name_course: string | null; desc_topic: string; pct: number;
};
type Item = {
    id: string; index: number; question: string; appearances: number;
    upper_correct: number; lower_correct: number; upper_pct: number | null; lower_pct: number | null;
    df: number | null; df_remarks: string | null; ds: number | null; ds_remarks: string | null;
    decision: string | null; options: Record<string, { upper: number; lower: number }>;
};
type Report = {
    history: History; items: Item[]; warnings: string[];
    summary: { students: number; upper: number; lower: number; metric_mode: string; show_options: boolean; recalculated_at: string };
};
type Filters = { bs_exam_session_id: string | null; bs_course_id: string | null; bs_topic_id: string | null };
type PageEvent = { page: number; rows: number; first: number };
type SortEvent = { sortField: string; sortOrder: number };
const base = '/api/v1/assessment-reports/item-analysis-history';
const emptyFilters = (): Filters => ({ bs_exam_session_id: null, bs_course_id: null, bs_topic_id: null });
const filters = ref<Filters>(emptyFilters());
let appliedFilters: Filters = emptyFilters();
const sessions = ref<{ id: string; session_code: string }[]>([]);
const packages = ref<{ id: string; name_course: string }[]>([]);
const subjects = ref<{ id: string; desc_topic: string }[]>([]);
const histories = ref<History[]>([]);
const loading = ref(false);
const optionsLoading = ref(false);
const subjectsLoading = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const canDelete = ref(false);
const total = ref(0);
const first = ref(0);
const perPage = ref(10);
const search = ref('');
const sortField = ref('date_analyzed');
const sortDirection = ref<'asc' | 'desc'>('desc');
const reportVisible = ref(false);
const reportLoading = ref(false);
const reportError = ref('');
const report = ref<Report | null>(null);
const selectedId = ref('');
const metricMode = ref<'legacy' | 'normalized'>('legacy');
const downloading = ref(false);
const deleteVisible = ref(false);
const deleteTarget = ref<History | null>(null);
const deleting = ref(false);
const deleteError = ref('');
let listRequest: AbortController | null = null;
let subjectRequest: AbortController | null = null;
let reportRequest: AbortController | null = null;
const lifetime = new AbortController();
let disposed = false;

const columns: DataTableColumn[] = [
    { field: 'date_analyzed', header: 'Date Analyzed', sortable: true },
    { field: 'analyzed_by', header: 'Analyzed By', sortable: true },
    { field: 'session_code', header: 'Exam Session', sortable: true },
    { field: 'name_course', header: 'Exam Package', sortable: true },
    { field: 'desc_topic', header: 'Subject', sortable: true },
    { field: 'pct', header: 'Group Percentage', sortable: true },
    { field: 'actions', header: 'Actions', sortable: false, class: 'min-w-[140px] text-center' },
];
const modes = [
    { label: 'Legacy DF/DS', value: 'legacy' },
    { label: 'Normalized DF/DS (0–1)', value: 'normalized' },
];
const letters = ['A', 'B', 'C', 'D', 'E'];
function message(error: unknown, fallback: string): string {
    if (axios.isAxiosError(error)) {
        const data = error.response?.data;
        const validation = data?.errors ? Object.values(data.errors).flat()[0] : null;
        return typeof validation === 'string' ? validation : data?.message || fallback;
    }
    return fallback;
}
function number(value: number | null): string { return value === null ? '—' : value.toFixed(2); }
function date(value: unknown): string {
    // Date analyzed is a school-local legacy date; don't introduce a browser timezone shift.
    const raw = String(value ?? '');
    const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!match) return raw || '—';
    return new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' })
        .format(new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]))));
}
async function loadOptions(): Promise<void> {
    optionsLoading.value = true;
    try {
        const { data } = await axios.get(base + '/options', { signal: lifetime.signal });
        sessions.value = data.sessions;
        packages.value = data.packages;
        canDelete.value = data.can_delete;
    } catch (error) {
        if (!axios.isCancel(error)) errorMessage.value = message(error, 'Could not load filter options.');
    } finally { if (!disposed) optionsLoading.value = false; }
}
async function packageChanged(): Promise<void> {
    subjectRequest?.abort();
    filters.value.bs_topic_id = null;
    subjects.value = [];
    subjectsLoading.value = false;
    const courseId = filters.value.bs_course_id;
    if (!courseId) return;
    const controller = new AbortController();
    subjectRequest = controller;
    subjectsLoading.value = true;
    try {
        const { data } = await axios.get(base + '/packages/' + encodeURIComponent(courseId) + '/subjects', { signal: controller.signal });
        if (!controller.signal.aborted) subjects.value = data.data;
    } catch (error) {
        if (!axios.isCancel(error)) errorMessage.value = message(error, 'Could not load subjects.');
    } finally { if (!controller.signal.aborted && !disposed) subjectsLoading.value = false; }
}
async function loadHistory(page = 1): Promise<void> {
    listRequest?.abort();
    const controller = new AbortController();
    listRequest = controller;
    loading.value = true;
    errorMessage.value = '';
    try {
        const { data } = await axios.get(base, {
            signal: controller.signal,
            params: { ...appliedFilters, page, per_page: perPage.value, search: search.value, sort_field: sortField.value, sort_direction: sortDirection.value },
        });
        if (controller.signal.aborted) return;
        histories.value = data.data;
        total.value = data.meta.total;
        perPage.value = data.meta.perPage;
        first.value = (data.meta.currentPage - 1) * data.meta.perPage;
        canDelete.value = data.can_delete;
    } catch (error) {
        if (!axios.isCancel(error)) { histories.value = []; total.value = 0; errorMessage.value = message(error, 'Could not load history.'); }
    } finally { if (!controller.signal.aborted && !disposed) loading.value = false; }
}
function applyFilters(): void { appliedFilters = { ...filters.value }; first.value = 0; void loadHistory(); }
function clearFilters(): void {
    subjectRequest?.abort(); subjectsLoading.value = false; subjects.value = [];
    filters.value = emptyFilters(); applyFilters();
}
function pageChanged(event: PageEvent): void { perPage.value = event.rows; void loadHistory(event.page + 1); }
function sortChanged(event: SortEvent): void {
    sortField.value = event.sortField || 'date_analyzed'; sortDirection.value = event.sortOrder === 1 ? 'asc' : 'desc';
    first.value = 0; void loadHistory();
}
function searchChanged(value: string): void { search.value = value; first.value = 0; void loadHistory(); }
function viewHistory(row: History): void {
    selectedId.value = row.id; metricMode.value = 'legacy'; reportVisible.value = true; void loadReport();
}
async function loadReport(): Promise<void> {
    reportRequest?.abort();
    const controller = new AbortController(); reportRequest = controller;
    report.value = null; reportError.value = ''; reportLoading.value = true;
    try {
        const { data } = await axios.get<Report>(base + '/' + encodeURIComponent(selectedId.value), {
            signal: controller.signal, params: { metric_mode: metricMode.value },
        });
        if (!controller.signal.aborted) report.value = data;
    } catch (error) {
        if (!axios.isCancel(error)) reportError.value = message(error, 'Could not calculate the report.');
    } finally { if (!controller.signal.aborted && !disposed) reportLoading.value = false; }
}
function reportClosed(): void { reportRequest?.abort(); report.value = null; }
async function downloadReport(): Promise<void> {
    downloading.value = true; reportError.value = '';
    try {
        const { data } = await axios.get(base + '/' + encodeURIComponent(selectedId.value) + '/export', {
            responseType: 'blob', signal: lifetime.signal, params: { metric_mode: metricMode.value },
        });
        const url = URL.createObjectURL(data);
        const link = document.createElement('a'); link.href = url; link.download = 'item-analysis-' + selectedId.value + '.csv';
        document.body.appendChild(link); link.click(); link.remove(); URL.revokeObjectURL(url);
    } catch (error) {
        if (axios.isCancel(error)) return;
        if (axios.isAxiosError(error) && error.response?.data instanceof Blob) {
            try { const payload = JSON.parse(await error.response.data.text()); reportError.value = Object.values(payload.errors ?? {}).flat().join(' ') || payload.message || 'Export failed.'; }
            catch { reportError.value = 'Export failed.'; }
        } else reportError.value = message(error, 'Export failed.');
    } finally { if (!disposed) downloading.value = false; }
}
function requestDelete(row: History): void { deleteTarget.value = row; deleteError.value = ''; deleteVisible.value = true; }
async function deleteHistory(): Promise<void> {
    if (!deleteTarget.value || deleting.value) return;
    deleting.value = true; deleteError.value = '';
    try {
        const { data } = await axios.delete(base + '/' + encodeURIComponent(deleteTarget.value.id));
        if (disposed) return;
        deleteVisible.value = false; successMessage.value = data.message;
        const newTotal = Math.max(0, total.value - 1);
        const page = Math.max(1, Math.min(Math.floor(first.value / perPage.value) + 1, Math.ceil(newTotal / perPage.value)));
        await loadHistory(page);
    } catch (error) { if (!disposed) deleteError.value = message(error, 'Could not delete history.'); }
    finally { if (!disposed) deleting.value = false; }
}
onMounted(() => { void loadOptions(); void loadHistory(); });
onBeforeUnmount(() => { disposed = true; lifetime.abort(); listRequest?.abort(); subjectRequest?.abort(); reportRequest?.abort(); });
</script>

<template>
    <Head title="Item Analysis History" />
    <div class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5">
        <Message v-if="errorMessage" severity="error">{{ errorMessage }}</Message>
        <Message v-if="successMessage" severity="success" closable @close="successMessage = ''">{{ successMessage }}</Message>
        <form class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 lg:grid-cols-4" @submit.prevent="applyFilters">
            <div class="space-y-2">
                <label for="history-session" class="block text-sm font-semibold">Exam Session</label>
                <Select id="history-session" v-model="filters.bs_exam_session_id" :options="sessions" option-label="session_code" option-value="id" placeholder="All exam sessions" show-clear filter :loading="optionsLoading" class="w-full" />
            </div>
            <div class="space-y-2">
                <label for="history-package" class="block text-sm font-semibold">Exam Package</label>
                <Select id="history-package" v-model="filters.bs_course_id" :options="packages" option-label="name_course" option-value="id" placeholder="All exam packages" show-clear filter :loading="optionsLoading" class="w-full" @change="packageChanged" />
            </div>
            <div class="space-y-2">
                <label for="history-subject" class="block text-sm font-semibold">Subject</label>
                <Select id="history-subject" v-model="filters.bs_topic_id" :options="subjects" option-label="desc_topic" option-value="id" placeholder="All subjects" show-clear filter :disabled="!filters.bs_course_id || subjectsLoading" :loading="subjectsLoading" class="w-full" />
            </div>
            <div class="flex items-end gap-2">
                <Button type="submit" label="Generate Report" icon="pi pi-chart-line" :disabled="subjectsLoading" />
                <Button type="button" label="Clear" icon="pi pi-times" severity="secondary" outlined @click="clearFilters" />
            </div>
        </form>
        <Datatable title="Item Analysis History" description="Browse saved analysis references and view recalculated item statistics."
            header-icon="pi pi-chart-line" search-placeholder="Search analyst, session, package or subject..."
            empty-title="No history found" empty-description="No item-analysis history matches the selected filters."
            table-min-width="1200px" data-key="id" lazy :data="histories" :columns="columns" :actions="[]"
            :loading="loading" :total-records="total" :first="first" :rows="perPage" :rows-per-page-options="[10, 20, 50, 100]"
            @page="pageChanged" @sort="sortChanged" @search="searchChanged">
            <template #cell-date_analyzed="{ value }">{{ date(value) }}</template>
            <template #cell-pct="{ value }">{{ value }}%</template>
            <template #cell-actions="{ data }">
                <div class="flex justify-center gap-2">
                    <Button icon="pi pi-eye" aria-label="View item analysis" rounded severity="info" @click="viewHistory(data)" />
                    <Button v-if="canDelete" icon="pi pi-trash" aria-label="Delete history" rounded severity="danger" @click="requestDelete(data)" />
                </div>
            </template>
        </Datatable>
        <Dialog v-model:visible="reportVisible" header="Item Analysis Report" modal maximizable :style="{ width: '95vw', maxWidth: '1600px' }" @hide="reportClosed">
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <label for="metric-mode" class="font-semibold">Calculation mode</label>
                <Select id="metric-mode" v-model="metricMode" :options="modes" option-label="label" option-value="value" :disabled="downloading" @change="loadReport" />
                <Button label="Download CSV" icon="pi pi-download" :loading="downloading" :disabled="!report || reportLoading" @click="downloadReport" />
            </div>
            <Message v-if="reportError" severity="error" class="mb-3">{{ reportError }}</Message>
            <p v-if="reportLoading" role="status" class="p-8 text-center"><i class="pi pi-spin pi-spinner mr-2" />Calculating report…</p>
            <template v-if="report">
                <div class="mb-4 grid gap-2 rounded-xl bg-slate-50 p-4 md:grid-cols-3">
                    <p><strong>Exam Session:</strong> {{ report.history.session_code || '—' }}</p>
                    <p><strong>Exam Package:</strong> {{ report.history.name_course || '—' }}</p>
                    <p><strong>Subject:</strong> {{ report.history.desc_topic || '—' }}</p>
                    <p><strong>Originally Analyzed:</strong> {{ date(report.history.date_analyzed) }}</p>
                    <p><strong>Analyzed By:</strong> {{ report.history.analyzed_by || '—' }}</p>
                    <p><strong>Group:</strong> {{ report.history.pct }}% · Upper {{ report.summary.upper }} · Lower {{ report.summary.lower }}</p>
                </div>
                <Message v-for="warning in report.warnings" :key="warning" severity="warn" class="mb-2">{{ warning }}</Message>
                <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200">
                    <table class="w-full min-w-[1300px] border-collapse text-sm">
                        <thead class="bg-[#21365A] text-white">
                            <tr><th class="p-3">No.</th><th class="p-3 text-left">Question</th><th class="p-3">Appearances</th><th class="p-3">Group</th>
                                <template v-if="report.summary.show_options"><th v-for="letter in letters" :key="letter" class="p-3">{{ letter }}</th></template>
                                <th class="p-3">Correct</th><th class="p-3">Percentage</th><th class="p-3">DF</th><th class="p-3">DF Remarks</th><th class="p-3">DS</th><th class="p-3">DS Remarks</th><th class="p-3">Decision</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="item in report.items" :key="item.id">
                                <tr class="border-t border-slate-200">
                                    <td rowspan="2" class="p-3 text-center">{{ item.index }}</td>
                                    <td rowspan="2" class="min-w-[280px] p-3 whitespace-pre-wrap">{{ item.question }}</td>
                                    <td rowspan="2" class="p-3 text-center">{{ item.appearances }}</td><td class="p-3">Upper {{ report.history.pct }}%</td>
                                    <template v-if="report.summary.show_options"><td v-for="letter in letters" :key="letter" class="p-3 text-center">{{ item.options[letter].upper }}</td></template>
                                    <td class="p-3 text-center">{{ item.upper_correct }}</td><td class="p-3 text-center">{{ number(item.upper_pct) }}{{ item.upper_pct === null ? '' : '%' }}</td>
                                    <td rowspan="2" class="p-3 text-center">{{ number(item.df) }}</td><td rowspan="2" class="p-3">{{ item.df_remarks || '—' }}</td>
                                    <td rowspan="2" class="p-3 text-center">{{ number(item.ds) }}</td><td rowspan="2" class="p-3">{{ item.ds_remarks || '—' }}</td>
                                    <td rowspan="2" class="p-3 font-semibold" :class="item.decision === 'Retain' ? 'text-green-600' : item.decision === 'Revise' ? 'text-orange-600' : 'text-red-600'">{{ item.decision || '—' }}</td>
                                </tr>
                                <tr class="bg-slate-50"><td class="p-3">Lower {{ report.history.pct }}%</td>
                                    <template v-if="report.summary.show_options"><td v-for="letter in letters" :key="letter" class="p-3 text-center">{{ item.options[letter].lower }}</td></template>
                                    <td class="p-3 text-center">{{ item.lower_correct }}</td><td class="p-3 text-center">{{ number(item.lower_pct) }}{{ item.lower_pct === null ? '' : '%' }}</td>
                                </tr>
                            </template>
                            <tr v-if="!report.items.length"><td :colspan="report.summary.show_options ? 16 : 11" class="p-6 text-center">No currently active questions found for this subject.</td></tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </Dialog>
        <Dialog v-model:visible="deleteVisible" header="Delete History" modal :closable="!deleting" :close-on-escape="!deleting" :style="{ width: '450px' }">
            <Message v-if="deleteError" severity="error" class="mb-3">{{ deleteError }}</Message>
            <p>Delete this history record for <strong>{{ deleteTarget?.session_code || 'this exam session' }}</strong>? Exam results and questions will not be deleted.</p>
            <template #footer>
                <Button label="Cancel" severity="secondary" :disabled="deleting" @click="deleteVisible = false" />
                <Button label="Delete" icon="pi pi-trash" severity="danger" :loading="deleting" @click="deleteHistory" />
            </template>
        </Dialog>
    </div>
</template>
