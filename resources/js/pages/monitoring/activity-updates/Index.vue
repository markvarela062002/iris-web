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
                title: 'Activity Update List',
                href: '/monitoring/activity-updates',
            },
        ],
    },
});

/*
|--------------------------------------------------------------------------
| API response types
|--------------------------------------------------------------------------
*/

type ActivityApiResponse = {
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

/*
|--------------------------------------------------------------------------
| Page state
|--------------------------------------------------------------------------
*/

const activities = ref<DataTableRow[]>([]);

const loading = ref(false);

const totalRecords = ref(0);
const first = ref(0);
const perPage = ref(10);

const search = ref('');

const sortField = ref('last_update');

const sortDirection = ref<'asc' | 'desc'>(
    'desc',
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
        sortable: true,
        searchable: true,
        frozen: true,
        alignFrozen: 'left',
        class: 'w-[360px] min-w-[360px]',
    },
    {
        field: 'desc_activity',
        header: 'Activity',
        sortable: true,
        searchable: true,
        class: 'w-[320px] min-w-[320px] whitespace-normal',
    },
    {
        field: 'start_date',
        header: 'Activity Period',
        sortable: true,
        searchable: true,
        class: 'w-[260px] min-w-[260px]',
    },
    {
        field: 'sto_validated',
        header: 'Verified',
        sortable: true,
        searchable: false,
        class: 'w-[150px] min-w-[150px]',
    },
    {
        field: 'revise_remarks',
        header: 'Remarks',
        sortable: false,
        searchable: true,
        class: 'w-[260px] min-w-[260px]',
    },
];

/*
|--------------------------------------------------------------------------
| Datatable actions
|--------------------------------------------------------------------------
*/

const hasUploadedFile = (
    row: DataTableRow,
): boolean => {
    return (
        typeof row.file_url === 'string' &&
        row.file_url.trim() !== ''
    );
};

const actions: DataTableAction[] = [
    {
        key: 'view-file',
        label: 'View uploaded file',
        icon: 'pi pi-download',
        severity: 'info',
        visible: (row) => {
            return hasUploadedFile(row);
        },
    },
    {
        key: 'no-pdf',
        label: 'No file uploaded',
        icon: 'pi pi-download',
        severity: 'secondary',
        visible: (row) => {
            return !hasUploadedFile(row);
        },
    },
];

/*
|--------------------------------------------------------------------------
| Load activities
|--------------------------------------------------------------------------
*/

