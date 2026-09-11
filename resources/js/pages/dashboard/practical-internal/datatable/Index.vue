<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Avatar from 'primevue/avatar';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import Dialog from 'primevue/dialog';
import Message from 'primevue/message';
import PrimeTag from 'primevue/tag';
import Select from 'primevue/select';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
} from 'vue';

import Datatable from '@/components/Datatable.vue';
import { dashboard } from '@/routes';

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
                href: dashboard(),
            },
            {
                title: 'Practical Internal',
                href: '/dashboard/practical-internal',
            },
        ],
    },
});

type RemoteFile = {
    name: string;
    url: string;
};

type RubricOption = {
    id: string;
    title: string;
    points: number;
};

type RubricCriterion = {
    id: string;
    title: string;
    description: string;
    options: RubricOption[];
};

type AssessmentItem = {
    id: string;
    assessment_item_id: string;
    description: string;
    answer: string;
    points: number;
    maximum_points: number;
    reference_file: RemoteFile | null;
    evidence_file: RemoteFile | null;
};

type AssessmentDetails = {
    id: string;

    student: {
        id: string;
        name: string;
        school_id_no: string;
        dept: string | null;
        gender: string | null;
    };

    title: string;
    instructions: string;
    grade_system: string;
    passing_mark: number;
    date_taken: string | null;
    due_date: string | null;
    is_completed: boolean;
    is_pending: boolean;

    items: AssessmentItem[];
    rubric_criteria: RubricCriterion[];
    reference_files: RemoteFile[];

    result: {
        earned_points: number;
        maximum_points: number;
        percentage: number;
        passing_mark: number;
        remarks: 'PASS' | 'FAIL';
    };
};

type AssessmentApiResponse = {
    data: DataTableRow[];

    meta: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
        from: number | null;
        to: number | null;
    };

    links: {
        first: string | null;
        last: string | null;
        previous: string | null;
        next: string | null;
    };
};

type DataTablePageEvent = {
    page: number;
    rows: number;
    first: number;
};

type DataTableSortEvent = {
    sortField: string;
    sortOrder: number;
};

type GradePayload = {
    item_id: string;
    points: number | null;
    rubric_selections?: Record<string, string | null>;
};

const assessments = ref<DataTableRow[]>([]);
const selectedAssessment =
    ref<AssessmentDetails | null>(null);

const loading = ref(false);
const detailsLoading = ref(false);
const saving = ref(false);

const gradingDialogVisible = ref(false);

const totalRecords = ref(0);
const first = ref(0);
const rows = ref(10);
const search = ref('');

const sortField = ref('date_taken');
const sortDirection = ref<'asc' | 'desc'>(
    'desc',
);

const successMessage = ref('');
const errorMessage = ref('');
const gradingError = ref('');

const pointGrades = reactive<
    Record<string, number | null>
>({});

const checklistGrades = reactive<
    Record<string, boolean>
>({});

const rubricSelections = reactive<
    Record<string, Record<string, string | null>>
>({});

let requestController: AbortController | null =
    null;

const columns: DataTableColumn[] = [
    {
        field: 'student_name',
        header: 'Student Information',
        sortable: true,
        searchable: true,
        frozen: true,
        alignFrozen: 'left',
        class: 'min-w-[320px]',
    },
    {
        field: 'title_assess',
        header: 'Practical Assessment',
        sortable: true,
        searchable: true,
        class: 'min-w-[360px] whitespace-normal',
    },
    {
        field: 'date_taken',
        header: 'Assessment Schedule',
        sortable: true,
        searchable: false,
        class: 'min-w-[240px]',
    },
    {
        field: 'grade_system',
        header: 'Grading System',
        sortable: false,
        searchable: true,
        class: 'min-w-[160px]',
    },
];

const actions: DataTableAction[] = [
    {
        key: 'grade',
        label: 'View and grade assessment',
        icon: 'pi pi-pencil',
        severity: 'warn',
    },
];

const currentPage = computed(() => {
    return (
        Math.floor(first.value / rows.value) + 1
    );
});

const normalizedGradeSystem = computed(() => {
    return String(
        selectedAssessment.value?.grade_system ?? '',
    )
        .trim()
        .toLowerCase();
});

