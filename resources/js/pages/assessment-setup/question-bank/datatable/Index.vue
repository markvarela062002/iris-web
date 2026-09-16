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
import Toast from 'primevue/toast';
import ToggleSwitch from 'primevue/toggleswitch';
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
            { title: 'Question Bank', href: '/assessment-setup/question-bank' },
        ],
    },
});

type ListResponse = {
    data: DataTableRow[];
    meta: { currentPage: number; perPage: number; total: number };
};
type PageEvent = { page: number; rows: number; first: number };
type SortEvent = { sortField: string; sortOrder: number };
type Choice = {
    id: string;
    answer_text: string;
    filename: string;
    answer: 'Y' | 'N';
    image_url: string | null;
};
type Course = { id: string; name_course: string };
type Subject = { id: string; desc_topic: string };
type Errors = Record<string, string>;

const API_BASE = '/api/v1/assessment-setup/questions';
const DATATABLE_URL = '/api/v1/assessment-setup/datatable/questions';
const toast = useToast();

const rows = ref<DataTableRow[]>([]);
const courses = ref<Course[]>([]);
const filterSubjects = ref<Subject[]>([]);
const formSubjects = ref<Subject[]>([]);
const answers = ref<Choice[]>([]);
const loading = ref(false);
const saving = ref(false);
const childLoading = ref(false);
const uploading = ref(false);
const errorMessage = ref('');
const totalRecords = ref(0);
const first = ref(0);
const perPage = ref(10);
const search = ref('');
const sortField = ref('last_update');
const sortDirection = ref<'asc' | 'desc'>('desc');
const courseFilter = ref<string | null>(null);
const subjectFilter = ref<string | null>(null);
const questionDialogVisible = ref(false);
const questionDeleteVisible = ref(false);
const answerDialogVisible = ref(false);
const answerDeleteVisible = ref(false);
const selectedQuestion = ref<DataTableRow | null>(null);
const selectedAnswer = ref<Choice | null>(null);
const questionErrors = ref<Errors>({});
const answerErrors = ref<Errors>({});
let requestController: AbortController | null = null;

const questionForm = reactive({
    id: '',
    quest_text: '',
    bs_course_id: null as string | null,
    bs_topic_id: null as string | null,
    quest_img: '',
    question_image_url: null as string | null,
    active: true,
});
const answerForm = reactive({
    id: '',
    answer_text: '',
    filename: '',
    image_url: null as string | null,
    correct: false,
});

const columns: DataTableColumn[] = [
    { field: 'index', header: '#', sortable: false, searchable: false, class: 'w-[70px]' },
    { field: 'quest_text', header: 'Question', sortable: true, searchable: true, class: 'min-w-[300px] whitespace-normal' },
    { field: 'name_course', header: 'Exam Package / Subject', sortable: true, searchable: true, class: 'min-w-[240px]' },
    { field: 'answers', header: 'Options', sortable: false, searchable: false, class: 'min-w-[360px]' },
    { field: 'active', header: 'Active', sortable: true, searchable: false, class: 'min-w-[100px]' },
    { field: 'validated', header: 'Validated', sortable: true, searchable: false, class: 'min-w-[110px]' },
];
const actions: DataTableAction[] = [
    { key: 'edit', label: 'Edit question', icon: 'pi pi-pencil', severity: 'warn' },
    { key: 'delete', label: 'Delete question', icon: 'pi pi-trash', severity: 'danger' },
];

const currentPage = computed(() => Math.floor(first.value / perPage.value) + 1);
const questionDialogTitle = computed(() => questionForm.id ? 'Edit Question' : 'Create Question');
const answerDialogTitle = computed(() => answerForm.id ? 'Edit Option' : 'Add Option');

