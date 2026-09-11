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
                title: 'Theoretical External Batch',
                href: '/dashboard/theoretical-external/batch',
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

type Examinee = {
    id: string;
    email: string;
    fname: string;
    mname: string;
    lname: string;
    name: string;
    email_sent: boolean;
};

type BatchDetails = {
    id: string;
    bs_course_id: string;
    bs_exam_session_id: string;
    name_course: string;
    session_code: string;
    exam_type: string;
    duration: number;
    proctor_name: string;
    access_exp_date_from: string;
    access_exp_time_from: string;
    access_exp_date_to: string;
    access_exp_time_to: string;
    examinees: Examinee[];
    examinees_locked: boolean;
};

type BatchApiResponse = {
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

const examineeForm = reactive({
    email: '',
    fname: '',
    mname: '',
    lname: '',
});

const courses = ref<CourseOption[]>([]);
const sessions = ref<SessionOption[]>([]);
const timeOptions = ref<string[]>([]);

const examinees = ref<Examinee[]>([]);
const examineesLocked = ref(false);

const batches = ref<DataTableRow[]>([]);

const loading = ref(false);
const optionsLoading = ref(false);
const detailsLoading = ref(false);
const saving = ref(false);

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

let requestController:
    | AbortController
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
        key: 'view',
        label: 'View batch',
        icon: 'pi pi-eye',
        severity: 'info',
    },
];

const isViewing = computed(() => {
    return form.id !== '';
});

const selectedCourse = computed(() => {
    return courses.value.find(
        (course) =>
            course.id === form.bs_course_id,
    );
});

