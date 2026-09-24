<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Card from 'primevue/card';
import ProgressBar from 'primevue/progressbar';
import Tag from 'primevue/tag';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import Datatable from '@/components/Datatable.vue';
import type {
    DataTableAction,
    DataTableColumn,
    DataTableRow,
    SharedData,
} from '@/types';

defineOptions({
    inheritAttrs: false,
    layout: {
        breadcrumbs: [
            {
                title: 'Student Dashboard',
                href: '/student-dashboard',
            },
        ],
    },
});

type StudentAccount = SharedData['auth']['user'] & {
    code_person?: string | null;
    school_id_no?: string | null;
    login_name?: string | null;
    fname?: string | null;
    mname?: string | null;
    lname?: string | null;
    full_name?: string | null;
    gender?: string | null;
    dept?: string | null;
    batch_no?: string | null;
    avatar?: string | null;
    profile_image?: string | null;
    role?: string | null;
};

type StudentActivity = DataTableRow & {
    id: string;
    description: string;
    filename: string | null;
    file_url: string | null;
    start_date: string | null;
    end_date: string | null;
    last_update: string | null;
    revise_remarks: string | null;
    status: string;
};

type StudentDocumentFile = {
    name: string;
    label: string;
    url: string;
};

type StudentDocument = DataTableRow & {
    id: string;
    description: string;
    requirement: string | null;
    date_uploaded: string | null;
    time_uploaded: string | null;
    last_update: string | null;
    revise_remarks: string | null;
    status: string;
    files: StudentDocumentFile[];
};

type StudentOtgTask = DataTableRow & {
    id: string;
    task_id: string | null;
    reference_number: string | null;
    ref_no?: string | null;
    description: string;
    desc_task?: string | null;
    month_number: string | number | null;
    month_no?: string | number | null;
    completed_at: string | null;
    completed?: string | null;
    status: string;
};

type StudentJournal = DataTableRow & {
    id: string;
    date_journal: string | null;
    vessel_name: string | null;
    journal_time: string | null;
    journal_time_to: string | null;
    duty_hours: string | number | null;
    port_depart: string | null;
    port_dest: string | null;
    activities: string | null;
    key_areas: string | null;
    file_name: string | null;
    evidence_url: string | null;
    status: string;
};

type ListResponse<T> = {
    total: number;
    data: T[];
};

type OtgResponse = ListResponse<StudentOtgTask> & {
    percentage: number;
    completed: number;
};

type Severity = 'success' | 'warn' | 'danger' | 'secondary' | 'info';

const page = usePage<SharedData>();
const student = computed(() => page.props.auth.user as StudentAccount);
const school = computed(() => page.props.school);

const currentDate = ref('');
const currentTime = ref('');
let clockInterval: ReturnType<typeof setInterval> | null = null;

const activities = ref<StudentActivity[]>([]);
const activitiesTotal = ref<number | null>(null);
const activitiesLoading = ref(true);
const activitiesError = ref('');

const documents = ref<StudentDocument[]>([]);
const documentsTotal = ref<number | null>(null);
const documentsLoading = ref(true);
const documentsError = ref('');

const otgTasks = ref<StudentOtgTask[]>([]);
const otgPercentage = ref<number | null>(null);
const otgCompleted = ref<number | null>(null);
const otgTotal = ref<number | null>(null);
const otgLoading = ref(true);
const otgError = ref('');

const journals = ref<StudentJournal[]>([]);
const journalsTotal = ref<number | null>(null);
const journalsLoading = ref(true);
const journalsError = ref('');

let activitiesController: AbortController | null = null;
let documentsController: AbortController | null = null;
let otgController: AbortController | null = null;
let journalsController: AbortController | null = null;

const displayName = computed(() => {
    if (student.value.full_name?.trim()) {
        return student.value.full_name.trim();
    }

    return (
        [student.value.fname, student.value.mname, student.value.lname]
            .filter(
                (name): name is string =>
                    typeof name === 'string' && name.trim() !== '',
            )
            .map((name) => name.trim())
            .join(' ') ||
        student.value.login_name ||
        'Student'
    );
});