const displayedEarnedPoints = computed(() => {
    const assessment = selectedAssessment.value;

    if (!assessment) {
        return 0;
    }

    if (assessment.is_completed) {
        return assessment.result.earned_points;
    }

    if (normalizedGradeSystem.value === 'points') {
        return assessment.items.reduce(
            (total, item) =>
                total +
                Number(pointGrades[item.id] ?? 0),
            0,
        );
    }

    if (
        normalizedGradeSystem.value === 'checklist'
    ) {
        return assessment.items.reduce(
            (total, item) =>
                total +
                (checklistGrades[item.id] ? 1 : 0),
            0,
        );
    }

    return assessment.items.reduce(
        (total, item) => {
            const selections =
                rubricSelections[item.id] ?? {};

            return (
                total +
                Object.values(selections).reduce(
                    (itemTotal, optionId) => {
                        return (
                            itemTotal +
                            getRubricOptionPoints(
                                optionId,
                            )
                        );
                    },
                    0,
                )
            );
        },
        0,
    );
});

const displayedMaximumPoints = computed(() => {
    const assessment = selectedAssessment.value;

    if (!assessment) {
        return 0;
    }

    return assessment.result.maximum_points;
});

const displayedPercentage = computed(() => {
    if (displayedMaximumPoints.value <= 0) {
        return 0;
    }

    return Number(
        (
            (displayedEarnedPoints.value /
                displayedMaximumPoints.value) *
            100
        ).toFixed(1),
    );
});

const displayedRemarks = computed<
    'PASS' | 'FAIL'
>(() => {
    const passingMark =
        selectedAssessment.value?.passing_mark ?? 0;

    return displayedPercentage.value >= passingMark
        ? 'PASS'
        : 'FAIL';
});

async function loadAssessments(
    pageNumber = 1,
): Promise<void> {
    requestController?.abort();

    const controller = new AbortController();

    requestController = controller;
    loading.value = true;
    errorMessage.value = '';

    try {
        const response =
            await axios.get<AssessmentApiResponse>(
                '/api/v1/dashboard/datatable/practical-internal',
                {
                    signal: controller.signal,

                    params: {
                        page: pageNumber,
                        per_page: rows.value,
                        search: search.value,
                        sort_field: sortField.value,
                        sort_direction:
                            sortDirection.value,
                    },

                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With':
                            'XMLHttpRequest',
                    },

                    withCredentials: true,
                },
            );

        assessments.value = response.data.data;
        totalRecords.value =
            response.data.meta.total;
        rows.value = response.data.meta.perPage;

        first.value =
            (response.data.meta.currentPage - 1) *
            response.data.meta.perPage;
    } catch (error: unknown) {
        if (
            axios.isCancel(error) ||
            (axios.isAxiosError(error) &&
                error.code === 'ERR_CANCELED')
        ) {
            return;
        }

        assessments.value = [];
        totalRecords.value = 0;

        errorMessage.value = getErrorMessage(
            error,
            'Unable to load practical assessments.',
        );
    } finally {
        if (requestController === controller) {
            loading.value = false;
        }
    }
}

async function openAssessment(
    assessmentId: string,
): Promise<void> {
    detailsLoading.value = true;
    gradingError.value = '';
    resetGradeState();

    try {
        const response = await axios.get<{
            data: AssessmentDetails;
        }>(
            `/api/v1/dashboard/practical-internal/${encodeURIComponent(
                assessmentId,
            )}`,
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With':
                        'XMLHttpRequest',
                },

                withCredentials: true,
            },
        );

        selectedAssessment.value =
            response.data.data;

        initializeGradeState(
            response.data.data,
        );

        gradingDialogVisible.value = true;
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(
            error,
            'Unable to load the selected assessment.',
        );
    } finally {
        detailsLoading.value = false;
    }
}

function initializeGradeState(
    assessment: AssessmentDetails,
): void {
    resetGradeState();

    assessment.items.forEach((item) => {
        pointGrades[item.id] =
            assessment.is_completed
                ? item.points
                : null;

        checklistGrades[item.id] =
            item.points > 0;

        rubricSelections[item.id] = {};

        assessment.rubric_criteria.forEach(
            (criterion) => {
                rubricSelections[item.id][
                    criterion.id
                ] = null;
            },
        );
    });
}

function resetGradeState(): void {
    Object.keys(pointGrades).forEach((key) => {
        delete pointGrades[key];
    });

    Object.keys(checklistGrades).forEach(
        (key) => {
            delete checklistGrades[key];
        },
    );

    Object.keys(rubricSelections).forEach(
        (key) => {
            delete rubricSelections[key];
        },
    );
}

