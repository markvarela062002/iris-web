<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import AutoComplete from 'primevue/autocomplete';
import Avatar from 'primevue/avatar';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Select from 'primevue/select';
import PrimeTag from 'primevue/tag';
import InputNumber from 'primevue/inputnumber';
import { onMounted, ref } from 'vue';
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
                title: 'Theoretical Internal',
                href: '/dashboard/theoretical-internal',
            },
        ],
    },
});
type Option = {
    id: string;
    label: string;
};
type StudentOption = {
    id: string;
    student_name: string;
    school_id_no?: string | null;
    code_person?: string | null;
    fname?: string | null;
    mname?: string | null;
    lname?: string | null;
    gender?: string | null;
    dept?: string | null;
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
        field: 'student_name',
        header: 'Student Information',
        sortable: false,
        searchable: true,
        frozen: true,
        class: 'min-w-[290px]',
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
        sortable: false,
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
const actions: DataTableAction[] = [
    /*
     * Certificate available.
     */
    {
        key: 'certificate',
        label: 'Download Certificate',
        icon: 'pi pi-download',
        severity: 'info',
        visible: (row) =>
            row.is_completed === true,
    },
    /*
     * Certificate unavailable fallback.
     *
     * No severity means PrimeVue's default primary severity.
     */
    {
        key: 'certificate-unavailable',
        label: 'No Certificate Available',
        icon: 'pi pi-download',
        visible: (row) =>
            row.is_completed !== true,
        disabled: () => true,
    },
    /*
     * Answers available.
     */
    {
        key: 'answers',
        label: 'View Answers',
        icon: 'pi pi-eye',
        severity: 'danger',
        visible: (row) =>
            row.is_completed === true,
    },
    /*
     * Answers unavailable fallback.
     *
     * No severity means PrimeVue's default primary severity.
     */
    {
        key: 'answers-unavailable',
        label: 'No Answers Available',
        icon: 'pi pi-eye',
        visible: (row) =>
            row.is_completed !== true,
        disabled: () => true,
    },
    /*
     * Edit is available for every assessment.
     */
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
| Formatting helpers
|--------------------------------------------------------------------------
*/
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
    row: DataTableRow | StudentOption,
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
function getAvatarImage(
    row: DataTableRow | StudentOption,
): string {
    const gender = String(row.gender ?? '').trim().toUpperCase();
    if (gender === 'M' || gender === 'MALE') {
        return '/images/male-cadet.png';
    }
    if (gender === 'F' || gender === 'FEMALE') {
        return '/images/female-cadet.png';
    }
    return '/images/default-cadet.png';
}
/*
|--------------------------------------------------------------------------
| Department helpers
|--------------------------------------------------------------------------
*/
function normalizeDepartment(
    department: unknown,
): string {
    return String(
        department ?? '',
    )
        .trim()
        .toUpperCase();
}
function getDepartmentSeverity(
    department: unknown,
): TagSeverity {
    const value = normalizeDepartment(
        department,
    );
    if (value === 'DECK') {
        return 'success';
    }
    if (value === 'ENGINE') {
        return 'info';
    }
    return 'secondary';
}
function getDepartmentIcon(
    department: unknown,
): string {
    const value = normalizeDepartment(
        department,
    );
    if (value === 'DECK') {
        return 'pi pi-compass';
    }
    if (value === 'ENGINE') {
        return 'pi pi-cog';
    }
    return 'pi pi-building';
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
function buildParameters(page: number): Record<string, unknown> {
    return {
        page,
        per_page: rows.value,
        search: search.value,
        sort_field: sortField.value,
        sort_direction: sortDirection.value,
    };
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
            '/api/v1/dashboard/datatable/theoretical-assessments',
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
                'Unable to load theoretical assessments.';
        } else {
            pageError.value =
                'Unable to load theoretical assessments.';
        }
    } finally {
        loading.value = false;
    }
}
async function loadOptions(): Promise<void> {
    try {
        const response = await axios.get(
            '/api/v1/dashboard/theoretical-assessments/options',
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
            `/api/v1/dashboard/theoretical-assessments/${assessment.id}`,
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
     * Ignore fallback actions even if an event
     * is triggered programmatically.
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
            `/dashboard/theoretical-assessments/${assessment.id}/certificate`,
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
        void openEdit(assessment);
    }
}
/*
|--------------------------------------------------------------------------
| Lifecycle
|--------------------------------------------------------------------------
*/
type CreateForm = {
    student: StudentOption | null;
    course: Option | null;
    session: Option | null;
    examType: 'New' | 'Resit' | null;
    duration: number;
    proctorName: string;
    accessDate: string;
    accessTime: string;
    accessDateTo: string;
    accessTimeTo: string;
};
type EditableAssessment = {
    id: string;
    person_id: string;
    student_name: string;
    school_id_no?: string | null;
    bs_course_id: string;
    name_course: string;
    bs_exam_session_id: string;
    exam_type: 'New' | 'Resit';
    duration: number | string | null;
    proctor_name: string | null;
    access_exp_date: string | null;
    access_exp_time: string | null;
    access_exp_date_to: string | null;
    access_exp_time_to: string | null;
    started: string | null;
    ended: string | null;
    done: string | null;
};

const editingId = ref<string | null>(null);
const editLoading = ref(false);
const editLoadFailed = ref(false);
const createVisible = ref(false);

const createSaving = ref(false);
const createError = ref('');
const createSuccess = ref('');
const createErrors = ref<Record<string, string>>({});
const createStudentSuggestions = ref<StudentOption[]>([]);
const examTypeOptions = [
    { label: 'New', value: 'New' },
    { label: 'Resit', value: 'Resit' },
];
const createForm = ref<CreateForm>({
    student: null,
    course: null,
    session: null,
    examType: null,
    duration: 0,
    proctorName: '',
    accessDate: '',
    accessTime: '08:00',
    accessDateTo: '',
    accessTimeTo: '23:30',
});
function openCreate(): void {
    editingId.value = null;
    editLoading.value = false;
    editLoadFailed.value = false;
    createSuccess.value = '';
    createForm.value = {
        student: null,
        course: null,
        session: null,
        examType: null,
        duration: 0,
        proctorName: '',
        accessDate: '',
        accessTime: '08:00',
        accessDateTo: '',
        accessTimeTo: '23:30',
    };
    createStudentSuggestions.value = [];
    createErrors.value = {};
    createError.value = '';
    createVisible.value = true;
}
async function openEdit(assessment: DataTableRow): Promise<void> {
    if (createSaving.value) return;

    openCreate();
    const id = String(assessment.id);
    editingId.value = id;
    editLoading.value = true;

    try {
        if (!courses.value.length || !sessions.value.length) {
            await loadOptions();
        }
        const response = await axios.get<{ assessment: EditableAssessment }>(
            `/api/v1/dashboard/theoretical-assessments/${encodeURIComponent(id)}`,
            { withCredentials: true },
        );
        if (!createVisible.value || editingId.value !== id) return;
        const record = response.data.assessment;

        createForm.value = {
            student: {
                id: record.person_id,
                student_name: record.student_name,
                school_id_no: record.school_id_no,
            },
            course: courses.value.find((option) => option.id === record.bs_course_id)
                ?? { id: record.bs_course_id, label: record.name_course },
            session: sessions.value.find((option) => option.id === record.bs_exam_session_id) ?? null,
            examType: record.exam_type,
            duration: Number(record.duration ?? 0),
            proctorName: record.proctor_name ?? '',
            accessDate: (record.access_exp_date ?? '').slice(0, 10),
            accessTime: (record.access_exp_time ?? '').slice(0, 5),
            accessDateTo: (record.access_exp_date_to ?? '').slice(0, 10),
            accessTimeTo: (record.access_exp_time_to ?? '').slice(0, 5),
        };
    } catch (error: unknown) {
        if (!createVisible.value || editingId.value !== id) return;
        editLoadFailed.value = true;
        createError.value = axios.isAxiosError(error)
            ? error.response?.data?.message ?? 'The assessment could not be loaded.'
            : error instanceof Error ? error.message : 'The assessment could not be loaded.';
    } finally {
        if (editingId.value === id) editLoading.value = false;
    }
}

async function searchCreateStudents(event: { query: string }): Promise<void> {
    const query = event.query.trim();
    if (query.length < 2) {
        createStudentSuggestions.value = [];
        return;
    }
    try {
        const response = await axios.get(
            '/api/v1/dashboard/theoretical-assessments/students',
            {
                params: { search: query },
                withCredentials: true,
            },
        );
        createStudentSuggestions.value = response.data.data ?? [];
    } catch {
        createStudentSuggestions.value = [];
    }
}
async function saveAssessment(): Promise<void> {
    if (createSaving.value || editLoading.value || editLoadFailed.value) return;
    createError.value = '';
    createErrors.value = {};
    const form = createForm.value;
    if (!form.student?.id) {
        createErrors.value.person_id = 'Student Name is required.';
    }
    if (!form.course?.id) {
        createErrors.value.bs_course_id = 'Exam Package is required.';
    }
    if (!form.session?.id) {
        createErrors.value.bs_exam_session_id = 'Session is required.';
    }
    if (!form.examType) {
        createErrors.value.exam_type = 'Exam Type is required.';
    }
    if (!form.accessDate) {
        createErrors.value.access_exp_date = 'Access Date From is required.';
    }
    if (!form.accessTime) {
        createErrors.value.access_exp_time = 'Access Start Time is required.';
    }
    if (!form.accessDateTo) {
        createErrors.value.access_exp_date_to = 'Access Until Date is required.';
    }
    if (!form.accessTimeTo) {
        createErrors.value.access_exp_time_to = 'Access End Time is required.';
    }
    if (Object.keys(createErrors.value).length > 0) {
        return;
    }
    createSaving.value = true;
    try {
        const response = await axios.request({
            method: editingId.value ? 'put' : 'post',
            url: editingId.value
                ? `/api/v1/dashboard/theoretical-assessments/${encodeURIComponent(editingId.value)}`
                : '/api/v1/dashboard/theoretical-assessments',
            data: {
                person_id: form.student!.id,
                bs_course_id: form.course!.id,
                bs_exam_session_id: form.session!.id,
                exam_type: form.examType,
                duration: form.duration ?? 0,
                proctor_name: form.proctorName.trim(),
                access_exp_date: form.accessDate,
                access_exp_time: form.accessTime,
                access_exp_date_to: form.accessDateTo,
                access_exp_time_to: form.accessTimeTo,
            },
            withCredentials: true,
        });
        createVisible.value = false;
        createSuccess.value =
            response.data.message ?? 'The assessment has been saved.';
        first.value = 0;
        await loadAssessments(1);
    } catch (error: unknown) {
        if (axios.isAxiosError(error)) {
            const errors = error.response?.data?.errors;
            if (errors && typeof errors === 'object') {
                createErrors.value = Object.fromEntries(
                    Object.entries(errors).map(([key, value]) => [
                        key,
                        Array.isArray(value)
                            ? String(value[0] ?? '')
                            : String(value),
                    ]),
                );
            }
            createError.value =
                error.response?.data?.message ??
                'The assessment could not be saved.';
        } else {
            createError.value = 'The assessment could not be saved.';
        }
    } finally {
        createSaving.value = false;
    }
}
onMounted(() => {
    void Promise.all([
        loadOptions(),
        loadAssessments(),
    ]);
});
</script>
<template>
    <Head
        title="Theoretical Assessments - Internal (Enrolled)"
    />
    <div
        class="flex min-h-0 flex-1 flex-col gap-4 p-4"
    >
        <Message
            v-if="createSuccess"
            severity="success"
            closable
            @close="createSuccess = ''"
        >
            {{ createSuccess }}
        </Message>
        <Message
            v-if="pageError"
            severity="error"
            closable
            @close="pageError = ''"
        >
            {{ pageError }}
        </Message>
        <!-- DATATABLE -->
        <Datatable
            title="Theoretical Assessments - Internal (Enrolled)"
            description="Review enrolled students, examination results, answers, and certificates."
            header-icon="pi pi-clipboard"
            search-placeholder="Search assessments..."
            empty-title="No assessments found"
            empty-description="No enrolled assessments match your search."
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
            <template #header-actions>
                <Button
                    type="button"
                    label="Add New Record"
                    icon="pi pi-plus"
                    @click="openCreate"
                />
            </template>
            <!-- STUDENT INFORMATION -->
            <template
                #cell-student_name="{
                    data,
                }"
            >
                <div
                    class="flex items-center gap-3"
                >
                    <Avatar
                        v-if="
                            getAvatarImage(
                                data,
                            )
                        "
                        :image="
                            getAvatarImage(
                                data,
                            )
                        "
                        :aria-label="
                            uppercaseValue(
                                data.student_name,
                                'Student',
                            )
                        "
                        shape="circle"
                        size="large"
                        class="shrink-0"
                    />
                    <Avatar
                        v-else
                        :label="
                            getInitials(
                                data,
                            )
                        "
                        :aria-label="
                            uppercaseValue(
                                data.student_name,
                                'Student',
                            )
                        "
                        shape="circle"
                        size="large"
                        class="shrink-0 bg-blue-50 text-blue-600"
                    />
                    <div class="min-w-0">
                        <p
                            class="truncate font-semibold uppercase text-slate-700"
                        >
                            {{
                                uppercaseValue(
                                    data.student_name,
                                    'NO STUDENT NAME',
                                )
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
                                class="shrink-0 !whitespace-nowrap !px-2 !py-0.5 !text-xs !font-semibold"
                            />
                            <PrimeTag
                                v-if="
                                    data.dept
                                "
                                :value="
                                    normalizeDepartment(
                                        data.dept,
                                    )
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
                    <!-- SESSION AND TYPE -->
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
<!-- ADD / EDIT ASSESSMENT DIALOG -->
<Dialog
    v-model:visible="createVisible"
    modal
    :draggable="false"
    :header="editingId ? 'Edit Theoretical Assessment' : 'Add Theoretical Assessment'"
    :closable="!createSaving"
    :close-on-escape="!createSaving"
    class="w-[95vw] max-w-3xl"
>
            <div
                class="mb-4 flex items-center gap-2 rounded-xl border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900"
                role="note"
            >
                <i class="pi pi-info-circle" aria-hidden="true"></i>
                <span
                    >Note: Fields marked with
                    <span class="font-semibold text-red-500">*</span> are
                    required fields.</span
                >
            </div>
    <div v-if="editLoading" class="flex items-center justify-center gap-2 py-8" role="status">
        <i class="pi pi-spinner pi-spin" aria-hidden="true"></i>
        <span>Loading assessment...</span>
    </div>
    <div v-else-if="!editLoadFailed" class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div class="md:col-span-2">
            <label class="mb-2 block text-sm font-semibold text-slate-700">
                Student Name <span class="text-red-500">*</span>
            </label>
            <AutoComplete
                v-model="createForm.student"
                :disabled="editingId !== null"
                :suggestions="createStudentSuggestions"
                option-label="student_name"
                placeholder="Search student..."
                force-selection
                dropdown
                class="w-full"
                input-class="w-full"
                @complete="searchCreateStudents"
            />
            <small v-if="createErrors.person_id" class="text-red-500">
                {{ createErrors.person_id }}
            </small>
        </div>
        <div>
            <label class="mb-2 block text-sm font-semibold text-slate-700">
                Exam Package <span class="text-red-500">*</span>
            </label>
            <Select
                v-model="createForm.course"
                :options="courses"
                option-label="label"
                placeholder="Select exam package"
                class="w-full"
            />
            <small v-if="createErrors.bs_course_id" class="text-red-500">
                {{ createErrors.bs_course_id }}
            </small>
        </div>
        <div>
            <label class="mb-2 block text-sm font-semibold text-slate-700">
                Session <span class="text-red-500">*</span>
            </label>
            <Select
                v-model="createForm.session"
                :options="sessions"
                option-label="label"
                placeholder="Select session"
                class="w-full"
            />
            <small v-if="createErrors.bs_exam_session_id" class="text-red-500">
                {{ createErrors.bs_exam_session_id }}
            </small>
        </div>
        <div>
            <label class="mb-2 block text-sm font-semibold text-slate-700">
                Exam Type <span class="text-red-500">*</span>
            </label>
            <Select
                v-model="createForm.examType"
                :options="examTypeOptions"
                option-label="label"
                option-value="value"
                placeholder="Select exam type"
                class="w-full"
            />
            <small v-if="createErrors.exam_type" class="text-red-500">
                {{ createErrors.exam_type }}
            </small>
        </div>
        <div>
            <label class="mb-2 block text-sm font-semibold text-slate-700">
                Duration (minutes)
            </label>
            <InputNumber
                v-model="createForm.duration"
                :min="0"
                :max="1440"
                :use-grouping="false"
                fluid
            />
            <small class="text-slate-500">
                Enter 0 to calculate from the exam topics.
            </small>
            <small v-if="createErrors.duration" class="block text-red-500">
                {{ createErrors.duration }}
            </small>
        </div>
        <div class="md:col-span-2">
            <label class="mb-2 block text-sm font-semibold text-slate-700">
                Proctor Name
            </label>
            <InputText
                v-model="createForm.proctorName"
                class="w-full"
                placeholder="Enter proctor name"
            />
            <small v-if="createErrors.proctor_name" class="text-red-500">
                {{ createErrors.proctor_name }}
            </small>
        </div>
        <div>
            <label class="mb-2 block text-sm font-semibold text-slate-700">
                Access Date From <span class="text-red-500">*</span>
            </label>
            <InputText
                v-model="createForm.accessDate"
                type="date"
                class="w-full"
            />
            <small v-if="createErrors.access_exp_date" class="text-red-500">
                {{ createErrors.access_exp_date }}
            </small>
        </div>
        <div>
            <label class="mb-2 block text-sm font-semibold text-slate-700">
                Access Start Time <span class="text-red-500">*</span>
            </label>
            <InputText
                v-model="createForm.accessTime"
                type="time"
                step="1800"
                class="w-full"
            />
            <small v-if="createErrors.access_exp_time" class="text-red-500">
                {{ createErrors.access_exp_time }}
            </small>
        </div>
        <div>
            <label class="mb-2 block text-sm font-semibold text-slate-700">
                Access Until Date <span class="text-red-500">*</span>
            </label>
            <InputText
                v-model="createForm.accessDateTo"
                type="date"
                class="w-full"
            />
            <small v-if="createErrors.access_exp_date_to" class="text-red-500">
                {{ createErrors.access_exp_date_to }}
            </small>
        </div>
        <div>
            <label class="mb-2 block text-sm font-semibold text-slate-700">
                Access End Time <span class="text-red-500">*</span>
            </label>
            <InputText
                v-model="createForm.accessTimeTo"
                type="time"
                step="1800"
                class="w-full"
            />
            <small v-if="createErrors.access_exp_time_to" class="text-red-500">
                {{ createErrors.access_exp_time_to }}
            </small>
        </div>
    </div>
    <Message v-if="createError" severity="error" class="mt-4">
        {{ createError }}
    </Message>
    <template #footer>
        <Button
            type="button"
            label="Cancel"
            severity="secondary"
            variant="outlined"
            :disabled="createSaving"
            @click="createVisible = false"
        />
        <Button
            type="button"
            :label="editingId ? 'Save Changes' : 'Save Assessment'"
            :disabled="editLoading || editLoadFailed"
            icon="pi pi-save"
            :loading="createSaving"
            @click="saveAssessment"
        />
    </template>
</Dialog>
        <!-- ANSWERS DIALOG -->
        <Dialog
            v-model:visible="
                detailsVisible
            "
            modal
            maximizable
            header="Assessment Answers"
            class="w-[95vw] max-w-6xl"
            :draggable="false"
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
                            Student:
                        </strong>
                        {{
                            uppercaseValue(
                                selectedAssessment.student_name,
                            )
                        }}
                    </p>
                    <p>
                        <strong>
                            School ID:
                        </strong>
                        {{
                            selectedAssessment.school_id_no ||
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