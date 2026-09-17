<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import Message from 'primevue/message';
import Select from 'primevue/select';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { dashboard } from '@/routes';

defineOptions({
    inheritAttrs: false,
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Questions Upload', href: '/assessment-setup/question-upload' },
        ],
    },
});

type Course = { id: string; name_course: string };
type Subject = { id: string; desc_topic: string };
type Issue = { sheet: string; row: number; message: string };
type Result = { message: string; added: number; updated: number; processed: number; skipped: number };
const API = '/api/v1/assessment-setup/question-upload';
const courses = ref<Course[]>([]);
const subjects = ref<Subject[]>([]);
const courseId = ref<string | null>(null);
const subjectId = ref<string | null>(null);
const file = ref<File | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const confirmed = ref(false);
const importing = ref(false);
const coursesLoading = ref(false);
const subjectsLoading = ref(false);
const error = ref('');
const issues = ref<Issue[]>([]);
const issueCount = ref(0);
const result = ref<Result | null>(null);
let subjectRequest: AbortController | null = null;
let disposed = false;

async function loadCourses(): Promise<void> {
    coursesLoading.value = true;
    try {
        const response = await axios.get<{ data: Course[] }>(`${API}/options`, { headers: { Accept: 'application/json' } });
        courses.value = response.data.data;
    } catch (caught: unknown) {
        error.value = errorMessage(caught, 'Unable to load exam packages.');
    } finally {
        coursesLoading.value = false;
    }
}

async function changeCourse(): Promise<void> {
    subjectRequest?.abort();
    subjectId.value = null;
    subjects.value = [];
    confirmed.value = false;
    resetFeedback();
    subjectsLoading.value = false;
    if (!courseId.value) return;
    const controller = new AbortController();
    subjectRequest = controller;
    subjectsLoading.value = true;
    try {
        const response = await axios.get<{ data: Subject[] }>(
            `${API}/packages/${encodeURIComponent(courseId.value)}/subjects`,
            { signal: controller.signal, headers: { Accept: 'application/json' } },
        );
        subjects.value = response.data.data;
    } catch (caught: unknown) {
        if (!axios.isCancel(caught)) error.value = errorMessage(caught, 'Unable to load subjects.');
    } finally {
        if (subjectRequest === controller) subjectsLoading.value = false;
    }
}

function selectFile(event: Event): void {
    resetFeedback();
    confirmed.value = false;
    const input = event.target as HTMLInputElement;
    const selected = input.files?.[0] ?? null;
    file.value = null;
    if (!selected) return;
    if (!selected.name.toLowerCase().endsWith('.xlsx')) {
        error.value = 'Select an XLSX file.';
        input.value = '';
        return;
    }
    if (selected.size > 5 * 1024 * 1024) {
        error.value = 'The file must not exceed 5 MB.';
        input.value = '';
        return;
    }
    file.value = selected;
}

async function importQuestions(): Promise<void> {
    resetFeedback();
    if (!courseId.value || !subjectId.value || !file.value) {
        error.value = 'Select an exam package, subject, and XLSX file.';
        return;
    }
    if (!confirmed.value) {
        error.value = 'Confirm that matching questions may have their options replaced.';
        return;
    }
    importing.value = true;
    const body = new FormData();
    body.append('bs_course_id', courseId.value);
    body.append('bs_topic_id', subjectId.value);
    body.append('file', file.value);
    body.append('replace_existing', '1');
    try {
        const response = await axios.post<Result>(`${API}/import`, body, {
            headers: { Accept: 'application/json' },
        });
        if (disposed) return;
        result.value = response.data;
        file.value = null;
        confirmed.value = false;
        if (fileInput.value) fileInput.value.value = '';
    } catch (caught: unknown) {
        if (disposed) return;
        error.value = errorMessage(caught, 'Unable to import questions.');
        if (axios.isAxiosError(caught)) {
            issues.value = caught.response?.data?.issues ?? [];
            issueCount.value = Number(caught.response?.data?.issue_count ?? issues.value.length);
        }
    } finally {
        importing.value = false;
    }
}

function errorMessage(caught: unknown, fallback: string): string {
    if (!axios.isAxiosError(caught)) return fallback;
    const body = caught.response?.data;
    const first = Object.values(body?.errors ?? {})[0];
    return Array.isArray(first) ? String(first[0]) : String(body?.message ?? fallback);
}
function resetFeedback(): void {
    error.value = '';
    issues.value = [];
    issueCount.value = 0;
    result.value = null;
}
function clearForm(): void {
    subjectRequest?.abort();
    courseId.value = null;
    subjectId.value = null;
    subjects.value = [];
    subjectsLoading.value = false;
    file.value = null;
    confirmed.value = false;
    if (fileInput.value) fileInput.value.value = '';
    resetFeedback();
}

onMounted(() => void loadCourses());
onBeforeUnmount(() => {
    disposed = true;
    subjectRequest?.abort();
    // Do not imply that navigating away cancels an import already running on the server.
});
</script>

