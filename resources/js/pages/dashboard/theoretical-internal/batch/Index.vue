<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Avatar from 'primevue/avatar';
import Button from 'primevue/button';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import PrimeTag from 'primevue/tag';
import Select from 'primevue/select';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
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
                title: 'Theoretical Batch',
                href: '/dashboard/theoretical/batch',
            },
        ],
    },
});

type CourseOption = {
    id: string;
    label: string;
    duration: number;
};

type SessionOption = {
    id: string;
    label: string;
};

type StudentOption = {
    id: string;
    name: string;
    school_id_no: string;
    email: string;
    dept: string | null;
    gender: string | null;
    has_pending_exam: boolean;
};

type BatchDetails = {
    id: string;
    bs_course_id: string;
    bs_exam_session_id: string;
    duration: number;
    exam_type: string;
    proctor_name: string;
    access_exp_date_from: string;
    access_exp_time_from: string;
    access_exp_date_to: string;
    access_exp_time_to: string;
    students: StudentOption[];
    students_locked: boolean;
};

type BatchResponse = {
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

const form = reactive({
    id: '',
    bs_course_id: '',
    bs_exam_session_id: '',
    duration: 0,
    proctor_name: '',
    access_exp_date_from: '',
    access_exp_time_from: '08:00',
    access_exp_date_to: '',
    access_exp_time_to: '23:30',
});

const courses = ref<CourseOption[]>([]);
const sessions = ref<SessionOption[]>([]);
const timeOptions = ref<string[]>([]);

const selectedStudents = ref<StudentOption[]>([]);
const studentResults = ref<StudentOption[]>([]);
const studentSearch = ref('');

const batches = ref<DataTableRow[]>([]);

const loading = ref(false);
const optionsLoading = ref(false);
const studentLoading = ref(false);
const saving = ref(false);
const detailsLoading = ref(false);

const totalRecords = ref(0);
const first = ref(0);
const rows = ref(10);
const search = ref('');

const sortField = ref('last_update');
const sortDirection = ref<'asc' | 'desc'>(
    'desc',
);

const successMessage = ref('');
const errorMessage = ref('');
const formErrors = ref<
    Record<string, string>
>({});

const studentsLocked = ref(false);

let requestController:
    | AbortController
    | null = null;

let studentSearchTimer:
    | ReturnType<typeof setTimeout>
    | null = null;

const columns: DataTableColumn[] = [
    {
        field: 'name_course',
        header: 'Exam Package',
        sortable: true,
        searchable: true,
        class: 'min-w-[300px] whitespace-normal',
    },
    {
        field: 'session_code',
        header: 'Session and Schedule',
        sortable: true,
        searchable: true,
        class: 'min-w-[300px]',
    },
    {
        field: 'proctor_name',
        header: 'Proctor',
        sortable: true,
        searchable: true,
        class: 'min-w-[220px]',
    },
    {
        field: 'duration',
        header: 'Duration',
        sortable: true,
        searchable: false,
        class: 'min-w-[130px]',
    },
    {
        field: 'examinee_count',
        header: 'Examinees',
        sortable: false,
        searchable: false,
        class: 'min-w-[130px]',
    },
];

const actions: DataTableAction[] = [
    {
        key: 'edit',
        label: 'View or edit batch',
        icon: 'pi pi-pencil',
        severity: 'warn',
    },
];

const isEditing = computed(() => {
    return form.id !== '';
});

const selectedCourse = computed(() => {
    return courses.value.find(
        (course) =>
            course.id === form.bs_course_id,
    );
});

const currentPage = computed(() => {
    return (
        Math.floor(
            first.value / rows.value,
        ) + 1
    );
});

const canSearchStudents = computed(() => {
    return (
        !studentsLocked.value &&
        form.bs_course_id !== '' &&
        form.bs_exam_session_id !== '' &&
        form.access_exp_date_from !== '' &&
        form.access_exp_time_from !== '' &&
        form.access_exp_date_to !== '' &&
        form.access_exp_time_to !== ''
    );
});

watch(
    () => form.bs_course_id,
    () => {
        if (!isEditing.value) {
            form.duration =
                selectedCourse.value?.duration ?? 0;
            clearStudents();
        }
    },
);

watch(
    () => form.bs_exam_session_id,
    () => {
        if (!isEditing.value) {
            clearStudents();
        }
    },
);

watch(studentSearch, (value) => {
    if (studentSearchTimer) {
        clearTimeout(studentSearchTimer);
    }

    const normalized = value.trim();

    if (
        normalized === '' ||
        !canSearchStudents.value
    ) {
        studentResults.value = [];

        return;
    }

    studentSearchTimer = setTimeout(() => {
        void searchStudents();
    }, 350);
});

async function loadOptions(): Promise<void> {
    optionsLoading.value = true;

    try {
        const response = await axios.get<{
            data: {
                courses: CourseOption[];
                sessions: SessionOption[];
                times: string[];
            };
        }>(
            '/api/v1/dashboard/theoretical/batch/options',
            {
                headers: jsonHeaders(),
                withCredentials: true,
            },
        );

        courses.value =
            response.data.data.courses;

        sessions.value =
            response.data.data.sessions;

        timeOptions.value =
            response.data.data.times;
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(
            error,
            'Unable to load batch options.',
        );
    } finally {
        optionsLoading.value = false;
    }
}

async function loadBatches(
    pageNumber = 1,
): Promise<void> {
    requestController?.abort();

    const controller =
        new AbortController();

    requestController = controller;
    loading.value = true;

    try {
        const response =
            await axios.get<BatchResponse>(
                '/api/v1/dashboard/datatable/theoretical-batches',
                {
                    signal:
                        controller.signal,

                    params: {
                        page: pageNumber,
                        per_page: rows.value,
                        search: search.value,
                        sort_field:
                            sortField.value,
                        sort_direction:
                            sortDirection.value,
                    },

                    headers: jsonHeaders(),
                    withCredentials: true,
                },
            );

        batches.value =
            response.data.data;

        totalRecords.value =
            response.data.meta.total;

        rows.value =
            response.data.meta.perPage;

        first.value =
            (
                response.data.meta
                    .currentPage - 1
            ) *
            response.data.meta.perPage;
    } catch (error: unknown) {
        if (
            axios.isCancel(error) ||
            (
                axios.isAxiosError(
                    error,
                ) &&
                error.code ===
                    'ERR_CANCELED'
            )
        ) {
            return;
        }

        batches.value = [];
        totalRecords.value = 0;

        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to load examination batches.',
            );
    } finally {
        if (
            requestController ===
            controller
        ) {
            loading.value = false;
        }
    }
}

