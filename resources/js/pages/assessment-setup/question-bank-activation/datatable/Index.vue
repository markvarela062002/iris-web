<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Message from 'primevue/message';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import ToggleSwitch from 'primevue/toggleswitch';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Datatable from '@/components/Datatable.vue';
import { dashboard } from '@/routes';
import type { DataTableColumn, DataTableRow } from '@/types';

defineOptions({
    inheritAttrs: false,
    layout: { breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Questions Activation', href: '/assessment-setup/question-activation' },
    ] },
});

type Course = { id: string; name_course: string };
type Subject = { id: string; desc_topic: string };
type Option = { id: string; letter: string; text: string; correct: boolean; image_url: string | null };
type Summary = { total: number; active: number; for_item: number; easy_total: number };
type Scope = { bs_course_id: string; bs_topic_id: string };
type Report = { data: DataTableRow[]; meta: { total: number; perPage: number; currentPage: number }; summary: Summary };
type PageEvent = { page: number; rows: number; first: number };
type SortEvent = { sortField: string; sortOrder: number };
type Flag = 'active' | 'for_item';
const API = '/api/v1/assessment-setup/question-activation';
const courses = ref<Course[]>([]);
const subjects = ref<Subject[]>([]);
const courseId = ref<string | null>(null);
const subjectId = ref<string | null>(null);
const scope = ref<Scope | null>(null);
const rows = ref<DataTableRow[]>([]);
const summary = ref<Summary>({ total: 0, active: 0, for_item: 0, easy_total: 0 });
const loading = ref(false);
const saving = ref(false);
const optionsLoading = ref(false);
const subjectsLoading = ref(false);
const error = ref('');
const success = ref('');
const totalRecords = ref(0);
const first = ref(0);
const perPage = ref(10);
const search = ref('');
const sortField = ref('quest_text');
const sortDirection = ref<'asc' | 'desc'>('asc');
const bulkVisible = ref(false);
const bulkField = ref<Flag>('active');
const bulkValue = ref<'Y' | 'N'>('Y');
let listRequest: AbortController | null = null;
let subjectRequest: AbortController | null = null;
let disposed = false;
const currentPage = computed(() => Math.floor(first.value / perPage.value) + 1);
const selectedSubjectName = computed(() => subjects.value.find(item => item.id === subjectId.value)?.desc_topic ?? 'the selected subject');
const bulkText = computed(() => bulkField.value === 'active'
    ? `${bulkValue.value === 'Y' ? 'Activate' : 'Deactivate'} ALL questions in ${selectedSubjectName.value}, across every difficulty level and page?`
    : `${bulkValue.value === 'Y' ? 'Mark' : 'Unmark'} Easy questions ONLY in ${selectedSubjectName.value} for Item Analysis, across every page? Moderate and Difficult questions will not change.`);

const columns: DataTableColumn[] = [
    { field: 'index', header: '#', sortable: false, searchable: false, class: 'w-[70px]' },
    { field: 'active', header: 'Active', sortable: true, searchable: false, class: 'min-w-[100px]' },
    { field: 'for_item', header: 'Item Analysis', sortable: true, searchable: false, class: 'min-w-[130px]' },
    { field: 'quest_text', header: 'Question', sortable: true, searchable: true, class: 'min-w-[330px] whitespace-normal' },
    { field: 'level', header: 'Difficulty', sortable: true, searchable: false, class: 'min-w-[130px]' },
    { field: 'options', header: 'Options A–E', sortable: false, searchable: false, class: 'min-w-[370px]' },
    { field: 'correct_answers', header: 'Answer', sortable: false, searchable: false, class: 'min-w-[100px]' },
];

async function loadCourses(): Promise<void> {
    optionsLoading.value = true;
    try {
        const response = await axios.get<{ data: Course[] }>(`${API}/options`, config());
        courses.value = response.data.data;
    } catch (caught: unknown) { error.value = message(caught, 'Unable to load exam packages.'); }
    finally { optionsLoading.value = false; }
}

