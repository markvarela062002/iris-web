<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Message from 'primevue/message';
import Select from 'primevue/select';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import Datatable from '@/components/Datatable.vue';
import type { DataTableColumn, DataTableRow } from '@/types';

defineOptions({
    inheritAttrs: false,
    layout: { breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Questions List', href: '/assessment-reports/question-list' },
    ] },
});

type Option = { id: string; text: string; correct: boolean };
type Question = DataTableRow & {
    id: string; index: number; quest_text: string; validated: string | null; marked: string; decision: string | null;
    option_count: number; extra_options: Option[];
    option_1: Option | null; option_2: Option | null; option_3: Option | null; option_4: Option | null; option_5: Option | null;
};
type Filters = { bs_course_id: string | null; bs_topic_id: string | null; status: string | null; validated: string | null; decision: string | null };
type PageEvent = { page: number; rows: number; first: number };
type SortEvent = { sortField: string; sortOrder: number };
type ApiResponse = {
    data: Question[]; context: { package: string; subject: string };
    meta: { currentPage: number; lastPage: number; perPage: number; total: number; from: number | null; to: number | null };
};
const base = '/api/v1/assessment-reports/question-list';
const emptyFilters = (): Filters => ({ bs_course_id: null, bs_topic_id: null, status: null, validated: null, decision: null });
const filters = ref<Filters>(emptyFilters());
let appliedFilters: Filters | null = null;
const packages = ref<{ id: string; name_course: string }[]>([]);
const subjects = ref<{ id: string; desc_topic: string }[]>([]);
const questions = ref<Question[]>([]);
const context = ref<{ package: string; subject: string } | null>(null);
const optionsLoading = ref(false);
const subjectsLoading = ref(false);
const loading = ref(false);
const downloading = ref(false);
const hasReport = ref(false);
const errorMessage = ref('');
const total = ref(0);
const first = ref(0);
const perPage = ref(10);
const search = ref('');
const sortField = ref('quest_text');
const sortDirection = ref<'asc' | 'desc'>('asc');
const optionNumbers = [1, 2, 3, 4, 5];
const statuses = [{ label: 'Active', value: 'Y' }, { label: 'Inactive', value: 'N' }];
const marks = [{ label: 'Validated', value: 'Y' }, { label: 'Not Yet Validated', value: 'N' }];
const decisions = [{ label: 'Retain', value: 'Retain' }, { label: 'Revise', value: 'Revise' }, { label: 'Reject', value: 'Reject' }];
const columns: DataTableColumn[] = [
    { field: 'index', header: 'No.', sortable: false, class: 'min-w-[70px] text-center' },
    { field: 'quest_text', header: 'Question', sortable: true, class: 'min-w-[320px] whitespace-normal' },
    ...optionNumbers.map(n => ({ field: `option_${n}`, header: `Option ${n}`, sortable: false, class: 'min-w-[220px] whitespace-normal' })),
    { field: 'validated', header: 'Marked', sortable: true, class: 'min-w-[170px] text-center' },
    { field: 'decision', header: 'Decision', sortable: true, class: 'min-w-[130px] text-center' },
];
let listRequest: AbortController | null = null;
let subjectRequest: AbortController | null = null;
let exportRequest: AbortController | null = null;
const lifetime = new AbortController();
let disposed = false;

