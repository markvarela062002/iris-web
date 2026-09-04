<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
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
    cardBg: string;
    href?: string;
    canCreateBatch?: boolean;
};

type StudentApiResponse = {
    /*

     * These totals are only present when the request included

     * include_totals=1. Marked optional so TypeScript reflects that the

     * backend omits them on every request after the initial page load.

     */

    activity_verification_total?: number;
    documents_upload_total?: number;
    otg_updates_total?: number;
    daily_journals_total?: number;
    theoretical_enrolled_total?: number;

    theoretical_not_enrolled_total?: number;

    practical_enrolled_total?: number;

    practical_not_enrolled_total?: number;

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

const dashboardTotals = ref({
    activityVerification: 0,

    documentsUpload: 0,

    otgUpdates: 0,

    dailyJournals: 0,

    theoreticalEnrolled: 0,

    theoreticalNotEnrolled: 0,

    practicalEnrolled: 0,

    practicalNotEnrolled: 0,
});

const dashboardCards = computed<DashboardCard[]>(() => [
    {
        label: 'Activity Updates - (VERIFICATION)',

        value: dashboardTotals.value.activityVerification,

        icon: 'pi pi-bell',

        cardBg: '!bg-orange-500',
    href: '/dashboard/activity-updates',

    },

    {
        label: 'Uploaded Documents - (VERIFICATION)',

        value: dashboardTotals.value.documentsUpload,

        icon: 'pi pi-file',

        cardBg: '!bg-emerald-500',
    },

    {
        label: 'OTG Updates (Current Month)',

        value: dashboardTotals.value.otgUpdates,

        icon: 'pi pi-book',

        cardBg: '!bg-red-500',
    },

    {
        label: 'Daily Journals (Current Month)',

        value: dashboardTotals.value.dailyJournals,

        icon: 'pi pi-calendar',

        cardBg: '!bg-blue-500',
    },

    {
        label: 'Theoretical Assessments (Enrolled)',

        value: dashboardTotals.value.theoreticalEnrolled,

        icon: 'pi pi-graduation-cap',

        cardBg: '!bg-amber-500',

        canCreateBatch: true,
    },

    {
        label: 'Theoretical Assessments (External)',

        value: dashboardTotals.value.theoreticalNotEnrolled,

        icon: 'pi pi-question-circle',

        cardBg: '!bg-yellow-500',

        canCreateBatch: true,
    },

    {
        label: 'Practical Assessments (Enrolled)',

        value: dashboardTotals.value.practicalEnrolled,

        icon: 'pi pi-clipboard',

        cardBg: '!bg-violet-500',

        canCreateBatch: true,
    },

    {
        label: 'Practical Assessments (External)',

        value: dashboardTotals.value.practicalNotEnrolled,

        icon: 'pi pi-wrench',

        cardBg: '!bg-purple-500',

        canCreateBatch: true,
    },
]);

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
        field: 'company',

        header: 'Vessel Type / Company',

        sortable: false,

        searchable: true,

        class: 'w-[280px] min-w-[280px] whitespace-normal',
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
        field: 'task_completed',

        header: 'OTG Task Progress',

        sortable: false,

        searchable: false,

        class: 'w-[240px] min-w-[240px]',
    },

    {
        field: 'first_sign_on',

        header: 'First Sign On',

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
        key: 'print-otg',

        label: 'Print OTG',

        icon: 'pi pi-file-pdf',

        severity: 'danger',
        
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

const studentListType = ref<1 | 2>(1);

const studentsSortField = ref('lname');

const studentsSortDirection = ref<'asc' | 'desc'>('asc');

let studentRequestController: AbortController | null = null;

/*

|--------------------------------------------------------------------------

| Student API

|--------------------------------------------------------------------------

*/

/**

 * @param pageNumber The page to load.

 * @param includeTotals Whether to ask the backend to also compute the 8

 *        dashboard summary totals. These totals don't change based on

 *        search/sort/page/filter, so they're only requested once on the

 *        initial mount — every subsequent call omits them to avoid

 *        recomputing them on every table interaction.

 */

async function loadStudents(
    pageNumber = 1,

    includeTotals = false,
): Promise<void> {
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

                    sort_direction: studentsSortDirection.value,

                    list_type: studentListType.value,

                    include_totals: includeTotals ? 1 : 0,
                },

                headers: {
                    Accept: 'application/json',

                    'X-Requested-With': 'XMLHttpRequest',
                },

                withCredentials: true,
            },
        );

        students.value = response.data.data;

        /*

         * Only overwrite dashboardTotals when the response actually

         * included them (i.e. this was the include_totals=1 request).

         * On every other request these fields are absent, and the

         * previously-loaded totals stay on screen unchanged.

         */

        if (response.data.activity_verification_total !== undefined) {
            dashboardTotals.value = {
                activityVerification:
                    response.data.activity_verification_total ?? 0,

                documentsUpload: response.data.documents_upload_total ?? 0,

                otgUpdates: response.data.otg_updates_total ?? 0,

                dailyJournals: response.data.daily_journals_total ?? 0,

                theoreticalEnrolled:
                    response.data.theoretical_enrolled_total ?? 0,

                theoreticalNotEnrolled:
                    response.data.theoretical_not_enrolled_total ?? 0,

                practicalEnrolled: response.data.practical_enrolled_total ?? 0,

                practicalNotEnrolled:
                    response.data.practical_not_enrolled_total ?? 0,
            };
        }

        studentsTotal.value = response.data.meta.total;

        studentsPerPage.value = response.data.meta.perPage;

        studentsFirst.value =
            (response.data.meta.currentPage - 1) * response.data.meta.perPage;
    } catch (error: unknown) {
        if (
            axios.isCancel(error) ||
            (axios.isAxiosError(error) && error.code === 'ERR_CANCELED')
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
    studentsSortField.value = event.sortField || 'lname';

    studentsSortDirection.value = event.sortOrder === -1 ? 'desc' : 'asc';

    studentsFirst.value = 0;

    void loadStudents(1);
}

function handleStudentListTypeChange(): void {
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

    void loadStudents(1, true);

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
    return [student.fname, student.mname, student.lname]

        .filter((name) => {
            return typeof name === 'string' && name.trim() !== '';
        })

        .map((name) => String(name).trim())

        .join(' ')

        .toUpperCase();
}

function getStudentInitials(student: DataTableRow): string {
    const firstName = String(student.fname ?? '').trim();

    const lastName = String(student.lname ?? '').trim();

    const initials = `${firstName.charAt(0)}${lastName.charAt(0)}`;

    return initials.toUpperCase() || 'ST';
}

function getStudentAvatar(gender: unknown): string | null {
    const normalizedGender = String(gender ?? '')
        .trim()

        .toUpperCase();

    if (normalizedGender === 'M' || normalizedGender === 'MALE') {
        return '/images/male-cadet.png';
    }

    if (normalizedGender === 'F' || normalizedGender === 'FEMALE') {
        return '/images/female-cadet.png';
    }

    return null;
}

function getDepartmentSeverity(department: unknown): DepartmentSeverity {
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

function getSchoolIdLabel(schoolId: unknown): string {
    const value = String(schoolId ?? '').trim();

    return value || 'No School ID';
}

function getNumber(value: unknown): number {
    const parsed = Number(value ?? 0);

    return Number.isFinite(parsed) ? parsed : 0;
}

function formatTaskCount(value: unknown): string {
    return new Intl.NumberFormat('en-PH').format(getNumber(value));
}

function formatTaskPercentage(value: unknown): string {
    return getNumber(value).toFixed(1);
}

function getTaskProgressWidth(value: unknown): string {
    const percentage = Math.min(100, Math.max(0, getNumber(value)));

    return `${percentage}%`;
}

function formatFirstSignOn(value: unknown): string {
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
    }).format(date);
}