async function loadQuestions(page = 1): Promise<void> {
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
                course_id: courseFilter.value,
                topic_id: subjectFilter.value,
            },
            ...requestConfig(),
        });
        rows.value = response.data.data;
        totalRecords.value = response.data.meta.total;
        perPage.value = response.data.meta.perPage;
        first.value = (response.data.meta.currentPage - 1) * response.data.meta.perPage;
    } catch (error: unknown) {
        if (isCanceled(error)) return;
        rows.value = [];
        totalRecords.value = 0;
        errorMessage.value = getErrorMessage(error, 'Unable to load questions.');
    } finally {
        if (requestController === controller) loading.value = false;
    }
}

async function loadOptions(): Promise<void> {
    try {
        const response = await axios.get<{ courses: Course[] }>(`${API_BASE}/options`, requestConfig());
        courses.value = response.data.courses;
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(error, 'Unable to load exam packages.');
    }
}

async function loadSubjects(courseId: string | null, target: 'filter' | 'form'): Promise<void> {
    const destination = target === 'filter' ? filterSubjects : formSubjects;
    destination.value = [];
    if (!courseId) return;
    try {
        const response = await axios.get<{ data: Subject[] }>(
            `${API_BASE}/packages/${encodeURIComponent(courseId)}/subjects`,
            requestConfig(),
        );
        destination.value = response.data.data;
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(error, 'Unable to load subjects.');
    }
}

async function changeCourseFilter(): Promise<void> {
    subjectFilter.value = null;
    await loadSubjects(courseFilter.value, 'filter');
    first.value = 0;
    await loadQuestions(1);
}
async function changeSubjectFilter(): Promise<void> {
    first.value = 0;
    await loadQuestions(1);
}
async function changeFormCourse(): Promise<void> {
    questionForm.bs_topic_id = null;
    await loadSubjects(questionForm.bs_course_id, 'form');
}

function handlePage(event: PageEvent): void {
    perPage.value = event.rows;
    first.value = event.first;
    void loadQuestions(event.page + 1);
}
function handleSort(event: SortEvent): void {
    sortField.value = event.sortField || 'last_update';
    sortDirection.value = event.sortOrder === -1 ? 'desc' : 'asc';
    first.value = 0;
    void loadQuestions(1);
}
function handleSearch(value: string): void {
    search.value = value;
    first.value = 0;
    void loadQuestions(1);
}
function handleAction(action: string, row: DataTableRow): void {
    if (action === 'edit') void openEditQuestion(row);
    if (action === 'delete') {
        selectedQuestion.value = row;
        questionDeleteVisible.value = true;
    }
}

function openCreateQuestion(): void {
    resetQuestion();
    answers.value = [];
    questionDialogVisible.value = true;
}
async function openEditQuestion(row: DataTableRow): Promise<void> {
    questionForm.id = String(row.id ?? '');
    questionForm.quest_text = String(row.quest_text ?? '');
    questionForm.bs_course_id = String(row.bs_course_id ?? '') || null;
    questionForm.bs_topic_id = String(row.bs_topic_id ?? '') || null;
    questionForm.quest_img = String(row.quest_img ?? '');
    questionForm.question_image_url = String(row.question_image_url ?? '') || null;
    questionForm.active = row.active === 'Y';
    questionErrors.value = {};
    questionDialogVisible.value = true;
    await loadSubjects(questionForm.bs_course_id, 'form');
    await loadAnswers();
}

async function saveQuestion(): Promise<void> {
    questionErrors.value = {};
    if (!questionForm.quest_text.trim()) questionErrors.value.quest_text = 'Question is required.';
    if (!questionForm.bs_course_id) questionErrors.value.bs_course_id = 'Exam package is required.';
    if (!questionForm.bs_topic_id) questionErrors.value.bs_topic_id = 'Subject is required.';
    if (Object.keys(questionErrors.value).length) return;

    const wasNew = !questionForm.id;
    saving.value = true;
    try {
        const payload = {
            quest_text: questionForm.quest_text.trim(),
            bs_course_id: questionForm.bs_course_id,
            bs_topic_id: questionForm.bs_topic_id,
            quest_img: questionForm.quest_img,
            active: questionForm.active ? 'Y' : 'N',
        };
        const response = wasNew
            ? await axios.post<{ id: string; message: string }>(API_BASE, payload, requestConfig())
            : await axios.put<{ id: string; message: string }>(`${API_BASE}/${encodeURIComponent(questionForm.id)}`, payload, requestConfig());
        questionForm.id = response.data.id;
        notify(wasNew ? 'Question Created' : 'Question Updated', response.data.message);
        await loadQuestions(wasNew ? 1 : currentPage.value);
        await loadAnswers();
    } catch (error: unknown) {
        questionErrors.value = getValidationErrors(error);
        errorMessage.value = getErrorMessage(error, 'Unable to save the question.');
    } finally {
        saving.value = false;
    }
}

