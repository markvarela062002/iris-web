<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Message from 'primevue/message';
import Select from 'primevue/select';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Datatable from '@/components/Datatable.vue';
import type { DataTableColumn, DataTableRow } from '@/types';

defineOptions({
    inheritAttrs: false,
    layout: { breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Item Analysis', href: '/assessment-reports/item-analysis' },
    ] },
});
type Filters = { bs_exam_session_id: string | null; bs_course_id: string | null; bs_topic_id: string | null; pct: number | null; metric_mode: 'legacy' | 'normalized' };
type Item = DataTableRow & {
    id: string; index: number; question: string; appearances: number;
    upper_correct: number; lower_correct: number; upper_pct: number | null; lower_pct: number | null;
    df: number | null; df_remarks: string | null; ds: number | null; ds_remarks: string | null; decision: string | null;
    options: Record<string, { upper: number; lower: number }>;
};
type Report = {
    history: { id: string | null; session_code: string; name_course: string; desc_topic: string; pct: number; analyzed_by: string; date_analyzed: string };
    summary: { students: number; upper: number; lower: number; metric_mode: string; show_options: boolean; recalculated_at: string };
    warnings: string[]; items: Item[]; saved: boolean; history_created?: boolean; updated_questions: number; message: string;
};
type PageEvent = { page: number; rows: number; first: number };
type SortEvent = { sortField: string; sortOrder: number };
const base = '/api/v1/assessment-reports/item-analysis';
const emptyFilters = (): Filters => ({ bs_exam_session_id: null, bs_course_id: null, bs_topic_id: null, pct: null, metric_mode: 'legacy' });
const filters = ref<Filters>(emptyFilters());
const sessions = ref<{ id: string; session_code: string }[]>([]);
const packages = ref<{ id: string; name_course: string }[]>([]);
const subjects = ref<{ id: string; desc_topic: string }[]>([]);
const percentages = [{ value: 25, label: '25% — Larger number of examinees' }, { value: 27, label: '27% — Normal number of examinees' }, { value: 33, label: '33% — Smaller number of examinees' }];
const modes = [{ value: 'legacy', label: 'Legacy DF/DS' }, { value: 'normalized', label: 'Normalized DF/DS (0–1)' }];
const report = ref<Report | null>(null);
const loading = ref(false);
const optionsLoading = ref(false);
const subjectsLoading = ref(false);
const canGenerate = ref(false);
const errorMessage = ref('');
const confirmVisible = ref(false);
let pendingFilters: Filters | null = null;
const first = ref(0);
const perPage = ref(10);
const search = ref('');
const sortField = ref('index');
const sortOrder = ref(1);
const letters = ['A', 'B', 'C', 'D', 'E'];
let subjectRequest: AbortController | null = null;
let previewRequest: AbortController | null = null;
const lifetime = new AbortController();
let disposed = false;
const columns = computed<DataTableColumn[]>(() => [
    { field: 'index', header: 'No.', sortable: true, class: 'min-w-[70px]' },
    { field: 'question', header: 'Question', sortable: true, class: 'min-w-[320px] whitespace-normal' },
    { field: 'appearances', header: 'Appearances', sortable: true },
    ...(report.value?.summary.show_options ? letters.map(letter => ({ field: 'option_' + letter, header: 'Option ' + letter, sortable: false, class: 'min-w-[100px]' })) : []),
    { field: 'upper_correct', header: 'Upper Group', sortable: true, class: 'min-w-[160px]' },
    { field: 'lower_correct', header: 'Lower Group', sortable: true, class: 'min-w-[160px]' },
    { field: 'df', header: 'DF', sortable: true },
    { field: 'df_remarks', header: 'DF Remarks', sortable: true },
    { field: 'ds', header: 'DS', sortable: true },
    { field: 'ds_remarks', header: 'DS Remarks', sortable: true },
    { field: 'decision', header: 'Decision', sortable: true },
]);
const filteredItems = computed(() => {
    const query = search.value.trim().toLowerCase();
    const rows = (report.value?.items ?? []).filter(item => !query || [item.question, item.df_remarks, item.ds_remarks, item.decision].some(value => String(value ?? '').toLowerCase().includes(query)));
    return [...rows].sort((a, b) => {
        const x = a[sortField.value], y = b[sortField.value];
        if (x === null || x === undefined) return y === null || y === undefined ? 0 : 1;
        if (y === null || y === undefined) return -1;
        const diff = typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y));
        return diff * sortOrder.value || a.index - b.index;
    });
});
const pageItems = computed(() => filteredItems.value.slice(first.value, first.value + perPage.value));
function number(value: unknown): string { return typeof value === 'number' ? value.toFixed(2) : '—'; }
function message(error: unknown, fallback: string): string {
    if (!axios.isAxiosError(error)) return fallback;
    const data = error.response?.data;
    const validation = data?.errors ? Object.values(data.errors).flat()[0] : null;
    return typeof validation === 'string' ? validation : data?.message || fallback;
}
async function loadOptions(): Promise<void> {
    optionsLoading.value = true;
    try {
        const { data } = await axios.get(base + '/options', { signal: lifetime.signal });
        sessions.value = data.sessions; packages.value = data.packages; canGenerate.value = data.can_generate;
    } catch (error) { if (!axios.isCancel(error)) errorMessage.value = message(error, 'Could not load options.'); }
    finally { if (!disposed) optionsLoading.value = false; }
}
async function packageChanged(): Promise<void> {
    subjectRequest?.abort(); subjects.value = []; filters.value.bs_topic_id = null; subjectsLoading.value = false;
    const id = filters.value.bs_course_id; if (!id) return;
    const controller = new AbortController(); subjectRequest = controller; subjectsLoading.value = true;
    try {
        const { data } = await axios.get(base + '/packages/' + encodeURIComponent(id) + '/subjects', { signal: controller.signal });
        if (!controller.signal.aborted) subjects.value = data.data;
    } catch (error) { if (!axios.isCancel(error)) errorMessage.value = message(error, 'Could not load subjects.'); }
    finally { if (!controller.signal.aborted && !disposed) subjectsLoading.value = false; }
}
function valid(): boolean {
    const missing = !filters.value.bs_exam_session_id ? 'Exam Session' : !filters.value.bs_course_id ? 'Exam Package' : !filters.value.bs_topic_id ? 'Subject' : !filters.value.pct ? 'Group Percentage' : '';
    if (missing) { errorMessage.value = missing + ' is required.'; return false; }
    return true;
}
async function preview(): Promise<void> {
    if (loading.value || !valid()) return;
    previewRequest?.abort(); const controller = new AbortController(); previewRequest = controller;
    loading.value = true; errorMessage.value = '';
    try {
        const { data } = await axios.post<Report>(base + '/preview', { ...filters.value }, { signal: controller.signal });
        if (!controller.signal.aborted) { report.value = data; first.value = 0; }
    } catch (error) { if (!axios.isCancel(error)) errorMessage.value = message(error, 'Preview failed.'); }
    finally { if (!controller.signal.aborted && !disposed) loading.value = false; }
}
function requestGeneration(): void {
    if (loading.value || !canGenerate.value || !valid()) return;
    pendingFilters = { ...filters.value }; errorMessage.value = ''; confirmVisible.value = true;
}
async function generate(): Promise<void> {
    if (!pendingFilters || loading.value) return;
    loading.value = true; errorMessage.value = '';
    try {
        // Don't abort or automatically retry a saving request: its commit status could be unknown.
        const { data } = await axios.post<Report>(base + '/generate', pendingFilters);
        if (!disposed) { report.value = data; first.value = 0; confirmVisible.value = false; pendingFilters = null; }
    } catch (error) {
        if (!disposed) errorMessage.value = axios.isAxiosError(error) && (!error.response || error.response.status >= 500)
            ? 'The save outcome may be unknown after a connection or server failure. Check History and Questions List before retrying.'
            : message(error, 'Generation failed.');
    } finally { if (!disposed) loading.value = false; }
}
function clear(): void {
    if (loading.value) return;
    subjectRequest?.abort(); subjectsLoading.value = false; subjects.value = [];
    filters.value = emptyFilters(); report.value = null; first.value = 0; errorMessage.value = '';
}
function pageChanged(event: PageEvent): void { first.value = event.first; perPage.value = event.rows; }
function sortChanged(event: SortEvent): void { sortField.value = event.sortField || 'index'; sortOrder.value = event.sortOrder === -1 ? -1 : 1; first.value = 0; }
function searchChanged(value: string): void { search.value = value; first.value = 0; }
function download(): void {
    const current = report.value; if (!current || loading.value) return;
    const h = current.history;
    const lines: unknown[][] = [
        ['Item Analysis', current.saved ? 'Saved' : 'Preview only'],
        ['Exam Session', h.session_code, 'Exam Package', h.name_course, 'Subject', h.desc_topic],
        ['Analyzed By', h.analyzed_by, 'History ID', h.id || '', 'History Date', h.date_analyzed],
        ['Group Percentage', h.pct, 'Mode', current.summary.metric_mode, 'Calculated At', current.summary.recalculated_at],
        ...current.warnings.map(warning => ['Note', warning]),
        ['No.', 'Question', 'Appearances', 'Group', 'Correct', 'Percentage', 'DF', 'DF Remarks', 'DS', 'DS Remarks', 'Decision', 'A', 'B', 'C', 'D', 'E'],
    ];
    for (const item of current.items) {
        for (const group of ['upper', 'lower'] as const) {
            lines.push([item.index, item.question, item.appearances, group, item[group + '_correct'], item[group + '_pct'], item.df, item.df_remarks, item.ds, item.ds_remarks, item.decision,
                ...letters.map(letter => current.summary.show_options ? item.options[letter][group] : '')]);
        }
    }
    const cell = (value: unknown): string => {
        let text = String(value ?? '');
        if (typeof value === 'string' && /^[\s\x00-\x1f]*[=+@-]/u.test(text)) text = "'" + text;
        return '"' + text.replace(/"/g, '""') + '"';
    };
    const csv = '\uFEFF' + lines.map(row => row.map(cell).join(',')).join('\r\n');
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
    const link = document.createElement('a'); link.href = url; link.download = 'item-analysis-' + (h.id || 'preview') + '.csv';
    document.body.appendChild(link); link.click(); link.remove(); URL.revokeObjectURL(url);
}
onMounted(() => { void loadOptions(); });
onBeforeUnmount(() => { disposed = true; lifetime.abort(); subjectRequest?.abort(); previewRequest?.abort(); });
</script>

<template>
    <Head title="Item Analysis" />
    <div class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5">
        <Message v-if="errorMessage" severity="error">{{ errorMessage }}</Message>
        <Message severity="info">Preview makes no changes. Generate &amp; Save creates or reuses a history reference and marks eligible questions validated with their new decisions.</Message>
        <Message v-if="!canGenerate && !optionsLoading" severity="warn">Your account can preview reports but cannot save analysis. Write permission is checked by the server.</Message>
        <form class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 md:grid-cols-2 xl:grid-cols-4" @submit.prevent="requestGeneration">
            <div class="space-y-2"><label for="analysis-session" class="block text-sm font-semibold">Exam Session *</label><Select id="analysis-session" v-model="filters.bs_exam_session_id" :options="sessions" option-label="session_code" option-value="id" placeholder="Select session" filter show-clear :loading="optionsLoading" :disabled="loading" class="w-full" /></div>
            <div class="space-y-2"><label for="analysis-package" class="block text-sm font-semibold">Exam Package *</label><Select id="analysis-package" v-model="filters.bs_course_id" :options="packages" option-label="name_course" option-value="id" placeholder="Select exam package" filter show-clear :loading="optionsLoading" :disabled="loading" class="w-full" @change="packageChanged" /></div>
            <div class="space-y-2"><label for="analysis-subject" class="block text-sm font-semibold">Subject *</label><Select id="analysis-subject" v-model="filters.bs_topic_id" :options="subjects" option-label="desc_topic" option-value="id" placeholder="Select subject" filter show-clear :loading="subjectsLoading" :disabled="!filters.bs_course_id || subjectsLoading || loading" class="w-full" /></div>
            <div class="space-y-2"><label for="analysis-percentage" class="block text-sm font-semibold">Group Percentage *</label><Select id="analysis-percentage" v-model="filters.pct" :options="percentages" option-label="label" option-value="value" placeholder="Select percentage" :disabled="loading" class="w-full" /></div>
            <div class="space-y-2"><label for="analysis-mode" class="block text-sm font-semibold">Calculation Mode</label><Select id="analysis-mode" v-model="filters.metric_mode" :options="modes" option-label="label" option-value="value" :disabled="loading" class="w-full" /></div>
            <div class="flex flex-wrap items-end gap-2 md:col-span-2 xl:col-span-3">
                <Button type="button" label="Preview" icon="pi pi-eye" severity="info" :disabled="loading || subjectsLoading" @click="preview" />
                <Button type="submit" label="Generate & Save" icon="pi pi-chart-line" severity="success" :disabled="loading || subjectsLoading || !canGenerate" />
                <Button type="button" label="Clear" icon="pi pi-times" severity="secondary" outlined :disabled="loading" @click="clear" />
            </div>
        </form>
        <p v-if="loading" role="status"><i class="pi pi-spin pi-spinner mr-2" />Processing item analysis…</p>
        <template v-if="report">
            <Message :severity="report.saved ? 'success' : 'info'">{{ report.message }}{{ report.saved ? ` Processed questions: ${report.updated_questions}.` : '' }}</Message>
            <div class="grid gap-2 rounded-xl bg-white p-4 text-sm md:grid-cols-3">
                <p><strong>Session:</strong> {{ report.history.session_code }}</p><p><strong>Package:</strong> {{ report.history.name_course }}</p><p><strong>Subject:</strong> {{ report.history.desc_topic }}</p>
                <p><strong>Scored Students:</strong> {{ report.summary.students }}</p><p><strong>Groups:</strong> Upper {{ report.summary.upper }} · Lower {{ report.summary.lower }} ({{ report.history.pct }}%)</p><p><strong>Mode:</strong> {{ report.summary.metric_mode }}</p>
                <p><strong>Analyzed By:</strong> {{ report.history.analyzed_by }}</p><p><strong>Calculated:</strong> {{ report.summary.recalculated_at }}</p><p><strong>History:</strong> {{ report.history.id || 'Preview — not saved' }}</p>
            </div>
            <Message v-for="warning in report.warnings" :key="warning" severity="warn">{{ warning }}</Message>
        </template>
        <Datatable title="Item Analysis" description="Compare upper and lower groups, difficulty, discrimination and question decisions." header-icon="pi pi-chart-line"
            table-min-width="1600px" search-placeholder="Search questions, remarks or decisions..." data-key="id" lazy
            :data="pageItems" :columns="columns" :actions="[]" :loading="loading" :total-records="filteredItems.length" :first="first" :rows="perPage" :rows-per-page-options="[10, 20, 50, 100]"
            :empty-title="report ? 'No matching questions' : 'Generate an item analysis'" empty-description="Select the required fields and preview or generate the report."
            @page="pageChanged" @sort="sortChanged" @search="searchChanged">
            <template #header-actions><Button label="Download Full CSV" icon="pi pi-download" severity="success" :disabled="!report || loading" @click="download" /></template>
            <template #cell-question="{ value }"><div class="whitespace-pre-wrap">{{ value }}</div></template>
            <template v-for="letter in letters" :key="letter" #[`cell-option_${letter}`]="{ data }"><p>Upper: {{ data.options[letter].upper }}</p><p class="text-slate-500">Lower: {{ data.options[letter].lower }}</p></template>
            <template #cell-upper_correct="{ data }"><p class="font-semibold">{{ data.upper_correct }} correct</p><p class="text-slate-500">{{ number(data.upper_pct) }}{{ data.upper_pct === null ? '' : '%' }}</p></template>
            <template #cell-lower_correct="{ data }"><p class="font-semibold">{{ data.lower_correct }} correct</p><p class="text-slate-500">{{ number(data.lower_pct) }}{{ data.lower_pct === null ? '' : '%' }}</p></template>
            <template #cell-df="{ value }">{{ number(value) }}</template><template #cell-ds="{ value }">{{ number(value) }}</template>
            <template #cell-decision="{ value }"><span class="font-semibold" :class="value === 'Retain' ? 'text-green-600' : value === 'Revise' ? 'text-orange-600' : value === 'Reject' ? 'text-red-600' : 'text-slate-400'">{{ value || '—' }}</span></template>
        </Datatable>
        <Dialog v-model:visible="confirmVisible" header="Generate and Save Item Analysis?" modal :closable="!loading" :close-on-escape="!loading" :style="{ width: '520px' }">
            <Message v-if="errorMessage" severity="error" class="mb-3">{{ errorMessage }}</Message>
            <p>This will calculate the selected analysis, save or reuse its history reference, and set eligible active questions to <strong>Validated</strong> with Retain, Revise or Reject decisions.</p>
            <p class="mt-3">Existing question decisions will be replaced. Student classifications and exam answers will not be changed. Saving recalculates current data, which may differ from an earlier preview.</p>
            <template #footer><Button label="Cancel" severity="secondary" :disabled="loading" @click="confirmVisible = false" /><Button label="Generate & Save" icon="pi pi-check" severity="success" :loading="loading" @click="generate" /></template>
        </Dialog>
    </div>
</template>
