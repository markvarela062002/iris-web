<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Avatar from 'primevue/avatar';
import Button from 'primevue/button';
import Card from 'primevue/card';
import Checkbox from 'primevue/checkbox';
import DatePicker from 'primevue/datepicker';
import Message from 'primevue/message';
import PrimeTag from 'primevue/tag';
import Select from 'primevue/select';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
} from 'vue';

import Datatable from '@/components/Datatable.vue';

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
                href: '/dashboard',
            },
            {
                title: 'Student List Report',
                href: '/databases/student-list-report',
            },
        ],
    },
});

type LookupOption = {
    label: string;
    value: string;
};

type AppliedFilters = {
    from_date: string;
    to_date: string;
    batch_no: string | null;
    dept: string | null;
};

type OptionalField =
    | 'etrb_type'
    | 'mobile'
    | 'phone'
    | 'email'
    | 'st_address'
    | 'birth_date'
    | 'gender'
    | 'civ_status'
    | 'mother_name'
    | 'mother_nos'
    | 'father_name'
    | 'father_nos'
    | 'spouse_name'
    | 'spouse_nos';

type OptionalColumn = {
    field: OptionalField;
    label: string;
    class: string;
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

type ApiResponse = {
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

type OptionsResponse = {
    data: {
        cciYears: LookupOption[];
        departments: LookupOption[];
    };
};

type DepartmentSeverity =
    | 'success'
    | 'info'
    | 'secondary';

type EtrbSeverity =
    | 'success'
    | 'info'
    | 'warn'
    | 'secondary'
    | 'contrast';

const API =
    '/api/v1/databases/student-list-report';

/*
|--------------------------------------------------------------------------
| Report date range
|--------------------------------------------------------------------------
|
| Match the Daily Journals pattern:
| - one PrimeVue DatePicker
| - range selection
| - current month as the default
| - the report loads immediately on page open
|
*/

function defaultDateRange(): (Date | null)[] {
    const now = new Date();

    return [
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
    ];
}

const reportDateRange =
    ref<(Date | null)[]>(
        defaultDateRange(),
    );

const dateFrom =
    computed<Date | null>(() => {
        return (
            reportDateRange.value[0] ??
            null
        );
    });

const dateTo =
    computed<Date | null>(() => {
        return (
            reportDateRange.value[1] ??
            null
        );
    });

const selectedCciYear =
    ref<string | null>(null);

const selectedDepartment =
    ref<string | null>(null);

/*
|--------------------------------------------------------------------------
| Optional column filters
|--------------------------------------------------------------------------
*/

const optionalColumns: OptionalColumn[] = [
    {
        field: 'etrb_type',
        label: 'e-TRB Type',
        class: 'min-w-[150px]',
    },
    {
        field: 'mobile',
        label: 'Mobile No.',
        class: 'min-w-[170px]',
    },
    {
        field: 'phone',
        label: 'Phone No.',
        class: 'min-w-[170px]',
    },
    {
        field: 'email',
        label: 'Email',
        class: 'min-w-[240px]',
    },
    {
        field: 'st_address',
        label: 'Address',
        class:
            'min-w-[320px] whitespace-normal',
    },
    {
        field: 'birth_date',
        label: 'Date of Birth',
        class: 'min-w-[170px]',
    },
    {
        field: 'gender',
        label: 'Gender',
        class: 'min-w-[130px]',
    },
    {
        field: 'civ_status',
        label: 'Civil Status',
        class: 'min-w-[150px]',
    },
    {
        field: 'mother_name',
        label: "Mother's Name",
        class: 'min-w-[220px]',
    },
    {
        field: 'mother_nos',
        label: "Mother's Contact No.",
        class: 'min-w-[200px]',
    },
    {
        field: 'father_name',
        label: "Father's Name",
        class: 'min-w-[220px]',
    },
    {
        field: 'father_nos',
        label: "Father's Contact No.",
        class: 'min-w-[200px]',
    },
    {
        field: 'spouse_name',
        label: "Spouse's Name",
        class: 'min-w-[220px]',
    },
    {
        field: 'spouse_nos',
        label: "Spouse's Contact No.",
        class: 'min-w-[200px]',
    },
];

function emptyOptionalSelection(): Record<
    OptionalField,
    boolean
> {
    return optionalColumns.reduce(
        (
            selection,
            column,
        ) => {
            selection[column.field] =
                false;

            return selection;
        },
        {} as Record<
            OptionalField,
            boolean
        >,
    );
}

const selectedOptionalColumns =
    ref<
        Record<
            OptionalField,
            boolean
        >
    >(
        emptyOptionalSelection(),
    );

const appliedOptionalColumns =
    ref<OptionalField[]>([]);

const optionalFiltersVisible =
    ref(false);

const includeAll = computed({
    get(): boolean {
        return optionalColumns.every(
            (column) =>
                selectedOptionalColumns
                    .value[
                    column.field
                ],
        );
    },

    set(value: boolean): void {
        optionalColumns.forEach(
            (column) => {
                selectedOptionalColumns
                    .value[
                    column.field
                ] = value;
            },
        );
    },
});

function showOptionalFilters(): void {
    optionalFiltersVisible.value = true;
}

function hideOptionalFilters(): void {
    optionalFiltersVisible.value =
        false;
}

const appliedOptionalFieldSet =
    computed(() => {
        return new Set(
            appliedOptionalColumns.value,
        );
    });

function hasAppliedField(
    field: OptionalField,
): boolean {
    return appliedOptionalFieldSet
        .value
        .has(
            field,
        );
}

const showPersonalInformation =
    computed(() => {
        return (
            hasAppliedField(
                'st_address',
            )
            ||
            hasAppliedField(
                'birth_date',
            )
            ||
            hasAppliedField(
                'gender',
            )
            ||
            hasAppliedField(
                'civ_status',
            )
        );
    });

const showMotherInformation =
    computed(() => {
        return (
            hasAppliedField(
                'mother_name',
            )
            ||
            hasAppliedField(
                'mother_nos',
            )
        );
    });

const showFatherInformation =
    computed(() => {
        return (
            hasAppliedField(
                'father_name',
            )
            ||
            hasAppliedField(
                'father_nos',
            )
        );
    });

const showSpouseInformation =
    computed(() => {
        return (
            hasAppliedField(
                'spouse_name',
            )
            ||
            hasAppliedField(
                'spouse_nos',
            )
        );
    });

/*
|--------------------------------------------------------------------------
| Lookup options
|--------------------------------------------------------------------------
*/

const cciYears =
    ref<LookupOption[]>([]);

const departments =
    ref<LookupOption[]>([]);

const optionsLoading = ref(false);

/*
|--------------------------------------------------------------------------
| Datatable state
|--------------------------------------------------------------------------
*/

const students =
    ref<DataTableRow[]>([]);

const loading = ref(false);
const downloading = ref(false);
const hasReport = ref(false);
const errorMessage = ref('');

const totalRecords = ref(0);
const first = ref(0);
const perPage = ref(10);
const search = ref('');

const sortField =
    ref('date_reg');

const sortDirection =
    ref<'asc' | 'desc'>(
        'asc',
    );

let appliedFilters:
    | AppliedFilters
    | null = null;

let listRequest:
    | AbortController
    | null = null;

let exportRequest:
    | AbortController
    | null = null;

const lifetime =
    new AbortController();

let disposed = false;

/*
|--------------------------------------------------------------------------
| Datatable columns
|--------------------------------------------------------------------------
|
| Match the Daily Journals layout:
| - one compact Student Information cell
| - one compact Registration Details cell
| - optional columns are appended only after a report is generated
|
*/

const columns =
    computed<DataTableColumn[]>(
        () => {
            const result: DataTableColumn[] = [
                {
                    field: 'lname',
                    header:
                        'Student Information',
                    sortable: false,
                    searchable: true,
                    frozen: true,
                    alignFrozen: 'left',
                    class:
                        'min-w-[330px]',
                },
                {
                    field: 'date_reg',
                    header:
                        'Registration Details',
                    sortable: false,
                    searchable: false,
                    class:
                        'min-w-[280px]',
                },
            ];

            if (
                showPersonalInformation.value
            ) {
                result.push({
                    field:
                        'personal_information',
                    header:
                        'Personal Information',
                    sortable: false,
                    searchable: false,
                    class:
                        'min-w-[280px]',
                });
            }

            if (
                showMotherInformation.value
            ) {
                result.push({
                    field:
                        'mother_information',
                    header:
                        "Mother's Information",
                    sortable: false,
                    searchable: false,
                    class:
                        'min-w-[230px]',
                });
            }

            if (
                showFatherInformation.value
            ) {
                result.push({
                    field:
                        'father_information',
                    header:
                        "Father's Information",
                    sortable: false,
                    searchable: false,
                    class:
                        'min-w-[230px]',
                });
            }

            if (
                showSpouseInformation.value
            ) {
                result.push({
                    field:
                        'spouse_information',
                    header:
                        "Spouse's Information",
                    sortable: false,
                    searchable: false,
                    class:
                        'min-w-[230px]',
                });
            }

            return result;
        },
    );

const tableMinWidth =
    computed(() => {
        let width = 760;

        if (
            showPersonalInformation.value
        ) {
            width += 280;
        }

        if (
            showMotherInformation.value
        ) {
            width += 230;
        }

        if (
            showFatherInformation.value
        ) {
            width += 230;
        }

        if (
            showSpouseInformation.value
        ) {
            width += 230;
        }

        return `${width}px`;
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

    const year =
        value.getFullYear();

    const month =
        String(
            value.getMonth() + 1,
        ).padStart(
            2,
            '0',
        );

    const day =
        String(
            value.getDate(),
        ).padStart(
            2,
            '0',
        );

    return `${year}-${month}-${day}`;
}

function formatDisplayDate(
    value: unknown,
): string {
    if (
        !value
        ||
        value === '1970-01-01'
        ||
        value ===
            '1970-01-01 00:00:00'
    ) {
        return '—';
    }

    const rawValue =
        String(value);

    const date =
        new Date(
            `${rawValue.substring(
                0,
                10,
            )}T00:00:00`,
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
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        },
    ).format(
        date,
    );
}

function getStudentFullName(
    row: DataTableRow,
): string {
    const lastName =
        String(
            row.lname ?? '',
        ).trim();

    const otherNames = [
        row.fname,
        row.mname,
    ]
        .filter(
            (name) =>
                typeof name ===
                    'string'
                &&
                name.trim() !== '',
        )
        .map(
            (name) =>
                String(
                    name,
                ).trim(),
        )
        .join(' ');

    if (
        lastName
        &&
        otherNames
    ) {
        return `${lastName}, ${otherNames}`.toUpperCase();
    }

    return (
        lastName ||
        otherNames ||
        '—'
    ).toUpperCase();
}

function getStudentInitials(
    row: DataTableRow,
): string {
    const firstName =
        String(
            row.fname ?? '',
        ).trim();

    const lastName =
        String(
            row.lname ?? '',
        ).trim();

    return (
        `${firstName.charAt(
            0,
        )}${lastName.charAt(
            0,
        )}`
            .toUpperCase()
        ||
        'ST'
    );
}

function getStudentAvatar(
    gender: unknown,
): string | undefined {
    const value =
        String(
            gender ?? '',
        )
            .trim()
            .toUpperCase();

    if (
        value === 'M'
        ||
        value === 'MALE'
    ) {
        return '/images/male-cadet.png';
    }

    if (
        value === 'F'
        ||
        value === 'FEMALE'
    ) {
        return '/images/female-cadet.png';
    }

    return undefined;
}

function getSchoolId(
    row: DataTableRow,
): string {
    const value =
        String(
            row.school_id_no ?? '',
        ).trim();

    return (
        value ||
        'No School ID'
    );
}

function getDepartmentSeverity(
    department: unknown,
): DepartmentSeverity {
    const value =
        String(
            department ?? '',
        )
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
    const value =
        String(
            department ?? '',
        )
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

function getCciYear(
    value: unknown,
): string {
    const year =
        String(
            value ?? '',
        ).trim();

    return (
        year ||
        'No CCI Year'
    );
}

function getEtrbSeverity(
    value: unknown,
): EtrbSeverity {
    const type =
        String(
            value ?? '',
        )
            .trim()
            .toUpperCase();

    if (type === 'GMET') {
        return 'info';
    }

    if (type === 'ISF') {
        return 'info';
    }

    if (
        type === 'GMET AND ISF'
    ) {
        return 'info';
    }

    if (type === 'TRMF') {
        return 'info';
    }

    return 'info';
}

function displayValue(
    value: unknown,
): string {
    const text =
        String(
            value ?? '',
        ).trim();

    return text || '—';
}

function formatGender(
    value: unknown,
): string {
    const gender =
        String(
            value ?? '',
        )
            .trim()
            .toUpperCase();

    if (
        gender === 'F'
        ||
        gender === 'FEMALE'
    ) {
        return 'Female';
    }

    if (
        gender === 'M'
        ||
        gender === 'MALE'
    ) {
        return 'Male';
    }

    return (
        gender ||
        '—'
    );
}

/*
|--------------------------------------------------------------------------
| Filter helpers
|--------------------------------------------------------------------------
*/

function validateDateRange(): boolean {
    if (
        !dateFrom.value
        ||
        !dateTo.value
    ) {
        errorMessage.value =
            'Please select both the start and end date.';

        return false;
    }

    if (
        dateFrom.value >
        dateTo.value
    ) {
        errorMessage.value =
            'The start date must be before or equal to the end date.';

        return false;
    }

    return true;
}

function captureAppliedFilters(): boolean {
    if (!validateDateRange()) {
        return false;
    }

    appliedFilters = {
        from_date:
            formatDateParameter(
                dateFrom.value,
            ),
        to_date:
            formatDateParameter(
                dateTo.value,
            ),
        batch_no:
            selectedCciYear.value,
        dept:
            selectedDepartment.value,
    };

    appliedOptionalColumns.value =
        optionalColumns
            .filter(
                (column) =>
                    selectedOptionalColumns
                        .value[
                        column.field
                    ],
            )
            .map(
                (column) =>
                    column.field,
            );

    return true;
}

/*
|--------------------------------------------------------------------------
| API requests
|--------------------------------------------------------------------------
*/

async function loadOptions(): Promise<void> {
    optionsLoading.value = true;

    try {
        const response =
            await axios.get<OptionsResponse>(
                `${API}/options`,
                {
                    signal:
                        lifetime.signal,

                    headers: {
                        Accept:
                            'application/json',
                        'X-Requested-With':
                            'XMLHttpRequest',
                    },

                    withCredentials: true,
                },
            );

        cciYears.value =
            response.data.data
                .cciYears;

        departments.value =
            response.data.data
                .departments;
    } catch (error: unknown) {
        if (
            axios.isCancel(error)
        ) {
            return;
        }

        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to load Student List Report options.',
            );
    } finally {
        if (!disposed) {
            optionsLoading.value =
                false;
        }
    }
}

async function loadReport(
    pageNumber = 1,
): Promise<void> {
    if (!appliedFilters) {
        return;
    }

    listRequest?.abort();
    exportRequest?.abort();

    downloading.value = false;

    const controller =
        new AbortController();

    listRequest =
        controller;

    loading.value = true;
    errorMessage.value = '';

    try {
        const response =
            await axios.get<ApiResponse>(
                API,
                {
                    signal:
                        controller.signal,

                    params: {
                        ...appliedFilters,
                        columns:
                            appliedOptionalColumns
                                .value,
                        page:
                            pageNumber,
                        per_page:
                            perPage.value,
                        search:
                            search.value,
                        sort_field:
                            sortField.value,
                        sort_direction:
                            sortDirection.value,
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

        if (
            controller.signal.aborted
        ) {
            return;
        }

        students.value =
            response.data.data;

        totalRecords.value =
            response.data.meta
                .total;

        perPage.value =
            response.data.meta
                .perPage;

        first.value =
            (
                response.data.meta
                    .currentPage -
                1
            )
            *
            response.data.meta
                .perPage;

        hasReport.value = true;
    } catch (error: unknown) {
        if (
            axios.isCancel(error)
            ||
            (
                axios.isAxiosError(
                    error,
                )
                &&
                error.code ===
                    'ERR_CANCELED'
            )
        ) {
            return;
        }

        students.value = [];
        totalRecords.value = 0;
        hasReport.value = false;

        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to load the Student List Report.',
            );
    } finally {
        if (
            !controller.signal.aborted
            &&
            !disposed
        ) {
            loading.value = false;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Filter actions
|--------------------------------------------------------------------------
*/

function viewReport(): void {
    errorMessage.value = '';

    if (
        !captureAppliedFilters()
    ) {
        return;
    }

    first.value = 0;

    void loadReport(1);
}

function clearFilters(): void {
    listRequest?.abort();
    exportRequest?.abort();

    reportDateRange.value =
        defaultDateRange();

    selectedCciYear.value = null;
    selectedDepartment.value = null;

    selectedOptionalColumns.value =
        emptyOptionalSelection();

    appliedOptionalColumns.value =
        [];

    optionalFiltersVisible.value =
        false;

    search.value = '';
    sortField.value = 'date_reg';
    sortDirection.value = 'asc';

    first.value = 0;
    errorMessage.value = '';

    if (
        captureAppliedFilters()
    ) {
        void loadReport(1);
    }
}

/*
|--------------------------------------------------------------------------
| Datatable events
|--------------------------------------------------------------------------
*/

function pageChanged(
    event: DataTablePageEvent,
): void {
    perPage.value =
        event.rows;

    first.value =
        event.first;

    void loadReport(
        event.page + 1,
    );
}

function sortChanged(
    event: DataTableSortEvent,
): void {
    sortField.value =
        event.sortField
        ||
        'date_reg';

    sortDirection.value =
        event.sortOrder === -1
            ? 'desc'
            : 'asc';

    first.value = 0;

    void loadReport(1);
}

function searchChanged(
    value: string,
): void {
    search.value = value;
    first.value = 0;

    void loadReport(1);
}

/*
|--------------------------------------------------------------------------
| Excel download
|--------------------------------------------------------------------------
*/

async function downloadExcel(): Promise<void> {
    if (
        !appliedFilters
        ||
        !hasReport.value
        ||
        downloading.value
    ) {
        return;
    }

    exportRequest?.abort();

    const controller =
        new AbortController();

    exportRequest =
        controller;

    downloading.value = true;
    errorMessage.value = '';

    try {
        const response =
            await axios.get(
                `${API}/export`,
                {
                    responseType:
                        'blob',

                    signal:
                        controller.signal,

                    params: {
                        ...appliedFilters,
                        columns:
                            appliedOptionalColumns
                                .value,
                        search:
                            search.value,
                        sort_field:
                            sortField.value,
                        sort_direction:
                            sortDirection.value,
                    },

                    headers: {
                        Accept:
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'X-Requested-With':
                            'XMLHttpRequest',
                    },

                    withCredentials: true,
                },
            );

        if (
            controller.signal.aborted
        ) {
            return;
        }

        const blob =
            response.data as Blob;

        const disposition =
            String(
                response.headers[
                    'content-disposition'
                ] ?? '',
            );

        const filenameMatch =
            disposition.match(
                /filename\*?=(?:UTF-8''|")?([^";]+)/i,
            );

        let filename =
            'student-list-report.xlsx';

        if (
            filenameMatch?.[1]
        ) {
            try {
                filename =
                    decodeURIComponent(
                        filenameMatch[1]
                            .replace(
                                /^"|"$/g,
                                '',
                            )
                            .trim(),
                    );
            } catch {
                filename =
                    'student-list-report.xlsx';
            }
        }

        const objectUrl =
            URL.createObjectURL(
                blob,
            );

        const link =
            document.createElement(
                'a',
            );

        link.href =
            objectUrl;

        link.download =
            filename;

        document.body
            .appendChild(
                link,
            );

        link.click();
        link.remove();

        URL.revokeObjectURL(
            objectUrl,
        );
    } catch (error: unknown) {
        if (
            axios.isCancel(error)
        ) {
            return;
        }

        if (
            axios.isAxiosError(
                error,
            )
            &&
            error.response
                ?.data instanceof Blob
        ) {
            try {
                const payload =
                    JSON.parse(
                        await error
                            .response
                            .data
                            .text(),
                    ) as {
                        message?: string;
                        errors?: Record<
                            string,
                            string[]
                        >;
                    };

                errorMessage.value =
                    Object.values(
                        payload.errors
                            ?? {},
                    )
                        .flat()
                        .join(' ')
                    ||
                    payload.message
                    ||
                    'Unable to download the Excel report.';
            } catch {
                errorMessage.value =
                    'Unable to download the Excel report.';
            }

            return;
        }

        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to download the Excel report.',
            );
    } finally {
        if (
            !controller.signal
                .aborted
            &&
            !disposed
        ) {
            downloading.value =
                false;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Error helpers
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
                  errors?: Record<
                      string,
                      string[]
                  >;
              }
            | undefined;

    const validation =
        responseData?.errors
            ? Object.values(
                  responseData.errors,
              )
                  .flat()
                  .find(
                      (
                          value,
                      ) =>
                          typeof value ===
                          'string',
                  )
            : undefined;

    return (
        validation
        ||
        responseData?.message
        ||
        fallback
    );
}

/*
|--------------------------------------------------------------------------
| Lifecycle
|--------------------------------------------------------------------------
*/

onMounted(() => {
    /*
     * Load the default report immediately,
     * exactly like the senior Daily Journals page.
     */
    if (
        captureAppliedFilters()
    ) {
        void loadReport(1);
    }

    void loadOptions();
});

onBeforeUnmount(() => {
    disposed = true;

    lifetime.abort();
    listRequest?.abort();
    exportRequest?.abort();
});
</script>

<template>
    <Head title="Student List Report" />

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
                                for="student-report-date-range"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Registration Date Range
                            </label>

                            <DatePicker
                                id="student-report-date-range"
                                v-model="reportDateRange"
                                selection-mode="range"
                                date-format="M d, yy"
                                show-icon
                                icon-display="input"
                                show-button-bar
                                :manual-input="false"
                                :disabled="loading"
                                class="w-full"
                                input-class="w-full"
                                placeholder="Select start and end date"
                            />
                        </div>

                        <!-- Department -->

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-report-department"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Department
                            </label>

                            <Select
                                id="student-report-department"
                                v-model="selectedDepartment"
                                :options="departments"
                                option-label="label"
                                option-value="value"
                                placeholder="All departments"
                                show-clear
                                :loading="optionsLoading"
                                :disabled="loading"
                                class="w-full"
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
                            :loading="loading"
                            @click="viewReport"
                        />

                        <Button
                            type="button"
                            label="Filters"
                            icon="pi pi-filter"
                            severity="secondary"
                            :disabled="
                                optionalFiltersVisible ||
                                loading
                            "
                            @click="showOptionalFilters"
                        />

                        <Button
                            type="button"
                            label="Clear"
                            icon="pi pi-filter-slash"
                            severity="secondary"
                            variant="outlined"
                            :disabled="loading"
                            @click="clearFilters"
                        />
                    </div>
                </div>

                <!-- Optional columns -->

                <div
                    v-if="optionalFiltersVisible"
                    class="mt-4 rounded-2xl border border-slate-200 bg-slate-50/70 p-4"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-3"
                    >
                        <div>
                            <p
                                class="text-sm font-semibold text-slate-700"
                            >
                                Optional Columns
                            </p>

                            <p
                                class="mt-0.5 text-xs text-slate-500"
                            >
                                Choose additional student information to include in the table and Excel report.
                            </p>
                        </div>

                        <div
                            class="flex flex-wrap items-center gap-3"
                        >
                            <div
                                class="flex items-center gap-2"
                            >
                                <Checkbox
                                    input-id="student-report-include-all"
                                    v-model="includeAll"
                                    binary
                                    :disabled="loading"
                                />

                                <label
                                    for="student-report-include-all"
                                    class="cursor-pointer text-sm font-semibold text-slate-700"
                                >
                                    Include All
                                </label>
                            </div>

                            <Button
                                type="button"
                                label="Hide Filters"
                                icon="pi pi-chevron-up"
                                severity="secondary"
                                variant="outlined"
                                size="small"
                                :disabled="loading"
                                @click="hideOptionalFilters"
                            />
                        </div>
                    </div>

                    <div
                        class="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                    >
                        <div
                            v-for="column in optionalColumns"
                            :key="column.field"
                            class="flex items-center gap-2"
                        >
                            <Checkbox
                                :input-id="`student-report-${column.field}`"
                                v-model="
                                    selectedOptionalColumns[
                                        column.field
                                    ]
                                "
                                binary
                                :disabled="loading"
                            />

                            <label
                                :for="`student-report-${column.field}`"
                                class="cursor-pointer text-sm text-slate-700"
                            >
                                {{ column.label }}
                            </label>
                        </div>
                    </div>
                </div>

                <Message
                    v-if="errorMessage"
                    severity="error"
                    closable
                    class="mt-4"
                    @close="errorMessage = ''"
                >
                    {{ errorMessage }}
                </Message>
            </template>
        </Card>

        <!-- DataTable -->

        <Datatable
            title="Student List Report"
            description="Review active student records for the selected registration period."
            header-icon="pi pi-users"
            search-placeholder="Search student report..."
            :table-min-width="tableMinWidth"
            data-key="id"
            lazy
            empty-title="No students found"
            empty-description="No active students match the selected registration period and filters."
            empty-icon="pi pi-users"
            :data="students"
            :columns="columns"
            :actions="[]"
            :show-actions="false"
            :loading="loading"
            :total-records="totalRecords"
            :first="first"
            :rows="perPage"
            :rows-per-page-options="[10, 20, 50, 100]"
            @page="pageChanged"
            @sort="sortChanged"
            @search="searchChanged"
        >
            <template #header-actions>
                <Button
                    type="button"
                    label="Download Excel"
                    icon="pi pi-download"
                    severity="success"
                    :disabled="
                        !hasReport ||
                        loading
                    "
                    :loading="downloading"
                    @click="downloadExcel"
                />
            </template>

            <!-- Student information -->

            <template #cell-lname="{ data }">
                <div
                    class="flex items-center gap-3"
                >
                    <Avatar
                        v-if="
                            getStudentAvatar(
                                data.avatar_gender,
                            )
                        "
                        :image="
                            getStudentAvatar(
                                data.avatar_gender,
                            )
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
                        class="shrink-0 !bg-blue-50 !font-bold !text-blue-600"
                    />

                    <div class="min-w-0">
                        <p
                            class="truncate font-semibold text-slate-700"
                        >
                            {{
                                getStudentFullName(
                                    data,
                                )
                            }}
                        </p>

                        <div
                            class="mt-1 flex flex-wrap items-center gap-1"
                        >
                            <PrimeTag
                                :value="
                                    getSchoolId(
                                        data,
                                    )
                                "
                                icon="pi pi-id-card"
                                rounded
                                severity="info"
                                class="!px-2 !py-0.5 !text-[11px] !font-semibold"
                            />

                            <PrimeTag
                                :value="
                                    String(
                                        data.dept ||
                                            '—',
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
                                rounded
                                class="!px-2 !py-0.5 !text-[11px] !font-semibold"
                            />

                            <PrimeTag
                                :value="
                                    getCciYear(
                                        data.batch_no,
                                    )
                                "
                                icon="pi pi-calendar"
                                rounded
                                severity="secondary"
                                class="!px-2 !py-0.5 !text-[11px] !font-semibold"
                            />

                            <PrimeTag
                                v-if="
                                    hasAppliedField(
                                        'etrb_type',
                                    )
                                "
                                :value="
                                    displayValue(
                                        data.etrb_type,
                                    )
                                "
                                :severity="
                                    getEtrbSeverity(
                                        data.etrb_type,
                                    )
                                "
                                icon="pi pi-book"
                                rounded
                                class="!px-2 !py-0.5 !text-[11px] !font-semibold"
                            />
                        </div>
                    </div>
                </div>
            </template>

            <!-- Registration and contact details -->

            <template #cell-date_reg="{ data }">
                <div class="space-y-1.5">
                    <div
                        class="flex items-center gap-2"
                    >
                        <i
                            class="pi pi-calendar text-xs font-bold text-blue-500"
                        ></i>

                        <span
                            class="text-sm font-semibold text-slate-700"
                        >
                            {{
                                formatDisplayDate(
                                    data.date_reg,
                                )
                            }}
                        </span>
                    </div>

                    <div
                        v-if="
                            hasAppliedField(
                                'email',
                            )
                        "
                        class="flex min-w-0 items-center gap-2"
                    >
                        <i
                            class="pi pi-envelope shrink-0 text-xs text-red-500"
                        ></i>

                        <span
                            class="min-w-0 truncate text-xs text-slate-600"
                            :title="
                                displayValue(
                                    data.email,
                                )
                            "
                        >
                            {{
                                displayValue(
                                    data.email,
                                )
                            }}
                        </span>
                    </div>

                    <div
                        v-if="
                            hasAppliedField(
                                'mobile',
                            ) ||
                            hasAppliedField(
                                'phone',
                            )
                        "
                        class="flex flex-wrap items-center gap-x-3 gap-y-1"
                    >
                        <span
                            v-if="
                                hasAppliedField(
                                    'mobile',
                                )
                            "
                            class="flex items-center gap-1.5 text-xs text-slate-600"
                        >
                            <i
                                class="pi pi-mobile text-cyan-500"
                            ></i>

                            {{
                                displayValue(
                                    data.mobile,
                                )
                            }}
                        </span>

                        <span
                            v-if="
                                hasAppliedField(
                                    'phone',
                                )
                            "
                            class="flex items-center gap-1.5 text-xs text-slate-600"
                        >
                            <i
                                class="pi pi-phone text-emerald-500"
                            ></i>

                            {{
                                displayValue(
                                    data.phone,
                                )
                            }}
                        </span>
                    </div>
                </div>
            </template>

            <!-- Personal information -->

            <template
                #cell-personal_information="{ data }"
            >
                <div class="space-y-1.5">
                    <div
                        v-if="
                            hasAppliedField(
                                'birth_date',
                            )
                        "
                        class="flex items-center gap-2 text-xs"
                    >
                        <i
                            class="pi pi-calendar shrink-0 text-blue-500"
                        ></i>

                        <span
                            class="font-medium text-slate-700"
                        >
                            {{
                                formatDisplayDate(
                                    data.birth_date,
                                )
                            }}
                        </span>
                    </div>

                    <div
                        v-if="
                            hasAppliedField(
                                'gender',
                            ) ||
                            hasAppliedField(
                                'civ_status',
                            )
                        "
                        class="flex flex-wrap items-center gap-1.5"
                    >
                        <PrimeTag
                            v-if="
                                hasAppliedField(
                                    'gender',
                                )
                            "
                            :value="
                                formatGender(
                                    data.gender,
                                )
                            "
                            icon="pi pi-user"
                            severity="info"
                            rounded
                            class="!px-2 !py-0.5 !text-[11px] !font-semibold"
                        />

                        <PrimeTag
                            v-if="
                                hasAppliedField(
                                    'civ_status',
                                )
                            "
                            :value="
                                displayValue(
                                    data.civ_status,
                                )
                            "
                            icon="pi pi-heart"
                            severity="secondary"
                            rounded
                            class="!px-2 !py-0.5 !text-[11px] !font-semibold"
                        />
                    </div>

                    <div
                        v-if="
                            hasAppliedField(
                                'st_address',
                            )
                        "
                        class="flex items-start gap-2 text-xs leading-4 text-slate-600"
                    >
                        <i
                            class="pi pi-map-marker mt-0.5 shrink-0 text-red-400"
                        ></i>

                        <span
                            class="line-clamp-2"
                            :title="
                                displayValue(
                                    data.st_address,
                                )
                            "
                        >
                            {{
                                displayValue(
                                    data.st_address,
                                )
                            }}
                        </span>
                    </div>
                </div>
            </template>

            <!-- Mother's information -->

            <template
                #cell-mother_information="{ data }"
            >
                <div class="space-y-1.5">
                    <div
                        v-if="
                            hasAppliedField(
                                'mother_name',
                            )
                        "
                        class="flex items-center gap-2 text-sm font-medium text-slate-700"
                    >
                        <i
                            class="pi pi-user text-pink-500"
                        ></i>

                        <span
                            class="truncate"
                            :title="
                                displayValue(
                                    data.mother_name,
                                )
                            "
                        >
                            {{
                                displayValue(
                                    data.mother_name,
                                )
                            }}
                        </span>
                    </div>

                    <div
                        v-if="
                            hasAppliedField(
                                'mother_nos',
                            )
                        "
                        class="flex items-center gap-2 text-xs text-slate-600"
                    >
                        <i
                            class="pi pi-phone text-emerald-500"
                        ></i>

                        {{
                            displayValue(
                                data.mother_nos,
                            )
                        }}
                    </div>
                </div>
            </template>

            <!-- Father's information -->

            <template
                #cell-father_information="{ data }"
            >
                <div class="space-y-1.5">
                    <div
                        v-if="
                            hasAppliedField(
                                'father_name',
                            )
                        "
                        class="flex items-center gap-2 text-sm font-medium text-slate-700"
                    >
                        <i
                            class="pi pi-user text-blue-500"
                        ></i>

                        <span
                            class="truncate"
                            :title="
                                displayValue(
                                    data.father_name,
                                )
                            "
                        >
                            {{
                                displayValue(
                                    data.father_name,
                                )
                            }}
                        </span>
                    </div>

                    <div
                        v-if="
                            hasAppliedField(
                                'father_nos',
                            )
                        "
                        class="flex items-center gap-2 text-xs text-slate-600"
                    >
                        <i
                            class="pi pi-phone text-emerald-500"
                        ></i>

                        {{
                            displayValue(
                                data.father_nos,
                            )
                        }}
                    </div>
                </div>
            </template>

            <!-- Spouse's information -->

            <template
                #cell-spouse_information="{ data }"
            >
                <div class="space-y-1.5">
                    <div
                        v-if="
                            hasAppliedField(
                                'spouse_name',
                            )
                        "
                        class="flex items-center gap-2 text-sm font-medium text-slate-700"
                    >
                        <i
                            class="pi pi-user text-violet-500"
                        ></i>

                        <span
                            class="truncate"
                            :title="
                                displayValue(
                                    data.spouse_name,
                                )
                            "
                        >
                            {{
                                displayValue(
                                    data.spouse_name,
                                )
                            }}
                        </span>
                    </div>

                    <div
                        v-if="
                            hasAppliedField(
                                'spouse_nos',
                            )
                        "
                        class="flex items-center gap-2 text-xs text-slate-600"
                    >
                        <i
                            class="pi pi-phone text-emerald-500"
                        ></i>

                        {{
                            displayValue(
                                data.spouse_nos,
                            )
                        }}
                    </div>
                </div>
            </template>
        </Datatable>
    </div>
</template>
