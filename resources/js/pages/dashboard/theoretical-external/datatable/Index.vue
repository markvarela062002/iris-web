<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';

import axios from 'axios';

import Avatar from 'primevue/avatar';

import Button from 'primevue/button';

import Dialog from 'primevue/dialog';
import DatePicker from 'primevue/datepicker';

import InputText from 'primevue/inputtext';

import Message from 'primevue/message';

import Select from 'primevue/select';

import PrimeTag from 'primevue/tag';

import { onMounted, ref } from 'vue';

import Datatable from '@/components/Datatable.vue';

import type { DataTableAction, DataTableColumn, DataTableRow } from '@/types';

defineOptions({
    inheritAttrs: false,

    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',

                href: '/dashboard',
            },

            {
                title: 'Theoretical External',

                href: '/dashboard/theoretical-external',
            },
        ],
    },
});

type Option = {
    id: string;

    label: string;
};

type AnswerRow = {
    index: number;

    question: string;

    answer: string | null;

    correct_answer: string | null;

    is_correct: boolean;
};

type PageEvent = {
    first: number;

    rows: number;

    page?: number;
};

type SortEvent = {
    sortField?: string;

    sortOrder?: number;
};

type TagSeverity =
    'success' | 'info' | 'warn' | 'danger' | 'secondary' | 'contrast';

/*

|--------------------------------------------------------------------------

| DataTable configuration

|--------------------------------------------------------------------------

*/

const columns: DataTableColumn[] = [
    {
        field: 'examinee_name',

        header: 'Examinee Information',

        sortable: false,

        searchable: true,

        frozen: true,

        class: 'min-w-[280px]',
    },

    {
        field: 'exam_details',

        header: 'Exam Details',

        sortable: false,

        searchable: false,

        class: 'min-w-[320px]',
    },

    {
        field: 'proctor_name',

        header: 'Proctor',

        sortable: false,

        searchable: true,

        class: 'min-w-[210px]',
    },

    {
        field: 'score',

        header: 'Score',

        sortable: true,

        searchable: false,

        class: 'min-w-[100px]',

        headerClass: '!text-left',

        bodyClass: '!text-left',
    },

    {
        field: 'done',

        header: 'Status',

        sortable: true,

        searchable: false,

        class: 'min-w-[110px]',

        headerClass: '!text-left',

        bodyClass: '!text-left',
    },
];

/*

|--------------------------------------------------------------------------

| Action buttons

|--------------------------------------------------------------------------

|

| Arrangement:

|

| 1. Download Certificate

| 2. View Answers

| 3. Edit

|

| Certificate and Answer buttons remain visible when unavailable.

| The fallback buttons use PrimeVue's default primary severity.

|

*/

const actions: DataTableAction[] = [
    {
        key: 'certificate',

        label: 'Download Certificate',

        icon: 'pi pi-download',

        severity: 'info',

        visible: (row) => row.is_completed === true,
    },

    {
        key: 'certificate-unavailable',

        label: 'No Certificate Available',

        icon: 'pi pi-download',

        severity: 'secondary',

        visible: (row) => row.is_completed !== true,

        disabled: () => true,
    },

    {
        key: 'answers',

        label: 'View Answers',

        icon: 'pi pi-eye',

        severity: 'danger',

        visible: (row) => row.is_completed === true,
    },

    {
        key: 'answers-unavailable',

        label: 'No Answers Available',

        icon: 'pi pi-eye',

        severity: 'secondary',

        visible: (row) => row.is_completed !== true,

        disabled: () => true,
    },

    {
        key: 'edit',

        label: 'Edit Assessment',

        icon: 'pi pi-pencil',

        severity: 'warn',
    },
];

/*

|--------------------------------------------------------------------------

| Table state

|--------------------------------------------------------------------------

*/

const assessments = ref<DataTableRow[]>([]);

const loading = ref(false);

const totalRecords = ref(0);

const first = ref(0);

const rows = ref(10);

const search = ref('');

const sortField = ref('started');

const sortDirection = ref<'asc' | 'desc'>('desc');

/*

|--------------------------------------------------------------------------

| Filter state

|--------------------------------------------------------------------------

*/

const courses = ref<Option[]>([]);

const sessions = ref<Option[]>([]);

