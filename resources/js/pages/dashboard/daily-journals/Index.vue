<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import AutoComplete from 'primevue/autocomplete';
import Avatar from 'primevue/avatar';
import Button from 'primevue/button';
import Card from 'primevue/card';
import DatePicker from 'primevue/datepicker';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import PrimeTag from 'primevue/tag';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
} from 'vue';

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
                title: 'Daily Journals',
                href: '/dashboard/daily-journals',
            },
        ],
    },
});

type JournalApiResponse = {
    data: DataTableRow[];

    meta: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
        from: number | null;
        to: number | null;
    };
};

type StudentOption = {
    id: string;
    school_id_no: string | null;
    fname: string | null;
    mname: string | null;
    lname: string | null;
    gender: string | null;
    dept: string | null;
    name: string;
    label: string;
};

type StudentLookupResponse = {
    data: StudentOption[];
};

type PageEvent = {
    page: number;
    rows: number;
    first: number;
};

type SortEvent = {
    sortField: string;
    sortOrder: number;
};

type DepartmentSeverity =
    | 'success'
    | 'info'
    | 'secondary';

/*
|--------------------------------------------------------------------------
| DataTable configuration
|--------------------------------------------------------------------------
*/

const columns: DataTableColumn[] = [
    {
        field: 'student_name',
        header: 'Student Information',
        sortable: true,
        searchable: true,
        frozen: true,
        alignFrozen: 'left',
        class: 'w-[320px] min-w-[320px]',
    },
    {
        field: 'date_journal',
        header: 'Journal Details',
        sortable: true,
        searchable: false,
        class: 'w-[230px] min-w-[230px]',
    },
    {
        field: 'port_depart',
        header: 'Voyage',
        sortable: false,
        searchable: true,
        class: 'w-[250px] min-w-[250px]',
    },
    {
        field: 'duty_hours',
        header: 'Watchkeeping Hours',
        sortable: false,
        searchable: false,
        class: 'w-[180px] min-w-[180px]',
    },
    {
        field: 'status',
        header: 'Status',
        sortable: true,
        searchable: false,
        class: 'w-[140px] min-w-[140px] text-center',
        headerClass: '!text-center',
        bodyClass: '!text-center',
    },
];

const actions: DataTableAction[] = [
    {
        key: 'view-evidence',
        label: 'View Evidence',
        icon: 'pi pi-file-pdf',
        severity: 'danger',

        visible: (row) => {
            return (
                typeof row.evidence_url === 'string' &&
                row.evidence_url.trim() !== ''
            );
        },
    },
];

/*
|--------------------------------------------------------------------------
| Table state
|--------------------------------------------------------------------------
*/

const journals = ref<DataTableRow[]>([]);
const loading = ref(false);
const totalRecords = ref(0);
const first = ref(0);
const rows = ref(10);
const search = ref('');

const sortField = ref('date_journal');

const sortDirection = ref<'asc' | 'desc'>(
    'desc',
);

/*
|--------------------------------------------------------------------------
| Filter state
|--------------------------------------------------------------------------
*/

const now = new Date();

const journalDateRange = ref<
    (Date | null)[]
>([
    new Date(
        now.getFullYear(),
        now.getMonth(),
        1,
    ),
    new Date(
        now.getFullYear(),
        now.getMonth() + 1,
        0,
    ),
]);

const dateFrom = computed<Date | null>(() => {
    return journalDateRange.value[0] ?? null;
});

const dateTo = computed<Date | null>(() => {
    return journalDateRange.value[1] ?? null;
});

const selectedStudent =
    ref<StudentOption | null>(null);

const studentSuggestions =
    ref<StudentOption[]>([]);

const studentSearchLoading = ref(false);
const schoolId = ref('');
const pageError = ref('');

let journalController: AbortController | null =
    null;

let studentController: AbortController | null =
    null;

const canPrint = computed(() => {
    return (
        selectedStudent.value !== null ||
        schoolId.value.trim() !== ''
    );
});

/*
|--------------------------------------------------------------------------
| Formatting helpers
|--------------------------------------------------------------------------
*/