function handlePage(
    event: DataTablePageEvent,
): void {
    rows.value = event.rows;
    first.value = event.first;

    void loadAssessments(event.page + 1);
}

function handleSort(
    event: DataTableSortEvent,
): void {
    sortField.value =
        event.sortField || 'date_taken';

    sortDirection.value =
        event.sortOrder === -1
            ? 'desc'
            : 'asc';

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
    if (action !== 'grade') {
        return;
    }

    const assessmentId = String(
        assessment.id ?? '',
    ).trim();

    if (!assessmentId) {
        errorMessage.value =
            'The selected assessment ID is missing.';

        return;
    }

    void openAssessment(assessmentId);
}

function closeGradingDialog(): void {
    if (saving.value) {
        return;
    }

    gradingDialogVisible.value = false;
    gradingError.value = '';
    selectedAssessment.value = null;
    resetGradeState();
}

async function saveGrades(): Promise<void> {
    const assessment = selectedAssessment.value;

    if (!assessment || assessment.is_completed) {
        return;
    }

    gradingError.value = '';

    if (hasIncompleteGrades()) {
        const shouldContinue = window.confirm(
            'Some graded items do not have grades. Do you want to save this assessment with the missing grades set to zero?',
        );

        if (!shouldContinue) {
            return;
        }
    }

    const grades = buildGradePayload(
        assessment,
    );

    saving.value = true;

    try {
        const response = await axios.patch<{
            message: string;

            data: {
                total_points: number;
                next_assessment_id:
                    | string
                    | null;
            };
        }>(
            `/api/v1/dashboard/practical-internal/${encodeURIComponent(
                assessment.id,
            )}/grade`,
            {
                grades,
            },
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With':
                        'XMLHttpRequest',
                },

                withCredentials: true,
            },
        );

        successMessage.value =
            response.data.message ||
            'The practical assessment was graded successfully.';

        const nextAssessmentId =
            response.data.data
                .next_assessment_id;

        gradingDialogVisible.value = false;
        selectedAssessment.value = null;
        resetGradeState();

        await reloadCurrentPage();

        if (nextAssessmentId) {
            const openNext = window.confirm(
                'The assessment was saved. Do you want to grade the next pending assessment?',
            );

            if (openNext) {
                await openAssessment(
                    nextAssessmentId,
                );
            }
        }
    } catch (error: unknown) {
        gradingError.value = getErrorMessage(
            error,
            'The practical assessment could not be saved.',
        );
    } finally {
        saving.value = false;
    }
}

function buildGradePayload(
    assessment: AssessmentDetails,
): GradePayload[] {
    return assessment.items.map((item) => {
        if (
            normalizedGradeSystem.value ===
            'points'
        ) {
            return {
                item_id: item.id,
                points:
                    pointGrades[item.id] ?? 0,
            };
        }

        if (
            normalizedGradeSystem.value ===
            'checklist'
        ) {
            return {
                item_id: item.id,
                points: checklistGrades[item.id]
                    ? 1
                    : 0,
            };
        }

        return {
            item_id: item.id,
            points: null,
            rubric_selections:
                rubricSelections[item.id] ?? {},
        };
    });
}

function hasIncompleteGrades(): boolean {
    const assessment = selectedAssessment.value;

    if (!assessment) {
        return false;
    }

    if (normalizedGradeSystem.value === 'points') {
        return assessment.items.some(
            (item) =>
                pointGrades[item.id] === null ||
                pointGrades[item.id] === undefined,
        );
    }

    if (
        normalizedGradeSystem.value === 'rubrics'
    ) {
        return assessment.items.some((item) => {
            return assessment.rubric_criteria.some(
                (criterion) => {
                    return !rubricSelections[
                        item.id
                    ]?.[criterion.id];
                },
            );
        });
    }

    return false;
}

function getPointOptions(
    maximum: number,
): Array<{
    label: string;
    value: number;
}> {
    const maximumPoints = Math.max(
        0,
        Math.floor(Number(maximum)),
    );

    return Array.from(
        {
            length: maximumPoints + 1,
        },
        (_, points) => ({
            label: String(points),
            value: points,
        }),
    );
}

function selectRubricOption(
    itemId: string,
    criterionId: string,
    optionId: string,
): void {
    if (!rubricSelections[itemId]) {
        rubricSelections[itemId] = {};
    }

    rubricSelections[itemId][criterionId] =
        optionId;
}

