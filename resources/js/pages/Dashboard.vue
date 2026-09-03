<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import Card from 'primevue/card';
import PrimeTag from 'primevue/tag';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Datatable from '@/components/Datatable.vue';
import { dashboard } from '@/routes';
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
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});
type DashboardCard = {
    label: string;
    value: number;
    icon: string;
    iconBg: string;
    iconText: string;
    valueText: string;
    canCreateBatch?: boolean;
};
type StudentApiResponse = {
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
type StudentPageEvent = {
    page: number;
    rows: number;
    first: number;
};
type StudentSortEvent = {
    sortField: string;
    sortOrder: number;
};
type DepartmentSeverity = 'success' | 'info' | 'secondary';
const page = usePage<SharedData>();
const school = computed(() => page.props.school);
const currentDate = ref('');
const currentTime = ref('');
let clockInterval: ReturnType<typeof setInterval> | null = null;
/*
|--------------------------------------------------------------------------
| Dashboard Summary Cards
|--------------------------------------------------------------------------
*/
const dashboardCards: DashboardCard[] = [
    {
        label: 'Activity Updates - (VERIFICATION)',
        value: 12,
        icon: 'pi pi-bell',
        iconBg: 'bg-orange-500',
        iconText: 'text-white',
        valueText: 'text-orange-600',
    },
    {
        label: 'Uploaded Documents - (VERIFICATION)',
        value: 0,
        icon: 'pi pi-file',
        iconBg: 'bg-emerald-500',
        iconText: 'text-white',
        valueText: 'text-emerald-600',
    },
    {
        label: 'OTG Updates (Current Month)',
        value: 975,
        icon: 'pi pi-book',
        iconBg: 'bg-red-500',
        iconText: 'text-white',
        valueText: 'text-red-600',
    },
    {
        label: 'Daily Journals (Current Month)',
        value: 16,
        icon: 'pi pi-calendar',
        iconBg: 'bg-blue-500',
        iconText: 'text-white',
        valueText: 'text-blue-600',
    },
    {
        label: 'Theoretical Assessments (Enrolled)',
        value: 16,
        icon: 'pi pi-graduation-cap',
        iconBg: 'bg-amber-500',
        iconText: 'text-white',
        valueText: 'text-amber-600',
        canCreateBatch: true,
    },
    {
        label: 'Theoretical Assessments (Not Enrolled)',
        value: 16,
        icon: 'pi pi-question-circle',
        iconBg: 'bg-amber-500',
        iconText: 'text-white',
        valueText: 'text-amber-600',
        canCreateBatch: true,
    },
    {
        label: 'Practical Assessments (Enrolled)',
        value: 16,
        icon: 'pi pi-clipboard',
        iconBg: 'bg-violet-500',
        iconText: 'text-white',
        valueText: 'text-violet-600',
        canCreateBatch: true,
    },
    {
        label: 'Practical Assessments (Not Enrolled)',
        value: 16,
        icon: 'pi pi-wrench',
        iconBg: 'bg-violet-500',
        iconText: 'text-white',
        valueText: 'text-violet-600',
        canCreateBatch: true,
    },
];
/*
|--------------------------------------------------------------------------
| Student Monitoring Columns
|--------------------------------------------------------------------------
*/
const studentColumns: DataTableColumn[] = [
    {
        field: 'fname',
        header: 'Student Information',
        sortable: false,
        searchable: true,
        frozen: true,
        alignFrozen: 'left',
        class: 'min-w-[360px]',
    },
    {
        field: 'school_id_no',
        header: 'School ID No.',
        sortable: false,
        searchable: true,
        class: 'min-w-[170px]',
    },
    {
        field: 'batch_no',
        header: 'CCI Year',
        sortable: false,
        searchable: true,
        class: 'min-w-[130px] text-center',
    },
    {
        field: 'company',
        header: 'Company',
        sortable: false,
        searchable: true,
        class: 'min-w-[170px]',
    },
    {
        field: 'vessel_type',
        header: 'Vessel Type',
        sortable: false,
        searchable: true,
        class: 'min-w-[170px]',
    },
    {
        field: 'total_activities',
        header: 'Activities',
        sortable: false,
        searchable: true,
        class: 'min-w-[130px] text-center',
    },
    {
        field: 'total_file_upload',
        header: 'File',
        sortable: false,
        searchable: true,
        class: 'min-w-[130px] text-center',
    },
    {
        field: 'total_daily_journal',
        header: 'Journals',
        sortable: false,
        searchable: true,
        class: 'min-w-[130px] text-center',
    },
    {
        field: 'total_task',
        header: 'Tasks',
        sortable: false,
        searchable: true,
        class: 'min-w-[130px] text-center',
    },
    {
        field: 'task_completed',
        header: 'Tasks Completed',
        sortable: false,
        searchable: true,
        class: 'min-w-[130px] text-center',
    },
    {
        field: 'task_percentage',
        header: 'Task %',
        sortable: false,
        searchable: true,
        class: 'min-w-[130px] text-center',
    },
    {
        field: 'last_update',
        header: 'Last Update',
        sortable: true,
        class: 'min-w-[200px]',
    },

];
/*
|--------------------------------------------------------------------------
| Student Monitoring Actions
|--------------------------------------------------------------------------
*/
const studentActions: DataTableAction[] = [
    {
        key: 'view',
        label: 'View',
        icon: 'pi pi-eye',
        severity: 'info',
    },
];
/*
|--------------------------------------------------------------------------
| Student API State
|--------------------------------------------------------------------------
*/
const students = ref<DataTableRow[]>([]);
const studentsLoading = ref(false);
const studentsTotal = ref(0);
const studentsFirst = ref(0);
const studentsPerPage = ref(10);
const studentsSearch = ref('');
const studentsSortField = ref('last_update');
const studentsSortDirection = ref<'asc' | 'desc'>('desc');
let studentRequestController: AbortController | null = null;
/*
|--------------------------------------------------------------------------
| Student API
|--------------------------------------------------------------------------
*/
async function loadStudents(pageNumber = 1): Promise<void> {
    studentRequestController?.abort();
    const controller = new AbortController();
    studentRequestController = controller;
    studentsLoading.value = true;
    try {
        const response = await axios.get<StudentApiResponse>(
            '/api/v1/dashboard/students',
            {
                signal: controller.signal,
                params: {
                    page: pageNumber,
                    per_page: studentsPerPage.value,
                    search: studentsSearch.value,
                    sort_field: studentsSortField.value,
                    sort_direction:
                        studentsSortDirection.value,
                },
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                withCredentials: true,
            },
        );
        students.value = response.data.data;
        studentsTotal.value = response.data.meta.total;
        studentsPerPage.value =
            response.data.meta.perPage;
        studentsFirst.value =
            (response.data.meta.currentPage - 1) *
            response.data.meta.perPage;
    } catch (error: unknown) {
        if (
            axios.isCancel(error) ||
            (
                axios.isAxiosError(error) &&
                error.code === 'ERR_CANCELED'
            )
        ) {
            return;
        }
        students.value = [];
        studentsTotal.value = 0;
        if (axios.isAxiosError(error)) {
            console.error(
                'Unable to load students:',
                error.response?.data ?? error.message,
            );
            return;
        }
        console.error('Unable to load students:', error);
    } finally {
        if (studentRequestController === controller) {
            studentsLoading.value = false;
        }
    }
}
function handleStudentPage(event: StudentPageEvent): void {
    studentsPerPage.value = event.rows;
    studentsFirst.value = event.first;
    void loadStudents(event.page + 1);
}
function handleStudentSort(event: StudentSortEvent): void {
    studentsSortField.value =
        event.sortField || 'last_update';
    studentsSortDirection.value =
        event.sortOrder === -1 ? 'desc' : 'asc';
    studentsFirst.value = 0;
    void loadStudents(1);
}
function handleStudentSearch(value: string): void {
    studentsSearch.value = value;
    studentsFirst.value = 0;
    void loadStudents(1);
}
/*
|--------------------------------------------------------------------------
| Clock
|--------------------------------------------------------------------------
*/
function updatePhilippineTime(): void {
    const now = new Date();
    currentDate.value = new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        month: 'long',
        day: 'numeric',
        year: 'numeric',
    }).format(now);
    currentTime.value = new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
    }).format(now);
}
onMounted(() => {
    updatePhilippineTime();
    void loadStudents();
    clockInterval = setInterval(() => {
        updatePhilippineTime();
    }, 1000);
});
onBeforeUnmount(() => {
    if (clockInterval) {
        clearInterval(clockInterval);
    }
    studentRequestController?.abort();
});
/*
|--------------------------------------------------------------------------
| Student Helpers
|--------------------------------------------------------------------------
*/
function getStudentFullName(student: DataTableRow): string {
    return [
        student.fname,
        student.mname,
        student.lname,
    ]
        .filter((name) => {
            return (
                typeof name === 'string' &&
                name.trim() !== ''
            );
        })
        .map((name) => String(name).trim())
        .join(' ')
        .toUpperCase();
}
function getStudentInitials(student: DataTableRow): string {
    const firstName = String(student.fname ?? '').trim();
    const lastName = String(student.lname ?? '').trim();
    const initials =
        `${firstName.charAt(0)}${lastName.charAt(0)}`;
    return initials.toUpperCase() || 'ST';
}
function getStudentAvatar(gender: unknown): string | null {
    const normalizedGender = String(gender ?? '')
        .trim()
        .toUpperCase();
    if (normalizedGender === 'MALE') {
        return '/images/male-cadet.png';
    }
    if (normalizedGender === 'FEMALE') {
        return '/images/female-cadet.png';
    }
    return null;
}
function getDepartmentSeverity(
    department: unknown,
): DepartmentSeverity {
    const firstLetter = String(department ?? '')
        .trim()
        .charAt(0)
        .toUpperCase();
    if (firstLetter === 'D') {
        return 'success';
    }
    if (firstLetter === 'E') {
        return 'info';
    }
    return 'secondary';
}
function getDepartmentIcon(department: unknown): string {
    const firstLetter = String(department ?? '')
        .trim()
        .charAt(0)
        .toUpperCase();
    if (firstLetter === 'D') {
        return 'pi pi-compass';
    }
    if (firstLetter === 'E') {
        return 'pi pi-cog';
    }
    return 'pi pi-building';
}
function getDepartmentLabel(department: unknown): string {
    const value = String(department ?? '').trim();
    if (!value) {
        return '—';
    }
    return value.toUpperCase();
}
function getSystemIdLabel(systemId: unknown): string {
    const value = String(systemId ?? '').trim();
    return value || 'No System ID';
}
function formatLastUpdate(value: unknown): string {
    if (!value) {
        return '—';
    }
    const rawValue = String(value);
    const normalizedValue = rawValue.includes('T')
        ? rawValue
        : rawValue.replace(' ', 'T');
    const date = new Date(normalizedValue);
    if (Number.isNaN(date.getTime())) {
        return rawValue;
    }
    return new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
    }).format(date);
}
function handleStudentAction(
    action: string,
    student: DataTableRow,
): void {
    if (action === 'view') {
        console.log('View student:', student);
    }
}
</script>
<template>
    <Head title="Dashboard" />
    <div
        class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5"
    >
        <!-- SCHOOL HERO SECTION -->
        <section
            class="relative overflow-hidden rounded-2xl border border-[#377EC0]/15 bg-white shadow-sm"
        >
            <div
                class="pointer-events-none absolute inset-0 bg-gradient-to-r from-[#377EC0]/[0.07] via-white to-[#377EC0]/[0.04]"
            />
            <div
                class="pointer-events-none absolute -top-28 -right-24 h-72 w-72 rounded-full bg-[#377EC0]/[0.08]"
            />
            <div
                class="pointer-events-none absolute right-32 -bottom-28 h-56 w-56 rounded-full border-[28px] border-[#377EC0]/[0.04]"
            />
            <div
                class="pointer-events-none absolute top-5 left-[43%] h-20 w-20 rounded-full border border-[#377EC0]/10"
            />
            <div
                class="relative z-10 flex min-h-[165px] flex-col justify-between gap-6 px-6 py-5 md:flex-row md:items-center lg:px-8 lg:py-6"
            >
                <!-- SCHOOL IDENTITY -->
                <div class="flex min-w-0 items-center gap-5 lg:gap-7">
                    <div
                        class="flex size-24 shrink-0 items-center justify-center rounded-2xl border border-[#377EC0]/15 bg-white p-3 shadow-sm sm:size-28 lg:size-32"
                    >
                        <img
                            :src="school.logo"
                            :alt="school.name"
                            class="h-full w-full object-contain"
                        />
                    </div>
                    <div class="min-w-0">
                        <h1
                            class="text-2xl leading-tight font-bold tracking-tight text-[#21365A] sm:text-3xl lg:text-4xl"
                        >
                            {{ school.name }}
                        </h1>
                        <p
                            class="mt-2 text-sm font-medium text-slate-500 sm:text-base"
                        >
                            IRIS — Student Activity Monitoring System
                        </p>
                        <p
                            class="mt-1 hidden max-w-[650px] text-sm leading-6 text-slate-400 lg:block"
                        >
                            A centralized platform for monitoring,
                            organizing, and managing student activities.
                        </p>
                    </div>
                </div>
                <!-- DATE AND TIME -->
                <div
                    class="flex min-w-[290px] shrink-0 items-center gap-4 rounded-2xl border border-slate-200 bg-white/95 px-5 py-3.5 shadow-sm backdrop-blur"
                >
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#377EC0]/10"
                    >
                        <i
                            class="pi pi-calendar text-lg text-[#377EC0]"
                        />
                    </div>
                    <div class="flex-1 text-right">
                        <div class="text-sm font-medium text-slate-500">
                            {{ currentDate }}
                        </div>
                        <div
                            class="mt-0.5 whitespace-nowrap font-mono text-2xl font-bold tracking-tight text-slate-900 lg:text-[28px]"
                        >
                            {{ currentTime }}
                        </div>
                        <div
                            class="mt-0.5 text-[10px] font-semibold tracking-[0.15em] text-slate-400 uppercase"
                        >
                            Philippine Standard Time
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <!-- DASHBOARD CARDS -->
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Card
                v-for="item in dashboardCards"
                :key="item.label"
                class="dashboard-card overflow-hidden rounded-2xl border border-slate-200 !bg-white !text-slate-900 shadow-sm"
            >
                <template #content>
                    <div class="relative">
                        <div class="flex items-center justify-between">
                            <div
                                :class="[
                                    'flex h-11 w-11 items-center justify-center rounded-xl shadow-sm',
                                    item.iconBg,
                                ]"
                            >
                                <i
                                    :class="[
                                        item.icon,
                                        item.iconText,
                                        'text-lg',
                                    ]"
                                />
                            </div>
                            <button
                                type="button"
                                class="flex h-7 w-7 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                                aria-label="More options"
                            >
                                <i class="pi pi-ellipsis-v text-xs" />
                            </button>
                        </div>
                        <div class="mt-3">
                            <p class="text-sm font-medium text-slate-500">
                                {{ item.label }}
                            </p>
                            <h2
                                :class="[
                                    'mt-0.5 text-[34px] leading-none font-bold tracking-tight',
                                    item.valueText,
                                ]"
                            >
                                {{ item.value }}
                            </h2>
                        </div>
                        <div
                            class="mt-4 flex items-end justify-between gap-3"
                        >
                            <div
                                class="flex flex-wrap items-center gap-x-4 gap-y-2"
                            >
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 transition hover:text-[#377EC0]"
                                >
                                    <i class="pi pi-eye text-xs" />
                                    View details
                                </button>
                                <button
                                    v-if="item.canCreateBatch"
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-[#377EC0]/10 px-2.5 py-1.5 text-xs font-semibold text-[#377EC0] transition hover:bg-[#377EC0] hover:text-white"
                                >
                                    <i class="pi pi-users text-[11px]" />
                                    Create Batch Examinees
                                </button>
                            </div>
                            <div
                                class="flex h-8 shrink-0 items-end gap-1 opacity-40"
                            >
                                <span
                                    class="h-3 w-1.5 rounded-full bg-slate-300"
                                />
                                <span
                                    class="h-5 w-1.5 rounded-full bg-slate-300"
                                />
                                <span
                                    class="h-8 w-1.5 rounded-full bg-slate-300"
                                />
                            </div>
                        </div>
                    </div>
                </template>
            </Card>
        </div>
        <!-- STUDENT MONITORING TABLE -->
        <Datatable
            title="Student Monitoring"
            description="Student records ordered by their latest update."
            header-icon="pi pi-users"
            search-placeholder="Search students..."
            empty-title="No students found"
            empty-description="No matching student records were found."
            empty-icon="pi pi-users"
            table-min-width="900px"
            data-key="code_person"
            lazy
            :loading="studentsLoading"
            :data="students"
            :columns="studentColumns"
            :actions="studentActions"
            :total-records="studentsTotal"
            :first="studentsFirst"
            :rows="studentsPerPage"
            :rows-per-page-options="[10, 20, 50, 100]"
            @page="handleStudentPage"
            @sort="handleStudentSort"
            @search="handleStudentSearch"
            @action="handleStudentAction"
        >
            <!-- STUDENT NAME, DEPARTMENT, AND SYSTEM ID -->
            <template #cell-fname="{ data }">
                <div class="flex items-center gap-3">
                    <!-- AVATAR -->
                    <div
                        class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[#377EC0]/10 text-xs font-bold text-[#377EC0]"
                    >
                        <img
                            v-if="getStudentAvatar(data.gender)"
                            :src="
                                getStudentAvatar(data.gender) ??
                                undefined
                            "
                            :alt="getStudentFullName(data)"
                            class="h-full w-full object-cover"
                        />
                        <span v-else>
                            {{ getStudentInitials(data) }}
                        </span>
                    </div>
                    <!-- DETAILS -->
                    <div class="min-w-0">
                        <p
                            class="truncate font-semibold text-slate-700"
                        >
                            {{ getStudentFullName(data) || '—' }}
                        </p>
                        <div
                            class="mt-1 flex flex-wrap items-center gap-1.5"
                        >
                            <!-- DEPARTMENT TAG -->
                            <PrimeTag
                                :value="getDepartmentLabel(data.dept)"
                                :severity="getDepartmentSeverity(data.dept)"
                                :icon="getDepartmentIcon(data.dept)"
                                class="!px-2 !py-0.5 !text-[15px] !font-semibold"
                            />
                            <!-- SYSTEM ID TAG -->
                            <PrimeTag
                                :value="
                                    getSystemIdLabel(
                                        data.code_person,
                                    )
                                "
                                severity="secondary"
                                icon="pi pi-id-card"
                                class="!px-2 !py-0.5 !text-[15px] !font-semibold"
                            />
                        </div>
                    </div>
                </div>
            </template>
            <!-- SCHOOL ID -->
            <template #cell-school_id_no="{ value }">
                <span class="font-medium text-slate-700">
                    {{ value || '—' }}
                </span>
            </template>
            <!-- CCI YEAR -->
            <template #cell-batch_no="{ value }">
                <span
                    class="inline-flex rounded-md bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700"
                >
                    {{ value || '—' }}
                </span>
            </template>
            <template #cell-company="{ value }">
                <span class="font-medium text-slate-700">
                    {{ value || '—' }}
                </span>
            </template>
            <template #cell-vessel_type="{ value }">
                <span class="font-medium text-slate-700">
                    {{ value || '—' }}
                </span>
            </template>
            <template #cell-total_activities="{ value }">
                <span
                    class="inline-flex rounded-md bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-700"
                >
                    {{ value || '—' }}
                </span>
            </template>
            <template #cell-total_file_upload="{ value }">
                <span
                    class="inline-flex rounded-md bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700"
                >
                    {{ value || '—' }}
                </span>
            </template>
            <template #cell-total_daily_journal="{ value }">
                <span
                    class="inline-flex rounded-md bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700"
                >
                    {{ value || '—' }}
                </span>
            </template>
            <template #cell-total_task="{ value }">
                <span class="font-medium text-slate-700">
                    {{ value || '—' }}
                </span>
            </template>
            <template #cell-task_completed="{ value }">
                <span class="font-medium text-slate-700">
                    {{ value || '—' }}
                </span>
            </template>
            <template #cell-task_percentage="{ value }">
                <span class="font-medium text-slate-700">
                    {{ value || '—' }}%
                </span>
            </template>
            <!-- LAST UPDATE -->
            <template #cell-last_update="{ value }">
                <span class="whitespace-nowrap text-sm text-slate-500">
                    {{ formatLastUpdate(value) }}
                </span>
            </template>
        </Datatable>
    </div>
</template>