function message(error: unknown, fallback: string): string {
    if (!axios.isAxiosError(error)) return fallback;
    const payload = error.response?.data;
    const validation = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
    return typeof validation === 'string' ? validation : payload?.message || fallback;
}
async function loadOptions(): Promise<void> {
    optionsLoading.value = true;
    try {
        const { data } = await axios.get<{ packages: { id: string; name_course: string }[] }>(base + '/options', { signal: lifetime.signal });
        packages.value = data.packages;
    } catch (error) {
        if (!axios.isCancel(error)) errorMessage.value = message(error, 'Could not load exam packages.');
    } finally { if (!disposed) optionsLoading.value = false; }
}
async function packageChanged(): Promise<void> {
    subjectRequest?.abort(); subjects.value = []; filters.value.bs_topic_id = null; subjectsLoading.value = false;
    const courseId = filters.value.bs_course_id;
    if (!courseId) return;
    const controller = new AbortController(); subjectRequest = controller; subjectsLoading.value = true;
    try {
        const { data } = await axios.get<{ data: { id: string; desc_topic: string }[] }>(base + '/packages/' + encodeURIComponent(courseId) + '/subjects', { signal: controller.signal });
        if (!controller.signal.aborted) subjects.value = data.data;
    } catch (error) {
        if (!axios.isCancel(error)) errorMessage.value = message(error, 'Could not load subjects.');
    } finally { if (!controller.signal.aborted && !disposed) subjectsLoading.value = false; }
}
async function loadQuestions(page = 1): Promise<void> {
    if (!appliedFilters) return;
    listRequest?.abort(); exportRequest?.abort(); downloading.value = false;
    const controller = new AbortController(); listRequest = controller;
    loading.value = true; hasReport.value = false; errorMessage.value = '';
    try {
        const { data } = await axios.get<ApiResponse>(base, {
            signal: controller.signal,
            params: { ...appliedFilters, page, per_page: perPage.value, search: search.value, sort_field: sortField.value, sort_direction: sortDirection.value },
        });
        if (controller.signal.aborted) return;
        questions.value = data.data; context.value = data.context; total.value = data.meta.total;
        first.value = (data.meta.currentPage - 1) * data.meta.perPage; perPage.value = data.meta.perPage; hasReport.value = true;
    } catch (error) {
        if (!axios.isCancel(error)) { questions.value = []; context.value = null; total.value = 0; errorMessage.value = message(error, 'Could not generate the questions list.'); }
    } finally { if (!controller.signal.aborted && !disposed) loading.value = false; }
}
function generate(): void {
    if (!filters.value.bs_course_id) { errorMessage.value = 'Exam Package is required.'; return; }
    if (!filters.value.bs_topic_id) { errorMessage.value = 'Subject is required.'; return; }
    appliedFilters = { ...filters.value }; first.value = 0; void loadQuestions();
}
function clearFilters(): void {
    listRequest?.abort(); subjectRequest?.abort(); exportRequest?.abort();
    filters.value = emptyFilters(); appliedFilters = null; subjects.value = []; questions.value = []; context.value = null;
    total.value = 0; first.value = 0; hasReport.value = false; errorMessage.value = '';
    loading.value = false; subjectsLoading.value = false; downloading.value = false;
    // Preserve the Datatable's current search/sort state; the next generation uses it.
}
function pageChanged(event: PageEvent): void { perPage.value = event.rows; void loadQuestions(event.page + 1); }
function sortChanged(event: SortEvent): void {
    sortField.value = event.sortField || 'quest_text'; sortDirection.value = event.sortOrder === 1 ? 'asc' : 'desc';
    first.value = 0; void loadQuestions();
}
function searchChanged(value: string): void { search.value = value; first.value = 0; void loadQuestions(); }
async function download(): Promise<void> {
    if (!appliedFilters || !hasReport.value || downloading.value) return;
    exportRequest?.abort(); const controller = new AbortController(); exportRequest = controller;
    downloading.value = true; errorMessage.value = '';
    try {
        const { data } = await axios.get(base + '/export', {
            responseType: 'blob', signal: controller.signal,
            params: { ...appliedFilters, search: search.value, sort_field: sortField.value, sort_direction: sortDirection.value },
        });
        if (controller.signal.aborted) return;
        const url = URL.createObjectURL(data); const link = document.createElement('a');
        link.href = url; link.download = 'questions-list.csv'; document.body.appendChild(link); link.click(); link.remove(); URL.revokeObjectURL(url);
    } catch (error) {
        if (axios.isCancel(error)) return;
        if (axios.isAxiosError(error) && error.response?.data instanceof Blob) {
            try { const payload = JSON.parse(await error.response.data.text()); errorMessage.value = Object.values(payload.errors ?? {}).flat().join(' ') || payload.message || 'Export failed.'; }
            catch { errorMessage.value = 'Export failed.'; }
        } else errorMessage.value = message(error, 'Export failed.');
    } finally { if (!controller.signal.aborted && !disposed) downloading.value = false; }
}
onMounted(() => { void loadOptions(); });
onBeforeUnmount(() => { disposed = true; lifetime.abort(); listRequest?.abort(); subjectRequest?.abort(); exportRequest?.abort(); });
</script>