<template>
    <Head title="Questions Upload" />
    <div class="flex flex-1 flex-col gap-5 bg-[#F8FAFC] p-4 lg:p-6">
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:p-6">
            <div class="mb-5 flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div
    class="relative flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-gradient-to-br from-[#123A63] to-[#377EC0] text-white shadow-lg shadow-[#377EC0]/20"
>
    <div
        class="pointer-events-none absolute -top-3 -right-3 size-8 rounded-full bg-white/15"
    ></div>

    <i
        class="pi pi-upload relative z-10 !text-[1.65rem] !leading-none !text-white"
    ></i>
</div>
                    <div>
                        <h1 class="text-xl font-bold text-slate-800">Questions Upload</h1>
                        <p class="text-sm text-slate-500">Import questions and answer options into a selected subject.</p>
                    </div>
                </div>
                <a :href="`${API}/template`" class="inline-flex items-center gap-2 rounded-lg border border-blue-200 px-4 py-2 text-sm font-semibold text-blue-700">
                    <i class="pi pi-download" /> Download XLSX Template
                </a>
            </div>

            <Message severity="info" :closable="false" class="mb-5">
                XLSX only, maximum 5 MB and 2,000 data rows across all worksheets.
                Keep the header row. Options A–D are required; E is optional.
                ANSWER must be A–E. LEVEL is optional: 1 = Easy, 2 = Medium, 3 = Difficult.
                Blank rows are skipped. Any invalid row cancels the entire import.
            </Message>

            <form class="space-y-5" @submit.prevent="importQuestions">
                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label for="upload-package" class="mb-2 block text-sm font-semibold text-slate-700">Exam Package *</label>
                        <Select v-model="courseId" input-id="upload-package" :options="courses"
                            option-label="name_course" option-value="id" filter placeholder="Select an exam package"
                            :loading="coursesLoading" :disabled="importing || coursesLoading" class="w-full" @change="changeCourse" />
                    </div>
                    <div>
                        <label for="upload-subject" class="mb-2 block text-sm font-semibold text-slate-700">Subject *</label>
                        <Select v-model="subjectId" input-id="upload-subject" :options="subjects"
                            option-label="desc_topic" option-value="id" filter placeholder="Select a subject"
                            :loading="subjectsLoading" :disabled="importing || !courseId || subjectsLoading" class="w-full"
                            @change="confirmed = false; resetFeedback()" />
                        <small v-if="courseId && !subjectsLoading && !subjects.length" class="text-slate-500">No subjects are available for this package.</small>
                    </div>
                </div>

                <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4">
                    <label for="question-workbook" class="mb-2 block text-sm font-semibold text-slate-700">Excel File *</label>
                    <input id="question-workbook" ref="fileInput" type="file" accept=".xlsx" :disabled="importing"
                        class="block w-full text-sm text-slate-600" @change="selectFile" />
                    <p v-if="file" class="mt-2 text-sm text-slate-500">{{ file.name }} — {{ (file.size / 1024).toFixed(1) }} KB</p>
                </div>

                <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <Checkbox v-model="confirmed" input-id="replace-confirmation" binary :disabled="importing" />
                    <label for="replace-confirmation" class="text-sm text-amber-900">
                        I understand that matching question text in this subject will update the existing question
                        and replace all of its answer options, including option image references.
                    </label>
                </div>

                <div class="flex flex-wrap gap-3">
                    <Button type="submit" label="Import Questions" icon="pi pi-upload" :loading="importing"
                        :disabled="!courseId || !subjectId || !file || !confirmed" />
                    <Button type="button" label="Clear" icon="pi pi-times" severity="secondary" outlined :disabled="importing" @click="clearForm" />
                    <span v-if="importing" class="self-center text-sm text-slate-500">Please keep this page open until import finishes.</span>
                </div>
            </form>
        </section>

        <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
        <section v-if="issues.length" class="overflow-x-auto rounded-xl border border-red-200 bg-white p-4">
            <h2 class="mb-3 font-semibold text-red-700">Validation issues ({{ issueCount }})</h2>
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b"><th class="p-2">Worksheet</th><th class="p-2">Row</th><th class="p-2">Issue</th></tr></thead>
                <tbody><tr v-for="(issue, index) in issues" :key="index" class="border-b border-slate-100">
                    <td class="p-2">{{ issue.sheet }}</td><td class="p-2">{{ issue.row }}</td><td class="p-2">{{ issue.message }}</td>
                </tr></tbody>
            </table>
            <p v-if="issueCount > issues.length" class="mt-3 text-sm text-slate-500">Showing the first {{ issues.length }} issues.</p>
        </section>
        <Message v-if="result" severity="success" :closable="false">
            {{ result.message }} {{ result.added }} added, {{ result.updated }} updated,
            {{ result.processed }} processed, and {{ result.skipped }} blank/instruction rows skipped.
        </Message>
    </div>
</template>