const profileImage = computed(() => {
    if (student.value.avatar || student.value.profile_image) {
        return student.value.avatar || student.value.profile_image;
    }

    const gender = String(student.value.gender ?? '').trim().toUpperCase();

    if (gender === 'F') return '/images/female.png';
    if (gender === 'M') return '/images/male.png';

    return '/images/default-user.png';
});

const studentInformation = computed(() => [
    {
        label: 'Student ID',
        value: student.value.school_id_no || 'Not assigned',
        icon: 'pi pi-id-card',
    },
    {
        label: 'Student Code',
        value: student.value.code_person || 'Not assigned',
        icon: 'pi pi-hashtag',
    },
    {
        label: 'Department',
        value: student.value.dept || 'Not assigned',
        icon: 'pi pi-building',
    },
    {
        label: 'Batch Number',
        value: student.value.batch_no || 'Not assigned',
        icon: 'pi pi-users',
    },
]);

const activityColumns: DataTableColumn[] = [
    {
        field: 'description',
        header: 'Activity',
        searchable: true,
        class: 'min-w-[260px]',
    },
    {
        field: 'activity_period',
        header: 'Activity Period',
        searchable: false,
        class: 'min-w-[210px]',
    },
    {
        field: 'filename',
        header: 'Uploaded File',
        searchable: true,
        class: 'min-w-[190px]',
    },
    {
        field: 'last_update',
        header: 'Last Updated',
        searchable: false,
        class: 'min-w-[210px]',
    },
    {
        field: 'status',
        header: 'Status',
        searchable: true,
        class: 'min-w-[145px]',
    },
    {
        field: 'revise_remarks',
        header: 'Remarks',
        searchable: true,
        class: 'min-w-[220px]',
    },
];

const activityActions: DataTableAction[] = [
    {
        key: 'download',
        label: 'Download activity file',
        icon: 'pi pi-download',
        severity: 'info',
        disabled: (row) => !row.file_url,
    },
];

const documentColumns: DataTableColumn[] = [
    {
        field: 'description',
        header: 'Document',
        searchable: true,
        class: 'min-w-[260px]',
    },
    {
        field: 'requirement',
        header: 'Requirement',
        searchable: true,
        class: 'min-w-[220px]',
    },
    {
        field: 'uploaded_at',
        header: 'Uploaded',
        searchable: false,
        class: 'min-w-[190px]',
    },
    {
        field: 'status',
        header: 'Status',
        searchable: true,
        class: 'min-w-[145px]',
    },
    {
        field: 'revise_remarks',
        header: 'Remarks',
        searchable: true,
        class: 'min-w-[220px]',
    },
];

const documentActions: DataTableAction[] = [
    {
        key: 'download',
        label: 'Download document',
        icon: 'pi pi-download',
        severity: 'info',
    },
];

const otgColumns: DataTableColumn[] = [
    {
        field: 'description',
        header: 'Task Information',
        searchable: true,
        class: 'min-w-[420px]',
    },
    {
        field: 'month_number',
        header: 'Month',
        searchable: false,
        class: 'min-w-[110px]',
    },
    {
        field: 'completed_at',
        header: 'Completed',
        searchable: false,
        class: 'min-w-[210px]',
    },
    {
        field: 'status',
        header: 'Status',
        searchable: true,
        class: 'min-w-[145px]',
    },
];

const journalColumns: DataTableColumn[] = [
    {
        field: 'date_journal',
        header: 'Date',
        searchable: false,
        class: 'min-w-[160px]',
    },
    {
        field: 'vessel_name',
        header: 'Vessel',
        searchable: true,
        class: 'min-w-[200px]',
    },
    {
        field: 'duty_time',
        header: 'Duty Time',
        searchable: false,
        class: 'min-w-[190px]',
    },
    {
        field: 'voyage',
        header: 'Voyage',
        searchable: true,
        class: 'min-w-[220px]',
    },
    {
        field: 'activities',
        header: 'Activities',
        searchable: true,
        class: 'min-w-[330px]',
    },
    {
        field: 'status',
        header: 'Status',
        searchable: true,
        class: 'min-w-[145px]',
    },
];