<template>
    <Head title="Questions List" />
    <div class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5">
        <Message severity="warn">Decisions reflect the stored results of Item Analysis generation. This report does not recalculate or update them.</Message>
        <Message v-if="errorMessage" severity="error">{{ errorMessage }}</Message>
        <form class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 md:grid-cols-2 xl:grid-cols-5" @submit.prevent="generate">
            <div class="space-y-2">
                <label for="question-package" class="block text-sm font-semibold">Exam Package <span class="text-red-500">*</span></label>
                <Select id="question-package" v-model="filters.bs_course_id" :options="packages" option-label="name_course" option-value="id" placeholder="Select exam package" filter show-clear :loading="optionsLoading" class="w-full" @change="packageChanged" />
            </div>
            <div class="space-y-2">
                <label for="question-subject" class="block text-sm font-semibold">Subject <span class="text-red-500">*</span></label>
                <Select id="question-subject" v-model="filters.bs_topic_id" :options="subjects" option-label="desc_topic" option-value="id" placeholder="Select subject" filter show-clear :loading="subjectsLoading" :disabled="!filters.bs_course_id || subjectsLoading" class="w-full" />
            </div>
            <div class="space-y-2">
                <label for="question-status" class="block text-sm font-semibold">Status</label>
                <Select id="question-status" v-model="filters.status" :options="statuses" option-label="label" option-value="value" placeholder="All statuses" show-clear class="w-full" />
            </div>
            <div class="space-y-2">
                <label for="question-mark" class="block text-sm font-semibold">Mark</label>
                <Select id="question-mark" v-model="filters.validated" :options="marks" option-label="label" option-value="value" placeholder="All marks" show-clear class="w-full" />
            </div>
            <div class="space-y-2">
                <label for="question-decision" class="block text-sm font-semibold">Decision</label>
                <Select id="question-decision" v-model="filters.decision" :options="decisions" option-label="label" option-value="value" placeholder="All decisions" show-clear class="w-full" />
            </div>
            <div class="flex flex-wrap gap-2 md:col-span-2 xl:col-span-5">
                <Button type="submit" label="Generate Report" icon="pi pi-chart-line" :disabled="subjectsLoading" :loading="loading" />
                <Button type="button" label="Clear" icon="pi pi-times" severity="secondary" outlined @click="clearFilters" />
            </div>
        </form>
        <p v-if="context" class="text-sm text-slate-600">Report for <strong>{{ context.package }}</strong> · <strong>{{ context.subject }}</strong>. Correct answers are highlighted in red. Change filters and click Generate Report to refresh.</p>
        <Datatable title="Questions List" description="Review question options, validation marks and stored item-analysis decisions."
            header-icon="pi pi-list" search-placeholder="Search questions..." table-min-width="1800px" data-key="id" lazy
            :empty-title="hasReport ? 'No questions found' : 'Generate a questions list'"
            :empty-description="hasReport ? 'No questions match your selected filters and search.' : 'Select an exam package and subject, then click Generate Report.'"
            :data="questions" :columns="columns" :actions="[]" :loading="loading" :total-records="total" :first="first" :rows="perPage"
            :rows-per-page-options="[10, 20, 50, 100]" @page="pageChanged" @sort="sortChanged" @search="searchChanged">
            <template #header-actions>
                <Button label="Download CSV" icon="pi pi-download" severity="success" :disabled="!hasReport || loading" :loading="downloading" @click="download" />
            </template>
            <template #cell-quest_text="{ data }">
                <div class="whitespace-pre-wrap">{{ data.quest_text }}</div>
                <details v-if="data.extra_options.length" class="mt-2 text-xs">
                    <summary class="cursor-pointer text-orange-600">Additional options beyond the expected maximum of five</summary>
                    <p v-for="(option, index) in data.extra_options" :key="option.id" class="mt-2 whitespace-pre-wrap" :class="option.correct ? 'font-semibold text-red-600' : 'text-slate-600'">Option {{ index + 6 }}: {{ option.text || '—' }}{{ option.correct ? ' (Correct)' : '' }}</p>
                </details>
            </template>
            <template v-for="n in optionNumbers" :key="n" #[`cell-option_${n}`]="{ value }">
                <div v-if="value" class="whitespace-pre-wrap" :class="value.correct ? 'font-semibold text-red-600' : 'text-slate-700'">
                    {{ value.text || '—' }}
                    <span v-if="value.correct" class="mt-1 block text-xs"><i class="pi pi-check-circle" /> Correct Answer</span>
                </div>
                <span v-else class="text-slate-400">—</span>
            </template>
            <template #cell-validated="{ data }">
                <span :class="data.validated === 'Y' ? 'font-semibold text-green-600' : 'text-slate-500'">{{ data.marked }}</span>
            </template>
            <template #cell-decision="{ value }">
                <span class="font-semibold" :class="value === 'Retain' ? 'text-green-600' : value === 'Revise' ? 'text-orange-600' : value === 'Reject' ? 'text-red-600' : 'text-slate-400'">{{ value || '—' }}</span>
            </template>
        </Datatable>
    </div>
</template>