function handleStudentAction(
    action: string,

    student: DataTableRow,
): void {
    if (action !== 'print-otg') {
        return;
    }

    const personId = String(student.id ?? '').trim();

    if (!personId) {
        console.error('Unable to print OTG: student ID is missing.');

        return;
    }

    window.open(
        `/students/${encodeURIComponent(personId)}/otg/print`,

        '_blank',

        'noopener,noreferrer',
    );
}
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5">
        <!-- SCHOOL HERO SECTION -->

        <section
            class="relative overflow-hidden rounded-2xl border border-[#377EC0]/15 bg-white shadow-sm"
        >
            <div
                class="pointer-events-none absolute inset-0 bg-gradient-to-r from-[#377EC0]/[0.07] via-white to-[#377EC0]/[0.04]"
            ></div>

            <div
                class="pointer-events-none absolute -top-28 -right-24 h-72 w-72 rounded-full bg-[#377EC0]/[0.08]"
            ></div>

            <div
                class="pointer-events-none absolute right-32 -bottom-28 h-56 w-56 rounded-full border-[28px] border-[#377EC0]/[0.04]"
            ></div>

            <div
                class="pointer-events-none absolute top-5 left-[43%] h-20 w-20 rounded-full border border-[#377EC0]/10"
            ></div>

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
                            A centralized platform for monitoring, organizing,
                            and managing student activities.
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
                        <i class="pi pi-calendar text-lg text-[#377EC0]"></i>
                    </div>

                    <div class="flex-1 text-right">
                        <div class="text-sm font-medium text-slate-500">
                            {{ currentDate }}
                        </div>

                        <div
                            class="mt-0.5 font-mono text-2xl font-bold tracking-tight whitespace-nowrap text-slate-900 lg:text-[28px]"
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
                :class="[
                    'dashboard-card overflow-hidden rounded-2xl border-0 !text-white shadow-sm [&_.p-card-body]:!p-0 [&_.p-card-content]:!p-0',

                    item.cardBg,
                ]"
            >
                <template #content>
                    <div class="flex flex-col p-4">
                        <div class="flex items-start gap-3">
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/20 text-white"
                            >
                                <i :class="[item.icon, 'text-lg']"></i>
                            </div>

                            <div class="min-w-0 flex-1">
                                <p
                                    class="text-sm leading-5 font-semibold text-white/90"
                                >
                                    {{ item.label }}
                                </p>

                                <h2
                                    class="mt-1 text-[32px] leading-none font-bold tracking-tight text-white"
                                >
                                    {{ item.value }}
                                </h2>
                            </div>
                        </div>

                        <div
                            :class="[
                                'mt-3 grid w-full gap-2',

                                item.canCreateBatch
                                    ? 'grid-cols-2'
                                    : 'grid-cols-1',
                            ]"
                        >