async function loadActivities(
    pageNumber = 1,
): Promise<void> {
    /*
     * Cancel an older request if another request
     * starts before it finishes.
     *
     * This pattern comes directly from the
     * senior's Activity Verification page.
     */
    requestController?.abort();

    const controller = new AbortController();

    requestController = controller;

    loading.value = true;
    errorMessage.value = '';

    try {
        const response =
            await axios.get<ActivityApiResponse>(
                '/api/v1/monitoring/datatable/activity-updates',
                {
                    signal: controller.signal,

                    params: {
                        page: pageNumber,
                        per_page: perPage.value,
                        search: search.value,
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

        /*
         * Laravel returned our rows.
         * Put them inside Vue state.
         */
        activities.value =
            response.data.data;

        totalRecords.value =
            response.data.meta.total;

        perPage.value =
            response.data.meta.perPage;

        first.value =
            (
                response.data.meta.currentPage -
                1
            ) *
            response.data.meta.perPage;
    } catch (error: unknown) {
        /*
         * A canceled request is intentional.
         * Do not show it as an error.
         */
        if (
            axios.isCancel(error) ||
            (
                axios.isAxiosError(error) &&
                error.code === 'ERR_CANCELED'
            )
        ) {
            return;
        }

        activities.value = [];
        totalRecords.value = 0;

        errorMessage.value =
            'Unable to load activity updates.';

        console.error(
            'Unable to load activity updates:',
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
| Datatable events
|--------------------------------------------------------------------------
*/

function handlePage(
    event: DataTablePageEvent,
): void {
    perPage.value = event.rows;
    first.value = event.first;

    void loadActivities(
        event.page + 1,
    );
}

function handleSort(
    event: DataTableSortEvent,
): void {
    sortField.value =
        event.sortField || 'last_update';

    sortDirection.value =
        event.sortOrder === -1
            ? 'desc'
            : 'asc';

    first.value = 0;

    void loadActivities(1);
}

function handleSearch(
    value: string,
): void {
    search.value = value;
    first.value = 0;

    void loadActivities(1);
}

/*
|--------------------------------------------------------------------------
| Actions
|--------------------------------------------------------------------------
*/

function handleAction(
    action: string,
    activity: DataTableRow,
): void {
    errorMessage.value = '';

    /*
     * Keep the secondary PDF icon visible,
     * but do nothing when there is no file.
     */
    if (action === 'no-pdf') {
        return;
    }

    if (action === 'view-file') {
        openUploadedFile(activity);
    }
}

function openUploadedFile(
    activity: DataTableRow,
): void {
    const fileUrl = String(
        activity.file_url ?? '',
    ).trim();

    if (!fileUrl) {
        errorMessage.value =
            'This activity does not have an uploaded file.';

        return;
    }

    window.open(
        fileUrl,
        '_blank',
        'noopener,noreferrer',
    );
}

/*
|--------------------------------------------------------------------------
| Navigation
|--------------------------------------------------------------------------
*/

function navigateToVerification(): void {
    router.visit(
        '/dashboard/activity-updates',
    );
}

/*
|--------------------------------------------------------------------------
| Student helpers
|--------------------------------------------------------------------------
*/

function getStudentFullName(
    activity: DataTableRow,
): string {
    const lastName = String(
        activity.lname ?? '',
    ).trim();

    const otherNames = [
        activity.fname,
        activity.mname,
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
    activity: DataTableRow,
): string {
    const firstName = String(
        activity.fname ?? '',
    ).trim();

    const lastName = String(
        activity.lname ?? '',
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
| Status helpers
|--------------------------------------------------------------------------
*/

function isVerified(
    activity: DataTableRow,
): boolean {
    return String(
        activity.sto_validated ?? '',
    )
        .trim()
        .toUpperCase() === 'Y';
}

/*
|--------------------------------------------------------------------------
| Date helpers
|--------------------------------------------------------------------------
*/

function formatDate(
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
| Lifecycle
|--------------------------------------------------------------------------
*/

onMounted(() => {
    void loadActivities(1);
});

onBeforeUnmount(() => {
    requestController?.abort();
});
</script>

<template>
    <Head title="Activity Updates" />

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

        <!-- ACTIVITY UPDATES TABLE -->

        <Datatable
            title="Activity Update List"
            description="Monitor student activity records and their verification status."
            header-icon="pi pi-list-check"
            search-placeholder="Search activity records..."
            empty-title="No activity records found"
            empty-description="No matching student activity records were found."
            empty-icon="pi pi-list-check"
            table-min-width="1350px"
            data-key="id"
            lazy
            :loading="loading"
            :data="activities"
            :columns="columns"
            :actions="actions"
            :total-records="totalRecords"
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

            <template #header-actions>
                <Button
                    type="button"
                    label="Activity Verification"
                    icon="pi pi-check-circle"
                    severity="info"
                    size="small"
                    @click="
                        navigateToVerification
                    "
                />
            </template>
            <!-- STUDENT INFORMATION -->

            <template #cell-fname="{ data }">
                <div class="flex items-center gap-3">
                    <Avatar
                        v-if="
                            getStudentAvatar(
                                data.gender,
                            )
                        "
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
                                    getSchoolIdLabel(
                                        data.code_person,
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
                        <!-- ACTIVITY -->
            <template
                #cell-desc_activity="{ value }"
            >
                <div
                    class="flex min-w-0 items-start gap-2 whitespace-normal"
                >
                    <i
                        class="pi pi-list-check mt-0.5 shrink-0 text-green-500"
                    ></i>

                    <span
                        class="min-w-0 font-medium break-words whitespace-normal text-slate-700"
                    >
                        {{ value || '—' }}
                    </span>
                </div>
            </template>
            <!-- ACTIVITY PERIOD -->
            <template
                #cell-start_date="{ data }"
            >
                <div class="space-y-2">
                    <div
                        class="flex items-center gap-2"
                    >
                        <PrimeTag
                            value="Started"
                            severity="success"
                            class="w-16 !justify-center !px-2 !py-1 !text-xs !font-semibold"
                        />

                        <span
                            class="whitespace-nowrap text-sm font-medium text-slate-600"
                        >
                            {{
                                formatDate(
                                    data.start_date,
                                )
                            }}
                        </span>
                    </div>

                    <div
                        class="flex items-center gap-2"
                    >
                        <PrimeTag
                            value="Ended"
                            severity="danger"
                            class="w-16 !justify-center !px-2 !py-1 !text-xs !font-semibold"
                        />

                        <span
                            class="whitespace-nowrap text-sm font-medium text-slate-600"
                        >
                            {{
                                formatDate(
                                    data.end_date,
                                )
                            }}
                        </span>
                    </div>
                </div>
            </template>
            <!-- VERIFIED -->
            <template
                #cell-sto_validated="{ data }"
            >
                <PrimeTag
                    :value="
                        isVerified(data)
                            ? 'Verified'
                            : 'Pending'
                    "
                    :severity="
                        isVerified(data)
                            ? 'success'
                            : 'warn'
                    "
                    :icon="
                        isVerified(data)
                            ? 'pi pi-check-circle'
                            : 'pi pi-clock'
                    "
                />
            </template>
            <!-- REMARKS -->
            <template
                #cell-revise_remarks="{ value }"
            >
                <span
                    v-if="
                        value &&
                        String(value).trim() !== '-'
                    "
                    class="text-sm font-medium text-slate-700"
                >
                    {{ value }}
                </span>

                <span
                    v-else
                    class="text-slate-400"
                >
                    —
                </span>
            </template>
        </Datatable>
    </div>
</template>