const journalActions: DataTableAction[] = [
    {
        key: 'download',
        label: 'Download journal evidence',
        icon: 'pi pi-download',
        severity: 'info',
        disabled: (row) => !row.evidence_url,
    },
];

function updateClock(): void {
    const now = new Date();

    currentDate.value = new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: 'numeric',
    }).format(now);

    currentTime.value = new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
    }).format(now);
}

function isCancelled(error: unknown): boolean {
    return (
        axios.isCancel(error) ||
        (axios.isAxiosError(error) && error.code === 'ERR_CANCELED')
    );
}

function errorMessage(error: unknown, fallback: string): string {
    return axios.isAxiosError(error)
        ? String(error.response?.data?.message ?? fallback)
        : fallback;
}

async function loadActivities(): Promise<void> {
    activitiesController?.abort();
    const controller = new AbortController();
    activitiesController = controller;
    activitiesLoading.value = true;
    activitiesError.value = '';

    try {
        const response = await axios.get<ListResponse<StudentActivity>>(
            '/api/v1/student-dashboard/activities',
            { signal: controller.signal, withCredentials: true },
        );

        if (activitiesController !== controller) return;

        activitiesTotal.value = Number(response.data.total) || 0;
        activities.value = Array.isArray(response.data.data)
            ? response.data.data.slice(0, 10)
            : [];
    } catch (error: unknown) {
        if (isCancelled(error) || activitiesController !== controller) return;
        activities.value = [];
        activitiesTotal.value = null;
        activitiesError.value = errorMessage(error, 'Unable to load activities.');
    } finally {
        if (activitiesController === controller) activitiesLoading.value = false;
    }
}

async function loadDocuments(): Promise<void> {
    documentsController?.abort();
    const controller = new AbortController();
    documentsController = controller;
    documentsLoading.value = true;
    documentsError.value = '';

    try {
        const response = await axios.get<ListResponse<StudentDocument>>(
            '/api/v1/student-dashboard/documents',
            { signal: controller.signal, withCredentials: true },
        );

        if (documentsController !== controller) return;

        documentsTotal.value = Number(response.data.total) || 0;
        documents.value = Array.isArray(response.data.data)
            ? response.data.data.slice(0, 10)
            : [];
    } catch (error: unknown) {
        if (isCancelled(error) || documentsController !== controller) return;
        documents.value = [];
        documentsTotal.value = null;
        documentsError.value = errorMessage(
            error,
            'Unable to load uploaded documents.',
        );
    } finally {
        if (documentsController === controller) documentsLoading.value = false;
    }
}

async function loadOtg(): Promise<void> {
    otgController?.abort();
    const controller = new AbortController();
    otgController = controller;
    otgLoading.value = true;
    otgError.value = '';

    try {
        const response = await axios.get<OtgResponse>(
            '/api/v1/student-dashboard/otg',
            { signal: controller.signal, withCredentials: true },
        );

        if (otgController !== controller) return;

        otgPercentage.value = Math.min(
            100,
            Math.max(0, Number(response.data.percentage) || 0),
        );
        otgCompleted.value = Number(response.data.completed) || 0;
        otgTotal.value = Number(response.data.total) || 0;
        otgTasks.value = Array.isArray(response.data.data)
            ? response.data.data.slice(0, 10)
            : [];
    } catch (error: unknown) {
        if (isCancelled(error) || otgController !== controller) return;
        otgTasks.value = [];
        otgPercentage.value = null;
        otgCompleted.value = null;
        otgTotal.value = null;
        otgError.value = errorMessage(error, 'Unable to load OTG progress.');
    } finally {
        if (otgController === controller) otgLoading.value = false;
    }
}

