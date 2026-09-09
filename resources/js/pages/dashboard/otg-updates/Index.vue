<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import Avatar from 'primevue/avatar';
import Button from 'primevue/button';
import DatePicker from 'primevue/datepicker';
import Message from 'primevue/message';
import PrimeTag from 'primevue/tag';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';

import Datatable from '@/components/Datatable.vue';
import { dashboard } from '@/routes';

import type {
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
                title: 'OTG Updates',
                href: '/dashboard/otg-updates',
            },
        ],
    },
});

type OtgUpdatesApiResponse = {
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

    filters: {
        month: number;
        year: number;
        fromDate: string;
        toDate: string;
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

type DepartmentSeverity =
    | 'success'
    | 'info'
    | 'secondary';

const now = new Date();

const selectedPeriod = ref<Date | null>(
    new Date(
        now.getFullYear(),
        now.getMonth(),
        1,
    ),
);

const minimumPeriod = new Date(2023, 0, 1);

const appliedMonth = ref(
    now.getMonth() + 1,
);

const appliedYear = ref(
    now.getFullYear(),
);

const tasks = ref<DataTableRow[]>([]);
const loading = ref(false);

const totalRecords = ref(0);
const first = ref(0);
const perPage = ref(10);
const search = ref('');

const sortField = ref('completed');
const sortDirection = ref<'asc' | 'desc'>(
    'asc',
);

const errorMessage = ref('');

let requestController: AbortController | null =
    null;

/*
|--------------------------------------------------------------------------
| Datatable columns
|--------------------------------------------------------------------------
*/

const columns: DataTableColumn[] = [
    {
        field: 'fname',
        header: 'Student Information',
        sortable: false,
        searchable: true,
        frozen: true,
        alignFrozen: 'left',
        class: 'w-[360px] min-w-[360px]',
    },
    {
        field: 'ref_no',
        header: 'Task',
        sortable: false,
        searchable: true,
        class: 'w-[530px] min-w-[530px] whitespace-normal',
    },
    {
        field: 'completed',
        header: 'Date Completed',
        sortable: true,
        searchable: false,
        class: 'w-[210px] min-w-[210px]',
    },
];

const dateRangeLabel = computed(() => {
    const firstDate = new Date(
        appliedYear.value,
        appliedMonth.value - 1,
        1,
    );

    const finalDate = new Date(
        appliedYear.value,
        appliedMonth.value,
        0,
    );

    const formatter =
        new Intl.DateTimeFormat('en-PH', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });

    return `${formatter.format(firstDate)} – ${formatter.format(finalDate)}`;
});

/*
|--------------------------------------------------------------------------
| Load completed tasks
|--------------------------------------------------------------------------
*/

async function loadTasks(
    pageNumber = 1,
): Promise<void> {
    requestController?.abort();

    const controller = new AbortController();

    requestController = controller;
    loading.value = true;
    errorMessage.value = '';

    try {
        const response =
            await axios.get<OtgUpdatesApiResponse>(
                '/api/v1/dashboard/datatable/otg-updates',
                {
                    signal: controller.signal,

                    params: {
                        page: pageNumber,
                        per_page: perPage.value,
                        search: search.value,
                        sort_field: sortField.value,
                        sort_direction:
                            sortDirection.value,
                        month: appliedMonth.value,
                        year: appliedYear.value,
                    },

                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With':
                            'XMLHttpRequest',
                    },

                    withCredentials: true,
                },
            );

        tasks.value = response.data.data;
        totalRecords.value =
            response.data.meta.total;
        perPage.value =
            response.data.meta.perPage;

        first.value =
            (response.data.meta.currentPage - 1) *
            response.data.meta.perPage;

        appliedMonth.value =
            response.data.filters.month;

        appliedYear.value =
            response.data.filters.year;
    } catch (error: unknown) {
        if (
            axios.isCancel(error) ||
            (axios.isAxiosError(error) &&
                error.code === 'ERR_CANCELED')
        ) {
            return;
        }

        tasks.value = [];
        totalRecords.value = 0;

        errorMessage.value = getErrorMessage(
            error,
            'Unable to load completed OTG tasks.',
        );

        console.error(
            'Unable to load completed OTG tasks:',
            error,
        );
    } finally {
        if (
            requestController === controller
        ) {
            loading.value = false;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Month and year filter
|--------------------------------------------------------------------------
*/

function handlePeriodChange(
    value: Date | null,
): void {
    if (
        !value ||
        Number.isNaN(value.getTime())
    ) {
        errorMessage.value =
            'Please select a valid month and year.';

        return;
    }

    appliedMonth.value =
        value.getMonth() + 1;

    appliedYear.value =
        value.getFullYear();

    first.value = 0;
    errorMessage.value = '';

    void loadTasks(1);
}

watch(selectedPeriod, (value) => {
    handlePeriodChange(value);
});

/*
|--------------------------------------------------------------------------
| Datatable events
|--------------------------------------------------------------------------
*/

function handlePage(
    event: DataTablePageEvent,
): void {
    perPage.value = event.rows;
    first.value = event.first;

    void loadTasks(event.page + 1);
}

function handleSort(
    event: DataTableSortEvent,
): void {
    sortField.value =
        event.sortField || 'completed';

    sortDirection.value =
        event.sortOrder === -1
            ? 'desc'
            : 'asc';

    first.value = 0;

    void loadTasks(1);
}

function handleSearch(value: string): void {
    search.value = value;
    first.value = 0;

    void loadTasks(1);
}

/*
|--------------------------------------------------------------------------
| Navigation
|--------------------------------------------------------------------------
*/

function navigateToDashboard(): void {
    router.visit('/dashboard');
}

/*
|--------------------------------------------------------------------------
| Student helpers
|--------------------------------------------------------------------------
*/

function getStudentFullName(
    task: DataTableRow,
): string {
    const lastName = String(
        task.lname ?? '',
    ).trim();

    const otherNames = [
        task.fname,
        task.mname,
    ]
        .filter((name) => {
            return (
                typeof name === 'string' &&
                name.trim() !== ''
            );
        })
        .map((name) => {
            return String(name).trim();
        })
        .join(' ');

    if (lastName && otherNames) {
        return `${lastName}, ${otherNames}`.toUpperCase();
    }

    return (lastName || otherNames).toUpperCase();
}

function getStudentInitials(
    task: DataTableRow,
): string {
    const firstName = String(
        task.fname ?? '',
    ).trim();

    const lastName = String(
        task.lname ?? '',
    ).trim();

    const initials =
        `${firstName.charAt(0)}${lastName.charAt(0)}`;

    return initials.toUpperCase() || 'ST';
}

function getStudentAvatar(
    gender: unknown,
): string | null {
    const normalizedGender = String(
        gender ?? '',
    )
        .trim()
        .toUpperCase();

    if (
        normalizedGender === 'M' ||
        normalizedGender === 'MALE'
    ) {
        return '/images/male-cadet.png';
    }

    if (
        normalizedGender === 'F' ||
        normalizedGender === 'FEMALE'
    ) {
        return '/images/female-cadet.png';
    }

    return null;
}

function getDepartmentSeverity(
    department: unknown,
): DepartmentSeverity {
    const firstLetter = String(
        department ?? '',
    )
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

function getDepartmentIcon(
    department: unknown,
): string {
    const firstLetter = String(
        department ?? '',
    )
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

function getDepartmentLabel(
    department: unknown,
): string {
    const value = String(
        department ?? '',
    ).trim();

    return value
        ? value.toUpperCase()
        : '—';
}

function getSystemIdLabel(
    value: unknown,
): string {
    const systemId = String(
        value ?? '',
    ).trim();

    return systemId || 'No System ID';
}

function getSchoolIdLabel(
    value: unknown,
): string {
    const schoolId = String(
        value ?? '',
    ).trim();

    return schoolId || 'No School ID';
}

/*
|--------------------------------------------------------------------------
| Task and date helpers
|--------------------------------------------------------------------------
*/

function getTaskDescription(
    value: unknown,
): string {
    const description = String(
        value ?? '',
    );

    if (!description) {
        return '—';
    }

    try {
        return decodeURIComponent(
            description.replace(/\+/g, ' '),
        );
    } catch {
        return description;
    }
}

function formatCompletedDate(
    value: unknown,
): string {
    if (
        !value ||
        value === '1970-01-01' ||
        value === '1970-01-01 00:00:00'
    ) {
        return '—';
    }

    const rawValue = String(value);

    const normalizedValue =
        rawValue.includes('T')
            ? rawValue
            : rawValue.replace(' ', 'T');

    const date = new Date(normalizedValue);

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

/*
|--------------------------------------------------------------------------
| Error helper
|--------------------------------------------------------------------------
*/

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
              }
            | undefined;

    return responseData?.message || fallback;
}

/*
|--------------------------------------------------------------------------
| Lifecycle
|--------------------------------------------------------------------------
*/

onMounted(() => {
    void loadTasks(1);
});

onBeforeUnmount(() => {
    requestController?.abort();
});
</script>

<template>
    <Head title="OTG Updates" />

    <div
        class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5"
    >
        <!-- ERROR MESSAGE -->

        <Message
            v-if="errorMessage"
            severity="error"
            closable
            @close="errorMessage = ''"
        >
            {{ errorMessage }}
        </Message>

        <!-- OTG DATATABLE -->

        <Datatable
            title="Tasks Completed"
            :description="`Tasks completed from ${dateRangeLabel}`"
            header-icon="pi pi-book"
            search-placeholder="Search completed tasks..."
            empty-title="No completed tasks found"
            empty-description="No completed OTG tasks were found for the selected month and year."
            empty-icon="pi pi-book"
            table-min-width="1100px"
            data-key="id"
            lazy
            :show-actions="false"
            :loading="loading"
            :data="tasks"
            :columns="columns"
            :total-records="totalRecords"
            :first="first"
            :rows="perPage"
            :rows-per-page-options="[10, 20, 50, 100]"
            @page="handlePage"
            @sort="handleSort"
            @search="handleSearch"
        >
            <!-- HEADER ACTIONS -->

            <template #header-actions>
                <div
                    class="flex flex-wrap items-center gap-2"
                >
                    <!-- PRIMEVUE MONTH AND YEAR PICKER -->

                    <DatePicker
                        v-model="selectedPeriod"
                        view="month"
                        date-format="MM yy"
                        :min-date="minimumPeriod"
                        show-icon
                        icon-display="input"
                        placeholder="Select month and year"
                        aria-label="Select month and year"
                        input-class="!h-10 !w-52 !rounded-lg !border-slate-300 !bg-white !text-sm !font-semibold !text-slate-700"
                        :disabled="loading"
                    />

                    <!-- PRIMEVUE NAVIGATION BUTTON -->

                    <Button
                        type="button"
                        label="Individual Cadet OTG"
                        icon="pi pi-users"
                        severity="secondary"
                        variant="outlined"
                        size="small"
                        @click="navigateToDashboard"
                    />
                </div>
            </template>

            <!-- STUDENT INFORMATION -->

            <template #cell-fname="{ data }">
                <div class="flex items-center gap-3">
                    <Avatar
                        v-if="getStudentAvatar(data.gender)"
                        :image="
                            getStudentAvatar(
                                data.gender,
                            ) ?? undefined
                        "
                        :aria-label="
                            getStudentFullName(data)
                        "
                        shape="circle"
                        size="large"
                        class="shrink-0"
                    />

                    <Avatar
                        v-else
                        :label="
                            getStudentInitials(data)
                        "
                        shape="circle"
                        size="large"
                        class="shrink-0 !bg-[#377EC0]/10 !text-xs !font-bold !text-[#377EC0]"
                    />

                    <div class="min-w-0">
                        <p
                            class="truncate font-semibold text-slate-700"
                        >
                            {{
                                getStudentFullName(
                                    data,
                                ) || '—'
                            }}
                        </p>

                        <div
                            class="mt-1 flex flex-wrap items-center gap-1.5"
                        >
                            <PrimeTag
                                :value="
                                    getDepartmentLabel(
                                        data.dept,
                                    )
                                "
                                :severity="
                                    getDepartmentSeverity(
                                        data.dept,
                                    )
                                "
                                :icon="
                                    getDepartmentIcon(
                                        data.dept,
                                    )
                                "
                                class="!px-2 !py-0.5 !text-xs !font-semibold"
                            />

                            <PrimeTag
                                :value="
                                    getSchoolIdLabel(
                                        data.school_id_no,
                                    )
                                "
                                severity="info"
                                icon="pi pi-building"
                                class="!px-2 !py-0.5 !text-xs !font-semibold"
                            />
                        </div>
                    </div>
                </div>
            </template>

            <!-- TASK -->

            <template #cell-ref_no="{ data }">
                <div
                    class="flex min-w-0 items-start gap-3"
                >
                    <div
                        class="flex size-9 shrink-0 items-center justify-center text-green-500"
                    >
                        <i
                            class="pi pi-list-check"
                        ></i>
                    </div>

                    <div class="min-w-0">
                        <p
                            class="text-sm font-bold text-[#377EC0]"
                        >
                            {{
                                data.ref_no ||
                                'No reference'
                            }}
                        </p>

                        <p
                            class="mt-1 leading-5 break-words whitespace-normal text-slate-700"
                        >
                            {{
                                getTaskDescription(
                                    data.desc_task,
                                )
                            }}
                        </p>
                    </div>
                </div>
            </template>

            <!-- DATE COMPLETED -->

            <template #cell-completed="{ value }">
                <div class="flex items-center gap-2">
                    <i
                        class="pi pi-clock text-lg font-bold text-yellow-500"
                    ></i>

                    <span
                        class="whitespace-nowrap text-sm font-semibold text-slate-600"
                    >
                        {{
                            formatCompletedDate(
                                value,
                            )
                        }}
                    </span>
                </div>
            </template>
        </Datatable>
    </div>
</template>