const createVisible = ref(false);
const creating = ref(false);
const createError = ref('');
const createSuccess = ref('');
const createForm = ref({
    email: '',
    fname: '',
    mname: '',
    lname: '',
    bs_course_id: '',
    bs_exam_session_id: '',
    exam_type: 'New',
    duration: 0,
    proctor_name: '',
    access_exp_date: new Date(),
    access_exp_time: '08:00',
    access_exp_date_to: new Date(),
    access_exp_time_to: '23:30',
});
const timeOptions = Array.from({ length: 32 }, (_, index) => {
    const hour = 8 + Math.floor(index / 2);
    return `${String(hour).padStart(2, '0')}:${index % 2 ? '30' : '00'}`;
}).filter((value) => value <= '23:30');
function openCreate(): void {
    createError.value = '';
    createVisible.value = true;
}
async function saveCreate(): Promise<void> {
    createError.value = '';
    creating.value = true;
    try {
        const { data } = await axios.post(
            '/api/v1/dashboard/theoretical-external',
            {
                ...createForm.value,
                access_exp_date: formatLocalDate(
                    createForm.value.access_exp_date,
                ),
                access_exp_date_to: formatLocalDate(
                    createForm.value.access_exp_date_to,
                ),
            },
        );
        createVisible.value = false;
        createSuccess.value = data.message ?? 'The record has been saved.';
        createForm.value = {
            email: '',
            fname: '',
            mname: '',
            lname: '',
            bs_course_id: '',
            bs_exam_session_id: '',
            exam_type: 'New',
            duration: 0,
            proctor_name: '',
            access_exp_date: new Date(),
            access_exp_time: '08:00',
            access_exp_date_to: new Date(),
            access_exp_time_to: '23:30',
        };
        first.value = 0;
        await loadAssessments(1);
    } catch (error: unknown) {
        if (axios.isAxiosError(error)) {
            const errors = error.response?.data?.errors;
            createError.value = errors
                ? Object.values(errors as Record<string, string[]>)
                      .flat()
                      .join(' ')
                : (error.response?.data?.message ??
                  'Unable to save the assessment.');
        } else {
            createError.value = 'Unable to save the assessment.';
        }
    } finally {
        creating.value = false;
    }
}

/*

|--------------------------------------------------------------------------

| Dialog state

|--------------------------------------------------------------------------

*/

const detailsVisible = ref(false);

const loadingDetails = ref(false);

const selectedAssessment = ref<Record<string, unknown> | null>(null);

const answers = ref<AnswerRow[]>([]);

const pageError = ref('');

/*

|--------------------------------------------------------------------------

| Computed properties

|--------------------------------------------------------------------------

*/

/*

|--------------------------------------------------------------------------

| Formatting helpers

|--------------------------------------------------------------------------

*/