async function loadJournals(): Promise<void> {
    journalsController?.abort();
    const controller = new AbortController();
    journalsController = controller;
    journalsLoading.value = true;
    journalsError.value = '';

    try {
        const response = await axios.get<ListResponse<StudentJournal>>(
            '/api/v1/student-dashboard/journals',
            { signal: controller.signal, withCredentials: true },
        );

        if (journalsController !== controller) return;

        journalsTotal.value = Number(response.data.total) || 0;
        journals.value = Array.isArray(response.data.data)
            ? response.data.data.slice(0, 10)
            : [];
    } catch (error: unknown) {
        if (isCancelled(error) || journalsController !== controller) return;
        journals.value = [];
        journalsTotal.value = null;
        journalsError.value = errorMessage(error, 'Unable to load journals.');
    } finally {
        if (journalsController === controller) journalsLoading.value = false;
    }
}

function formatDate(value: string | null): string {
    if (!value) return '—';
    const date = new Date(value.includes('T') ? value : value.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return value;

    return new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(date);
}

function formatDateTime(value: string | null): string {
    if (!value) return '—';
    const date = new Date(value.includes('T') ? value : value.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return value;

    return new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    }).format(date);
}

function formatActivityPeriod(activity: StudentActivity): string {
    const start = formatDate(activity.start_date);
    const end = formatDate(activity.end_date);
    if (start === '—' && end === '—') return '—';
    return start === end ? start : `${start} – ${end}`;
}

function statusSeverity(status: string): Severity {
    switch (status.trim().toLowerCase()) {
        case 'verified':
        case 'completed':
        case 'approved':
            return 'success';
        case 'for verification':
        case 'pending':
            return 'warn';
        case 'for revision':
        case 'rejected':
            return 'danger';
        case 'draft':
            return 'secondary';
        default:
            return 'info';
    }
}

function otgReference(task: StudentOtgTask): string {
    return task.reference_number || task.ref_no || '—';
}

function otgDescription(task: StudentOtgTask): string {
    const value = task.description || task.desc_task || '';

    if (!value) return '—';

    try {
        return decodeURIComponent(value.replace(/\+/g, ' '));
    } catch {
        return value.replace(/\+/g, ' ');
    }
}

function otgMonth(task: StudentOtgTask): string {
    const value = task.month_number ?? task.month_no;
    return value === null || value === '' ? '—' : String(value);
}

function otgCompletedAt(task: StudentOtgTask): string {
    return formatDateTime(task.completed_at || task.completed || null);
}

function journalTime(journal: StudentJournal): string {
    const start = journal.journal_time?.trim();
    const end = journal.journal_time_to?.trim();
    if (!start && !end) return '—';
    if (!end) return start || '—';
    return `${start || '—'} – ${end}`;
}

function openDownload(url: unknown): void {
    if (typeof url !== 'string' || url.trim() === '') return;

    window.open(url, '_blank', 'noopener,noreferrer');
}

function handleActivityAction(
    action: string,
    row: DataTableRow,
): void {
    if (action === 'download') {
        openDownload(row.file_url);
    }
}

function handleJournalAction(
    action: string,
    row: DataTableRow,
): void {
    if (action === 'download') {
        openDownload(row.evidence_url);
    }
}

onMounted(() => {
    updateClock();
    clockInterval = setInterval(updateClock, 1000);

    void Promise.allSettled([
        loadActivities(),
        loadDocuments(),
        loadOtg(),
        loadJournals(),
    ]);
});

onBeforeUnmount(() => {
    if (clockInterval) clearInterval(clockInterval);
    activitiesController?.abort();
    documentsController?.abort();
    otgController?.abort();
    journalsController?.abort();
});
</script>

