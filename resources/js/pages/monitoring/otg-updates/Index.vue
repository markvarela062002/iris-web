<script setup lang="ts">
import {
    Head,
    router,
} from '@inertiajs/vue3';
import axios from 'axios';
import Avatar from 'primevue/avatar';
import Button from 'primevue/button';
import Message from 'primevue/message';
import PrimeTag from 'primevue/tag';
import {
    onBeforeUnmount,
    onMounted,
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
                title:
                    'Training Record Book (OTG)',
                href:
                    '/monitoring/otg-updates',
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

type DepartmentSeverity =
    | 'success'
    | 'info'
    | 'secondary';

/*
|--------------------------------------------------------------------------
| Page state
|--------------------------------------------------------------------------
*/

const tasks =
    ref<DataTableRow[]>([]);

const loading = ref(false);

const totalRecords = ref(0);
const first = ref(0);
const perPage = ref(10);

const search = ref('');

const sortField =
    ref('completed');

const sortDirection =
    ref<'asc' | 'desc'>(
        'desc',
    );

const errorMessage = ref('');
const infoMessage = ref('');

let requestController:
    AbortController | null = null;

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
        class: 'min-w-[340px]',
    },
    {
        field: 'ref_no',
        header: 'Task',
        sortable: false,
        searchable: true,
        class: 'min-w-[520px] whitespace-normal',
    },
    {
        field: 'month_no',
        header: 'Month Onboard',
        sortable: false,
        searchable: false,
        class: 'min-w-[170px]',
    },
    {
        field: 'completed',
        header: 'Date Completed',
        sortable: true,
        searchable: false,
        class: 'min-w-[190px]',
    },
];

/*
|--------------------------------------------------------------------------
| Datatable actions
|--------------------------------------------------------------------------
*/

const actions: DataTableAction[] = [
    {
        key: 'view-etrb',
        label: 'Download eTRB',
        icon: 'pi pi-download',
        severity: 'info',
    },
];

/*
|--------------------------------------------------------------------------
| Load OTG records
|--------------------------------------------------------------------------
*/

async function loadTasks(
    pageNumber = 1,
): Promise<void> {
    requestController?.abort();

    const controller =
        new AbortController();

    requestController =
        controller;

    loading.value = true;
    errorMessage.value = '';

    try {
        const response =
            await axios.get<OtgApiResponse>(
                '/api/v1/monitoring/datatable/otg-updates',
                {
                    signal:
                        controller.signal,

                    params: {
                        page: pageNumber,

                        per_page:
                            perPage.value,

                        search:
                            search.value,

                        sort_field:
                            sortField.value,

                        sort_direction:
                            sortDirection.value,

                        monitoring: true,
                    },

                    headers: {
                        Accept:
                            'application/json',

                        'X-Requested-With':
                            'XMLHttpRequest',
                    },

                    withCredentials: true,
                },
            );

        tasks.value =
            response.data.data;

        totalRecords.value =
            response.data.meta.total;

        perPage.value =
            response.data.meta.perPage;

        first.value =
            (
                response.data.meta
                    .currentPage -
                1
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

        tasks.value = [];
        totalRecords.value = 0;

        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to load OTG records.',
            );

        console.error(
            'Unable to load OTG records:',
            error,
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

    void loadTasks(
        event.page + 1,
    );
}

function handleSort(
    event: DataTableSortEvent,
): void {
    sortField.value =
        event.sortField ||
        'completed';

    sortDirection.value =
        event.sortOrder === -1
            ? 'desc'
            : 'asc';

    first.value = 0;

    void loadTasks(1);
}

function handleSearch(
    value: string,
): void {
    search.value = value;
    first.value = 0;

    void loadTasks(1);
}

/*
|--------------------------------------------------------------------------
| Actions
|--------------------------------------------------------------------------
*/

function handleAction(
    action: string,
    task: DataTableRow,
): void {
    errorMessage.value = '';
    infoMessage.value = '';

    if (action === 'view-etrb') {
        viewEtrb(task);

        return;
    }
}

function viewEtrb(
    task: DataTableRow,
): void {
    const personId = String(
        task.person_id ?? '',
    ).trim();

    if (!personId) {
        errorMessage.value =
            'The selected student ID is missing.';

        return;
    }

    /*
     * The existing OTG endpoint renders the
     * student's complete TRB as an inline PDF.
     *
     * We use it as the read-only eTRB preview.
     */
    window.open(
        `/students/${encodeURIComponent(
            personId,
        )}/otg/print`,
        '_blank',
        'noopener,noreferrer',
    );
}

/*
|--------------------------------------------------------------------------
| Navigation
|--------------------------------------------------------------------------
*/

function navigateToMonthlyCompletion(): void {
    router.visit(
        '/dashboard/otg-updates',
    );
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
                typeof name ===
                    'string' &&
                name.trim() !== ''
            );
        })
        .map((name) => {
            return String(
                name,
            ).trim();
        })
        .join(' ');

    if (
        lastName &&
        otherNames
    ) {
        return `${lastName}, ${otherNames}`.toUpperCase();
    }

    return (
        lastName ||
        otherNames
    ).toUpperCase();
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
        `${firstName.charAt(
            0,
        )}${lastName.charAt(0)}`;

    return (
        initials.toUpperCase() ||
        'ST'
    );
}