function resetList(): void {
    listRequest?.abort();
    listRequest = null;
    loading.value = false;
    scope.value = null;
    rows.value = [];
    totalRecords.value = 0;
    first.value = 0;
    summary.value = { total: 0, active: 0, for_item: 0, easy_total: 0 };
    error.value = '';
    success.value = '';
}

async function changeCourse(): Promise<void> {
    resetList();
    subjectRequest?.abort();
    subjectId.value = null;
    subjects.value = [];
    subjectsLoading.value = false;
    if (!courseId.value) return;
    const controller = new AbortController();
    subjectRequest = controller;
    subjectsLoading.value = true;
    try {
        const response = await axios.get<{ data: Subject[] }>(`${API}/packages/${encodeURIComponent(courseId.value)}/subjects`, {
            ...config(), signal: controller.signal,
        });
        subjects.value = response.data.data;
    } catch (caught: unknown) {
        if (!axios.isCancel(caught)) error.value = message(caught, 'Unable to load subjects.');
    } finally { if (subjectRequest === controller) subjectsLoading.value = false; }
}

function showQuestions(): void {
    if (!courseId.value || !subjectId.value) {
        error.value = 'Select an exam package and subject first.';
        return;
    }
    scope.value = { bs_course_id: courseId.value, bs_topic_id: subjectId.value };
    first.value = 0;
    success.value = '';
    void loadQuestions(1);
}

async function loadQuestions(page = 1): Promise<void> {
    if (!scope.value) return;
    listRequest?.abort();
    const controller = new AbortController();
    listRequest = controller;
    loading.value = true;
    error.value = '';
    try {
        const response = await axios.get<Report>(`${API}/questions`, {
            ...config(), signal: controller.signal,
            params: { ...scope.value, page, per_page: perPage.value, search: search.value,
                sort_field: sortField.value, sort_direction: sortDirection.value },
        });
        if (controller.signal.aborted || disposed) return;
        rows.value = response.data.data;
        totalRecords.value = response.data.meta.total;
        perPage.value = response.data.meta.perPage;
        first.value = (response.data.meta.currentPage - 1) * perPage.value;
        summary.value = response.data.summary;
    } catch (caught: unknown) {
        if (axios.isCancel(caught) || controller.signal.aborted) return;
        rows.value = [];
        totalRecords.value = 0;
        error.value = message(caught, 'Unable to load questions.');
    } finally { if (listRequest === controller) loading.value = false; }
}

function handlePage(event: PageEvent): void {
    if (saving.value) return;
    perPage.value = event.rows;
    first.value = event.first;
    void loadQuestions(event.page + 1);
}
function handleSort(event: SortEvent): void {
    if (saving.value) return;
    sortField.value = event.sortField || 'quest_text';
    sortDirection.value = event.sortOrder === -1 ? 'desc' : 'asc';
    void loadQuestions(1);
}
function handleSearch(value: string): void {
    search.value = value;
    if (!saving.value) void loadQuestions(1);
}

async function setFlag(row: DataTableRow, field: Flag, value: boolean): Promise<void> {
    if (!scope.value || saving.value || loading.value) return;
    saving.value = true;
    error.value = '';
    success.value = '';
    try {
        const response = await axios.patch<{ message: string; summary: Summary }>(
            `${API}/questions/${encodeURIComponent(String(row.id))}`,
            { ...scope.value, field, value: value ? 'Y' : 'N' }, config(),
        );
        if (disposed) return;
        row[field] = value ? 'Y' : 'N';
        summary.value = response.data.summary;
        success.value = response.data.message;
        await loadQuestions(currentPage.value);
    } catch (caught: unknown) {
        if (!disposed) error.value = message(caught, 'Unable to update the question. Refresh before retrying if the request status is uncertain.');
    } finally { saving.value = false; }
}