async function deleteQuestion(): Promise<void> {
    const id = String(selectedQuestion.value?.id ?? '');
    if (!id) return;
    saving.value = true;
    try {
        const response = await axios.delete<{ message: string }>(`${API_BASE}/${encodeURIComponent(id)}`, requestConfig());
        questionDeleteVisible.value = false;
        selectedQuestion.value = null;
        notify('Question Deleted', response.data.message);
        await reloadCurrentPage();
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(error, 'Unable to delete the question.');
    } finally {
        saving.value = false;
    }
}

async function uploadImage(event: Event, target: 'question' | 'answer'): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    uploading.value = true;
    try {
        const body = new FormData();
        body.append('image', file);
        const response = await axios.post<{ filename: string; url: string | null }>(`${API_BASE}/images`, body, {
            headers: { Accept: 'application/json', 'Content-Type': 'multipart/form-data' },
        });
        if (target === 'question') {
            questionForm.quest_img = response.data.filename;
            questionForm.question_image_url = response.data.url;
        } else {
            answerForm.filename = response.data.filename;
            answerForm.image_url = response.data.url;
        }
        notify('Image Uploaded', 'The image is ready to be saved.');
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(error, 'Unable to upload the image.');
    } finally {
        uploading.value = false;
        input.value = '';
    }
}

async function loadAnswers(): Promise<void> {
    if (!questionForm.id) {
        answers.value = [];
        return;
    }
    childLoading.value = true;
    try {
        const response = await axios.get<{ data: Choice[] }>(
            `${API_BASE}/${encodeURIComponent(questionForm.id)}/answers`,
            requestConfig(),
        );
        answers.value = response.data.data;
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(error, 'Unable to load options.');
    } finally {
        childLoading.value = false;
    }
}

function openCreateAnswer(): void {
    if (!questionForm.id) {
        errorMessage.value = 'Save the question before adding options.';
        return;
    }
    if (answers.value.length >= 5) {
        errorMessage.value = 'A question can have a maximum of five options.';
        return;
    }
    resetAnswer();
    answerDialogVisible.value = true;
}
function openEditAnswer(answer: Choice): void {
    answerForm.id = answer.id;
    answerForm.answer_text = answer.answer_text;
    answerForm.filename = answer.filename;
    answerForm.image_url = answer.image_url;
    answerForm.correct = answer.answer === 'Y';
    answerErrors.value = {};
    answerDialogVisible.value = true;
}
async function saveAnswer(): Promise<void> {
    answerErrors.value = {};
    if (!answerForm.answer_text.trim() && !answerForm.filename) {
        answerErrors.value.answer_text = 'Enter option text or upload an option image.';
        return;
    }
    saving.value = true;
    try {
        const payload = {
            answer_text: answerForm.answer_text.trim(),
            filename: answerForm.filename,
            answer: answerForm.correct ? 'Y' : 'N',
        };
        const base = `${API_BASE}/${encodeURIComponent(questionForm.id)}/answers`;
        const response = answerForm.id
            ? await axios.put<{ message: string }>(`${base}/${encodeURIComponent(answerForm.id)}`, payload, requestConfig())
            : await axios.post<{ message: string }>(base, payload, requestConfig());
        answerDialogVisible.value = false;
        notify(answerForm.id ? 'Option Updated' : 'Option Added', response.data.message);
        await loadAnswers();
        await loadQuestions(currentPage.value);
    } catch (error: unknown) {
        answerErrors.value = getValidationErrors(error);
        errorMessage.value = getErrorMessage(error, 'Unable to save the option.');
    } finally {
        saving.value = false;
    }
}