function isRubricOptionSelected(
    itemId: string,
    criterionId: string,
    optionId: string,
): boolean {
    return (
        rubricSelections[itemId]?.[
            criterionId
        ] === optionId
    );
}

function getRubricOptionPoints(
    optionId: string | null,
): number {
    if (!optionId || !selectedAssessment.value) {
        return 0;
    }

    for (const criterion of selectedAssessment.value
        .rubric_criteria) {
        const option = criterion.options.find(
            (candidate) =>
                candidate.id === optionId,
        );

        if (option) {
            return Number(option.points);
        }
    }

    return 0;
}

async function reloadCurrentPage(): Promise<void> {
    await loadAssessments(currentPage.value);

    if (
        assessments.value.length === 0 &&
        currentPage.value > 1
    ) {
        await loadAssessments(
            currentPage.value - 1,
        );
    }
}

function getAvatarImage(
    row: DataTableRow,
): string | undefined {
    const gender = String(
        row.gender ?? '',
    )
        .trim()
        .toUpperCase();

    if (gender === 'M' || gender === 'MALE') {
        return '/images/male-cadet.png';
    }

    if (gender === 'F' || gender === 'FEMALE') {
        return '/images/female-cadet.png';
    }

    return undefined;
}

function getInitials(
    row: DataTableRow,
): string {
    const name = String(
        row.student_name ?? '',
    )
        .replace(',', ' ')
        .trim();

    const words = name
        .split(/\s+/)
        .filter(Boolean);

    return (
        words
            .slice(0, 2)
            .map((word) => word.charAt(0))
            .join('')
            .toUpperCase() || 'ST'
    );
}

function getDepartmentSeverity(
    department: unknown,
): 'info' | 'success' | 'secondary' {
    const value = String(
        department ?? '',
    ).toUpperCase();

    if (value.includes('ENGINE')) {
        return 'info';
    }

    if (value.includes('DECK')) {
        return 'success';
    }

    return 'secondary';
}

function getDepartmentIcon(
    department: unknown,
): string {
    const value = String(
        department ?? '',
    ).toUpperCase();

    if (value.includes('ENGINE')) {
        return 'pi pi-cog';
    }

    if (value.includes('DECK')) {
        return 'pi pi-compass';
    }

    return 'pi pi-building';
}

function getGradeSystemSeverity(
    gradeSystem: unknown,
): 'info' | 'success' | 'warn' {
    const value = String(
        gradeSystem ?? '',
    ).toLowerCase();

    if (value === 'points') {
        return 'info';
    }

    if (value === 'rubrics') {
        return 'success';
    }

    return 'warn';
}

function formatDate(
    value: unknown,
): string {
    const rawValue = String(
        value ?? '',
    ).trim();

    if (
        !rawValue ||
        rawValue === '1970-01-01' ||
        rawValue === '0000-00-00'
    ) {
        return '—';
    }

    const date = new Date(
        rawValue.includes('T')
            ? rawValue
            : rawValue.replace(' ', 'T'),
    );

    if (Number.isNaN(date.getTime())) {
        return rawValue;
    }

    return new Intl.DateTimeFormat(
        'en-PH',
        {
            timeZone: 'Asia/Manila',
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        },
    ).format(date);
}

function openFile(
    file: RemoteFile | null,
): void {
    if (!file?.url) {
        return;
    }

    window.open(
        file.url,
        '_blank',
        'noopener,noreferrer',
    );
}

function getFileIcon(
    filename: string,
): string {
    const extension =
        filename
            .split('.')
            .pop()
            ?.toLowerCase() ?? '';

    if (extension === 'pdf') {
        return 'pi pi-file-pdf';
    }

    if (
        extension === 'doc' ||
        extension === 'docx'
    ) {
        return 'pi pi-file-word';
    }

    if (
        extension === 'xls' ||
        extension === 'xlsx'
    ) {
        return 'pi pi-file-excel';
    }

    if (
        extension === 'jpg' ||
        extension === 'jpeg' ||
        extension === 'png' ||
        extension === 'webp'
    ) {
        return 'pi pi-image';
    }

    return 'pi pi-file';
}