async function searchStudents(): Promise<void> {
    if (!canSearchStudents.value) {
        return;
    }

    studentLoading.value = true;

    try {
        const response = await axios.get<{
            data: StudentOption[];
        }>(
            '/api/v1/dashboard/theoretical/batch/students',
            {
                params: {
                    search:
                        studentSearch.value.trim(),

                    bs_course_id:
                        form.bs_course_id,

                    bs_exam_session_id:
                        form.bs_exam_session_id,
                },

                headers: jsonHeaders(),
                withCredentials: true,
            },
        );

        const selectedIds = new Set(
            selectedStudents.value.map(
                (student) => student.id,
            ),
        );

        studentResults.value =
            response.data.data.filter(
                (student) =>
                    !selectedIds.has(
                        student.id,
                    ),
            );
    } catch (error: unknown) {
        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to search students.',
            );
    } finally {
        studentLoading.value = false;
    }
}

function addStudent(
    student: StudentOption,
): void {
    errorMessage.value = '';

    if (studentsLocked.value) {
        return;
    }

    if (student.has_pending_exam) {
        errorMessage.value =
            'This student already has a pending exam with the same package and session.';

        return;
    }

    if (
        selectedStudents.value.some(
            (selected) =>
                selected.id === student.id,
        )
    ) {
        errorMessage.value =
            'This student is already in the batch.';

        return;
    }

    if (
        selectedStudents.value.length >= 40
    ) {
        errorMessage.value =
            'The number of examinees is limited to 40 per batch.';

        return;
    }

    selectedStudents.value.push(student);

    studentResults.value =
        studentResults.value.filter(
            (result) =>
                result.id !== student.id,
        );

    studentSearch.value = '';
}

function removeStudent(
    studentId: string,
): void {
    if (studentsLocked.value) {
        return;
    }

    selectedStudents.value =
        selectedStudents.value.filter(
            (student) =>
                student.id !== studentId,
        );
}