function confirmDeleteAnswer(answer: Choice): void {
    selectedAnswer.value = answer;
    answerDeleteVisible.value = true;
}
async function deleteAnswer(): Promise<void> {
    if (!selectedAnswer.value) return;
    saving.value = true;
    try {
        const response = await axios.delete<{ message: string }>(
            `${API_BASE}/${encodeURIComponent(questionForm.id)}/answers/${encodeURIComponent(selectedAnswer.value.id)}`,
            requestConfig(),
        );
        answerDeleteVisible.value = false;
        selectedAnswer.value = null;
        notify('Option Deleted', response.data.message);
        await loadAnswers();
        await loadQuestions(currentPage.value);
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(error, 'Unable to delete the option.');
    } finally {
        saving.value = false;
    }
}

function resetQuestion(): void {
    Object.assign(questionForm, {
        id: '', quest_text: '', bs_course_id: null, bs_topic_id: null,
        quest_img: '', question_image_url: null, active: true,
    });
    formSubjects.value = [];
    questionErrors.value = {};
}
function resetAnswer(): void {
    Object.assign(answerForm, { id: '', answer_text: '', filename: '', image_url: null, correct: false });
    answerErrors.value = {};
}
async function reloadCurrentPage(): Promise<void> {
    const finalPage = Math.max(1, Math.ceil(Math.max(totalRecords.value - 1, 0) / perPage.value));
    await loadQuestions(Math.min(currentPage.value, finalPage));
}
function requestConfig(): { headers: Record<string, string> } {
    return { headers: { Accept: 'application/json' } };
}
function notify(summary: string, detail: string): void {
    toast.add({ severity: 'success', summary, detail, life: 3500 });
}
function isCanceled(error: unknown): boolean {
    return axios.isCancel(error) || (error instanceof DOMException && error.name === 'AbortError');
}
function getValidationErrors(error: unknown): Errors {
    if (!axios.isAxiosError(error) || error.response?.status !== 422) return {};
    const errors = error.response.data?.errors ?? {};
    return Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, Array.isArray(value) ? String(value[0]) : String(value)]));
}
function getErrorMessage(error: unknown, fallback: string): string {
    if (axios.isAxiosError(error)) return String(error.response?.data?.message ?? fallback);
    return fallback;
}

onMounted(async () => {
    await Promise.all([loadOptions(), loadQuestions(1)]);
});
onBeforeUnmount(() => requestController?.abort());
</script>