function getStudentAvatar(
    gender: unknown,
): string | null {
    const normalizedGender =
        String(
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
        normalizedGender ===
            'FEMALE'
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

function getSchoolIdLabel(
    value: unknown,
): string {
    const schoolId = String(
        value ?? '',
    ).trim();

    return (
        schoolId ||
        'No School ID'
    );
}

/*
|--------------------------------------------------------------------------
| Task helpers
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
            description.replace(
                /\+/g,
                ' ',
            ),
        );
    } catch {
        return description;
    }
}

function getMonthLabel(
    value: unknown,
): string {
    const month = String(
        value ?? '',
    ).trim();

    if (!month) {
        return '—';
    }

    const normalized =
        Number.parseInt(
            month,
            10,
        );

    if (
        Number.isNaN(
            normalized,
        )
    ) {
        return month;
    }

    return `Month ${normalized}`;
}

function formatCompletedDate(
    value: unknown,
): string {
    if (
        !value ||
        value ===
            '1970-01-01' ||
        value ===
            '1970-01-01 00:00:00'
    ) {
        return '—';
    }

    const rawValue =
        String(value);

    const normalizedValue =
        rawValue.includes('T')
            ? rawValue
            : rawValue.replace(
                  ' ',
                  'T',
              );

    const date =
        new Date(
            normalizedValue,
        );

    if (
        Number.isNaN(
            date.getTime(),
        )
    ) {
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
    if (
        !axios.isAxiosError(
            error,
        )
    ) {
        return fallback;
    }

    const responseData =
        error.response?.data as
            | {
                  message?: string;
              }
            | undefined;

    return (
        responseData?.message ||
        fallback
    );
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
    <Head
        title="Training Record Book (OTG)"
    />

    <div
        class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5"
    >
        <Message
            v-if="infoMessage"
            severity="info"
            closable
            @close="infoMessage = ''"
        >
            {{ infoMessage }}
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
            title="Training Record Book (OTG)"
            description="Monitor recent student OTG submissions and open their electronic Training Record Book."
            header-icon="pi pi-book"
            search-placeholder="Search student or OTG task..."
            empty-title="No OTG records found"
            empty-description="No completed OTG submissions were found."
            empty-icon="pi pi-book"
            table-min-width="100px"
            actions-width="110px"
            data-key="id"
            lazy
            :loading="loading"
            :data="tasks"
            :columns="columns"
            :actions="actions"
            :total-records="
                totalRecords
            "
            :first="first"
            :rows="perPage"
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
            <!-- HEADER ACTIONS -->

            <template
                #header-actions
            >
                <Button
                    type="button"
                    label="Monthly Task Completion"
                    icon="pi pi-calendar"
                    severity="info"
                    size="small"
                    @click="
                        navigateToMonthlyCompletion
                    "
                />
            </template>

            <!-- STUDENT INFORMATION -->

            <template
                #cell-fname="{ data }"
            >
                <div
                    class="flex items-center gap-3"
                >
                    <Avatar
                        v-if="
                            getStudentAvatar(
                                data.gender,
                            )
                        "
                        :image="
                            getStudentAvatar(
                                data.gender,
                            ) ??
                            undefined
                        "
                        :aria-label="
                            getStudentFullName(
                                data,
                            )
                        "
                        shape="circle"
                        size="large"
                        class="shrink-0"
                    />

                    <Avatar
                        v-else
                        :label="
                            getStudentInitials(
                                data,
                            )
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
                                icon="pi pi-id-card"
                                class="!px-2 !py-0.5 !text-xs !font-semibold"
                            />
                        </div>
                    </div>
                </div>
            </template>

            <!-- TASK -->

            <template
                #cell-ref_no="{ data }"
            >
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

            <!-- MONTH ONBOARD -->

            <template
                #cell-month_no="{ value }"
            >
                <PrimeTag
                    :value="
                        getMonthLabel(
                            value,
                        )
                    "
                    severity="info"
                    icon="pi pi-calendar"
                />
            </template>

            <!-- DATE COMPLETED -->

            <template
                #cell-completed="{ value }"
            >
                <div
                    class="flex items-center gap-2"
                >
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