async function saveBatch(): Promise<void> {
    successMessage.value = '';
    errorMessage.value = '';
    formErrors.value = {};

    if (!validateForm()) {
        return;
    }

    saving.value = true;

    const payload = {
        bs_course_id:
            form.bs_course_id,

        bs_exam_session_id:
            form.bs_exam_session_id,

        duration: form.duration,
        proctor_name:
            form.proctor_name.trim(),

        access_exp_date_from:
            form.access_exp_date_from,

        access_exp_time_from:
            form.access_exp_time_from,

        access_exp_date_to:
            form.access_exp_date_to,

        access_exp_time_to:
            form.access_exp_time_to,

        student_ids:
            selectedStudents.value.map(
                (student) => student.id,
            ),
    };

    try {
        if (isEditing.value) {
            const response =
                await axios.patch<{
                    message: string;
                }>(
                    `/api/v1/dashboard/theoretical/batch/${encodeURIComponent(
                        form.id,
                    )}`,
                    {
                        duration:
                            payload.duration,

                        proctor_name:
                            payload.proctor_name,

                        access_exp_date_from:
                            payload.access_exp_date_from,

                        access_exp_time_from:
                            payload.access_exp_time_from,

                        access_exp_date_to:
                            payload.access_exp_date_to,

                        access_exp_time_to:
                            payload.access_exp_time_to,
                    },
                    {
                        headers:
                            jsonHeaders(),

                        withCredentials: true,
                    },
                );

            successMessage.value =
                response.data.message;
        } else {
            const response =
                await axios.post<{
                    message: string;

                    data: {
                        id: string;
                    };
                }>(
                    '/api/v1/dashboard/theoretical/batch',
                    payload,
                    {
                        headers:
                            jsonHeaders(),

                        withCredentials: true,
                    },
                );

            successMessage.value =
                response.data.message;

            await openBatch(
                response.data.data.id,
            );
        }

        await loadBatches(1);
    } catch (error: unknown) {
        setValidationErrors(error);

        errorMessage.value =
            getErrorMessage(
                error,
                'The examination batch could not be saved.',
            );
    } finally {
        saving.value = false;
    }
}

async function openBatch(
    batchId: string,
): Promise<void> {
    detailsLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await axios.get<{
            data: BatchDetails;
        }>(
            `/api/v1/dashboard/theoretical/batch/${encodeURIComponent(
                batchId,
            )}`,
            {
                headers: jsonHeaders(),
                withCredentials: true,
            },
        );

        const batch =
            response.data.data;

        form.id = batch.id;
        form.bs_course_id =
            batch.bs_course_id;

        form.bs_exam_session_id =
            batch.bs_exam_session_id;

        form.duration = batch.duration;
        form.proctor_name =
            batch.proctor_name;

        form.access_exp_date_from =
            batch.access_exp_date_from;

        form.access_exp_time_from =
            batch.access_exp_time_from;

        form.access_exp_date_to =
            batch.access_exp_date_to;

        form.access_exp_time_to =
            batch.access_exp_time_to;

        selectedStudents.value =
            batch.students;

        studentsLocked.value =
            batch.students_locked;

        studentSearch.value = '';
        studentResults.value = [];

        window.scrollTo({
            top: 0,
            behavior: 'smooth',
        });
    } catch (error: unknown) {
        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to load the selected batch.',
            );
    } finally {
        detailsLoading.value = false;
    }
}

function newBatch(): void {
    form.id = '';
    form.bs_course_id = '';
    form.bs_exam_session_id = '';
    form.duration = 0;
    form.proctor_name = '';
    form.access_exp_date_from = '';
    form.access_exp_time_from = '08:00';
    form.access_exp_date_to = '';
    form.access_exp_time_to = '23:30';

    selectedStudents.value = [];
    studentResults.value = [];
    studentSearch.value = '';

    studentsLocked.value = false;
    formErrors.value = {};
    errorMessage.value = '';
}

function clearStudents(): void {
    selectedStudents.value = [];
    studentResults.value = [];
    studentSearch.value = '';
}