<Link
    v-if="item.href"
    :href="item.href"
    class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-white px-3 py-2.5 text-sm font-semibold transition hover:bg-white/90"
    :class="{
        'text-orange-500': item.cardBg.includes('orange'),
        'text-emerald-500': item.cardBg.includes('emerald'),
        'text-red-500': item.cardBg.includes('red'),
        'text-blue-500': item.cardBg.includes('blue'),
        'text-amber-500': item.cardBg.includes('amber'),
        'text-yellow-500': item.cardBg.includes('yellow'),
        'text-violet-500': item.cardBg.includes('violet'),
        'text-purple-500': item.cardBg.includes('purple'),
    }"
>
    <i class="pi pi-eye text-sm"></i>
    View details
</Link>

<button
    v-else
    type="button"
    class="inline-flex w-full cursor-not-allowed items-center justify-center gap-2 rounded-lg bg-white/70 px-3 py-2.5 text-sm font-semibold text-slate-400"
    disabled
>
    <i class="pi pi-eye text-sm"></i>
    View details
</button>

                            <button
                                v-if="item.canCreateBatch"
                                type="button"
                                class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-white/10 px-2.5 py-2 text-xs font-semibold text-white transition hover:bg-white/20"
                            >
                                <i class="pi pi-users text-[11px]"></i>

                                Create Batch Examinees
                            </button>
                        </div>
                    </div>
                </template>
            </Card>
        </div>

        <!-- STUDENT MONITORING TABLE -->

        <Datatable
            title="Student Monitoring"
            :description="
                studentListType === 1
                    ? 'Students with an assigned OTG setup.'
                    : 'All student records.'
            "
            header-icon="pi pi-users"
            search-placeholder="Search students..."
            empty-title="No students found"
            empty-description="No matching student records were found."
            empty-icon="pi pi-users"
            table-min-width="900px"
            data-key="id"
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
            <template #header-actions>
                <label class="flex items-center gap-2">
                    <span
                        class="text-sm font-semibold whitespace-nowrap text-slate-600"
                    >
                        Filter
                    </span>

                    <select
                        v-model.number="studentListType"
                        class="h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 transition outline-none focus:border-[#377EC0] focus:ring-2 focus:ring-[#377EC0]/15"
                        @change="handleStudentListTypeChange"
                    >
                        <option :value="1">Students on board</option>

                        <option :value="2">All students</option>
                    </select>
                </label>
            </template>

            <!-- STUDENT NAME, DEPARTMENT, SCHOOL ID, AND CCI YEAR -->

            <template #cell-fname="{ data }">
                <div class="flex items-center gap-3">
                    <!-- AVATAR -->

                    <div
                        class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[#377EC0]/10 text-xs font-bold text-[#377EC0]"
                    >
                        <img
                            v-if="getStudentAvatar(data.gender)"
                            :src="getStudentAvatar(data.gender) ?? undefined"
                            :alt="getStudentFullName(data)"
                            class="h-full w-full object-cover"
                        />

                        <span v-else>
                            {{ getStudentInitials(data) }}
                        </span>
                    </div>

                    <!-- DETAILS -->

                    <div class="min-w-0">
                        <p class="truncate font-semibold text-slate-700">
                            {{ getStudentFullName(data) || '—' }}
                        </p>

                        <div class="mt-1 flex flex-wrap items-center gap-1.5">
                            <!-- DEPARTMENT TAG -->

                            <PrimeTag
                                :value="getDepartmentLabel(data.dept)"
                                :severity="getDepartmentSeverity(data.dept)"
                                :icon="getDepartmentIcon(data.dept)"
                                class="!px-2 !py-0.5 !text-[15px] !font-semibold"
                            />

                            <!-- SCHOOL ID TAG -->

                            <PrimeTag
                                :value="getSchoolIdLabel(data.school_id_no)"
                                severity="secondary"
                                icon="pi pi-id-card"
                                class="!px-2 !py-0.5 !text-[15px] !font-semibold"
                            />

                            <!-- CCI YEAR TAG -->

                            <PrimeTag
                                :value="String(data.batch_no || 'No CCI Year')"
                                severity="info"
                                icon="pi pi-calendar"
                                class="!px-2 !py-0.5 !text-[15px] !font-semibold"
                            />
                        </div>
                    </div>
                </div>
            </template>

            <template #cell-company="{ data }">
                <div class="w-full min-w-0 space-y-1.5 whitespace-normal">
                    <div
                        class="flex min-w-0 items-start gap-2 whitespace-normal"
                    >
                        <i
                            class="pi pi-compass mt-0.5 shrink-0 text-xs text-[#377EC0]"
                        ></i>

                        <span
                            class="min-w-0 flex-1 text-sm leading-5 font-semibold [overflow-wrap:anywhere] break-words whitespace-normal text-slate-700 uppercase"
                        >
                            {{ data.vessel_type || '—' }}
                        </span>
                    </div>

                    <div
                        class="flex min-w-0 items-start gap-2 whitespace-normal"
                    >
                        <i
                            class="pi pi-building mt-0.5 shrink-0 text-xs text-slate-400"
                        ></i>

                        <span
                            class="min-w-0 flex-1 text-xs leading-4 font-medium [overflow-wrap:anywhere] break-words whitespace-normal text-slate-500 uppercase"
                        >
                            {{ data.company || '—' }}
                        </span>
                    </div>
                </div>
            </template>

            <template #cell-total_activities="{ value }">
                <span
                    class="inline-flex rounded-md bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-700"
                >
                    {{ getNumber(value) }}
                </span>
            </template>

            <template #cell-total_file_upload="{ value }">
                <span
                    class="inline-flex rounded-md bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700"
                >
                    {{ getNumber(value) }}
                </span>
            </template>

            <template #cell-total_daily_journal="{ value }">
                <span
                    class="inline-flex rounded-md bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700"
                >
                    {{ getNumber(value) }}
                </span>
            </template>

            <template #cell-task_completed="{ data }">
                <div class="w-full min-w-0 space-y-2">
                    <div class="flex items-baseline justify-between gap-3">
                        <span
                            class="text-sm font-semibold whitespace-nowrap text-slate-700"
                        >
                            {{ formatTaskCount(data.task_completed) }} of

                            {{ formatTaskCount(data.total_task) }} tasks
                        </span>

                        <span
                            class="text-sm font-bold whitespace-nowrap text-[#377EC0]"
                        >
                            {{ formatTaskPercentage(data.task_percentage) }}%
                        </span>
                    </div>

                    <div
                        class="h-2 w-full overflow-hidden rounded-full bg-slate-200"
                    >
                        <div
                            class="h-full rounded-full bg-[#377EC0] transition-all duration-300"
                            :style="{
                                width: getTaskProgressWidth(
                                    data.task_percentage,
                                ),
                            }"
                        ></div>
                    </div>

                    <p class="text-xs font-medium text-slate-500">
                        {{ formatTaskCount(data.task_completed) }} completed,

                        {{
                            formatTaskCount(
                                Math.max(
                                    0,

                                    getNumber(data.total_task) -
                                        getNumber(data.task_completed),
                                ),
                            )
                        }}

                        remaining
                    </p>
                </div>
            </template>

            <!-- FIRST SIGN ON -->

            <template #cell-first_sign_on="{ value }">
                <span class="text-sm whitespace-nowrap text-slate-500">
                    {{ formatFirstSignOn(value) }}
                </span>
            </template>
        </Datatable>
    </div>
</template>