function formatLocalDate(value: Date): string {
    const year = value.getFullYear();

    const month = String(value.getMonth() + 1).padStart(2, '0');

    const day = String(value.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function formatDateTime(value: unknown): string {
    const raw = String(value ?? '').trim();

    if (!raw || raw.startsWith('1970-01-01') || raw.startsWith('0000-00-00')) {
        return 'Not taken';
    }

    const date = new Date(raw.replace(' ', 'T'));

    if (Number.isNaN(date.getTime())) {
        return raw;
    }

    return new Intl.DateTimeFormat(
        'en-US',

        {
            month: 'short',

            day: 'numeric',

            year: 'numeric',

            hour: 'numeric',

            minute: '2-digit',

            hour12: true,
        },
    ).format(date);
}

function uppercaseValue(
    value: unknown,

    fallback = 'N/A',
): string {
    const normalized = String(value ?? '').trim();

    return normalized ? normalized.toUpperCase() : fallback;
}


/*

|--------------------------------------------------------------------------

| Exam type helpers

|--------------------------------------------------------------------------

*/

function normalizeExamType(examType: unknown): string {
    return String(examType ?? '')
        .trim()

        .toUpperCase();
}

function getExamTypeSeverity(examType: unknown): TagSeverity {
    const value = normalizeExamType(examType);

    if (value === 'NEW') {
        return 'success';
    }

    if (value === 'RESIT') {
        return 'info';
    }

    return 'secondary';
}

function getExamTypeIcon(examType: unknown): string {
    const value = normalizeExamType(examType);

    if (value === 'NEW') {
        return 'pi pi-check-circle';
    }

    if (value === 'RESIT') {
        return 'pi pi-refresh';
    }

    return 'pi pi-question-circle';
}

/*

|--------------------------------------------------------------------------

| Request helpers

|--------------------------------------------------------------------------

*/

function buildParameters(page: number): Record<string, unknown> {
    const parameters: Record<string, unknown> = {
        page,

        per_page: rows.value,

        search: search.value,

        sort_field: sortField.value,

        sort_direction: sortDirection.value,
    };

    return parameters;
}

/*

|--------------------------------------------------------------------------

| API requests

|--------------------------------------------------------------------------

*/

async function loadAssessments(page = 1): Promise<void> {
    loading.value = true;

    pageError.value = '';

    try {
        const response = await axios.get(
            '/api/v1/dashboard/datatable/theoretical-external',

            {
                params: buildParameters(page),

                withCredentials: true,
            },
        );

        assessments.value = response.data.data ?? [];

        totalRecords.value = response.data.meta?.total ?? 0;

        const currentPage = response.data.meta?.currentPage ?? page;

        const currentPerPage = response.data.meta?.perPage ?? rows.value;

        rows.value = currentPerPage;

        first.value = (currentPage - 1) * currentPerPage;
    } catch (error: unknown) {
        assessments.value = [];

        totalRecords.value = 0;

        if (axios.isAxiosError(error)) {
            pageError.value =
                error.response?.data?.message ??
                'Unable to load external theoretical assessments.';
        } else {
            pageError.value =
                'Unable to load external theoretical assessments.';
        }
    } finally {
        loading.value = false;
    }
}

async function loadOptions(): Promise<void> {
    try {
        const response = await axios.get(
            '/api/v1/dashboard/theoretical-external/options',

            {
                withCredentials: true,
            },
        );

        courses.value = response.data.courses ?? [];

        sessions.value = response.data.sessions ?? [];
    } catch (error: unknown) {
        if (axios.isAxiosError(error)) {
            pageError.value =
                error.response?.data?.message ??
                'Unable to load assessment filters.';
        } else {
            pageError.value = 'Unable to load assessment filters.';
        }
    }
}

async function viewAnswers(assessment: DataTableRow): Promise<void> {
    detailsVisible.value = true;

    loadingDetails.value = true;

    selectedAssessment.value = null;

    answers.value = [];

    pageError.value = '';

    try {
        const response = await axios.get(
            `/api/v1/dashboard/theoretical-external/${assessment.id}`,

            {
                withCredentials: true,
            },
        );

        selectedAssessment.value = response.data.assessment;

        answers.value = response.data.answers ?? [];
    } catch (error: unknown) {
        detailsVisible.value = false;

        if (axios.isAxiosError(error)) {
            pageError.value =
                error.response?.data?.message ??
                'Unable to load assessment answers.';
        } else {
            pageError.value = 'Unable to load assessment answers.';
        }
    } finally {
        loadingDetails.value = false;
    }
}

/*

|--------------------------------------------------------------------------

| DataTable events

|--------------------------------------------------------------------------

*/

function handlePage(event: PageEvent): void {
    rows.value = event.rows;

    first.value = event.first;

    const page = Math.floor(event.first / event.rows) + 1;

    void loadAssessments(page);
}

function handleSort(event: SortEvent): void {
    if (typeof event.sortField === 'string') {
        sortField.value = event.sortField;
    }

    sortDirection.value = event.sortOrder === -1 ? 'desc' : 'asc';

    first.value = 0;

    void loadAssessments(1);
}

function handleSearch(value: string): void {
    search.value = value;

    first.value = 0;

    void loadAssessments(1);
}

function handleAction(
    action: string,

    assessment: DataTableRow,
): void {
    /*

     * Ignore unavailable fallback actions.

     */

    if (
        action === 'certificate-unavailable' ||
        action === 'answers-unavailable'
    ) {
        return;
    }

    if (action === 'certificate') {
        if (assessment.is_completed !== true) {
            return;
        }

        window.open(
            `/dashboard/theoretical-external/${assessment.id}/certificate`,

            '_blank',

            'noopener,noreferrer',
        );

        return;
    }

    if (action === 'answers') {
        if (assessment.is_completed !== true) {
            return;
        }

        void viewAnswers(assessment);

        return;
    }

    if (action === 'edit') {
        /*

         * Temporary destination until the

         * External edit page is migrated.

         */

        router.visit('/dashboard');
    }
}

/*

|--------------------------------------------------------------------------

| Lifecycle

|--------------------------------------------------------------------------

*/

onMounted(() => {
    void Promise.all([loadOptions(), loadAssessments()]);
});
</script>

<template>
    <Head title="Theoretical Assessments - External" />

    <div class="flex min-h-0 flex-1 flex-col gap-4 p-4">
        <Message
            v-if="pageError"
            severity="error"
            closable
            @close="pageError = ''"
            >{{ pageError }}</Message
        >
        <Message
            v-if="createSuccess"
            severity="success"
            closable
            @close="createSuccess = ''"
            >{{ createSuccess }}</Message
        >

        <!-- DATATABLE -->

        <Datatable
            title="Theoretical Assessments - External"
            description="Review external examinees, examination results, answers, and certificates."
            header-icon="pi pi-question-circle"
            search-placeholder="Search external assessments..."
            empty-title="No external assessments found"
            empty-description="No external assessments match the selected filters."
            table-min-width="1200px"
            data-key="id"
            lazy
            :loading="loading"
            :data="assessments"
            :columns="columns"
            :actions="actions"
            :total-records="totalRecords"
            :first="first"
            :rows="rows"
            :rows-per-page-options="[
                10,

                20,

                50,

                100,
            ]"
            actions-header="Actions"
            actions-width="170px"
            @page="handlePage"
            @sort="handleSort"
            @search="handleSearch"
            @action="handleAction"
        >
            <template #header-actions>
                <Button
                    label="Add New Record"
                    icon="pi pi-plus"
                    severity="success"
                    @click="openCreate"
                />
            </template>

            <!-- EXAMINEE INFORMATION -->

            <template #cell-examinee_name="{ data }">
                <div class="flex items-center gap-3">
                    <Avatar
    image="/images/default-cadet.png"
    :aria-label="uppercaseValue(data.examinee_name, 'Examinee')"
    shape="circle"
    size="large"
    class="shrink-0"
/>

                    <div class="min-w-0">
                        <p
                            class="truncate font-semibold text-slate-700 uppercase"
                        >
                            {{
                                uppercaseValue(
                                    data.examinee_name,

                                    'NO EXAMINEE NAME',
                                )
                            }}
                        </p>

                        <div class="mt-1 flex flex-nowrap items-center gap-1.5">
                            <PrimeTag
                                :value="data.email || 'No Email'"
                                icon="pi pi-envelope"
                                severity="info"
                                rounded
                                class="shrink-0 !px-2 !py-0.5 !text-xs !font-semibold !whitespace-nowrap"
                            />
                        </div>
                    </div>
                </div>
            </template>

            <!-- MERGED EXAM DETAILS -->

            <template #cell-exam_details="{ data }">
                <div class="min-w-0 space-y-2">
                    <!-- PACKAGE -->

                    <div class="flex items-start gap-2">
                        <i
                            class="pi pi-graduation-cap mt-0.5 shrink-0 text-blue-500"
                        ></i>

                        <p class="line-clamp-2 font-semibold text-slate-700">
                            {{ data.name_course || 'No exam package' }}
                        </p>
                    </div>

                    <!-- SESSION AND EXAM TYPE -->

                    <div class="flex flex-wrap items-center gap-1.5">
                        <PrimeTag
                            :value="data.session_code || 'No Session'"
                            icon="pi pi-calendar"
                            severity="secondary"
                            rounded
                            class="!px-2 !py-0.5 !text-xs !font-semibold !whitespace-nowrap"
                        />

                        <PrimeTag
                            :value="
                                normalizeExamType(data.exam_type) || 'NO TYPE'
                            "
                            :icon="getExamTypeIcon(data.exam_type)"
                            :severity="getExamTypeSeverity(data.exam_type)"
                            rounded
                            class="!px-2 !py-0.5 !text-xs !font-semibold !whitespace-nowrap"
                        />
                    </div>

                    <!-- DATE TAKEN -->

                    <p class="flex items-center gap-1.5 text-sm text-slate-500">
                        <i class="pi pi-clock shrink-0 text-amber-500"></i>

                        <span>
                            {{ formatDateTime(data.started) }}
                        </span>
                    </p>
                </div>
            </template>

            <!-- PROCTOR -->

            <template #cell-proctor_name="{ data }">
                <div class="flex items-center gap-2">
                    <i class="pi pi-user shrink-0 text-violet-500"></i>

                    <span class="font-semibold text-slate-700 uppercase">
                        {{
                            uppercaseValue(
                                data.proctor_name,

                                'NO PROCTOR',
                            )
                        }}
                    </span>
                </div>
            </template>

            <!-- SCORE -->

            <template #cell-score="{ data }">
                <div class="text-center">
                    <p class="font-semibold text-slate-700">
                        {{ data.score || 0 }}/{{ data.total_items || 0 }}
                    </p>

                    <PrimeTag
                        :value="`${data.score_percentage || 0}%`"
                        :severity="
                            Number(data.score_percentage) >= 70
                                ? 'success'
                                : 'danger'
                        "
                        rounded
                        class="mt-1 !px-2 !py-0.5 !text-xs !font-semibold"
                    />
                </div>
            </template>

            <!-- STATUS -->

            <template #cell-done="{ data }">
                <PrimeTag
                    :value="data.is_completed ? 'Done' : 'Pending'"
                    :icon="
                        data.is_completed ? 'pi pi-check-circle' : 'pi pi-clock'
                    "
                    :severity="data.is_completed ? 'success' : 'warn'"
                    rounded
                    class="!px-2 !py-0.5 !text-xs !font-semibold"
                />
            </template>
        </Datatable>

        <Dialog
            v-model:visible="createVisible"
            modal
            :draggable="false"
            header="Add External Assessment"
            class="w-[95vw] max-w-3xl"
        >
            <form
                class="grid grid-cols-1 gap-4 md:grid-cols-2"
                @submit.prevent="saveCreate"
            >
                <Message
                    v-if="createError"
                    severity="error"
                    class="md:col-span-2"
                    >{{ createError }}</Message
                >
                <div>
                    <label class="mb-2 block text-sm font-semibold"
                        >Email <span class="text-red-500">*</span></label
                    ><InputText
                        v-model="createForm.email"
                        type="email"
                        required
                        class="w-full"
                    />
                </div>
                <div>
                    <label class="mb-2 block text-sm font-semibold"
                        >First Name</label
                    ><InputText v-model="createForm.fname" class="w-full" />
                </div>
                <div>
                    <label class="mb-2 block text-sm font-semibold"
                        >Middle Name</label
                    ><InputText v-model="createForm.mname" class="w-full" />
                </div>
                <div>
                    <label class="mb-2 block text-sm font-semibold"
                        >Last Name</label
                    ><InputText v-model="createForm.lname" class="w-full" />
                </div>
                <div>
                    <label class="mb-2 block text-sm font-semibold"
                        >Exam Package <span class="text-red-500">*</span></label
                    ><Select
                        v-model="createForm.bs_course_id"
                        :options="courses"
                        option-label="label"
                        option-value="id"
                        required
                        class="w-full"
                    />
                </div>
                <div>
                    <label class="mb-2 block text-sm font-semibold"
                        >Session <span class="text-red-500">*</span></label
                    ><Select
                        v-model="createForm.bs_exam_session_id"
                        :options="sessions"
                        option-label="label"
                        option-value="id"
                        required
                        class="w-full"
                    />
                </div>
                <div>
                    <label class="mb-2 block text-sm font-semibold"
                        >Exam Type <span class="text-red-500">*</span></label
                    ><Select
                        v-model="createForm.exam_type"
                        :options="['New', 'Resit']"
                        required
                        class="w-full"
                    />
                </div>
                <div>
                    <label class="mb-2 block text-sm font-semibold"
                        >Duration (minutes, 0 = automatic)</label
                    ><input
                        v-model.number="createForm.duration"
                        type="number"
                        min="0"
                        max="1440"
                        class="w-full rounded-md border border-slate-300 px-3 py-2"
                    />
                </div>
                <div>
                    <label class="mb-2 block text-sm font-semibold"
                        >Access Date From
                        <span class="text-red-500">*</span></label
                    ><DatePicker
                        v-model="createForm.access_exp_date"
                        date-format="M d, yy"
                        show-icon
                        fluid
                        required
                    />
                </div>
                <div>
                    <label class="mb-2 block text-sm font-semibold"
                        >Access Start Time
                        <span class="text-red-500">*</span></label
                    ><Select
                        v-model="createForm.access_exp_time"
                        :options="timeOptions"
                        required
                        class="w-full"
                    />
                </div>
                <div>
                    <label class="mb-2 block text-sm font-semibold"
                        >Access Until Date
                        <span class="text-red-500">*</span></label
                    ><DatePicker
                        v-model="createForm.access_exp_date_to"
                        date-format="M d, yy"
                        show-icon
                        fluid
                        required
                    />
                </div>
                <div>
                    <label class="mb-2 block text-sm font-semibold"
                        >Access End Time
                        <span class="text-red-500">*</span></label
                    ><Select
                        v-model="createForm.access_exp_time_to"
                        :options="timeOptions"
                        required
                        class="w-full"
                    />
                </div>
                <div class="md:col-span-2">
                    <label class="mb-2 block text-sm font-semibold"
                        >Proctor Name</label
                    ><InputText
                        v-model="createForm.proctor_name"
                        class="w-full"
                    />
                </div>
                <div class="flex justify-end gap-2 md:col-span-2">
                    <Button
                        type="button"
                        label="Cancel"
                        severity="secondary"
                        @click="createVisible = false"
                    /><Button
                        type="submit"
                        label="Save"
                        icon="pi pi-check"
                        :loading="creating"
                    />
                </div>
            </form>
        </Dialog>

        <!-- ANSWERS DIALOG -->

        <Dialog
            v-model:visible="detailsVisible"
            modal
            maximizable
            header="External Assessment Answers"
            class="w-[95vw] max-w-6xl"
        >
            <div
                v-if="loadingDetails"
                class="flex min-h-48 items-center justify-center"
            >
                <i class="pi pi-spin pi-spinner text-3xl text-blue-500"></i>
            </div>

            <template v-else>
                <div
                    v-if="selectedAssessment"
                    class="mb-4 grid gap-3 rounded-xl bg-slate-50 p-4 md:grid-cols-2"
                >
                    <p>
                        <strong> Examinee: </strong>

                        {{ uppercaseValue(selectedAssessment.examinee_name) }}
                    </p>

                    <p>
                        <strong> Email: </strong>

                        {{ selectedAssessment.email || 'N/A' }}
                    </p>

                    <p>
                        <strong> Exam: </strong>

                        {{ selectedAssessment.name_course || 'N/A' }}
                    </p>

                    <p>
                        <strong> Exam Type: </strong>

                        {{
                            normalizeExamType(selectedAssessment.exam_type) ||
                            'N/A'
                        }}
                    </p>

                    <p>
                        <strong> Date Taken: </strong>

                        {{ formatDateTime(selectedAssessment.started) }}
                    </p>

                    <p>
                        <strong> Score: </strong>

                        {{ selectedAssessment.score || 0 }}/{{
                            selectedAssessment.total_items || 0
                        }}
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="bg-slate-100 text-left text-slate-700">
                                <th
                                    class="w-14 border border-slate-200 p-3 text-center"
                                >
                                    #
                                </th>

                                <th class="border border-slate-200 p-3">
                                    Question
                                </th>

                                <th class="w-52 border border-slate-200 p-3">
                                    Answer
                                </th>

                                <th class="w-52 border border-slate-200 p-3">
                                    Correct Answer
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr v-for="answer in answers" :key="answer.index">
                                <td
                                    class="border border-slate-200 p-3 text-center"
                                >
                                    {{ answer.index }}
                                </td>

                                <td class="border border-slate-200 p-3">
                                    {{ answer.question }}
                                </td>

                                <td
                                    class="border border-slate-200 p-3 font-semibold"
                                    :class="
                                        answer.is_correct
                                            ? 'text-green-600'
                                            : 'text-red-600'
                                    "
                                >
                                    {{ answer.answer || 'No answer' }}
                                </td>

                                <td
                                    class="border border-slate-200 p-3 font-semibold text-green-600"
                                >
                                    {{ answer.correct_answer || 'N/A' }}
                                </td>
                            </tr>

                            <tr v-if="answers.length === 0">
                                <td
                                    colspan="4"
                                    class="border border-slate-200 p-8 text-center text-slate-500"
                                >
                                    No answers are available.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </Dialog>
    </div>
</template>