function validateForm(): boolean {
    const errors: Record<string, string> =
        {};

    if (!form.bs_course_id) {
        errors.bs_course_id =
            'Exam Package is required.';
    }

    if (!form.bs_exam_session_id) {
        errors.bs_exam_session_id =
            'Exam Session is required.';
    }

    if (!form.access_exp_date_from) {
        errors.access_exp_date_from =
            'Date From is required.';
    }

    if (!form.access_exp_time_from) {
        errors.access_exp_time_from =
            'Start Time is required.';
    }

    if (!form.access_exp_date_to) {
        errors.access_exp_date_to =
            'Until Date is required.';
    }

    if (!form.access_exp_time_to) {
        errors.access_exp_time_to =
            'End Time is required.';
    }

    const from = new Date(
        `${form.access_exp_date_from}T${form.access_exp_time_from}`,
    );

    const to = new Date(
        `${form.access_exp_date_to}T${form.access_exp_time_to}`,
    );

    if (
        !Number.isNaN(from.getTime()) &&
        !Number.isNaN(to.getTime()) &&
        from > to
    ) {
        errors.access_exp_date_to =
            'The ending date and time must be after the starting date and time.';
    }

    if (
        !isEditing.value &&
        selectedStudents.value.length === 0
    ) {
        errors.student_ids =
            'Add at least one examinee.';
    }

    formErrors.value = errors;

    return Object.keys(errors).length === 0;
}

function handlePage(
    event: DataTablePageEvent,
): void {
    rows.value = event.rows;
    first.value = event.first;

    void loadBatches(event.page + 1);
}

function handleSort(
    event: DataTableSortEvent,
): void {
    sortField.value =
        event.sortField || 'last_update';

    sortDirection.value =
        event.sortOrder === -1
            ? 'desc'
            : 'asc';

    first.value = 0;

    void loadBatches(1);
}

function handleSearch(
    value: string,
): void {
    search.value = value;
    first.value = 0;

    void loadBatches(1);
}

function handleAction(
    action: string,
    batch: DataTableRow,
): void {
    if (action !== 'edit') {
        return;
    }

    const batchId = String(
        batch.id ?? '',
    ).trim();

    if (batchId) {
        void openBatch(batchId);
    }
}