watch(
    () => form.bs_course_id,
    () => {
        if (!isViewing.value) {
            form.duration =
                selectedCourse.value?.duration ?? 0;
        }
    },
);

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
            '/api/v1/dashboard/theoretical-external/batch/options',
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
        errorMessage.value =
            getErrorMessage(
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
            await axios.get<BatchApiResponse>(
                '/api/v1/dashboard/datatable/theoretical-external-batches',
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
                axios.isAxiosError(error) &&
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
                'Unable to load External batches.',
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

function addExaminee(): void {
    errorMessage.value = '';
    formErrors.value = {};

    if (examineesLocked.value) {
        return;
    }

    const email =
        examineeForm.email
            .trim()
            .toLowerCase();

    if (!email) {
        formErrors.value.email =
            'Email is required.';

        return;
    }

    if (
        !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(
            email,
        )
    ) {
        formErrors.value.email =
            'Enter a valid email address.';

        return;
    }

    if (
        !form.bs_course_id ||
        !form.bs_exam_session_id
    ) {
        errorMessage.value =
            'Select an Exam Package and Exam Session first.';

        return;
    }

    if (
        examinees.value.some(
            (examinee) =>
                examinee.email
                    .toLowerCase() === email,
        )
    ) {
        errorMessage.value =
            'This examinee is already in the batch.';

        return;
    }

    if (examinees.value.length >= 40) {
        errorMessage.value =
            'The number of examinees is limited to 40 per batch.';

        return;
    }

    const fname =
        examineeForm.fname.trim();

    const mname =
        examineeForm.mname.trim();

    const lname =
        examineeForm.lname.trim();

    const otherNames = [
        fname,
        mname,
    ]
        .filter(Boolean)
        .join(' ');

    const name = (
        lname && otherNames
            ? `${lname}, ${otherNames}`
            : lname ||
              otherNames ||
              'EXAMINEE'
    ).toUpperCase();

    examinees.value.push({
        id: crypto.randomUUID(),
        email,
        fname,
        mname,
        lname,
        name,
        email_sent: false,
    });

    resetExamineeForm();
}

function removeExaminee(
    examineeId: string,
): void {
    if (examineesLocked.value) {
        return;
    }

    examinees.value =
        examinees.value.filter(
            (examinee) =>
                examinee.id !== examineeId,
        );
}

function resetExamineeForm(): void {
    examineeForm.email = '';
    examineeForm.fname = '';
    examineeForm.mname = '';
    examineeForm.lname = '';
}

async function saveBatch(): Promise<void> {
    successMessage.value = '';
    errorMessage.value = '';
    formErrors.value = {};

    if (!validateForm()) {
        return;
    }

    saving.value = true;

    try {
        const response = await axios.post<{
            message: string;

            data: {
                id: string;
                emails_sent: number;
                emails_failed: number;
            };
        }>(
            '/api/v1/dashboard/theoretical-external/batch',
            {
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

                examinees:
                    examinees.value.map(
                        (examinee) => ({
                            email:
                                examinee.email,

                            fname:
                                examinee.fname,

                            mname:
                                examinee.mname,

                            lname:
                                examinee.lname,
                        }),
                    ),
            },
            {
                headers: jsonHeaders(),
                withCredentials: true,
            },
        );

        successMessage.value =
            response.data.message;

        await loadBatches(1);

        await openBatch(
            response.data.data.id,
        );
    } catch (error: unknown) {
        setValidationErrors(error);

        errorMessage.value =
            getErrorMessage(
                error,
                'The External batch could not be created.',
            );
    } finally {
        saving.value = false;
    }
}

async function openBatch(
    batchId: string,
): Promise<void> {
    detailsLoading.value = true;

    try {
        const response = await axios.get<{
            data: BatchDetails;
        }>(
            `/api/v1/dashboard/theoretical-external/batch/${encodeURIComponent(
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

        form.duration =
            batch.duration;

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

        examinees.value =
            batch.examinees;

        examineesLocked.value =
            batch.examinees_locked;

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

    examinees.value = [];
    examineesLocked.value = false;

    resetExamineeForm();

    formErrors.value = {};
    errorMessage.value = '';
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
            'Access Date From is required.';
    }

    if (!form.access_exp_time_from) {
        errors.access_exp_time_from =
            'Access Start Time is required.';
    }

    if (!form.access_exp_date_to) {
        errors.access_exp_date_to =
            'Access Until Date is required.';
    }

    if (!form.access_exp_time_to) {
        errors.access_exp_time_to =
            'Access End Time is required.';
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

    if (examinees.value.length === 0) {
        errors.examinees =
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
        event.sortField ||
        'last_update';

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
    if (action !== 'view') {
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
    examinee: Examinee,
): string {
    return (
        examinee.name
            .replace(',', ' ')
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((word) =>
                word.charAt(0),
            )
            .join('')
            .toUpperCase() || 'EX'
    );
}

function formatSchedule(
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

    return `${dateValue} ${timeValue}`;
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
            timeZone: 'Asia/Manila',
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: true,
        },
    ).format(date);
}

function jsonHeaders(): Record<
    string,
    string
> {
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

    formErrors.value =
        Object.fromEntries(
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
});
</script>

<template>
    <Head
        title="Theoretical External Batch"
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
                        class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-yellow-500 text-white shadow-md"
                    >
                        <i
                            class="pi pi-question-circle text-2xl"
                        ></i>
                    </div>

                    <div>
                        <h1
                            class="text-xl font-bold text-slate-800"
                        >
                            Theoretical Assessment
                            – External Batch
                        </h1>

                        <p
                            class="mt-1 text-sm text-slate-500"
                        >
                            Schedule examinations for
                            External examinees.
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
                v-if="
                    detailsLoading ||
                    optionsLoading
                "
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
                    v-if="isViewing"
                    severity="info"
                    :closable="false"
                >
                    This saved batch is read-only.
                    Create a new batch to schedule
                    additional examinees.
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
                            filter
                            fluid
                            :disabled="
                                isViewing ||
                                saving
                            "
                        />
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
                            filter
                            fluid
                            :disabled="
                                isViewing ||
                                saving
                            "
                        />
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
                            :disabled="
                                isViewing ||
                                saving
                            "
                        />
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
                            :disabled="
                                isViewing ||
                                saving
                            "
                        />
                    </div>

                    <div>
                        <label
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >
                            Access Date From *
                        </label>

                        <input
                            v-model="
                                form.access_exp_date_from
                            "
                            type="date"
                            :disabled="
                                isViewing ||
                                saving
                            "
                            class="h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-700 outline-none focus:border-blue-500 disabled:bg-slate-100"
                        />
                    </div>

                    <div>
                        <label
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >
                            Access Start Time *
                        </label>

                        <Select
                            v-model="
                                form.access_exp_time_from
                            "
                            :options="timeOptions"
                            fluid
                            :disabled="
                                isViewing ||
                                saving
                            "
                        />
                    </div>

                    <div>
                        <label
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >
                            Access Until Date *
                        </label>

                        <input
                            v-model="
                                form.access_exp_date_to
                            "
                            type="date"
                            :disabled="
                                isViewing ||
                                saving
                            "
                            class="h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-700 outline-none focus:border-blue-500 disabled:bg-slate-100"
                        />
                    </div>

                    <div>
                        <label
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >
                            Access End Time *
                        </label>

                        <Select
                            v-model="
                                form.access_exp_time_to
                            "
                            :options="timeOptions"
                            fluid
                            :disabled="
                                isViewing ||
                                saving
                            "
                        />
                    </div>
                </div>

                <div
                    v-if="!examineesLocked"
                    class="rounded-xl border border-yellow-200 bg-yellow-50/50 p-4"
                >
                    <h2
                        class="mb-3 font-semibold text-slate-700"
                    >
                        Add External Examinee
                    </h2>

                    <div
                        class="grid gap-3 md:grid-cols-2 xl:grid-cols-5"
                    >
                        <InputText
                            v-model="
                                examineeForm.email
                            "
                            type="email"
                            placeholder="Email address *"
                            :invalid="
                                Boolean(
                                    formErrors.email,
                                )
                            "
                        />

                        <InputText
                            v-model="
                                examineeForm.fname
                            "
                            placeholder="First name"
                        />

                        <InputText
                            v-model="
                                examineeForm.mname
                            "
                            placeholder="Middle name"
                        />

                        <InputText
                            v-model="
                                examineeForm.lname
                            "
                            placeholder="Last name"
                        />

                        <Button
                            type="button"
                            label="Add Examinee"
                            icon="pi pi-user-plus"
                            severity="success"
                            @click="addExaminee"
                        />
                    </div>

                    <small
                        v-if="formErrors.email"
                        class="mt-2 block text-red-500"
                    >
                        {{ formErrors.email }}
                    </small>
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
                                Examinees
                            </h2>

                            <p
                                class="text-sm text-slate-500"
                            >
                                Maximum of 40
                                examinees per batch.
                            </p>
                        </div>

                        <PrimeTag
                            :value="`${examinees.length} / 40`"
                            severity="info"
                            rounded
                        />
                    </div>

                    <div
                        v-if="examinees.length"
                        class="divide-y divide-slate-200"
                    >
                        <div
                            v-for="examinee in examinees"
                            :key="examinee.id"
                            class="flex items-center gap-3 p-4"
                        >
                            <Avatar
                                :label="
                                    getInitials(
                                        examinee,
                                    )
                                "
                                shape="circle"
                                class="shrink-0 !bg-yellow-50 !text-yellow-600"
                            />

                            <div
                                class="min-w-0 flex-1"
                            >
                                <p
                                    class="truncate font-semibold text-slate-700 uppercase"
                                >
                                    {{
                                        examinee.name
                                    }}
                                </p>

                                <PrimeTag
                                    :value="
                                        examinee.email
                                    "
                                    icon="pi pi-envelope"
                                    severity="info"
                                    rounded
                                    class="mt-1"
                                />
                            </div>

                            <PrimeTag
                                v-if="
                                    examineesLocked
                                "
                                :value="
                                    examinee.email_sent
                                        ? 'Email Sent'
                                        : 'Email Not Sent'
                                "
                                :icon="
                                    examinee.email_sent
                                        ? 'pi pi-check-circle'
                                        : 'pi pi-times-circle'
                                "
                                :severity="
                                    examinee.email_sent
                                        ? 'success'
                                        : 'danger'
                                "
                                rounded
                            />

                            <Button
                                v-else
                                type="button"
                                icon="pi pi-trash"
                                severity="danger"
                                rounded
                                aria-label="Remove examinee"
                                @click="
                                    removeExaminee(
                                        examinee.id,
                                    )
                                "
                            />
                        </div>
                    </div>

                    <div
                        v-else
                        class="p-8 text-center text-slate-500"
                    >
                        No examinees have been added.
                    </div>
                </div>

                <Message
                    v-if="
                        formErrors.examinees
                    "
                    severity="error"
                    :closable="false"
                >
                    {{ formErrors.examinees }}
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
                        v-if="!isViewing"
                        type="button"
                        label="Create Batch"
                        icon="pi pi-send"
                        severity="success"
                        :loading="saving"
                        :disabled="
                            saving ||
                            examinees.length === 0
                        "
                        @click="saveBatch"
                    />
                </div>
            </div>
        </section>

        <Datatable
            title="Theoretical External Batches"
            description="Review previously created External examination batches."
            header-icon="pi pi-question-circle"
            search-placeholder="Search External batches..."
            empty-title="No External batches found"
            empty-description="No External examination batches match your search."
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

                        {{ data.name_course }}
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
                            formatSchedule(
                                data.access_exp_date_from,
                                data.access_exp_time_from,
                            )
                        }}
                        –
                        {{
                            formatSchedule(
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

                    {{ value || 'NO PROCTOR' }}
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