<template>
    <Head title="Student Dashboard" />

    <div
        class="flex h-full min-h-0 flex-1 flex-col gap-5 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5"
    >
        <section
            class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[#377EC0] via-[#123A63] to-[#07182D] shadow-xl shadow-[#123A63]/15"
        >
            <div
                class="pointer-events-none absolute -top-28 -right-20 size-72 rounded-full bg-cyan-300/10 blur-3xl"
            ></div>
            <div
                class="pointer-events-none absolute -bottom-36 -left-24 size-80 rounded-full bg-blue-900/40 blur-3xl"
            ></div>

            <div
                class="relative z-10 flex flex-col gap-6 px-6 py-7 lg:flex-row lg:items-center lg:justify-between lg:px-9 lg:py-8"
            >
                <div class="flex min-w-0 items-center gap-5">
                    <div
                        class="flex size-24 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-white/20 bg-white p-1 shadow-xl"
                    >
                        <img
                            :src="profileImage"
                            :alt="displayName"
                            class="h-full w-full rounded-xl object-cover"
                        />
                    </div>

                    <div class="min-w-0">
                        <div
                            class="mb-3 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1 text-[11px] font-bold tracking-[0.14em] text-blue-100 uppercase backdrop-blur-sm"
                        >
                            <span class="size-1.5 rounded-full bg-emerald-400"></span>
                            {{ student.role || 'Cadet' }}
                        </div>
                        <p class="text-sm font-medium text-blue-100/80">
                            Welcome back,
                        </p>
                        <h1
                            class="mt-1 break-words text-2xl leading-tight font-bold tracking-tight text-white sm:text-3xl"
                        >
                            {{ displayName }}
                        </h1>
                        <p class="mt-2 text-sm text-blue-100/75">
                            {{ school.name }}
                        </p>
                    </div>
                </div>

                <div
                    class="grid shrink-0 grid-cols-2 gap-3 text-white sm:grid-cols-4 lg:max-w-2xl"
                >
                    <div
                        v-for="item in studentInformation"
                        :key="item.label"
                        class="rounded-2xl border border-white/10 bg-white/10 p-3 backdrop-blur-sm"
                    >
                        <div
                            class="relative mb-3 flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gradient-to-br from-[#123A63] to-[#377EC0] text-white shadow-lg shadow-[#377EC0]/20"
                        >
                            <div
                                class="pointer-events-none absolute -top-3 -right-3 size-8 rounded-full bg-white/15"
                            ></div>

                            <i
                                :class="[
                                    item.icon,
                                    'relative z-10 !text-[1.65rem] !leading-none !text-white',
                                ]"
                            ></i>
                        </div>
                        <p class="text-[10px] font-bold tracking-wider text-blue-100/65 uppercase">
                            {{ item.label }}
                        </p>
                        <p class="mt-1 break-words text-sm font-semibold">
                            {{ item.value }}
                        </p>
                    </div>
                </div>
            </div>

            <div
                class="relative z-10 flex flex-col gap-1 border-t border-white/10 bg-black/10 px-6 py-3 text-xs text-blue-100/80 sm:flex-row sm:items-center sm:justify-between lg:px-9"
            >
                <span>{{ currentDate }}</span>
                <span class="font-semibold text-white">{{ currentTime }}</span>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Card class="overflow-hidden !rounded-2xl !border-0 !bg-orange-500 !text-white shadow-lg">
                <template #content>
                    <div class="flex min-h-40 flex-col p-1">
                        <div class="flex items-start justify-between">
                            <span class="flex size-11 items-center justify-center rounded-xl bg-white/15">
                                <i class="pi pi-bolt text-xl"></i>
                            </span>
                            <span class="rounded-full bg-white/15 px-2.5 py-1 text-[10px] font-bold uppercase">Total</span>
                        </div>
                        <div class="mt-auto pt-5" :aria-busy="activitiesLoading">
                            <i v-if="activitiesLoading" class="pi pi-spin pi-spinner text-3xl"></i>
                            <span v-else-if="activitiesError" class="text-sm font-semibold">Unavailable</span>
                            <strong v-else class="text-4xl leading-none">{{ activitiesTotal }}</strong>
                            <p class="mt-2 text-sm font-semibold text-white/90">Submitted Activities</p>
                        </div>
                    </div>
                </template>
            </Card>

            <Card class="overflow-hidden !rounded-2xl !border-0 !bg-emerald-500 !text-white shadow-lg">
                <template #content>
                    <div class="flex min-h-40 flex-col p-1">
                        <div class="flex items-start justify-between">
                            <span class="flex size-11 items-center justify-center rounded-xl bg-white/15">
                                <i class="pi pi-file-arrow-up text-xl"></i>
                            </span>
                            <span class="rounded-full bg-white/15 px-2.5 py-1 text-[10px] font-bold uppercase">Total</span>
                        </div>
                        <div class="mt-auto pt-5" :aria-busy="documentsLoading">
                            <i v-if="documentsLoading" class="pi pi-spin pi-spinner text-3xl"></i>
                            <span v-else-if="documentsError" class="text-sm font-semibold">Unavailable</span>
                            <strong v-else class="text-4xl leading-none">{{ documentsTotal }}</strong>
                            <p class="mt-2 text-sm font-semibold text-white/90">Uploaded Documents</p>
                        </div>
                    </div>
                </template>
            </Card>

            <Card class="overflow-hidden !rounded-2xl !border-0 !bg-red-500 !text-white shadow-lg">
                <template #content>
                    <div class="flex min-h-40 flex-col p-1">
                        <div class="flex items-start justify-between">
                            <span class="flex size-11 items-center justify-center rounded-xl bg-white/15">
                                <i class="pi pi-bookmark text-xl"></i>
                            </span>
                            <span class="rounded-full bg-white/15 px-2.5 py-1 text-[10px] font-bold uppercase">Progress</span>
                        </div>
                        <div class="mt-auto pt-5" :aria-busy="otgLoading">
                            <i v-if="otgLoading" class="pi pi-spin pi-spinner text-3xl"></i>
                            <span v-else-if="otgError" class="text-sm font-semibold">Unavailable</span>
                            <template v-else>
                                <div class="flex items-end justify-between gap-3">
                                    <strong class="text-4xl leading-none">{{ otgPercentage }}%</strong>
                                    <span class="text-xs font-semibold text-white/85">{{ otgCompleted }}/{{ otgTotal }} tasks</span>
                                </div>
                                <ProgressBar
                                    :value="otgPercentage ?? 0"
                                    :show-value="false"
                                    class="mt-3 !h-2 !bg-white/20"
                                />
                            </template>
                            <p class="mt-2 text-sm font-semibold text-white/90">OTG Task Completion</p>
                        </div>
                    </div>
                </template>
            </Card>

            <Card class="overflow-hidden !rounded-2xl !border-0 !bg-blue-500 !text-white shadow-lg">
                <template #content>
                    <div class="flex min-h-40 flex-col p-1">
                        <div class="flex items-start justify-between">
                            <span class="flex size-11 items-center justify-center rounded-xl bg-white/15">
                                <i class="pi pi-book text-xl"></i>
                            </span>
                            <span class="rounded-full bg-white/15 px-2.5 py-1 text-[10px] font-bold uppercase">Total</span>
                        </div>
                        <div class="mt-auto pt-5" :aria-busy="journalsLoading">
                            <i v-if="journalsLoading" class="pi pi-spin pi-spinner text-3xl"></i>
                            <span v-else-if="journalsError" class="text-sm font-semibold">Unavailable</span>
                            <strong v-else class="text-4xl leading-none">{{ journalsTotal }}</strong>
                            <p class="mt-2 text-sm font-semibold text-white/90">Daily Journals</p>
                        </div>
                    </div>
                </template>
            </Card>
        </section>

        <section class="space-y-5">
            <div v-if="activitiesError" class="rounded-xl bg-red-50 p-3 text-sm text-red-700">
                {{ activitiesError }}
            </div>

            <Datatable
                title="Recent Activities"
                description="Your latest 10 submitted activities."
                header-icon="pi pi-bolt"
                search-placeholder="Search activities..."
                empty-title="No activities found"
                empty-description="Your submitted activities will appear here."
                empty-icon="pi pi-bolt"
                table-min-width="1250px"
                data-key="id"
                :loading="activitiesLoading"
                :data="activities"
                :columns="activityColumns"
                :actions="activityActions"
                :paginator="false"
                actions-header="Actions"
                actions-width="90px"
                @action="handleActivityAction"
            >
                <template #header-actions>
                    <Button
                        type="button"
                        icon="pi pi-refresh"
                        severity="secondary"
                        variant="outlined"
                        rounded
                        :loading="activitiesLoading"
                        aria-label="Refresh activities"
                        title="Refresh activities"
                        @click="loadActivities"
                    />
                </template>

                <template #cell-description="{ data }">
                    <span class="font-semibold text-slate-800">
                        {{ data.description }}
                    </span>
                </template>

                <template #cell-activity_period="{ data }">
                    {{ formatActivityPeriod(data) }}
                </template>

                <template #cell-filename="{ data }">
                    {{ data.filename || '—' }}
                </template>

                <template #cell-last_update="{ data }">
                    {{ formatDateTime(data.last_update) }}
                </template>

                <template #cell-status="{ data }">
                    <Tag
                        :value="data.status"
                        :severity="statusSeverity(data.status)"
                    />
                </template>

                <template #cell-revise_remarks="{ data }">
                    {{ data.revise_remarks || '—' }}
                </template>
            </Datatable>

            <div v-if="documentsError" class="rounded-xl bg-red-50 p-3 text-sm text-red-700">
                {{ documentsError }}
            </div>

            <Datatable
                title="Recent Uploaded Documents"
                description="Your latest 10 uploaded requirements."
                header-icon="pi pi-file-arrow-up"
                search-placeholder="Search documents..."
                empty-title="No uploaded documents found"
                empty-description="Your uploaded requirements will appear here."
                empty-icon="pi pi-file"
                table-min-width="1100px"
                data-key="id"
                :loading="documentsLoading"
                :data="documents"
                :columns="documentColumns"
                :actions="documentActions"
                :paginator="false"
                actions-header="Actions"
                actions-width="110px"
            >
                <template #header-actions>
                    <Button
                        type="button"
                        icon="pi pi-refresh"
                        severity="secondary"
                        variant="outlined"
                        rounded
                        :loading="documentsLoading"
                        aria-label="Refresh documents"
                        title="Refresh documents"
                        @click="loadDocuments"
                    />
                </template>

                <template #cell-description="{ data }">
                    <span class="font-semibold text-slate-800">
                        {{ data.description }}
                    </span>
                </template>

                <template #cell-requirement="{ data }">
                    {{ data.requirement || '—' }}
                </template>

                <template #cell-uploaded_at="{ data }">
                    <span>{{ formatDate(data.date_uploaded) }}</span>
                    <span
                        v-if="data.time_uploaded"
                        class="block text-xs text-slate-500"
                    >
                        {{ data.time_uploaded }}
                    </span>
                </template>

                <template #cell-status="{ data }">
                    <Tag
                        :value="data.status"
                        :severity="statusSeverity(data.status)"
                    />
                </template>

                <template #cell-revise_remarks="{ data }">
                    {{ data.revise_remarks || '—' }}
                </template>

                <template #actions="{ data }">
                    <div class="flex w-full items-center justify-start gap-2">
                        <Button
                            v-for="file in data.files"
                            :key="file.url"
                            as="a"
                            :href="file.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            icon="pi pi-download"
                            icon-only
                            rounded
                            raised
                            severity="info"
                            class="!size-10 !min-h-10 !min-w-10 !shrink-0 !p-0"
                            :aria-label="`Download ${file.name}`"
                            :title="`Download ${file.name}`"
                        />

                        <Button
                            v-if="!data.files?.length"
                            type="button"
                            icon="pi pi-download"
                            icon-only
                            rounded
                            raised
                            severity="secondary"
                            disabled
                            class="!size-10 !min-h-10 !min-w-10 !shrink-0 !p-0"
                            aria-label="No document available"
                            title="No document available"
                        />
                    </div>
                </template>
            </Datatable>

            <div v-if="otgError" class="rounded-xl bg-red-50 p-3 text-sm text-red-700">
                {{ otgError }}
            </div>

            <Datatable
                title="Recent Completed OTG Tasks"
                description="Your latest 10 completed onboard tasks."
                header-icon="pi pi-bookmark"
                search-placeholder="Search OTG tasks..."
                empty-title="No completed OTG tasks found"
                empty-description="Your completed onboard tasks will appear here."
                empty-icon="pi pi-bookmark"
                table-min-width="900px"
                data-key="id"
                :loading="otgLoading"
                :data="otgTasks"
                :columns="otgColumns"
                :actions="[]"
                :show-actions="false"
                :paginator="false"
            >
                <template #header-actions>
                    <Button
                        type="button"
                        icon="pi pi-refresh"
                        severity="secondary"
                        variant="outlined"
                        rounded
                        :loading="otgLoading"
                        aria-label="Refresh OTG tasks"
                        title="Refresh OTG tasks"
                        @click="loadOtg"
                    />
                </template>

                <template #cell-description="{ data }">
                    <div class="min-w-0 max-w-2xl">
                        <p class="font-semibold text-slate-800">
                            {{ otgReference(data) }}
                        </p>
                        <p
                            class="mt-1 line-clamp-2 break-words text-sm leading-5 text-slate-600"
                            :title="otgDescription(data)"
                        >
                            {{ otgDescription(data) }}
                        </p>
                    </div>
                </template>

                <template #cell-month_number="{ data }">
                    {{ otgMonth(data) }}
                </template>

                <template #cell-completed_at="{ data }">
                    {{ otgCompletedAt(data) }}
                </template>

                <template #cell-status="{ data }">
                    <Tag
                        :value="data.status || 'Completed'"
                        :severity="statusSeverity(data.status || 'Completed')"
                    />
                </template>
            </Datatable>

            <div v-if="journalsError" class="rounded-xl bg-red-50 p-3 text-sm text-red-700">
                {{ journalsError }}
            </div>

            <Datatable
                title="Recent Daily Journals"
                description="Your latest 10 journal entries."
                header-icon="pi pi-book"
                search-placeholder="Search journals..."
                empty-title="No journal entries found"
                empty-description="Your daily journal entries will appear here."
                empty-icon="pi pi-book"
                table-min-width="1250px"
                data-key="id"
                :loading="journalsLoading"
                :data="journals"
                :columns="journalColumns"
                :actions="journalActions"
                :paginator="false"
                actions-header="Actions"
                actions-width="90px"
                @action="handleJournalAction"
            >
                <template #header-actions>
                    <Button
                        type="button"
                        icon="pi pi-refresh"
                        severity="secondary"
                        variant="outlined"
                        rounded
                        :loading="journalsLoading"
                        aria-label="Refresh journals"
                        title="Refresh journals"
                        @click="loadJournals"
                    />
                </template>

                <template #cell-date_journal="{ data }">
                    <span class="font-semibold text-slate-800">
                        {{ formatDate(data.date_journal) }}
                    </span>
                </template>

                <template #cell-vessel_name="{ data }">
                    {{ data.vessel_name || '—' }}
                </template>

                <template #cell-duty_time="{ data }">
                    {{ journalTime(data) }}
                    <span
                        v-if="data.duty_hours !== null"
                        class="block text-xs text-slate-500"
                    >
                        {{ data.duty_hours }} hour(s)
                    </span>
                </template>

                <template #cell-voyage="{ data }">
                    {{ data.port_depart || '—' }}
                    →
                    {{ data.port_dest || '—' }}
                </template>

                <template #cell-activities="{ data }">
                    <span
                        class="line-clamp-3 max-w-xl"
                        :title="data.activities || ''"
                    >
                        {{ data.activities || '—' }}
                    </span>
                </template>

                <template #cell-status="{ data }">
                    <Tag
                        :value="data.status"
                        :severity="statusSeverity(data.status)"
                    />
                </template>
            </Datatable>
        </section>
    </div>
</template>
