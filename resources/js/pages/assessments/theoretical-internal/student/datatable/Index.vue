<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import axios from 'axios';

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
                href: '/student-dashboard',
            },
            {
                title: 'Theoretical Assessments',
                href: '/assessments/theoretical-internal/student/datatable',
            },
        ],
    },
});

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
| Datatable configuration
|--------------------------------------------------------------------------
*/

const columns: DataTableColumn[] = [
    {
        field: 'access_exp_date',
        header: 'Date Taken',
        sortable: true,
        searchable: false,
        class: 'min-w-[170px]',
    },
    {
        field: 'name_course',
        header: 'Exam Details',
        sortable: false,
        searchable: true,
        class: 'min-w-[360px] whitespace-normal',
    },
    {
        field: 'proctor_name',
        header: 'Proctor',
        sortable: false,
        searchable: false,
        class: 'min-w-[220px]',
    },
    {
        field: 'done',
        header: 'Status',
        sortable: false,
        searchable: false,
        class: 'min-w-[140px]',
    },
];

const actions: DataTableAction[] = [
    {
        key: 'exam-start',
        label: 'Start Exam',
        icon: 'pi pi-play',
        severity: 'help',
        disabled: (row) => row.exam_action_state !== 'start',
    },
    {
        key: 'exam-resume',
        label: 'Resume Exam',
        icon: 'pi pi-refresh',
        severity: 'help',
        disabled: (row) => row.exam_action_state !== 'resume',
    },
    {
        key: 'exam-time-expired',
        label: 'Finalize Timed Out Exam',
        icon: 'pi pi-clock',
        severity: 'danger',
        disabled: (row) => row.exam_action_state !== 'time_expired',
    },
    {
        key: 'certificate',
        label: 'Download Certificate',
        icon: 'pi pi-download',
        severity: 'info',
        disabled: (row) => row.is_completed !== true,
    },
];

/*
|--------------------------------------------------------------------------
| State
|--------------------------------------------------------------------------
*/

const assessments = ref<DataTableRow[]>([]);

const loading = ref(false);
const totalRecords = ref(0);
const first = ref(0);
const rows = ref(10);
const search = ref('');

const sortField = ref('access_exp_date');

const sortDirection = ref<'asc' | 'desc'>('desc');

const pageError = ref('');

/*
|--------------------------------------------------------------------------
| Formatting
|--------------------------------------------------------------------------
*/

function formatDate(value: unknown): string {
    const raw = String(value ?? '').trim();

    if (!raw || raw.startsWith('0000-00-00') || raw.startsWith('1970-01-01')) {
        return 'Not available';
    }

    const date = new Date(`${raw.slice(0, 10)}T00:00:00`);

    if (Number.isNaN(date.getTime())) {
        return raw;
    }

    return new Intl.DateTimeFormat('en-PH', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(date);
}

function normalizeExamType(value: unknown): string {
    return String(value ?? '')
        .trim()
        .toUpperCase();
}

function getExamTypeSeverity(examType: unknown): TagSeverity {
    const value = normalizeExamType(examType);

    if (value === 'NEW') {
        return 'success';
    }

    if (value === 'RESIT') {
        return 'warn';
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

function uppercaseValue(value: unknown, fallback = 'N/A'): string {
    const normalized = String(value ?? '').trim();

    return normalized ? normalized.toUpperCase() : fallback;
}

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
*/

function buildParameters(page: number): Record<string, unknown> {
    return {
        page,
        per_page: rows.value,
        search: search.value,
        sort_field: sortField.value,
        sort_direction: sortDirection.value,
    };
}

async function loadAssessments(page = 1): Promise<void> {
    loading.value = true;
    pageError.value = '';

    try {
        const response = await axios.get(
            '/api/v1/student/theoretical-assessments',
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
                'Unable to load your theoretical assessments.';
        } else {
            pageError.value = 'Unable to load your theoretical assessments.';
        }
    } finally {
        loading.value = false;
    }
}

/*
|--------------------------------------------------------------------------
| Datatable events
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

function handleAction(action: string, assessment: DataTableRow): void {
    if (action === 'exam-start' && assessment.exam_action_state === 'start') {
        window.location.href = `/assessments/theoretical-internal/student/${assessment.id}/exam`;

        return;
    }

    if (action === 'exam-resume' && assessment.exam_action_state === 'resume') {
        window.location.href = `/assessments/theoretical-internal/student/${assessment.id}/exam`;

        return;
    }

    if (
        action === 'exam-time-expired' &&
        assessment.exam_action_state === 'time_expired'
    ) {
        window.location.href = `/assessments/theoretical-internal/student/${assessment.id}/exam`;

        return;
    }

    if (action === 'certificate' && assessment.is_completed === true) {
        window.open(
            `/assessments/theoretical-internal/student/${assessment.id}/certificate`,
            '_blank',
            'noopener,noreferrer',
        );
    }
}

onMounted(() => {
    void loadAssessments();
});
</script>

<template>
    <Head title="Theoretical Assessments" />

    <div
        class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5"
    >
        <div
            v-if="pageError"
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700"
        >
            {{ pageError }}
        </div>

        <Datatable
            title="Theoretical Assessments"
            description="View your assigned internal theoretical assessments and download certificates for completed examinations."
            header-icon="pi pi-clipboard"
            search-placeholder="Search assessments..."
            empty-title="No assessments found"
            empty-description="Your assigned theoretical assessments will appear here."
            empty-icon="pi pi-clipboard"
            table-min-width="1000px"
            data-key="id"
            lazy
            :loading="loading"
            :data="assessments"
            :columns="columns"
            :actions="actions"
            :total-records="totalRecords"
            :first="first"
            :rows="rows"
            :rows-per-page-options="[10, 20, 50, 100]"
            actions-header="Actions"
            actions-width="130px"
            @page="handlePage"
            @sort="handleSort"
            @search="handleSearch"
            @action="handleAction"
        >
            <template #cell-access_exp_date="{ data }">
                <div class="flex items-center gap-2">
                    <i class="pi pi-calendar shrink-0 text-[#377EC0]"></i>

                    <span
                        class="text-sm font-semibold whitespace-nowrap text-slate-700"
                    >
                        {{ formatDate(data.access_exp_date) }}
                    </span>
                </div>
            </template>

            <template #cell-name_course="{ data }">
                <div class="min-w-0 space-y-2">
                    <div class="flex items-start gap-2">
                        <i
                            class="pi pi-graduation-cap mt-0.5 shrink-0 text-blue-500"
                        ></i>

                        <p
                            class="font-semibold break-words whitespace-normal text-slate-700"
                        >
                            {{ data.name_course || 'No exam package' }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <PrimeTag
                            :value="data.session_code || 'No Session'"
                            icon="pi pi-calendar"
                            severity="info"
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
                </div>
            </template>

            <template #cell-proctor_name="{ data }">
                <div class="flex items-center gap-2">
                    <i class="pi pi-user shrink-0 text-blue-500"></i>

                    <span class="font-semibold text-slate-700 uppercase">
                        {{ uppercaseValue(data.proctor_name, 'NO PROCTOR') }}
                    </span>
                </div>
            </template>

            <template #cell-done="{ data }">
                <PrimeTag
                    :value="data.is_completed ? 'Completed' : 'Pending'"
                    :icon="
                        data.is_completed ? 'pi pi-check' : 'pi pi-clock'
                    "
                    :severity="data.is_completed ? 'success' : 'warn'"
                    rounded
                    class="!px-2 !py-0.5 !text-xs !font-semibold"
                />
            </template>
        </Datatable>
    </div>
</template>