function getErrorMessage(
    error: unknown,
    fallback: string,
): string {
    if (!axios.isAxiosError(error)) {
        return fallback;
    }

    const responseData =
        error.response?.data as
            | {
                  message?: string;

                  errors?: Record<
                      string,
                      string[]
                  >;
              }
            | undefined;

    const validationMessage =
        responseData?.errors
            ? Object.values(
                  responseData.errors,
              )[0]?.[0]
            : null;

    return (
        validationMessage ||
        responseData?.message ||
        fallback
    );
}

onMounted(() => {
    void loadAssessments(1);
});

onBeforeUnmount(() => {
    requestController?.abort();
});
</script>

<template>
    <Head
        title="Practical Assessments - Internal"
    />

    <div
        class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5"
    >
        <Message
            v-if="successMessage"
            severity="success"
            closable
            @close="successMessage = ''"
        >
            {{ successMessage }}
        </Message>

        <Message
            v-if="errorMessage"
            severity="error"
            closable
            @close="errorMessage = ''"
        >
            {{ errorMessage }}
        </Message>

        <Datatable
            title="Practical Assessments - Internal"
            description="Review and grade practical assessments submitted by enrolled students."
            header-icon="pi pi-clipboard"
            search-placeholder="Search practical assessments..."
            empty-title="No assessments for grading"
            empty-description="There are no enrolled practical assessments waiting to be graded."
            empty-icon="pi pi-clipboard"
            table-min-width="1200px"
            actions-header="Actions"
            actions-width="100px"
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
            @page="handlePage"
            @sort="handleSort"
            @search="handleSearch"
            @action="handleAction"
        >
            <template
                #cell-student_name="{ data }"
            >
                <div
                    class="flex items-center gap-3"
                >
                    <Avatar
                        :image="
                            getAvatarImage(data)
                        "
                        :label="
                            getAvatarImage(data)
                                ? undefined
                                : getInitials(data)
                        "
                        shape="circle"
                        size="large"
                        class="shrink-0 bg-blue-50 font-semibold text-blue-600"
                    />

                    <div class="min-w-0">
                        <p
                            class="truncate font-semibold text-slate-700 uppercase"
                        >
                            {{
                                data.student_name ||
                                '—'
                            }}
                        </p>

                        <div
                            class="mt-1 flex flex-nowrap items-center gap-1.5"
                        >
                            <PrimeTag
                                :value="
                                    data.school_id_no ||
                                    'No School ID'
                                "
                                icon="pi pi-id-card"
                                severity="info"
                                rounded
                                class="shrink-0 !px-2 !py-0.5 !text-xs !font-semibold !whitespace-nowrap"
                            />

                            <PrimeTag
                                v-if="data.dept"
                                :value="
                                    String(
                                        data.dept,
                                    ).toUpperCase()
                                "
                                :icon="
                                    getDepartmentIcon(
                                        data.dept,
                                    )
                                "
                                :severity="
                                    getDepartmentSeverity(
                                        data.dept,
                                    )
                                "
                                rounded
                                class="shrink-0 !px-2 !py-0.5 !text-xs !font-semibold !whitespace-nowrap"
                            />
                        </div>
                    </div>
                </div>
            </template>

            <template
                #cell-title_assess="{ data }"
            >
                <div class="space-y-1.5">
                    <p
                        class="font-semibold text-slate-700"
                    >
                        <i
                            class="pi pi-clipboard mr-1 text-blue-500"
                        ></i>

                        {{
                            data.title_assess ||
                            'Untitled Assessment'
                        }}
                    </p>

                    <PrimeTag
                        value="Submitted for assessment"
                        icon="pi pi-check-circle"
                        severity="success"
                        rounded
                        class="!px-2 !py-0.5 !text-xs"
                    />
                </div>
            </template>

            <template
                #cell-date_taken="{ data }"
            >
                <div class="space-y-2">
                    <div
                        class="flex items-center gap-2"
                    >
                        <PrimeTag
                            value="Taken"
                            severity="info"
                            class="w-14 !justify-center !px-2 !py-0.5 !text-xs"
                        />

                        <span
                            class="whitespace-nowrap text-sm text-slate-600"
                        >
                            {{
                                formatDate(
                                    data.date_taken,
                                )
                            }}
                        </span>
                    </div>

                    <div
                        class="flex items-center gap-2"
                    >
                        <PrimeTag
                            value="Due"
                            severity="warn"
                            class="w-14 !justify-center !px-2 !py-0.5 !text-xs"
                        />

                        <span
                            class="whitespace-nowrap text-sm text-slate-600"
                        >
                            {{
                                formatDate(
                                    data.due_date,
                                )
                            }}
                        </span>
                    </div>
                </div>
            </template>

            <template
                #cell-grade_system="{ value }"
            >
                <PrimeTag
                    :value="
                        String(
                            value ||
                            'Checklist',
                        ).toUpperCase()
                    "
                    icon="pi pi-star"
                    :severity="
                        getGradeSystemSeverity(
                            value,
                        )
                    "
                    rounded
                />
            </template>
        </Datatable>

        <Dialog
            v-model:visible="
                gradingDialogVisible
            "
            modal
            maximizable
            header="Practical Assessment Grading"
            :closable="!saving"
            :dismissable-mask="false"
            class="w-[min(96vw,1280px)]"
            @hide="closeGradingDialog"
        >
            <div
                v-if="detailsLoading"
                class="flex min-h-64 items-center justify-center"
            >
                <i
                    class="pi pi-spin pi-spinner text-3xl text-blue-500"
                ></i>
            </div>

            <div
                v-else-if="selectedAssessment"
                class="space-y-5"
            >
                <Message
                    v-if="gradingError"
                    severity="error"
                    closable
                    @close="gradingError = ''"
                >
                    {{ gradingError }}
                </Message>

                <div
                    class="grid gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4 md:grid-cols-2"
                >
                    <div>
                        <p
                            class="text-xs font-semibold text-slate-400 uppercase"
                        >
                            Student
                        </p>

                        <p
                            class="mt-1 font-bold text-slate-700 uppercase"
                        >
                            {{
                                selectedAssessment
                                    .student.name
                            }}
                        </p>

                        <div
                            class="mt-2 flex flex-nowrap items-center gap-1.5"
                        >
                            <PrimeTag
                                :value="
                                    selectedAssessment
                                        .student
                                        .school_id_no ||
                                    'No School ID'
                                "
                                icon="pi pi-id-card"
                                severity="info"
                                rounded
                            />

                            <PrimeTag
                                v-if="
                                    selectedAssessment
                                        .student.dept
                                "
                                :value="
                                    String(
                                        selectedAssessment
                                            .student
                                            .dept,
                                    ).toUpperCase()
                                "
                                :icon="
                                    getDepartmentIcon(
                                        selectedAssessment
                                            .student
                                            .dept,
                                    )
                                "
                                :severity="
                                    getDepartmentSeverity(
                                        selectedAssessment
                                            .student
                                            .dept,
                                    )
                                "
                                rounded
                            />
                        </div>
                    </div>

                    <div
                        class="md:text-right"
                    >
                        <p
                            class="text-xs font-semibold text-slate-400 uppercase"
                        >
                            Due Date
                        </p>

                        <p
                            class="mt-1 font-semibold text-slate-700"
                        >
                            {{
                                formatDate(
                                    selectedAssessment.due_date,
                                )
                            }}
                        </p>

                        <PrimeTag
                            class="mt-2"
                            :value="
                                selectedAssessment.is_completed
                                    ? 'Graded'
                                    : 'Submitted for assessment'
                            "
                            :severity="
                                selectedAssessment.is_completed
                                    ? 'info'
                                    : 'success'
                            "
                            :icon="
                                selectedAssessment.is_completed
                                    ? 'pi pi-check-circle'
                                    : 'pi pi-clock'
                            "
                            rounded
                        />
                    </div>
                </div>

                <div
                    class="rounded-xl border border-slate-200 p-4"
                >
                    <p
                        class="text-xs font-semibold text-slate-400 uppercase"
                    >
                        Practical Assessment
                    </p>

                    <p
                        class="mt-1 text-lg font-bold text-slate-700"
                    >
                        {{
                            selectedAssessment.title
                        }}
                    </p>

                    <p
                        class="mt-4 text-xs font-semibold text-slate-400 uppercase"
                    >
                        Instructions
                    </p>

                    <p
                        class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-600"
                    >
                        {{
                            selectedAssessment.instructions ||
                            'No instructions provided.'
                        }}
                    </p>
                </div>

                <div
                    v-if="
                        selectedAssessment
                            .reference_files.length
                    "
                    class="rounded-xl border border-slate-200 p-4"
                >
                    <p
                        class="mb-3 text-sm font-semibold text-slate-700"
                    >
                        Assessment Reference Files
                    </p>

                    <div
                        class="flex flex-wrap gap-2"
                    >
                        <Button
                            v-for="file in selectedAssessment.reference_files"
                            :key="file.name"
                            type="button"
                            :label="file.name"
                            :icon="
                                getFileIcon(
                                    file.name,
                                )
                            "
                            severity="info"
                            size="small"
                            outlined
                            @click="
                                openFile(file)
                            "
                        />
                    </div>
                </div>

                <div
                    class="overflow-x-auto rounded-xl border border-slate-200"
                >
                    <table
                        class="w-full min-w-[980px] border-collapse"
                    >
                        <thead
                            class="bg-slate-700 text-left text-sm text-white"
                        >
                            <tr>
                                <th class="w-14 p-3">
                                    #
                                </th>

                                <th
                                    class="min-w-[250px] p-3"
                                >
                                    Graded Item
                                </th>

                                <th
                                    class="min-w-[300px] p-3"
                                >
                                    Student Answer
                                </th>

                                <th
                                    class="min-w-[320px] p-3"
                                >
                                    Grade
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="(
                                    item,
                                    index
                                ) in selectedAssessment.items"
                                :key="item.id"
                                class="border-t border-slate-200 align-top"
                            >
                                <td
                                    class="p-3 font-semibold text-slate-500"
                                >
                                    {{ index + 1 }}
                                </td>

                                <td class="p-3">
                                    <p
                                        class="whitespace-pre-line font-medium text-slate-700"
                                    >
                                        {{
                                            item.description ||
                                            '—'
                                        }}
                                    </p>

                                    <Button
                                        type="button"
                                        :label="
                                            item.reference_file
                                                ? 'Reference file'
                                                : 'No reference file'
                                        "
                                        :icon="
                                            item.reference_file
                                                ? getFileIcon(
                                                      item
                                                          .reference_file
                                                          .name,
                                                  )
                                                : 'pi pi-file'
                                        "
                                        :severity="
                                            item.reference_file
                                                ? 'info'
                                                : 'secondary'
                                        "
                                        size="small"
                                        outlined
                                        class="mt-3"
                                        :disabled="
                                            !item.reference_file
                                        "
                                        @click="
                                            openFile(
                                                item.reference_file,
                                            )
                                        "
                                    />
                                </td>

                                <td class="p-3">
                                    <p
                                        class="whitespace-pre-line text-sm leading-6 text-slate-600"
                                    >
                                        {{
                                            item.answer ||
                                            'No written answer.'
                                        }}
                                    </p>

                                    <Button
                                        type="button"
                                        :label="
                                            item.evidence_file
                                                ? 'View evidence'
                                                : 'No evidence file'
                                        "
                                        :icon="
                                            item.evidence_file
                                                ? getFileIcon(
                                                      item
                                                          .evidence_file
                                                          .name,
                                                  )
                                                : 'pi pi-download'
                                        "
                                        :severity="
                                            item.evidence_file
                                                ? 'info'
                                                : 'secondary'
                                        "
                                        size="small"
                                        outlined
                                        class="mt-3"
                                        :disabled="
                                            !item.evidence_file
                                        "
                                        @click="
                                            openFile(
                                                item.evidence_file,
                                            )
                                        "
                                    />
                                </td>

                                <td class="p-3">
                                    <template
                                        v-if="
                                            selectedAssessment.is_completed
                                        "
                                    >
                                        <div
                                            class="flex min-h-20 items-center justify-center"
                                        >
                                            <PrimeTag
                                                :value="`${item.points} points`"
                                                severity="info"
                                                rounded
                                                class="!px-4 !py-2 !text-base"
                                            />
                                        </div>
                                    </template>

                                    <template
                                        v-else-if="
                                            normalizedGradeSystem ===
                                            'points'
                                        "
                                    >
                                        <label
                                            :for="`points-${item.id}`"
                                            class="mb-2 block text-xs font-semibold text-slate-500"
                                        >
                                            Maximum points:
                                            {{
                                                item.maximum_points
                                            }}
                                        </label>

                                        <Select
                                            :id="`points-${item.id}`"
                                            v-model="
                                                pointGrades[
                                                    item.id
                                                ]
                                            "
                                            :options="
                                                getPointOptions(
                                                    item.maximum_points,
                                                )
                                            "
                                            option-label="label"
                                            option-value="value"
                                            placeholder="Select grade"
                                            fluid
                                        />
                                    </template>

                                    <template
                                        v-else-if="
                                            normalizedGradeSystem ===
                                            'checklist'
                                        "
                                    >
                                        <label
                                            class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 p-4"
                                        >
                                            <Checkbox
                                                v-model="
                                                    checklistGrades[
                                                        item.id
                                                    ]
                                                "
                                                binary
                                            />

                                            <span
                                                class="font-medium text-slate-700"
                                            >
                                                Mark item as
                                                completed
                                            </span>
                                        </label>
                                    </template>

                                    <template v-else>
                                        <div
                                            class="space-y-4"
                                        >
                                            <div
                                                v-for="criterion in selectedAssessment.rubric_criteria"
                                                :key="
                                                    criterion.id
                                                "
                                            >
                                                <p
                                                    class="font-semibold text-slate-700"
                                                >
                                                    {{
                                                        criterion.title
                                                    }}
                                                </p>

                                                <p
                                                    v-if="
                                                        criterion.description
                                                    "
                                                    class="mt-1 text-xs text-slate-500"
                                                >
                                                    {{
                                                        criterion.description
                                                    }}
                                                </p>

                                                <div
                                                    class="mt-2 flex flex-wrap gap-2"
                                                >
                                                    <Button
                                                        v-for="option in criterion.options"
                                                        :key="
                                                            option.id
                                                        "
                                                        type="button"
                                                        :label="`${option.title} (${option.points})`"
                                                        :severity="
                                                            isRubricOptionSelected(
                                                                item.id,
                                                                criterion.id,
                                                                option.id,
                                                            )
                                                                ? 'success'
                                                                : 'secondary'
                                                        "
                                                        size="small"
                                                        :outlined="
                                                            !isRubricOptionSelected(
                                                                item.id,
                                                                criterion.id,
                                                                option.id,
                                                            )
                                                        "
                                                        @click="
                                                            selectRubricOption(
                                                                item.id,
                                                                criterion.id,
                                                                option.id,
                                                            )
                                                        "
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </td>
                            </tr>

                            <tr
                                v-if="
                                    selectedAssessment
                                        .items.length === 0
                                "
                            >
                                <td
                                    colspan="4"
                                    class="p-8 text-center text-slate-500"
                                >
                                    No grading items
                                    were found.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    class="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:grid-cols-4"
                >
                    <div>
                        <p
                            class="text-xs font-semibold text-slate-400 uppercase"
                        >
                            Total Points
                        </p>

                        <p
                            class="mt-1 font-bold text-slate-700"
                        >
                            {{
                                displayedEarnedPoints
                            }}
                            /
                            {{
                                displayedMaximumPoints
                            }}
                        </p>
                    </div>

                    <div>
                        <p
                            class="text-xs font-semibold text-slate-400 uppercase"
                        >
                            Percentage
                        </p>

                        <p
                            class="mt-1 font-bold text-slate-700"
                        >
                            {{
                                displayedPercentage
                            }}%
                        </p>
                    </div>

                    <div>
                        <p
                            class="text-xs font-semibold text-slate-400 uppercase"
                        >
                            Passing Mark
                        </p>

                        <p
                            class="mt-1 font-bold text-slate-700"
                        >
                            {{
                                selectedAssessment.passing_mark
                            }}%
                        </p>
                    </div>

                    <div>
                        <p
                            class="text-xs font-semibold text-slate-400 uppercase"
                        >
                            Remarks
                        </p>

                        <PrimeTag
                            :value="
                                displayedRemarks
                            "
                            :severity="
                                displayedRemarks ===
                                'PASS'
                                    ? 'success'
                                    : 'danger'
                            "
                            rounded
                            class="mt-1"
                        />
                    </div>
                </div>
            </div>

            <template #footer>
                <Button
                    type="button"
                    label="Close"
                    icon="pi pi-times"
                    severity="secondary"
                    outlined
                    :disabled="saving"
                    @click="
                        closeGradingDialog
                    "
                />

                <Button
                    v-if="
                        selectedAssessment &&
                        !selectedAssessment.is_completed
                    "
                    type="button"
                    label="Save Assessment"
                    icon="pi pi-save"
                    severity="success"
                    :loading="saving"
                    :disabled="
                        saving ||
                        selectedAssessment.items
                            .length === 0
                    "
                    @click="saveGrades"
                />
            </template>
        </Dialog>
    </div>
</template>