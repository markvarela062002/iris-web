<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';

import Button from 'primevue/button';
import Message from 'primevue/message';
import Select from 'primevue/select';
import PrimeTag from 'primevue/tag';

import {
    onMounted,
    ref,
} from 'vue';

import Datatable from '@/components/Datatable.vue';
import { dashboard } from '@/routes';

import type {
    DataTableColumn,
    DataTableRow,
} from '@/types';

/*
|--------------------------------------------------------------------------
| Page configuration
|--------------------------------------------------------------------------
*/

defineOptions({
    inheritAttrs: false,

    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
            {
                title: 'Reports',
                href: '/monitoring/reports',
            },
        ],
    },
});

/*
|--------------------------------------------------------------------------
| Types
|--------------------------------------------------------------------------
*/

type Option = {
    value: number | string;
    label: string;
};

type OptionsResponse = {
    reports: Option[];
    years: number[];
    departments: string[];
    genders: string[];
};

type ReportResponse = {
    report: {
        type: number;
        title: string;
    };

    columns: DataTableColumn[];
    data: DataTableRow[];
};

type ReportColumn =
    DataTableColumn & {
        slotName: string;
    };

/*
|--------------------------------------------------------------------------
| Filter options
|--------------------------------------------------------------------------
*/

const reportOptions =
    ref<Option[]>([]);

const yearOptions =
    ref<number[]>([]);

const departmentOptions =
    ref<string[]>([]);

const genderOptions =
    ref<string[]>([]);

/*
|--------------------------------------------------------------------------
| Selected filters
|--------------------------------------------------------------------------
*/

/*
 * Legacy report type:
 *
 * 1 = Percent Deployment
 */
const selectedReport =
    ref<number | null>(1);

/*
 * Default CCI Year:
 * current year.
 */
const currentYear =
    new Date().getFullYear();

const selectedYear =
    ref<number | null>(
        currentYear,
    );

const selectedDepartment =
    ref<string | null>(null);

const selectedGender =
    ref<string | null>(null);

/*
|--------------------------------------------------------------------------
| Report state
|--------------------------------------------------------------------------
*/

const columns =
    ref<ReportColumn[]>([]);

const rows =
    ref<DataTableRow[]>([]);

const loading = ref(false);

const errorMessage = ref('');

/*
|--------------------------------------------------------------------------
| Load filter options
|--------------------------------------------------------------------------
*/

async function loadOptions(): Promise<void> {
    try {
        const response =
            await axios.get<OptionsResponse>(
                '/api/v1/monitoring/reports/options',
                {
                    headers: {
                        Accept:
                            'application/json',

                        'X-Requested-With':
                            'XMLHttpRequest',
                    },

                    withCredentials: true,
                },
            );

        reportOptions.value =
            response.data.reports;

        yearOptions.value =
            response.data.years;

        departmentOptions.value =
            response.data.departments;

        genderOptions.value =
            response.data.genders;

        /*
         * Prefer the current year.
         *
         * If the current year is not
         * included in the configured CCI
         * years, use the latest available
         * year instead.
         */
        if (
            yearOptions.value.includes(
                currentYear,
            )
        ) {
            selectedYear.value =
                currentYear;
        } else if (
            yearOptions.value.length > 0
        ) {
            selectedYear.value =
                yearOptions.value[
                    yearOptions.value.length -
                        1
                ];
        }
    } catch (error: unknown) {
        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to load report options.',
            );
    }
}

/*
|--------------------------------------------------------------------------
| Load report
|--------------------------------------------------------------------------
*/