function getInitials(
    student: StudentOption,
): string {
    const words = student.name
        .replace(',', ' ')
        .split(/\s+/)
        .filter(Boolean);

    return (
        words
            .slice(0, 2)
            .map((word) =>
                word.charAt(0),
            )
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

function formatDateTime(
    date: unknown,
    time: unknown,
): string {
    const dateValue = String(
        date ?? '',
    ).trim();

    const timeValue = String(
        time ?? '',
    ).substring(0, 5);

    if (!dateValue) {
        return '—';
    }

    return timeValue
        ? `${dateValue} ${timeValue}`
        : dateValue;
}

function formatLastUpdate(
    value: unknown,
): string {
    const rawValue = String(
        value ?? '',
    ).trim();

    if (!rawValue) {
        return '—';
    }

    const date = new Date(
        rawValue.replace(' ', 'T'),
    );

    if (Number.isNaN(date.getTime())) {
        return rawValue;
    }

    return new Intl.DateTimeFormat(
        'en-PH',
        {
            timeZone:
                'Asia/Manila',
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: true,
        },
    ).format(date);
}

function jsonHeaders(): Record<string, string> {
    return {
        Accept: 'application/json',
        'X-Requested-With':
            'XMLHttpRequest',
    };
}

function setValidationErrors(
    error: unknown,
): void {
    if (!axios.isAxiosError(error)) {
        return;
    }

    const errors = (
        error.response?.data as {
            errors?: Record<
                string,
                string[]
            >;
        }
    )?.errors;

    if (!errors) {
        return;
    }

    formErrors.value = Object.fromEntries(
        Object.entries(errors).map(
            ([field, messages]) => [
                field,
                messages[0] ?? '',
            ],
        ),
    );
}

function getErrorMessage(
    error: unknown,
    fallback: string,
): string {
    if (!axios.isAxiosError(error)) {
        return fallback;
    }

    const data = error.response?.data as
        | {
              message?: string;

              errors?: Record<
                  string,
                  string[]
              >;
          }
        | undefined;

    const firstValidationError =
        data?.errors
            ? Object.values(
                  data.errors,
              )[0]?.[0]
            : null;

    return (
        firstValidationError ||
        data?.message ||
        fallback
    );
}

onMounted(() => {
    void Promise.all([
        loadOptions(),
        loadBatches(1),
    ]);
});

onBeforeUnmount(() => {
    requestController?.abort();

    if (studentSearchTimer) {
        clearTimeout(studentSearchTimer);
    }
});
</script>

<template>
    <Head
        title="Theoretical Assessment Batch Creation"
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

        <section
            class="rounded-2xl border border-slate-200 bg-white shadow-sm"
        >
            <div
                class="flex flex-col gap-4 border-b border-slate-200 p-5 lg:flex-row lg:items-center lg:justify-between"
            >
                <div
                    class="flex items-center gap-4"
                >
                    <div
                        class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-blue-500 text-white shadow-md"
                    >
                        <i
                            class="pi pi-users text-2xl"
                        ></i>
                    </div>

                    <div>
                        <h1
                            class="text-xl font-bold text-slate-800"
                        >
                            Theoretical Assessment
                            Batch Creation
                        </h1>

                        <p
                            class="mt-1 text-sm text-slate-500"
                        >
                            Schedule an examination
                            for enrolled students.
                        </p>
                    </div>
                </div>

                <Button
                    type="button"
                    label="New Batch"
                    icon="pi pi-plus"
                    severity="success"
                    @click="newBatch"
                />
            </div>

            <div
                v-if="detailsLoading || optionsLoading"
                class="flex min-h-52 items-center justify-center"
            >
                <i
                    class="pi pi-spin pi-spinner text-3xl text-blue-500"
                ></i>
            </div>

            <div
                v-else
                class="space-y-5 p-5"
            >
                <Message
                    v-if="isEditing"
                    severity="info"
                    :closable="false"
                >
                    This batch has already generated
                    individual examinations. Its
                    students, package, and session
                    cannot be changed.
                </Message>

                <div
                    class="grid gap-4 md:grid-cols-2 xl:grid-cols-4"
                >
                    <div>
                        <label
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >
                            Exam Package *
                        </label>

                        <Select
                            v-model="
                                form.bs_course_id
                            "
                            :options="courses"
                            option-label="label"
                            option-value="id"
                            placeholder="Select package"
                            fluid
                            filter
                            :disabled="
                                isEditing ||
                                saving
                            "
                            :invalid="
                                Boolean(
                                    formErrors.bs_course_id,
                                )
                            "
                        />

                        <small
                            v-if="
                                formErrors.bs_course_id
                            "
                            class="mt-1 block text-red-500"
                        >
                            {{
                                formErrors.bs_course_id
                            }}
                        </small>
                    </div>

                    <div>
                        <label
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >
                            Exam Session *
                        </label>

                        <Select
                            v-model="
                                form.bs_exam_session_id
                            "
                            :options="sessions"
                            option-label="label"
                            option-value="id"
                            placeholder="Select session"
                            fluid
                            filter
                            :disabled="
                                isEditing ||
                                saving
                            "
                            :invalid="
                                Boolean(
                                    formErrors.bs_exam_session_id,
                                )
                            "
                        />

                        <small
                            v-if="
                                formErrors.bs_exam_session_id
                            "
                            class="mt-1 block text-red-500"
                        >
                            {{
                                formErrors.bs_exam_session_id
                            }}
                        </small>
                    </div>

                    <div>
                        <label
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >
                            Duration in minutes *
                        </label>

                        <InputNumber
                            v-model="form.duration"
                            :min="0"
                            :max="1440"
                            fluid
                            :disabled="saving"
                        />

                        <small
                            class="mt-1 block text-slate-400"
                        >
                            Use 0 to calculate from
                            the package topics.
                        </small>
                    </div>

                    <div>
                        <label
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >
                            Proctor Name
                        </label>

                        <InputText
                            v-model="
                                form.proctor_name
                            "
                            fluid
                            placeholder="Enter proctor name"
                            :disabled="saving"
                        />
                    </div>

                    <div>
                        <label
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >
                            Date From *
                        </label>

                        <input
                            v-model="
                                form.access_exp_date_from
                            "
                            type="date"
                            :disabled="saving"
                            class="h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-700 outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:bg-slate-100"
                        />
                    </div>



                    <div>
                        <label
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >
                            Until Date *
                        </label>

                        <input
                            v-model="
                                form.access_exp_date_to
                            "
                            type="date"
                            :disabled="saving"
                            class="h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-700 outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:bg-slate-100"
                        />

                        <small
                            v-if="
                                formErrors.access_exp_date_to
                            "
                            class="mt-1 block text-red-500"
                        >
                            {{
                                formErrors.access_exp_date_to
                            }}
                        </small>
                    </div>

                                        <div>
                        <label
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >
                            Start Time *
                        </label>

                        <Select
                            v-model="
                                form.access_exp_time_from
                            "
                            :options="timeOptions"
                            fluid
                            :disabled="saving"
                        />
                    </div>

                    <div>
                        <label
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >
                            End Time *
                        </label>

                        <Select
                            v-model="
                                form.access_exp_time_to
                            "
                            :options="timeOptions"
                            fluid
                            :disabled="saving"
                        />
                    </div>
                </div>

                <div
                    v-if="!studentsLocked"
                    class="rounded-xl border border-blue-100 bg-blue-50/50 p-4"
                >
                    <label
                        class="mb-2 block text-sm font-semibold text-slate-700"
                    >
                        Search student to add
                    </label>

                    <InputText
                        v-model="studentSearch"
                        fluid
                        placeholder="Search by student name or School ID..."
                        :disabled="
                            !canSearchStudents ||
                            saving
                        "
                    />

                    <p
                        v-if="
                            !canSearchStudents
                        "
                        class="mt-2 text-xs text-slate-500"
                    >
                        Select the package, session,
                        and complete the schedule
                        before searching for students.
                    </p>

                    <div
                        v-if="studentLoading"
                        class="py-4 text-center"
                    >
                        <i
                            class="pi pi-spin pi-spinner text-blue-500"
                        ></i>
                    </div>

                    <div
                        v-else-if="
                            studentResults.length
                        "
                        class="mt-3 max-h-72 divide-y divide-slate-200 overflow-y-auto rounded-xl border border-slate-200 bg-white"
                    >
                        <button
                            v-for="student in studentResults"
                            :key="student.id"
                            type="button"
                            class="flex w-full items-center gap-3 p-3 text-left transition hover:bg-blue-50 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:opacity-60"
                            :disabled="
                                student.has_pending_exam
                            "
                            @click="
                                addStudent(student)
                            "
                        >
                            <Avatar
                                :label="
                                    getInitials(
                                        student,
                                    )
                                "
                                shape="circle"
                                class="shrink-0 !bg-blue-50 !text-blue-600"
                            />

                            <div
                                class="min-w-0 flex-1"
                            >
                                <p
                                    class="truncate font-semibold text-slate-700"
                                >
                                    {{
                                        student.name
                                    }}
                                </p>

                                <div
                                    class="mt-1 flex flex-nowrap gap-1.5"
                                >
                                    <PrimeTag
                                        :value="
                                            student.school_id_no ||
                                            'No School ID'
                                        "
                                        icon="pi pi-id-card"
                                        severity="info"
                                        rounded
                                    />

                                    <PrimeTag
                                        v-if="
                                            student.dept
                                        "
                                        :value="
                                            String(
                                                student.dept,
                                            ).toUpperCase()
                                        "
                                        :icon="
                                            getDepartmentIcon(
                                                student.dept,
                                            )
                                        "
                                        :severity="
                                            getDepartmentSeverity(
                                                student.dept,
                                            )
                                        "
                                        rounded
                                    />
                                </div>
                            </div>

                            <PrimeTag
                                v-if="
                                    student.has_pending_exam
                                "
                                value="Pending same exam"
                                severity="danger"
                                rounded
                            />

                            <i
                                v-else
                                class="pi pi-plus-circle text-xl text-green-500"
                            ></i>
                        </button>
                    </div>
                </div>

                <div
                    class="rounded-xl border border-slate-200"
                >
                    <div
                        class="flex items-center justify-between border-b border-slate-200 p-4"
                    >
                        <div>
                            <h2
                                class="font-semibold text-slate-700"
                            >
                                Selected Examinees
                            </h2>

                            <p
                                class="text-sm text-slate-500"
                            >
                                Maximum of 40 students
                                per batch.
                            </p>
                        </div>

                        <PrimeTag
                            :value="`${selectedStudents.length} / 40`"
                            severity="info"
                            rounded
                        />
                    </div>

                    <div
                        v-if="
                            selectedStudents.length
                        "
                        class="divide-y divide-slate-200"
                    >
                        <div
                            v-for="student in selectedStudents"
                            :key="student.id"
                            class="flex items-center gap-3 p-4"
                        >
                            <Avatar
                                :label="
                                    getInitials(
                                        student,
                                    )
                                "
                                shape="circle"
                                class="shrink-0 !bg-blue-50 !text-blue-600"
                            />

                            <div
                                class="min-w-0 flex-1"
                            >
                                <p
                                    class="truncate font-semibold text-slate-700"
                                >
                                    {{
                                        student.name
                                    }}
                                </p>

                                <div
                                    class="mt-1 flex flex-nowrap items-center gap-1.5"
                                >
                                    <PrimeTag
                                        :value="
                                            student.school_id_no ||
                                            'No School ID'
                                        "
                                        icon="pi pi-id-card"
                                        severity="info"
                                        rounded
                                    />

                                    <PrimeTag
                                        :value="
                                            student.email ||
                                            'No email'
                                        "
                                        icon="pi pi-envelope"
                                        severity="secondary"
                                        rounded
                                    />
                                </div>
                            </div>

                            <Button
                                type="button"
                                icon="pi pi-trash"
                                severity="danger"
                                rounded
                                :disabled="
                                    studentsLocked ||
                                    saving
                                "
                                :aria-label="
                                    studentsLocked
                                        ? 'Student already assigned'
                                        : 'Remove student'
                                "
                                @click="
                                    removeStudent(
                                        student.id,
                                    )
                                "
                            />
                        </div>
                    </div>

                    <div
                        v-else
                        class="p-8 text-center text-slate-500"
                    >
                        No students have been added.
                    </div>
                </div>

                <Message
                    v-if="
                        formErrors.student_ids
                    "
                    severity="error"
                    :closable="false"
                >
                    {{
                        formErrors.student_ids
                    }}
                </Message>

                <div
                    class="flex justify-end gap-2"
                >
                    <Button
                        type="button"
                        label="Reset"
                        icon="pi pi-refresh"
                        severity="secondary"
                        outlined
                        :disabled="saving"
                        @click="newBatch"
                    />

                    <Button
                        type="button"
                        :label="
                            isEditing
                                ? 'Update Batch'
                                : 'Create Batch'
                        "
                        :icon="
                            isEditing
                                ? 'pi pi-save'
                                : 'pi pi-plus'
                        "
                        severity="success"
                        :loading="saving"
                        :disabled="saving"
                        @click="saveBatch"
                    />
                </div>
            </div>
        </section>

        <Datatable
            title="Theoretical Assessment Batches"
            description="Review previously created enrolled-student examination batches."
            header-icon="pi pi-list-check"
            search-placeholder="Search batches..."
            empty-title="No batches found"
            empty-description="No theoretical assessment batches match your search."
            empty-icon="pi pi-users"
            table-min-width="1250px"
            actions-header="Actions"
            actions-width="100px"
            data-key="id"
            lazy
            :loading="loading"
            :data="batches"
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
                #cell-name_course="{ data }"
            >
                <div class="space-y-1">
                    <p
                        class="font-semibold text-slate-700"
                    >
                        <i
                            class="pi pi-graduation-cap mr-1 text-blue-500"
                        ></i>

                        {{
                            data.name_course
                        }}
                    </p>

                    <p
                        class="text-xs text-slate-500"
                    >
                        Created
                        {{
                            formatLastUpdate(
                                data.last_update,
                            )
                        }}
                    </p>
                </div>
            </template>

            <template
                #cell-session_code="{ data }"
            >
                <div class="space-y-1.5">
                    <PrimeTag
                        :value="
                            data.session_code
                        "
                        icon="pi pi-calendar"
                        severity="info"
                        rounded
                    />

                    <p
                        class="text-xs text-slate-500"
                    >
                        {{
                            formatDateTime(
                                data.access_exp_date_from,
                                data.access_exp_time_from,
                            )
                        }}
                        –
                        {{
                            formatDateTime(
                                data.access_exp_date_to,
                                data.access_exp_time_to,
                            )
                        }}
                    </p>
                </div>
            </template>

            <template
                #cell-proctor_name="{ value }"
            >
                <p
                    class="font-semibold text-slate-700 uppercase"
                >
                    <i
                        class="pi pi-user mr-1 text-violet-500"
                    ></i>

                    {{
                        value ||
                        'NO PROCTOR'
                    }}
                </p>
            </template>

            <template
                #cell-duration="{ value }"
            >
                <PrimeTag
                    :value="`${value || 0} min`"
                    icon="pi pi-clock"
                    severity="warn"
                    rounded
                />
            </template>

            <template
                #cell-examinee_count="{ value }"
            >
                <PrimeTag
                    :value="String(value || 0)"
                    icon="pi pi-users"
                    severity="success"
                    rounded
                />
            </template>
        </Datatable>
    </div>
</template>