function openBulk(field: Flag, value: 'Y' | 'N'): void {
    bulkField.value = field;
    bulkValue.value = value;
    bulkVisible.value = true;
}
async function saveBulk(): Promise<void> {
    if (!scope.value || saving.value) return;
    saving.value = true;
    error.value = '';
    success.value = '';
    try {
        const response = await axios.patch<{ message: string; summary: Summary }>(`${API}/bulk`, {
            ...scope.value, field: bulkField.value, value: bulkValue.value, confirmed: true,
        }, config());
        if (disposed) return;
        bulkVisible.value = false;
        summary.value = response.data.summary;
        success.value = response.data.message;
        await loadQuestions(currentPage.value);
    } catch (caught: unknown) {
        if (!disposed) { bulkVisible.value = false; error.value = message(caught, 'Unable to apply the bulk update. Refresh before retrying if the request status is uncertain.'); }
    } finally { saving.value = false; }
}

function clear(): void {
    subjectRequest?.abort();
    courseId.value = null;
    subjectId.value = null;
    subjects.value = [];
    subjectsLoading.value = false;
    search.value = '';
    resetList();
}
function rowOptions(row: DataTableRow): Option[] { return (row.options ?? []) as Option[]; }
function answerLetters(row: DataTableRow): string { return ((row.correct_answers ?? []) as string[]).join(', ') || '—'; }
function levelLabel(value: unknown): string { return ({ 1: 'Easy', 2: 'Moderate', 3: 'Difficult' } as Record<number, string>)[Number(value)] ?? 'Unspecified'; }
function config(): { headers: Record<string, string> } { return { headers: { Accept: 'application/json' } }; }
function message(caught: unknown, fallback: string): string {
    if (!axios.isAxiosError(caught)) return fallback;
    const body = caught.response?.data;
    const firstError = Object.values(body?.errors ?? {})[0];
    return Array.isArray(firstError) ? String(firstError[0]) : String(body?.message ?? fallback);
}
onMounted(() => void loadCourses());
onBeforeUnmount(() => { disposed = true; listRequest?.abort(); subjectRequest?.abort(); });
</script>