function formatDateParameter(
    value: Date | null,
): string {
    if (!value) {
        return '';
    }

    const year = value.getFullYear();

    const month = String(
        value.getMonth() + 1,
    ).padStart(2, '0');

    const day = String(
        value.getDate(),
    ).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function formatDisplayDate(
    value: unknown,
): string {
    if (!value) {
        return '—';
    }

    const rawValue = String(value);

    const date = new Date(
        `${rawValue.substring(0, 10)}T00:00:00`,
    );

    if (Number.isNaN(date.getTime())) {
        return rawValue;
    }

    return new Intl.DateTimeFormat('en-PH', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(date);
}

function formatJournalTime(
    value: unknown,
): string {
    const time = String(value ?? '').trim();

    if (!time) {
        return '—';
    }

    const parts = time.split(':');

    if (parts.length < 2) {
        return time;
    }

    const hour = Number(parts[0]);
    const minute = Number(parts[1]);

    if (
        !Number.isFinite(hour) ||
        !Number.isFinite(minute)
    ) {
        return time;
    }

    const date = new Date();

    date.setHours(hour, minute, 0, 0);

    return new Intl.DateTimeFormat('en-PH', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    }).format(date);
}

function getStudentInitials(
    row: DataTableRow | StudentOption,
): string {
    const firstName = String(
        row.fname ?? '',
    ).trim();

    const lastName = String(
        row.lname ?? '',
    ).trim();

    return (
        `${firstName.charAt(0)}${lastName.charAt(0)}`
            .toUpperCase() || 'ST'
    );
}

function getStudentAvatar(
    gender: unknown,
): string | undefined {
    const value = String(gender ?? '')
        .trim()
        .toUpperCase();

    if (
        value === 'M' ||
        value === 'MALE'
    ) {
        return '/images/male-cadet.png';
    }

    if (
        value === 'F' ||
        value === 'FEMALE'
    ) {
        return '/images/female-cadet.png';
    }

    return undefined;
}

function getSchoolId(
    row: DataTableRow | StudentOption,
): string {
    const value = String(
        row.school_id_no ?? '',
    ).trim();

    return value || 'No School ID';
}

function getDepartmentSeverity(
    department: unknown,
): DepartmentSeverity {
    const value = String(department ?? '')
        .trim()
        .toUpperCase();

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
    const value = String(department ?? '')
        .trim()
        .toUpperCase();

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
| API requests
|--------------------------------------------------------------------------
*/

async function loadJournals(
    pageNumber = 1,
): Promise<void> {
    journalController?.abort();

    const controller =
        new AbortController();

    journalController = controller;
    loading.value = true;
    pageError.value = '';

    try {
        const response =
            await axios.get<JournalApiResponse>(
                '/api/v1/dashboard/datatable/daily-journals',
                {
                    signal: controller.signal,

                    params: {
                        page: pageNumber,
                        per_page: rows.value,
                        search: search.value,

                        sort_field:
                            sortField.value,

                        sort_direction:
                            sortDirection.value,

                        date_from:
                            formatDateParameter(
                                dateFrom.value,
                            ),

                        date_to:
                            formatDateParameter(
                                dateTo.value,
                            ),

                        person_id:
                            selectedStudent.value
                                ?.id ?? '',

                        school_id:
                            schoolId.value.trim(),
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

        journals.value =
            response.data.data;

        totalRecords.value =
            response.data.meta.total;

        rows.value =
            response.data.meta.perPage;

        first.value =
            (
                response.data.meta.currentPage -
                1
            ) *
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

        journals.value = [];
        totalRecords.value = 0;

        pageError.value =
            axios.isAxiosError(error)
                ? String(
                      error.response?.data
                          ?.message ??
                          'Unable to load daily journals.',
                  )
                : 'Unable to load daily journals.';
    } finally {
        if (
            journalController ===
            controller
        ) {
            loading.value = false;
        }
    }
}

async function searchStudents(event: {
    query: string;
}): Promise<void> {
    studentController?.abort();

    const controller =
        new AbortController();

    studentController = controller;
    studentSearchLoading.value = true;

    try {
        const response =
            await axios.get<StudentLookupResponse>(
                '/api/v1/dashboard/daily-journals/students',
                {
                    signal: controller.signal,

                    params: {
                        search:
                            event.query.trim(),
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

        studentSuggestions.value =
            response.data.data;
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

        studentSuggestions.value = [];
    } finally {
        if (
            studentController ===
            controller
        ) {
            studentSearchLoading.value =
                false;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Filter actions
|--------------------------------------------------------------------------
*/

function handleStudentSelect(): void {
    if (!selectedStudent.value) {
        return;
    }

    schoolId.value =
        selectedStudent.value
            .school_id_no ?? '';
}

function handleSchoolIdInput(): void {
    if (
        selectedStudent.value &&
        schoolId.value.trim() !==
            String(
                selectedStudent.value
                    .school_id_no ?? '',
            ).trim()
    ) {
        selectedStudent.value = null;
    }
}

function validateDateRange(): boolean {
    if (
        !dateFrom.value ||
        !dateTo.value
    ) {
        pageError.value =
            'Please select both the start and end date.';

        return false;
    }

    if (
        dateFrom.value >
        dateTo.value
    ) {
        pageError.value =
            'The start date must be before or equal to the end date.';

        return false;
    }

    return true;
}

function applyFilters(): void {
    if (!validateDateRange()) {
        return;
    }

    pageError.value = '';
    first.value = 0;

    void loadJournals(1);
}

function clearFilters(): void {
    const currentDate = new Date();

    journalDateRange.value = [
        new Date(
            currentDate.getFullYear(),
            currentDate.getMonth(),
            1,
        ),
        new Date(
            currentDate.getFullYear(),
            currentDate.getMonth() + 1,
            0,
        ),
    ];

    selectedStudent.value = null;
    studentSuggestions.value = [];
    schoolId.value = '';
    search.value = '';
    first.value = 0;
    pageError.value = '';

    void loadJournals(1);
}

function printJournals(): void {
    if (!canPrint.value) {
        pageError.value =
            'Select a student or enter an exact school ID before printing.';

        return;
    }

    if (!validateDateRange()) {
        return;
    }

    pageError.value = '';

    const parameters =
        new URLSearchParams();

    parameters.set(
        'date_from',
        formatDateParameter(dateFrom.value),
    );

    parameters.set(
        'date_to',
        formatDateParameter(dateTo.value),
    );

    if (selectedStudent.value?.id) {
        parameters.set(
            'person_id',
            selectedStudent.value.id,
        );
    } else {
        parameters.set(
            'school_id',
            schoolId.value.trim(),
        );
    }

    window.open(
        `/dashboard/daily-journals/print?${parameters.toString()}`,
        '_blank',
        'noopener,noreferrer',
    );
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

    void loadJournals(event.page + 1);
}

function handleSort(
    event: SortEvent,
): void {
    sortField.value =
        event.sortField ||
        'date_journal';

    sortDirection.value =
        event.sortOrder === -1
            ? 'desc'
            : 'asc';

    first.value = 0;

    void loadJournals(1);
}

function handleSearch(
    value: string,
): void {
    search.value = value;
    first.value = 0;

    void loadJournals(1);
}

function handleAction(
    action: string,
    journal: DataTableRow,
): void {
    if (
        action !== 'view-evidence'
    ) {
        return;
    }

    const evidenceUrl = String(
        journal.evidence_url ?? '',
    ).trim();

    if (!evidenceUrl) {
        return;
    }

    window.open(
        evidenceUrl,
        '_blank',
        'noopener,noreferrer',
    );
}

function goToDashboard(): void {
    router.visit('/dashboard');
}

/*
|--------------------------------------------------------------------------
| Lifecycle
|--------------------------------------------------------------------------
*/

onMounted(() => {
    void loadJournals(1);
});

onBeforeUnmount(() => {
    journalController?.abort();
    studentController?.abort();
});
</script>

<template>
    <Head title="Daily Journals" />

    <div
        class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-slate-50 p-4 lg:p-5"
    >
        <!-- Filters -->

        <Card
            class="!rounded-2xl !border !border-slate-200 !shadow-sm [&_.p-card-body]:!p-4 [&_.p-card-content]:!p-0"
        >
            <template #content>
                <div
                    class="flex flex-col gap-4 xl:flex-row xl:items-end"
                >
                    <div
                        class="grid flex-1 gap-4 sm:grid-cols-2 xl:grid-cols-3"
                    >
                        <!-- Date range -->

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="journal-date-range"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Journal Date Range
                            </label>

                            <DatePicker
                                id="journal-date-range"
                                v-model="
                                    journalDateRange
                                "
                                selection-mode="range"
                                date-format="M d, yy"
                                show-icon
                                icon-display="input"
                                show-button-bar
                                :manual-input="false"
                                class="w-full"
                                input-class="w-full"
                                placeholder="Select start and end date"
                            />
                        </div>

                        <!-- Student -->

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="journal-student"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Student
                            </label>

                            <AutoComplete
                                id="journal-student"
                                v-model="
                                    selectedStudent
                                "
                                :suggestions="
                                    studentSuggestions
                                "
                                option-label="label"
                                :loading="
                                    studentSearchLoading
                                "
                                dropdown
                                force-selection
                                class="w-full"
                                input-class="w-full"
                                placeholder="Search student..."
                                @complete="
                                    searchStudents
                                "
                                @item-select="
                                    handleStudentSelect
                                "
                            >
                                <template
                                    #option="{
                                        option,
                                    }"
                                >
                                    <div
                                        class="flex min-w-0 items-center gap-3"
                                    >
                                        <Avatar
                                            v-if="
                                                getStudentAvatar(
                                                    option.gender,
                                                )
                                            "
                                            :image="
                                                getStudentAvatar(
                                                    option.gender,
                                                )
                                            "
                                            shape="circle"
                                            class="shrink-0"
                                        />

                                        <Avatar
                                            v-else
                                            :label="
                                                getStudentInitials(
                                                    option,
                                                )
                                            "
                                            shape="circle"
                                            class="shrink-0 !bg-blue-50 !font-bold !text-blue-600"
                                        />

                                        <div
                                            class="min-w-0"
                                        >
                                            <p
                                                class="truncate text-sm font-semibold text-slate-700"
                                            >
                                                {{
                                                    option.name
                                                }}
                                            </p>

                                            <div
                                                class="mt-1"
                                            >
                                                <PrimeTag
                                                    :value="
                                                        getSchoolId(
                                                            option,
                                                        )
                                                    "
                                                    icon="pi pi-id-card"
                                                    rounded
                                                    class="!bg-blue-50 !px-2 !py-0.5 !text-[15px] !font-semibold !text-blue-600"
                                                />
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </AutoComplete>
                        </div>

                        <!-- School ID -->

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="journal-school-id"
                                class="text-sm font-semibold text-slate-700"
                            >
                                School ID No.
                            </label>

                            <InputText
                                id="journal-school-id"
                                v-model="schoolId"
                                class="w-full"
                                placeholder="Enter school ID..."
                                @input="
                                    handleSchoolIdInput
                                "
                            />
                        </div>
                    </div>

                    <!-- Actions -->

                    <div
                        class="flex flex-wrap items-center gap-2"
                    >
                        <Button
                            type="button"
                            label="View"
                            icon="pi pi-search"
                            severity="info"
                            @click="applyFilters"
                        />

                        <Button
                            type="button"
                            label="Print"
                            icon="pi pi-print"
                            severity="success"
                            :disabled="!canPrint"
                            @click="printJournals"
                        />

                        <Button
                            type="button"
                            label="Clear"
                            icon="pi pi-filter-slash"
                            severity="secondary"
                            variant="outlined"
                            @click="clearFilters"
                        />
                    </div>
                </div>

                <Message
                    v-if="pageError"
                    severity="error"
                    closable
                    class="mt-4"
                    @close="pageError = ''"
                >
                    {{ pageError }}
                </Message>
            </template>
        </Card>

        <!-- DataTable -->

        <Datatable
            title="Daily Journals"
            description="Review student watchkeeping journals and objective evidence."
            header-icon="pi pi-book"
            search-placeholder="Search journals..."
            empty-title="No journals found"
            empty-description="No daily journals matched the selected filters."
            empty-icon="pi pi-book"
            table-min-width="1250px"
            data-key="id"
            lazy
            :loading="loading"
            :data="journals"
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
            actions-header="Actions"
            actions-width="120px"
            @page="handlePage"
            @sort="handleSort"
            @search="handleSearch"
            @action="handleAction"
        >
        <!-- <template #header-actions>
            <Button
                type="button"
                label="Dashboard"
                icon="pi pi-arrow-left"
                severity="secondary"
                variant="outlined"
                size="small"
                @click="goToDashboard"
            />
        </template> -->

            <!-- Student information -->

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
                            getStudentAvatar(
                                data.gender,
                            )
                        "
                        :image="
                            getStudentAvatar(
                                data.gender,
                            )
                        "
                        :aria-label="
                            String(
                                data.student_name ||
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
                            getStudentInitials(data)
                        "
                        shape="circle"
                        size="large"
                        class="shrink-0 !bg-blue-50 !font-bold !text-blue-600"
                    />

                    <div class="min-w-0">
                        <p
                            class="truncate font-semibold text-slate-700"
                        >
                            {{
                                data.student_name ||
                                '—'
                            }}
                        </p>

                        <div
                            class="mt-1 flex flex-wrap items-center gap-1.5"
                        >
                            <PrimeTag
                                :value="
                                    getSchoolId(data)
                                "
                                icon="pi pi-building"
                                rounded
                                severity="info"
                                class="!px-2 !py-0.5 !text-xs !font-semibold"
                            />

                            <PrimeTag
                                :value="
                                    String(
                                        data.department ||
                                            '—',
                                    )
                                "
                                :severity="
                                    getDepartmentSeverity(
                                        data.department,
                                    )
                                "
                                :icon="
                                    getDepartmentIcon(
                                        data.department,
                                    )
                                "
                                rounded
                                class="!px-2 !py-0.5 !text-xs !font-semibold"
                            />
                        </div>
                    </div>
                </div>
            </template>

            <!-- Journal details -->

            <template
                #cell-date_journal="{
                    data,
                }"
            >
                <div class="space-y-2">
                    <div
                        class="flex items-center gap-2"
                    >
                        <i
                            class="pi pi-calendar text-sm font-bold text-blue-500"
                        />

                        <span
                            class="font-semibold text-slate-700"
                        >
                            {{
                                formatDisplayDate(
                                    data.date_journal,
                                )
                            }}
                        </span>
                    </div>

                    <div
                        class="flex items-center gap-2"
                    >
                        <i
                            class="pi pi-clock text-sm font-bold text-yellow-500"
                        />

                        <span
                            class="text-sm text-slate-500"
                        >
                            {{
                                formatJournalTime(
                                    data.journal_time,
                                )
                            }}
                            –
                            {{
                                formatJournalTime(
                                    data.journal_time_to,
                                )
                            }}
                        </span>
                    </div>

                    <div
                        v-if="
                            data.vessel_name
                        "
                        class="flex items-center gap-2"
                    >
                        <i
                            class="pi pi-compass text-sm font-bold text-cyan-500"
                        />

                        <span
                            class="text-sm text-slate-500"
                        >
                            {{
                                data.vessel_name
                            }}
                        </span>
                    </div>
                </div>
            </template>

            <!-- Voyage -->

            <template
                #cell-port_depart="{
                    data,
                }"
            >
                <div class="space-y-2">
                    <div
                        class="flex items-start gap-2"
                    >
                        <PrimeTag
                            value="From"
                            severity="success"
                            rounded
                            class="!px-2 !py-0.5 !font-semibold"
                        />

                        <span
                            class="pt-0.5 text-sm font-medium text-slate-700"
                        >
                            {{
                                data.port_depart ||
                                '—'
                            }}
                        </span>
                    </div>

                    <div
                        class="flex items-start gap-2"
                    >
                        <PrimeTag
                            value="To"
                            severity="info"
                            rounded
                            class="!px-2 !py-0.5 !font-semibold"
                        />

                        <span
                            class="pt-0.5 text-sm font-medium text-slate-700"
                        >
                            {{
                                data.port_dest ||
                                '—'
                            }}
                        </span>
                    </div>
                </div>
            </template>

            <!-- Duty hours -->

            <template
                #cell-duty_hours="{
                    value,
                }"
            >
                <div
                    class="flex items-center gap-2"
                >
                    <i
                        class="pi pi-clock text-base font-bold text-yellow-500"
                    />

                    <span
                        class="font-semibold text-slate-700"
                    >
                        {{
                            value ||
                            '0 hr 0 min'
                        }}
                    </span>
                </div>
            </template>

            <!-- Status -->

            <template
                #cell-status="{ data }"
            >
                <div
                    class="flex justify-center"
                >
                    <PrimeTag
                        :value="
                            String(
                                data.status ||
                                    'Pending',
                            )
                        "
                        :severity="
                            data.validated
                                ? 'success'
                                : 'warn'
                        "
                        :icon="
                            data.validated
                                ? 'pi pi-check-circle'
                                : 'pi pi-clock'
                        "
                        rounded
                        class="!px-2 !py-0.5 !font-semibold"
                    />
                </div>
            </template>
        </Datatable>
    </div>
</template>