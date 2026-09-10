<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import Avatar from 'primevue/avatar';
import Button from 'primevue/button';
import Card from 'primevue/card';
import DatePicker from 'primevue/datepicker';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Select from 'primevue/select';
import PrimeTag from 'primevue/tag';
import { computed, onMounted, ref } from 'vue';

import Datatable from '@/components/Datatable.vue';
import type {
    DataTableAction,
    DataTableColumn,
    DataTableRow,
} from '@/types';

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
    | 'success'
    | 'info'
    | 'warn'
    | 'danger'
    | 'secondary'
    | 'contrast';

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
        visible: (row) =>
            row.is_completed === true,
    },
    {
        key: 'certificate-unavailable',
        label: 'No Certificate Available',
        icon: 'pi pi-download',
        severity: 'secondary',
        visible: (row) =>
            row.is_completed !== true,
        disabled: () => true,
    },
    {
        key: 'answers',
        label: 'View Answers',
        icon: 'pi pi-eye',
        severity: 'danger',
        visible: (row) =>
            row.is_completed === true,
    },
    {
        key: 'answers-unavailable',
        label: 'No Answers Available',
        icon: 'pi pi-eye',
        severity: 'secondary',
        visible: (row) =>
            row.is_completed !== true,
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
const sortDirection =
    ref<'asc' | 'desc'>('desc');

/*
|--------------------------------------------------------------------------
| Filter state
|--------------------------------------------------------------------------
*/

const courses = ref<Option[]>([]);
const sessions = ref<Option[]>([]);

const selectedCourse =
    ref<Option | null>(null);

const selectedSession =
    ref<Option | null>(null);

const email = ref('');
const examineeName = ref('');
const dateRange = ref<Date[] | null>(null);

/*
|--------------------------------------------------------------------------
| Dialog state
|--------------------------------------------------------------------------
*/

const detailsVisible = ref(false);
const loadingDetails = ref(false);

const selectedAssessment =
    ref<Record<string, unknown> | null>(
        null,
    );

const answers = ref<AnswerRow[]>([]);
const pageError = ref('');

/*
|--------------------------------------------------------------------------
| Computed properties
|--------------------------------------------------------------------------
*/

const hasFilters = computed(() => {
    return Boolean(
        selectedCourse.value ||
        selectedSession.value ||
        email.value.trim() ||
        examineeName.value.trim() ||
        dateRange.value?.length ||
        search.value.trim(),
    );
});

/*
|--------------------------------------------------------------------------
| Formatting helpers
|--------------------------------------------------------------------------
*/

function formatLocalDate(
    value: Date,
): string {
    const year = value.getFullYear();

    const month = String(
        value.getMonth() + 1,
    ).padStart(2, '0');

    const day = String(
        value.getDate(),
    ).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function formatDateTime(
    value: unknown,
): string {
    const raw = String(
        value ?? '',
    ).trim();

    if (
        !raw ||
        raw.startsWith('1970-01-01') ||
        raw.startsWith('0000-00-00')
    ) {
        return 'Not taken';
    }

    const date = new Date(
        raw.replace(' ', 'T'),
    );

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
    const normalized = String(
        value ?? '',
    ).trim();

    return normalized
        ? normalized.toUpperCase()
        : fallback;
}

function getInitials(
    row: DataTableRow,
): string {
    const firstName = String(
        row.fname ?? '',
    ).trim();

    const lastName = String(
        row.lname ?? '',
    ).trim();

    const initials =
        `${firstName.charAt(0)}${lastName.charAt(0)}`
            .toUpperCase();

    return initials || 'NA';
}

/*
|--------------------------------------------------------------------------
| Exam type helpers
|--------------------------------------------------------------------------
*/

function normalizeExamType(
    examType: unknown,
): string {
    return String(
        examType ?? '',
    )
        .trim()
        .toUpperCase();
}

function getExamTypeSeverity(
    examType: unknown,
): TagSeverity {
    const value = normalizeExamType(
        examType,
    );

    if (value === 'NEW') {
        return 'success';
    }

    if (value === 'RESIT') {
        return 'info';
    }

    return 'secondary';
}

function getExamTypeIcon(
    examType: unknown,
): string {
    const value = normalizeExamType(
        examType,
    );

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

function buildParameters(
    page: number,
): Record<string, unknown> {
    const parameters: Record<
        string,
        unknown
    > = {
        page,
        per_page: rows.value,
        search: search.value,
        sort_field: sortField.value,
        sort_direction:
            sortDirection.value,
    };

    if (selectedCourse.value) {
        parameters.course_id =
            selectedCourse.value.id;
    }

    if (selectedSession.value) {
        parameters.session_id =
            selectedSession.value.id;
    }

    if (email.value.trim()) {
        parameters.email =
            email.value.trim();
    }

    if (examineeName.value.trim()) {
        parameters.examinee_name =
            examineeName.value.trim();
    }

    if (dateRange.value?.[0]) {
        parameters.date_from =
            formatLocalDate(
                dateRange.value[0],
            );
    }

    if (dateRange.value?.[1]) {
        parameters.date_to =
            formatLocalDate(
                dateRange.value[1],
            );
    }

    return parameters;
}

/*
|--------------------------------------------------------------------------
| API requests
|--------------------------------------------------------------------------
*/

async function loadAssessments(
    page = 1,
): Promise<void> {
    loading.value = true;
    pageError.value = '';

    try {
        const response = await axios.get(
            '/api/v1/dashboard/datatable/theoretical-external',
            {
                params: buildParameters(
                    page,
                ),
                withCredentials: true,
            },
        );

        assessments.value =
            response.data.data ?? [];

        totalRecords.value =
            response.data.meta?.total ?? 0;

        const currentPage =
            response.data.meta?.currentPage ??
            page;

        const currentPerPage =
            response.data.meta?.perPage ??
            rows.value;

        rows.value = currentPerPage;

        first.value =
            (currentPage - 1) *
            currentPerPage;
    } catch (error: unknown) {
        assessments.value = [];
        totalRecords.value = 0;

        if (axios.isAxiosError(error)) {
            pageError.value =
                error.response?.data
                    ?.message ??
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

        courses.value =
            response.data.courses ?? [];

        sessions.value =
            response.data.sessions ?? [];
    } catch (error: unknown) {
        if (axios.isAxiosError(error)) {
            pageError.value =
                error.response?.data
                    ?.message ??
                'Unable to load assessment filters.';
        } else {
            pageError.value =
                'Unable to load assessment filters.';
        }
    }
}

async function viewAnswers(
    assessment: DataTableRow,
): Promise<void> {
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

        selectedAssessment.value =
            response.data.assessment;

        answers.value =
            response.data.answers ?? [];
    } catch (error: unknown) {
        detailsVisible.value = false;

        if (axios.isAxiosError(error)) {
            pageError.value =
                error.response?.data
                    ?.message ??
                'Unable to load assessment answers.';
        } else {
            pageError.value =
                'Unable to load assessment answers.';
        }
    } finally {
        loadingDetails.value = false;
    }
}

/*
|--------------------------------------------------------------------------
| Filter actions
|--------------------------------------------------------------------------
*/

function applyFilters(): void {
    first.value = 0;

    void loadAssessments(1);
}

function clearFilters(): void {
    selectedCourse.value = null;
    selectedSession.value = null;
    email.value = '';
    examineeName.value = '';
    dateRange.value = null;
    search.value = '';
    first.value = 0;
    pageError.value = '';

    void loadAssessments(1);
}

/*
|--------------------------------------------------------------------------
| DataTable events
|--------------------------------------------------------------------------
*/

function handlePage(
    event: PageEvent,
): void {
    rows.value = event.rows;
    first.value = event.first;

    const page =
        Math.floor(
            event.first /
                event.rows,
        ) + 1;

    void loadAssessments(page);
}

function handleSort(
    event: SortEvent,
): void {
    if (
        typeof event.sortField ===
        'string'
    ) {
        sortField.value =
            event.sortField;
    }

    sortDirection.value =
        event.sortOrder === -1
            ? 'desc'
            : 'asc';

    first.value = 0;

    void loadAssessments(1);
}

function handleSearch(
    value: string,
): void {
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
        action ===
            'certificate-unavailable' ||
        action ===
            'answers-unavailable'
    ) {
        return;
    }

    if (action === 'certificate') {
        if (
            assessment.is_completed !==
            true
        ) {
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
        if (
            assessment.is_completed !==
            true
        ) {
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
    void Promise.all([
        loadOptions(),
        loadAssessments(),
    ]);
});
</script>

<template>
    <Head
        title="Theoretical Assessments - External"
    />

    <div
        class="flex min-h-0 flex-1 flex-col gap-4 p-4"
    >
        <!-- FILTERS -->
        <Card
            class="rounded-2xl border border-slate-200 shadow-sm"
        >
            <template #content>
                <div
                    class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-6"
                >
                    <!-- EXAM PACKAGE -->
                    <div>
                        <label
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >
                            Exam Package
                        </label>

                        <Select
                            v-model="selectedCourse"
                            :options="courses"
                            option-label="label"
                            placeholder="All packages"
                            show-clear
                            class="w-full"
                        />
                    </div>

                    <!-- EXAM SESSION -->
                    <div>
                        <label
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >
                            Exam Session
                        </label>

                        <Select
                            v-model="selectedSession"
                            :options="sessions"
                            option-label="label"
                            placeholder="All sessions"
                            show-clear
                            class="w-full"
                        />
                    </div>

                    <!-- EMAIL -->
                    <div>
                        <label
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >
                            Email
                        </label>

                        <InputText
                            v-model="email"
                            type="email"
                            placeholder="Enter email..."
                            class="w-full"
                            @keyup.enter="
                                applyFilters
                            "
                        />
                    </div>

                    <!-- EXAMINEE NAME -->
                    <div>
                        <label
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >
                            Examinee Name
                        </label>

                        <InputText
                            v-model="examineeName"
                            placeholder="Enter examinee name..."
                            class="w-full"
                            @keyup.enter="
                                applyFilters
                            "
                        />
                    </div>

                    <!-- DATE RANGE -->
                    <div>
                        <label
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >
                            Date Range
                        </label>

                        <DatePicker
                            v-model="dateRange"
                            selection-mode="range"
                            date-format="M d, yy"
                            placeholder="Select date range"
                            show-icon
                            fluid
                        />
                    </div>

                    <!-- FILTER BUTTONS -->
                    <div
                        class="flex items-end gap-2"
                    >
                        <Button
                            type="button"
                            label="Search"
                            icon="pi pi-search"
                            severity="info"
                            @click="
                                applyFilters
                            "
                        />

                        <Button
                            type="button"
                            label="Clear"
                            icon="pi pi-filter-slash"
                            severity="secondary"
                            variant="outlined"
                            :disabled="!hasFilters"
                            @click="
                                clearFilters
                            "
                        />
                    </div>
                </div>

                <Message
                    v-if="pageError"
                    severity="error"
                    closable
                    class="mt-4"
                    @close="
                        pageError = ''
                    "
                >
                    {{ pageError }}
                </Message>
            </template>
        </Card>

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
            :total-records="
                totalRecords
            "
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
            <!-- EXAMINEE INFORMATION -->
            <template
                #cell-examinee_name="{
                    data,
                }"
            >
                <div
                    class="flex items-center gap-3"
                >
                    <Avatar
                        :label="
                            getInitials(
                                data,
                            )
                        "
                        :aria-label="
                            uppercaseValue(
                                data.examinee_name,
                                'Examinee',
                            )
                        "
                        shape="circle"
                        size="large"
                        class="shrink-0 bg-yellow-50 text-yellow-600"
                    />

                    <div class="min-w-0">
                        <p
                            class="truncate font-semibold uppercase text-slate-700"
                        >
                            {{
                                uppercaseValue(
                                    data.examinee_name,
                                    'NO EXAMINEE NAME',
                                )
                            }}
                        </p>

                        <div
                            class="mt-1 flex flex-nowrap items-center gap-1.5"
                        >
                            <PrimeTag
                                :value="
                                    data.email ||
                                    'No Email'
                                "
                                icon="pi pi-envelope"
                                severity="info"
                                rounded
                                class="shrink-0 !whitespace-nowrap !px-2 !py-0.5 !text-xs !font-semibold"
                            />
                        </div>
                    </div>
                </div>
            </template>

            <!-- MERGED EXAM DETAILS -->
            <template
                #cell-exam_details="{
                    data,
                }"
            >
                <div
                    class="min-w-0 space-y-2"
                >
                    <!-- PACKAGE -->
                    <div
                        class="flex items-start gap-2"
                    >
                        <i
                            class="pi pi-graduation-cap mt-0.5 shrink-0 text-blue-500"
                        ></i>

                        <p
                            class="line-clamp-2 font-semibold text-slate-700"
                        >
                            {{
                                data.name_course ||
                                'No exam package'
                            }}
                        </p>
                    </div>

                    <!-- SESSION AND EXAM TYPE -->
                    <div
                        class="flex flex-wrap items-center gap-1.5"
                    >
                        <PrimeTag
                            :value="
                                data.session_code ||
                                'No Session'
                            "
                            icon="pi pi-calendar"
                            severity="secondary"
                            rounded
                            class="!whitespace-nowrap !px-2 !py-0.5 !text-xs !font-semibold"
                        />

                        <PrimeTag
                            :value="
                                normalizeExamType(
                                    data.exam_type,
                                ) ||
                                'NO TYPE'
                            "
                            :icon="
                                getExamTypeIcon(
                                    data.exam_type,
                                )
                            "
                            :severity="
                                getExamTypeSeverity(
                                    data.exam_type,
                                )
                            "
                            rounded
                            class="!whitespace-nowrap !px-2 !py-0.5 !text-xs !font-semibold"
                        />
                    </div>

                    <!-- DATE TAKEN -->
                    <p
                        class="flex items-center gap-1.5 text-sm text-slate-500"
                    >
                        <i
                            class="pi pi-clock shrink-0 text-amber-500"
                        ></i>

                        <span>
                            {{
                                formatDateTime(
                                    data.started,
                                )
                            }}
                        </span>
                    </p>
                </div>
            </template>

            <!-- PROCTOR -->
            <template
                #cell-proctor_name="{
                    data,
                }"
            >
                <div
                    class="flex items-center gap-2"
                >
                    <i
                        class="pi pi-user shrink-0 text-violet-500"
                    ></i>

                    <span
                        class="font-semibold uppercase text-slate-700"
                    >
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
            <template
                #cell-score="{ data }"
            >
                <div class="text-center">
                    <p
                        class="font-semibold text-slate-700"
                    >
                        {{
                            data.score || 0
                        }}/{{
                            data.total_items ||
                            0
                        }}
                    </p>

                    <PrimeTag
                        :value="`${data.score_percentage || 0}%`"
                        :severity="
                            Number(
                                data.score_percentage,
                            ) >= 70
                                ? 'success'
                                : 'danger'
                        "
                        rounded
                        class="mt-1 !px-2 !py-0.5 !text-xs !font-semibold"
                    />
                </div>
            </template>

            <!-- STATUS -->
            <template
                #cell-done="{ data }"
            >
                <PrimeTag
                    :value="
                        data.is_completed
                            ? 'Done'
                            : 'Pending'
                    "
                    :icon="
                        data.is_completed
                            ? 'pi pi-check-circle'
                            : 'pi pi-clock'
                    "
                    :severity="
                        data.is_completed
                            ? 'success'
                            : 'warn'
                    "
                    rounded
                    class="!px-2 !py-0.5 !text-xs !font-semibold"
                />
            </template>
        </Datatable>

        <!-- ANSWERS DIALOG -->
        <Dialog
            v-model:visible="
                detailsVisible
            "
            modal
            maximizable
            header="External Assessment Answers"
            class="w-[95vw] max-w-6xl"
        >
            <div
                v-if="loadingDetails"
                class="flex min-h-48 items-center justify-center"
            >
                <i
                    class="pi pi-spin pi-spinner text-3xl text-blue-500"
                ></i>
            </div>

            <template v-else>
                <div
                    v-if="
                        selectedAssessment
                    "
                    class="mb-4 grid gap-3 rounded-xl bg-slate-50 p-4 md:grid-cols-2"
                >
                    <p>
                        <strong>
                            Examinee:
                        </strong>

                        {{
                            uppercaseValue(
                                selectedAssessment.examinee_name,
                            )
                        }}
                    </p>

                    <p>
                        <strong>
                            Email:
                        </strong>

                        {{
                            selectedAssessment.email ||
                            'N/A'
                        }}
                    </p>

                    <p>
                        <strong>
                            Exam:
                        </strong>

                        {{
                            selectedAssessment.name_course ||
                            'N/A'
                        }}
                    </p>

                    <p>
                        <strong>
                            Exam Type:
                        </strong>

                        {{
                            normalizeExamType(
                                selectedAssessment.exam_type,
                            ) ||
                            'N/A'
                        }}
                    </p>

                    <p>
                        <strong>
                            Date Taken:
                        </strong>

                        {{
                            formatDateTime(
                                selectedAssessment.started,
                            )
                        }}
                    </p>

                    <p>
                        <strong>
                            Score:
                        </strong>

                        {{
                            selectedAssessment.score ||
                            0
                        }}/{{
                            selectedAssessment.total_items ||
                            0
                        }}
                    </p>
                </div>

                <div
                    class="overflow-x-auto"
                >
                    <table
                        class="w-full border-collapse text-sm"
                    >
                        <thead>
                            <tr
                                class="bg-slate-100 text-left text-slate-700"
                            >
                                <th
                                    class="w-14 border border-slate-200 p-3 text-center"
                                >
                                    #
                                </th>

                                <th
                                    class="border border-slate-200 p-3"
                                >
                                    Question
                                </th>

                                <th
                                    class="w-52 border border-slate-200 p-3"
                                >
                                    Answer
                                </th>

                                <th
                                    class="w-52 border border-slate-200 p-3"
                                >
                                    Correct Answer
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="
                                    answer in answers
                                "
                                :key="
                                    answer.index
                                "
                            >
                                <td
                                    class="border border-slate-200 p-3 text-center"
                                >
                                    {{
                                        answer.index
                                    }}
                                </td>

                                <td
                                    class="border border-slate-200 p-3"
                                >
                                    {{
                                        answer.question
                                    }}
                                </td>

                                <td
                                    class="border border-slate-200 p-3 font-semibold"
                                    :class="
                                        answer.is_correct
                                            ? 'text-green-600'
                                            : 'text-red-600'
                                    "
                                >
                                    {{
                                        answer.answer ||
                                        'No answer'
                                    }}
                                </td>

                                <td
                                    class="border border-slate-200 p-3 font-semibold text-green-600"
                                >
                                    {{
                                        answer.correct_answer ||
                                        'N/A'
                                    }}
                                </td>
                            </tr>

                            <tr
                                v-if="
                                    answers.length ===
                                    0
                                "
                            >
                                <td
                                    colspan="4"
                                    class="border border-slate-200 p-8 text-center text-slate-500"
                                >
                                    No answers are
                                    available.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </Dialog>
    </div>
</template>