async function loadReport(): Promise<void> {
    if (!selectedReport.value) {
        errorMessage.value =
            'Please select a report.';

        return;
    }

    loading.value = true;
    errorMessage.value = '';

    try {
        const response =
            await axios.get<ReportResponse>(
                '/api/v1/monitoring/reports',
                {
                    params: {
                        report_type:
                            selectedReport.value,

                        batch_no:
                            selectedYear.value,

                        dept:
                            selectedDepartment.value,

                        gender:
                            selectedGender.value,
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

        /*
         * Reports have dynamic columns.
         *
         * Datatable.vue expects custom
         * cell slots using:
         *
         * cell-{field}
         *
         * Examples:
         *
         * registered
         * → cell-registered
         *
         * activity_0
         * → cell-activity_0
         */
        columns.value =
            response.data.columns.map(
                (column) => {
                    return {
                        ...column,

                        slotName:
                            `cell-${column.field}`,

                        class: [
                            column.class,
                            'min-w-[170px] whitespace-normal',
                        ]
                            .filter(Boolean)
                            .join(' '),
                    };
                },
            );

        rows.value =
            response.data.data;
    } catch (error: unknown) {
        columns.value = [];
        rows.value = [];

        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to generate the report.',
            );
    } finally {
        loading.value = false;
    }
}

/*
|--------------------------------------------------------------------------
| Report icons
|--------------------------------------------------------------------------
*/

function getReportIcon(
    field: string,
): string {
    /*
     * Percent Deployment.
     */

    if (field === 'registered') {
        return 'pi pi-users';
    }

    if (field === 'cci') {
        return 'pi pi-check-circle';
    }

    if (field === 'wastage') {
        return 'pi pi-exclamation-triangle';
    }

    if (field === 'deployed') {
        return 'pi pi-send';
    }

    /*
     * Activities Summary.
     */

    if (
        field.startsWith(
            'activity_',
        )
    ) {
        return 'pi pi-list-check';
    }

    /*
     * Wastage Distribution.
     */

    if (
        field.startsWith(
            'wastage_',
        )
    ) {
        return 'pi pi-exclamation-circle';
    }

    /*
     * Wastages and CCI.
     */

    if (field === 'before_cci') {
        return 'pi pi-exclamation-triangle';
    }

    if (field === 'after_cci') {
        return 'pi pi-check-circle';
    }

    return 'pi pi-chart-bar';
}

/*
|--------------------------------------------------------------------------
| Report icon colors
|--------------------------------------------------------------------------
*/

function getReportIconClass(
    field: string,
): string {
    /*
     * Informational.
     */

    if (field === 'registered') {
        return 'text-blue-500';
    }

    /*
     * Positive / completed.
     */

    if (
        field === 'cci' ||
        field === 'deployed' ||
        field.startsWith(
            'activity_',
        ) ||
        field === 'after_cci'
    ) {
        return 'text-green-500';
    }

    /*
     * Negative / wastage.
     */

    if (
        field === 'wastage' ||
        field.startsWith(
            'wastage_',
        ) ||
        field === 'before_cci'
    ) {
        return 'text-red-500';
    }

    return 'text-slate-400';
}

/*
|--------------------------------------------------------------------------
| Report value helper
|--------------------------------------------------------------------------
*/

function formatReportValue(
    value: unknown,
): string {
    if (
        value === null ||
        value === undefined ||
        value === ''
    ) {
        return '0';
    }

    return String(value);
}

/*
|--------------------------------------------------------------------------
| Response helper
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

    const data =
        error.response?.data as
            | {
                  message?: string;
              }
            | undefined;

    return (
        data?.message ??
        fallback
    );
}

/*
|--------------------------------------------------------------------------
| Lifecycle
|--------------------------------------------------------------------------
*/

onMounted(async () => {
    /*
     * Load available report filters.
     */
    await loadOptions();

    /*
     * Automatically load:
     *
     * Percent Deployment
     * + Current CCI Year.
     */
    await loadReport();
});
</script>

<template>
    <Head title="Reports" />

    <div
        class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5"
    >
        <!-- ERROR MESSAGE -->

        <Message
            v-if="errorMessage"
            severity="error"
            closable
            @close="
                errorMessage = ''
            "
        >
            {{ errorMessage }}
        </Message>

        <!-- REPORTS DATATABLE -->

        <Datatable
            title="Reports"
            description="Generate and review student monitoring reports."
            header-icon="pi pi-chart-bar"
            empty-title="No report data"
            empty-description="No records were found for the selected criteria."
            empty-icon="pi pi-chart-bar"
            table-min-width="1100px"
            scroll-height="auto"
            class="!flex-none"
            data-key="index"
            :loading="loading"
            :data="rows"
            :columns="columns"
            :searchable="false"
            :paginator="false"
            :show-actions="false"
        >
            <!-- HEADER FILTERS -->

            <template #header-actions>
                <div
                    class="flex flex-col gap-4 xl:flex-row xl:items-end"
                >
                    <!-- SELECT REPORT -->

                    <div
                        class="flex min-w-[260px] flex-col gap-2"
                    >
                        <label
                            for="report-type"
                            class="text-sm font-semibold text-slate-700"
                        >
                            Select Report
                        </label>

                        <Select
                            id="report-type"
                            v-model="
                                selectedReport
                            "
                            :options="
                                reportOptions
                            "
                            option-label="label"
                            option-value="value"
                            placeholder="Select report"
                            class="w-full"
                        />
                    </div>

                    <!-- CCI YEAR -->

                    <div
                        class="flex min-w-[130px] flex-col gap-2"
                    >
                        <label
                            for="report-year"
                            class="text-sm font-semibold text-slate-700"
                        >
                            CCI Year
                        </label>

                        <Select
                            id="report-year"
                            v-model="
                                selectedYear
                            "
                            :options="
                                yearOptions
                            "
                            placeholder="Year"
                            class="w-full"
                        />
                    </div>

                    <!-- DEPARTMENT -->

                    <div
                        class="flex min-w-[150px] flex-col gap-2"
                    >
                        <label
                            for="report-department"
                            class="text-sm font-semibold text-slate-700"
                        >
                            Dept
                        </label>

                        <Select
                            id="report-department"
                            v-model="
                                selectedDepartment
                            "
                            :options="
                                departmentOptions
                            "
                            placeholder="All"
                            show-clear
                            class="w-full"
                        />
                    </div>

                    <!-- GENDER -->

                    <div
                        class="flex min-w-[150px] flex-col gap-2"
                    >
                        <label
                            for="report-gender"
                            class="text-sm font-semibold text-slate-700"
                        >
                            Gender
                        </label>

                        <Select
                            id="report-gender"
                            v-model="
                                selectedGender
                            "
                            :options="
                                genderOptions
                            "
                            placeholder="All"
                            show-clear
                            class="w-full"
                        />
                    </div>

                    <!-- VIEW -->

                    <Button
                        type="button"
                        label="View"
                        icon="pi pi-search"
                        severity="info"
                        :loading="loading"
                        @click="loadReport"
                    />
                </div>
            </template>

            <!-- DYNAMIC REPORT VALUES -->

            <template
                v-for="column in columns"
                :key="column.field"
                v-slot:[column.slotName]="{
                    value,
                }"
            >
                <!-- PERCENT DEPLOYMENT -->

                <PrimeTag
                    v-if="
                        column.field ===
                        'percentage'
                    "
                    :value="
                        formatReportValue(
                            value,
                        )
                    "
                    severity="info"
                    icon="pi pi-chart-line"
                    class="!px-3 !py-1 !text-sm !font-semibold"
                />

                <!-- NORMAL REPORT METRIC -->

                <div
                    v-else
                    class="flex items-center gap-2"
                >
                    <i
                        :class="[
                            getReportIcon(
                                column.field,
                            ),

                            getReportIconClass(
                                column.field,
                            ),

                            'shrink-0 text-lg',
                        ]"
                    ></i>

                    <span
                        class="font-semibold text-slate-700"
                    >
                        {{
                            formatReportValue(
                                value,
                            )
                        }}
                    </span>
                </div>
            </template>
        </Datatable>
    </div>
</template>