<template>
    <Head title="Questions Activation" />
    <div class="flex flex-1 flex-col gap-4 bg-[#F8FAFC] p-4 lg:p-5">
        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <div class="mb-5 flex items-center gap-3">
                <div class="relative flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-gradient-to-br from-[#123A63] to-[#377EC0] text-white shadow-lg shadow-[#377EC0]/20">
                    <div class="pointer-events-none absolute -top-3 -right-3 size-8 rounded-full bg-white/15"></div>
                    <i class="pi pi-check-circle relative z-10 !text-[1.65rem] !leading-none !text-white"></i>
                </div>
                <div><h1 class="text-xl font-bold text-[#21365A]">Questions Activation</h1>
                    <p class="text-sm text-slate-500">Select an exam package and subject, then show its questions.</p></div>
            </div>
            <div class="flex flex-wrap items-end gap-4">
                <div class="min-w-60 flex-1"><label class="mb-2 block text-sm font-semibold">Exam Package *</label>
                    <Select v-model="courseId" :options="courses" option-label="name_course" option-value="id" filter
                        placeholder="Select a package" :loading="optionsLoading" :disabled="saving || optionsLoading"
                        class="w-full" @change="changeCourse" /></div>
                <div class="min-w-60 flex-1"><label class="mb-2 block text-sm font-semibold">Subject *</label>
                    <Select v-model="subjectId" :options="subjects" option-label="desc_topic" option-value="id" filter
                        placeholder="Select a subject" :loading="subjectsLoading" :disabled="saving || !courseId || subjectsLoading"
                        class="w-full" @change="resetList" /></div>
                <Button label="Show Questions" icon="pi pi-search" :loading="loading" :disabled="saving || !subjectId || subjectsLoading" @click="showQuestions" />
                <Button label="Clear" icon="pi pi-times" outlined severity="secondary" :disabled="saving" @click="clear" />
            </div>
        </section>

        <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
        <Message v-if="success" severity="success" :closable="false">{{ success }}</Message>
        <Message v-if="!scope" severity="info" :closable="false">Select a package and subject, then click Show Questions.</Message>

        <template v-if="scope">
            <div class="flex flex-wrap gap-3">
                <Tag :value="`Total: ${summary.total}`" severity="info" />
                <Tag :value="`Active: ${summary.active}`" severity="success" />
                <Tag :value="`For Item Analysis: ${summary.for_item}`" severity="warn" />
            </div>
            <section class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="mb-3 text-sm text-slate-500">Bulk actions ignore search and pagination. Counts above cover the entire selected subject.</p>
                <div class="flex flex-wrap gap-2">
                    <Button label="Activate All" icon="pi pi-check" severity="success" size="small" :disabled="saving || loading || !summary.total" @click="openBulk('active', 'Y')" />
                    <Button label="Deactivate All" icon="pi pi-times" severity="danger" size="small" :disabled="saving || loading || !summary.total" @click="openBulk('active', 'N')" />
                    <Button label="Mark Easy for Analysis" severity="warn" size="small" :disabled="saving || loading || !summary.easy_total" @click="openBulk('for_item', 'Y')" />
                    <Button label="Unmark Easy for Analysis" severity="secondary" size="small" :disabled="saving || loading || !summary.easy_total" @click="openBulk('for_item', 'N')" />
                </div>
            </section>
            <Datatable title="Subject Questions" description="Changes save immediately. Options are displayed in a stable order; green indicates a correct answer."
                header-icon="pi pi-check-circle" search-placeholder="Search question text..."
                empty-title="No questions found" empty-description="Change the search or select another subject."
                table-min-width="1300px" data-key="id" lazy :data="rows" :columns="columns" :actions="[]"
                :loading="loading || saving" :total-records="totalRecords" :first="first" :rows="perPage"
                :rows-per-page-options="[10, 20, 50, 100]" @page="handlePage" @sort="handleSort" @search="handleSearch">
                <template #cell-active="{ data }"><ToggleSwitch :model-value="data.active === 'Y'" :disabled="saving || loading"
                    aria-label="Question active" @update:model-value="setFlag(data, 'active', $event)" /></template>
                <template #cell-for_item="{ data }"><ToggleSwitch :model-value="data.for_item === 'Y'" :disabled="saving || loading"
                    aria-label="For Item Analysis" @update:model-value="setFlag(data, 'for_item', $event)" /></template>
                <template #cell-quest_text="{ data }"><p class="whitespace-pre-wrap">{{ data.quest_text }}</p>
                    <a v-if="data.image_url" :href="String(data.image_url)" target="_blank" rel="noopener noreferrer" class="mt-2 block text-sm text-blue-600">View question image</a></template>
                <template #cell-level="{ value }"><Tag :value="levelLabel(value)" severity="info" /></template>
                <template #cell-options="{ data }"><div class="space-y-2">
                    <div v-for="option in rowOptions(data)" :key="option.id" class="flex items-start gap-2">
                        <Tag :value="option.letter" :severity="option.correct ? 'success' : 'secondary'" />
                        <div><p class="whitespace-pre-wrap" :class="option.correct ? 'font-semibold text-green-700' : ''">{{ option.text || 'Image option' }}</p>
                            <a v-if="option.image_url" :href="option.image_url" target="_blank" rel="noopener noreferrer" class="text-sm text-blue-600">View option image</a></div>
                    </div><span v-if="!rowOptions(data).length" class="text-slate-500">No options</span>
                </div></template>
                <template #cell-correct_answers="{ data }"><Tag :value="answerLetters(data)" severity="success" /></template>
            </Datatable>
        </template>
    </div>
    <Dialog v-model:visible="bulkVisible" modal header="Confirm Bulk Update" :closable="!saving" :close-on-escape="!saving" class="w-[min(540px,94vw)]">
        <p>{{ bulkText }}</p>
        <p class="mt-3 text-sm text-slate-500">Individual Item Analysis switches can update any difficulty level; the bulk Item Analysis action targets Easy questions only.</p>
        <template #footer><Button label="Cancel" text severity="secondary" :disabled="saving" @click="bulkVisible = false" />
            <Button label="Confirm Update" icon="pi pi-check" :loading="saving" @click="saveBulk" /></template>
    </Dialog>
</template>
