<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import Avatar from 'primevue/avatar';
import Button from 'primevue/button';
import Message from 'primevue/message';
import PrimeTag from 'primevue/tag';
import { onBeforeUnmount, onMounted, ref } from 'vue';

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
                title: 'Training Record Book (OTG)',
                href: '/monitoring/otg-updates',
            },
        ],
    },
});

type OtgApiResponse = {
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

type DepartmentSeverity = 'success' | 'info' | 'secondary';

const students = ref<DataTableRow[]>([]);
const loading = ref(false);
const totalRecords = ref(0);
const first = ref(0);
const perPage = ref(10);
const search = ref('');
const sortField = ref('lname');
const sortDirection = ref<'asc' | 'desc'>('asc');
const errorMessage = ref('');
let requestController: AbortController | null = null;

const columns: DataTableColumn[] = [
    {
        field: 'fname',
        header: 'Personal Information',
        sortable: true,
        searchable: true,
        frozen: true,
        alignFrozen: 'left',
        class: 'min-w-[360px]',
    },
    {
        field: 'workbook_count',
        header: 'Training Record',
        sortable: false,
        searchable: false,
        class: 'min-w-[260px]',
    },
    {
        field: 'completion_percentage',
        header: 'Completion',
        sortable: false,
        searchable: false,
        class: 'min-w-[240px]',
    },
];

const actions: DataTableAction[] = [
    {
        key: 'view-etrb',
        label: 'Print eTRB',
        icon: 'pi pi-download',
        severity: 'info',
    },
    {
        key: 'manage-training-record',
        label: 'Manage Training Record',
        icon: 'pi pi-pencil',
        severity: 'warn',
    },
];

async function loadStudents(pageNumber = 1): Promise<void> {
    requestController?.abort();

    const controller = new AbortController();
    requestController = controller;
    loading.value = true;
    errorMessage.value = '';

    try {
        const response = await axios.get<OtgApiResponse>(
            '/api/v1/monitoring/datatable/otg-updates',
            {
                signal: controller.signal,
                params: {
                    page: pageNumber,
                    per_page: perPage.value,
                    search: search.value,
                    sort_field: sortField.value,
                    sort_direction: sortDirection.value,
                },
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                withCredentials: true,
            },
        );

        students.value = response.data.data;
        totalRecords.value = response.data.meta.total;
        perPage.value = response.data.meta.perPage;
        first.value =
            (response.data.meta.currentPage - 1) *
            response.data.meta.perPage;
    } catch (error: unknown) {
        if (
            axios.isCancel(error) ||
            (axios.isAxiosError(error) && error.code === 'ERR_CANCELED')
        ) {
            return;
        }

        students.value = [];
        totalRecords.value = 0;
        errorMessage.value = getErrorMessage(
            error,
            'Unable to load student Training Records.',
        );
        console.error('Unable to load student Training Records:', error);
    } finally {
        if (requestController === controller) {
            loading.value = false;
        }
    }
}

function handlePage(event: DataTablePageEvent): void {
    perPage.value = event.rows;
    first.value = event.first;
    void loadStudents(event.page + 1);
}

function handleSort(event: DataTableSortEvent): void {
    sortField.value = event.sortField || 'lname';
    sortDirection.value = event.sortOrder === -1 ? 'desc' : 'asc';
    first.value = 0;
    void loadStudents(1);
}

function handleSearch(value: string): void {
    search.value = value;
    first.value = 0;
    void loadStudents(1);
}

function handleAction(action: string, student: DataTableRow): void {
    errorMessage.value = '';

    if (action === 'manage-training-record') {
        manageTrainingRecord(student);
        return;
    }

    if (action === 'view-etrb') {
        viewEtrb(student);
    }
}

function manageTrainingRecord(student: DataTableRow): void {
    const personId = getPersonId(student);

    if (!personId) {
        errorMessage.value = 'The selected student ID is missing.';
        return;
    }

    router.visit(
        `/monitoring/otg-updates/${encodeURIComponent(personId)}`,
    );
}

function viewEtrb(student: DataTableRow): void {
    const personId = getPersonId(student);

    if (!personId) {
        errorMessage.value = 'The selected student ID is missing.';
        return;
    }

    window.open(
        `/students/${encodeURIComponent(personId)}/otg/print`,
        '_blank',
        'noopener,noreferrer',
    );
}

function navigateToMonthlyCompletion(): void {
    router.visit('/dashboard/otg-updates');
}

function getPersonId(student: DataTableRow): string {
    return String(student.person_id ?? student.id ?? '').trim();
}

function getStudentFullName(student: DataTableRow): string {
    const lastName = String(student.lname ?? '').trim();
    const otherNames = [student.fname, student.mname]
        .filter((name) => {
            return typeof name === 'string' && name.trim() !== '';
        })
        .map((name) => String(name).trim())
        .join(' ');

    if (lastName && otherNames) {
        return `${lastName}, ${otherNames}`.toUpperCase();
    }

    return (lastName || otherNames).toUpperCase();
}

function getStudentInitials(student: DataTableRow): string {
    const firstName = String(student.fname ?? '').trim();
    const lastName = String(student.lname ?? '').trim();
    const initials = `${firstName.charAt(0)}${lastName.charAt(0)}`;

    return initials.toUpperCase() || 'ST';
}

function getStudentAvatar(gender: unknown): string {
    const normalizedGender = String(gender ?? '').trim().toUpperCase();

    if (normalizedGender === 'M' || normalizedGender === 'MALE') {
        return '/images/male-cadet.png';
    }

    if (normalizedGender === 'F' || normalizedGender === 'FEMALE') {
        return '/images/female-cadet.png';
    }

    return '/images/default-cadet.png';
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
    return value ? value.toUpperCase() : '—';
}

function getSchoolIdLabel(value: unknown): string {
    const schoolId = String(value ?? '').trim();
    return schoolId || 'No School ID';
}

function getWorkbookCount(student: DataTableRow): number {
    return Number(student.workbook_count ?? 0);
}

function getTaskCount(student: DataTableRow): number {
    return Number(student.effective_task_count ?? 0);
}

function getCompletedTaskCount(student: DataTableRow): number {
    return Number(student.completed_task_count ?? 0);
}

function getNaTaskCount(student: DataTableRow): number {
    return Number(student.completed_na_task_count ?? 0);
}

function getCompletionPercentage(student: DataTableRow): number {
    const value = Number(student.completion_percentage ?? 0);

    if (!Number.isFinite(value)) {
        return 0;
    }

    return Math.min(100, Math.max(0, value));
}

function formatPercentage(value: number): string {
    return `${value.toFixed(2)}%`;
}

function getErrorMessage(error: unknown, fallback: string): string {
    if (!axios.isAxiosError(error)) {
        return fallback;
    }

    const responseData = error.response?.data as
        | { message?: string }
        | undefined;

    return responseData?.message || fallback;
}

onMounted(() => {
    void loadStudents(1);
});

onBeforeUnmount(() => {
    requestController?.abort();
});
</script>

<template>
    <Head title="Training Record Book (OTG)" />

    <div
        class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5"
    >
        <Message
            v-if="errorMessage"
            severity="error"
            closable
            @close="errorMessage = ''"
        >
            {{ errorMessage }}
        </Message>

        <Datatable
            title="Training Record Book (OTG)"
            description="Select a cadet to view and revise individual Training Record tasks."
            header-icon="pi pi-book"
            search-placeholder="Search cadet name, School ID, department, or system ID..."
            empty-title="No cadets found"
            empty-description="No student records matched your search."
            empty-icon="pi pi-users"
            table-min-width="940px"
            actions-width="130px"
            data-key="id"
            lazy
            :loading="loading"
            :data="students"
            :columns="columns"
            :actions="actions"
            :total-records="totalRecords"
            :first="first"
            :rows="perPage"
            :rows-per-page-options="[10, 20, 50, 100]"
            @page="handlePage"
            @sort="handleSort"
            @search="handleSearch"
            @action="handleAction"
        >
            <template #header-actions>
                <Button
                    type="button"
                    label="Monthly Task Completion"
                    icon="pi pi-calendar"
                    severity="info"
                    size="small"
                    @click="navigateToMonthlyCompletion"
                />
            </template>

            <template #cell-fname="{ data }">
                <div class="flex items-center gap-3">
                    <Avatar
                        :image="getStudentAvatar(data.gender)"
                        :aria-label="getStudentFullName(data)"
                        shape="circle"
                        size="large"
                        class="shrink-0"
                    />

                    <div class="min-w-0">
                        <p class="truncate font-semibold text-slate-700">
                            {{ getStudentFullName(data) || '—' }}
                        </p>

                        <div class="mt-1 flex flex-wrap items-center gap-1.5">
                            <PrimeTag
                                :value="getDepartmentLabel(data.dept)"
                                :severity="getDepartmentSeverity(data.dept)"
                                :icon="getDepartmentIcon(data.dept)"
                                class="!px-2 !py-0.5 !text-xs !font-semibold"
                            />
                            <PrimeTag
                                :value="getSchoolIdLabel(data.school_id_no)"
                                severity="info"
                                icon="pi pi-id-card"
                                class="!px-2 !py-0.5 !text-xs !font-semibold"
                            />
                        </div>
                    </div>
                </div>
            </template>

            <template #cell-workbook_count="{ data }">
                <div class="space-y-2">
                    <div class="flex items-center gap-2">
                        <i class="pi pi-book text-[#377EC0]"></i>
                        <span class="font-semibold text-slate-700">
                            {{ getWorkbookCount(data) }}
                            {{ getWorkbookCount(data) === 1 ? 'Workbook' : 'Workbooks' }}
                        </span>
                    </div>
                    <div class="text-xs font-medium text-slate-500">
                        {{ getTaskCount(data) }} applicable tasks
                        <span v-if="getNaTaskCount(data) > 0">
                            · {{ getNaTaskCount(data) }} completed N/A
                        </span>
                    </div>
                </div>
            </template>

            <template #cell-completion_percentage="{ data }">
                <div class="min-w-[210px]">
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <span class="font-semibold text-slate-700">
                            {{ getCompletedTaskCount(data) }} / {{ getTaskCount(data) }}
                        </span>
                        <span class="font-bold text-[#21365A]">
                            {{ formatPercentage(getCompletionPercentage(data)) }}
                        </span>
                    </div>
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                        <div
                            class="h-full rounded-full bg-green-700 transition-all"
                            :style="{
                                width: `${getCompletionPercentage(data)}%`,
                            }"
                        ></div>
                    </div>
                </div>
            </template>
        </Datatable>
    </div>
</template>