<template>
    <Head title="Question Bank" />
    <Toast />

    <div class="space-y-4 p-4 md:p-6">
        <Message v-if="errorMessage" severity="error" :closable="true" @close="errorMessage = ''">
            {{ errorMessage }}
        </Message>

        <Datatable
            title="Question Bank"
            description="Create and maintain questions, images, and answer options."
            header-icon="pi pi-question-circle"
            search-placeholder="Search questions, packages, or subjects..."
            empty-title="No questions found"
            empty-description="Create a question or change the current filters."
            empty-icon="pi pi-question-circle"
            table-min-width="1300px"
            actions-header="Actions"
            actions-width="130px"
            data-key="id"
            lazy
            :columns="columns"
            :data="rows"
            :actions="actions"
            :loading="loading"
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
                <div class="flex flex-wrap items-center gap-2">
                    <Select v-model="courseFilter" :options="courses" option-label="name_course" option-value="id"
                        placeholder="All exam packages" show-clear class="w-56" @change="changeCourseFilter" />
                    <Select v-model="subjectFilter" :options="filterSubjects" option-label="desc_topic" option-value="id"
                        placeholder="All subjects" show-clear :disabled="!courseFilter" class="w-52" @change="changeSubjectFilter" />
                    <Button label="Create Question" icon="pi pi-plus" @click="openCreateQuestion" />
                </div>
            </template>

            <template #cell-quest_text="{ data }">
                <div class="space-y-2">
                    <p class="whitespace-pre-wrap">{{ data.quest_text }}</p>
                    <img v-if="data.question_image_url" :src="String(data.question_image_url)" alt="Question"
                        class="max-h-28 rounded border object-contain" />
                </div>
            </template>
            <template #cell-name_course="{ data }">
                <div class="space-y-1">
                    <div class="font-medium">{{ data.name_course || '—' }}</div>
                    <div class="text-sm text-surface-500">{{ data.desc_topic || '—' }}</div>
                </div>
            </template>
            <template #cell-answers="{ data }">
                <div class="flex flex-col gap-1.5">
                    <div v-for="(answer, index) in (data.answers as Choice[])" :key="answer.id" class="flex items-center gap-2">
                        <PrimeTag :value="String.fromCharCode(65 + index)" :severity="answer.answer === 'Y' ? 'success' : 'secondary'" />
                        <span :class="answer.answer === 'Y' ? 'font-medium text-green-700' : ''">
                            {{ answer.answer_text || answer.filename || 'Image option' }}
                        </span>
                    </div>
                    <span v-if="!(data.answers as Choice[])?.length" class="text-sm italic text-surface-500">No options</span>
                </div>
            </template>
            <template #cell-active="{ data }">
                <PrimeTag :value="data.active === 'Y' ? 'Yes' : 'No'" :severity="data.active === 'Y' ? 'success' : 'secondary'" />
            </template>
            <template #cell-validated="{ data }">
                <PrimeTag :value="data.validated === 'Y' ? 'Yes' : 'No'" :severity="data.validated === 'Y' ? 'success' : 'secondary'" />
            </template>
        </Datatable>
    </div>

    <Dialog v-model:visible="questionDialogVisible" modal :header="questionDialogTitle" class="w-[min(960px,96vw)]">
        <div class="space-y-5">
            <div>
                <label class="mb-1 block font-medium">Question *</label>
                <Textarea v-model="questionForm.quest_text" rows="4" class="w-full" />
                <small v-if="questionErrors.quest_text" class="text-red-600">{{ questionErrors.quest_text }}</small>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block font-medium">Exam Package *</label>
                    <Select v-model="questionForm.bs_course_id" :options="courses" option-label="name_course" option-value="id"
                        placeholder="Select an exam package" class="w-full" @change="changeFormCourse" />
                    <small v-if="questionErrors.bs_course_id" class="text-red-600">{{ questionErrors.bs_course_id }}</small>
                </div>
                <div>
                    <label class="mb-1 block font-medium">Subject *</label>
                    <Select v-model="questionForm.bs_topic_id" :options="formSubjects" option-label="desc_topic" option-value="id"
                        placeholder="Select a subject" :disabled="!questionForm.bs_course_id" class="w-full" />
                    <small v-if="questionErrors.bs_topic_id" class="text-red-600">{{ questionErrors.bs_topic_id }}</small>
                </div>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block font-medium">Question Image</label>
                    <input type="file" accept="image/png,image/jpeg" :disabled="uploading" class="block w-full text-sm"
                        @change="uploadImage($event, 'question')" />
                    <small class="text-surface-500">JPG or PNG, maximum 5 MB.</small>
                </div>
                <div class="flex items-center gap-3 pt-6">
                    <ToggleSwitch v-model="questionForm.active" input-id="question-active" />
                    <label for="question-active">Active</label>
                </div>
            </div>
            <img v-if="questionForm.question_image_url" :src="questionForm.question_image_url" alt="Question preview"
                class="max-h-48 rounded border object-contain" />

            <div class="border-t pt-4">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-semibold">Options</h3>
                        <p class="text-sm text-surface-500">Up to five options; only one may be correct.</p>
                    </div>
                    <Button label="Add Option" icon="pi pi-plus" severity="success" size="small"
                        :disabled="!questionForm.id || answers.length >= 5" @click="openCreateAnswer" />
                </div>
                <div v-if="childLoading" class="py-5 text-center text-surface-500">Loading options…</div>
                <div v-else-if="answers.length" class="divide-y rounded border">
                    <div v-for="(answer, index) in answers" :key="answer.id" class="flex items-start justify-between gap-4 p-3">
                        <div class="flex min-w-0 gap-3">
                            <PrimeTag :value="String.fromCharCode(65 + index)" :severity="answer.answer === 'Y' ? 'success' : 'secondary'" />
                            <div>
                                <p :class="answer.answer === 'Y' ? 'font-medium text-green-700' : ''">
                                    {{ answer.answer_text || 'Image option' }}
                                </p>
                                <img v-if="answer.image_url" :src="answer.image_url" alt="Option" class="mt-2 max-h-24 rounded border object-contain" />
                                <small v-if="answer.answer === 'Y'" class="text-green-700">Correct answer</small>
                            </div>
                        </div>
                        <div class="flex gap-1">
                            <Button icon="pi pi-pencil" text rounded severity="warn" aria-label="Edit option" @click="openEditAnswer(answer)" />
                            <Button icon="pi pi-trash" text rounded severity="danger" aria-label="Delete option" @click="confirmDeleteAnswer(answer)" />
                        </div>
                    </div>
                </div>
                <p v-else class="rounded border border-dashed p-5 text-center text-surface-500">
                    {{ questionForm.id ? 'No options have been added.' : 'Save the question before adding options.' }}
                </p>
            </div>
        </div>
        <template #footer>
            <Button label="Close" severity="secondary" text @click="questionDialogVisible = false" />
            <Button label="Save Question" icon="pi pi-save" :loading="saving" @click="saveQuestion" />
        </template>
    </Dialog>

    <Dialog v-model:visible="answerDialogVisible" modal :header="answerDialogTitle" class="w-[min(620px,94vw)]">
        <div class="space-y-4">
            <div>
                <label class="mb-1 block font-medium">Option Description</label>
                <InputText v-model="answerForm.answer_text" class="w-full" />
                <small v-if="answerErrors.answer_text" class="text-red-600">{{ answerErrors.answer_text }}</small>
            </div>
            <div>
                <label class="mb-1 block font-medium">Option Image</label>
                <input type="file" accept="image/png,image/jpeg" :disabled="uploading" class="block w-full text-sm"
                    @change="uploadImage($event, 'answer')" />
                <small class="text-surface-500">Text or an image is required.</small>
                <img v-if="answerForm.image_url" :src="answerForm.image_url" alt="Option preview"
                    class="mt-3 max-h-40 rounded border object-contain" />
            </div>
            <div class="flex items-center gap-3">
                <ToggleSwitch v-model="answerForm.correct" input-id="answer-correct" />
                <label for="answer-correct">Correct answer</label>
            </div>
        </div>
        <template #footer>
            <Button label="Cancel" severity="secondary" text @click="answerDialogVisible = false" />
            <Button label="Save Option" icon="pi pi-save" :loading="saving" @click="saveAnswer" />
        </template>
    </Dialog>

    <Dialog v-model:visible="questionDeleteVisible" modal header="Delete Question" class="w-[min(480px,92vw)]">
        <p>Delete this question and all of its options? This action cannot be undone.</p>
        <template #footer>
            <Button label="Cancel" severity="secondary" text @click="questionDeleteVisible = false" />
            <Button label="Delete" icon="pi pi-trash" severity="danger" :loading="saving" @click="deleteQuestion" />
        </template>
    </Dialog>

    <Dialog v-model:visible="answerDeleteVisible" modal header="Delete Option" class="w-[min(460px,92vw)]">
        <p>Delete this option?</p>
        <template #footer>
            <Button label="Cancel" severity="secondary" text @click="answerDeleteVisible = false" />
            <Button label="Delete" icon="pi pi-trash" severity="danger" :loading="saving" @click="deleteAnswer" />
        </template>
    </Dialog>
</template>
