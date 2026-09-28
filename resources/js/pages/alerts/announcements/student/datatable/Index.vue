<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Message from 'primevue/message';
import PrimeTag from 'primevue/tag';
import {
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
                href: '/student-dashboard',
            },
            {
                title: 'Announcements',
                href: '/alerts/announcements/student/datatable',
            },
        ],
    },
});

type ApiListResponse = {
    data: DataTableRow[];

    meta: {
        currentPage: number;
        lastPage?: number;
        perPage: number;
        total: number;
        from?: number | null;
        to?: number | null;
    };
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

const DATATABLE_URL =
    '/api/v1/student/alerts/datatable/announcements';

const announcements =
    ref<DataTableRow[]>([]);

const loading = ref(false);
const errorMessage = ref('');
const totalRecords = ref(0);
const first = ref(0);
const perPage = ref(10);
const search = ref('');
const sortField = ref('post_by');
const sortDirection =
    ref<'asc' | 'desc'>('desc');

let requestController:
    AbortController | null = null;

const columns: DataTableColumn[] = [
    {
        field: 'subject',
        header: 'Announcement',
        sortable: true,
        searchable: true,
        class:
            'w-[280px] min-w-[280px] whitespace-normal',
    },
    {
        field: 'details',
        header: 'Details',
        sortable: false,
        searchable: true,
        class:
            'w-[440px] min-w-[440px] whitespace-normal',
    },
    {
        field: 'post_by',
        header: 'Schedule',
        sortable: true,
        searchable: false,
        class:
            'w-[220px] min-w-[220px]',
    },
    {
        field: 'status',
        header: 'Status',
        sortable: false,
        searchable: false,
        class:
            'w-[130px] min-w-[130px]',
    },
];

async function loadAnnouncements(
    page = 1,
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
            await axios.get<ApiListResponse>(
                DATATABLE_URL,
                {
                    signal:
                        controller.signal,

                    params: {
                        page,
                        per_page:
                            perPage.value,
                        search:
                            search.value,
                        sort_field:
                            sortField.value,
                        sort_direction:
                            sortDirection.value,
                    },

                    ...requestConfig(),
                },
            );

        announcements.value =
            response.data.data;

        totalRecords.value =
            response.data.meta.total;

        perPage.value =
            response.data.meta.perPage;

        first.value =
            (
                response.data.meta
                    .currentPage
                - 1
            )
            *
            response.data.meta
                .perPage;
    } catch (error: unknown) {
        if (isCanceled(error)) {
            return;
        }

        announcements.value = [];
        totalRecords.value = 0;

        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to load announcements.',
            );
    } finally {
        if (
            requestController
            === controller
        ) {
            loading.value = false;
        }
    }
}

function handlePage(
    event: PageEvent,
): void {
    perPage.value = event.rows;
    first.value = event.first;

    void loadAnnouncements(
        event.page + 1,
    );
}

function handleSort(
    event: SortEvent,
): void {
    sortField.value =
        event.sortField
        || 'post_by';

    sortDirection.value =
        event.sortOrder === -1
            ? 'desc'
            : 'asc';

    first.value = 0;

    void loadAnnouncements(1);
}

function handleSearch(
    value: string,
): void {
    search.value = value;
    first.value = 0;

    void loadAnnouncements(1);
}

function shortText(
    value: unknown,
    maximum = 170,
): string {
    const text = String(
        value
        ?? '',
    )
        .replace(/\s+/g, ' ')
        .trim();

    if (
        text.length
        <= maximum
    ) {
        return text || '—';
    }

    return `${text.slice(
        0,
        maximum,
    )}…`;
}

function displayDate(
    value: unknown,
): string {
    const date =
        parseMysqlDate(
            String(
                value
                ?? '',
            ),
        );

    if (!date) {
        return '—';
    }

    return new Intl.DateTimeFormat(
        'en-US',
        {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        },
    ).format(date);
}

function parseMysqlDate(
    value: string,
): Date | null {
    const match =
        /^(\d{4})-(\d{2})-(\d{2})$/.exec(
            value,
        );

    if (!match) {
        return null;
    }

    return new Date(
        Number(match[1]),
        Number(match[2]) - 1,
        Number(match[3]),
    );
}

function requestConfig() {
    return {
        headers: {
            Accept:
                'application/json',
            'X-Requested-With':
                'XMLHttpRequest',
        },

        withCredentials: true,
    };
}

function isCanceled(
    error: unknown,
): boolean {
    return (
        axios.isCancel(error)
        ||
        (
            axios.isAxiosError(
                error,
            )
            &&
            error.code
                === 'ERR_CANCELED'
        )
    );
}

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

    return (
        (
            error.response
                ?.data as
                | {
                      message?: string;
                  }
                | undefined
        )?.message
        ||
        fallback
    );
}

onMounted(
    () =>
        void loadAnnouncements(
            1,
        ),
);

onBeforeUnmount(
    () =>
        requestController?.abort(),
);
</script>

<template>
    <Head title="Announcements" />

    <div
        class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5"
    >
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

        <Datatable
            title="Announcements"
            description="View announcements currently published to you."
            header-icon="pi pi-megaphone"
            search-placeholder="Search announcements..."
            empty-title="No announcements found"
            empty-description="There are no active announcements at this time."
            empty-icon="pi pi-megaphone"
            table-min-width="1080px"
            data-key="id"
            lazy
            :loading="loading"
            :data="announcements"
            :columns="columns"
            :total-records="
                totalRecords
            "
            :first="first"
            :rows="perPage"
            :rows-per-page-options="
                [10, 20, 50, 100]
            "
            :show-actions="false"
            @page="handlePage"
            @sort="handleSort"
            @search="handleSearch"
        >
            <template
                #cell-subject="{ data }"
            >
                <div
                    class="flex items-start gap-2.5"
                >
                    <i
                        class="pi pi-megaphone mt-1 shrink-0 text-amber-500"
                    ></i>

                    <div
                        class="min-w-0"
                    >
                        <div
                            class="font-semibold text-slate-800"
                        >
                            {{
                                data.subject
                                || '—'
                            }}
                        </div>
                    </div>
                </div>
            </template>

            <template
                #cell-details="{ value }"
            >
                <div
                    class="whitespace-normal text-sm leading-5 text-slate-600"
                    :title="
                        String(
                            value
                            ?? '',
                        )
                    "
                >
                    {{
                        shortText(
                            value,
                        )
                    }}
                </div>
            </template>

            <template
                #cell-post_by="{ data }"
            >
                <div
                    class="space-y-1.5 text-sm"
                >
                    <div
                        class="flex items-center gap-2"
                    >
                        <i
                            class="pi pi-calendar-plus text-emerald-500"
                        ></i>

                        <span
                            class="text-xs font-semibold text-slate-500"
                        >
                            From
                        </span>

                        <span
                            class="font-medium text-slate-700"
                        >
                            {{
                                displayDate(
                                    data.post_by,
                                )
                            }}
                        </span>
                    </div>

                    <div
                        class="flex items-center gap-2"
                    >
                        <i
                            class="pi pi-calendar-times text-rose-400"
                        ></i>

                        <span
                            class="text-xs font-semibold text-slate-500"
                        >
                            Until
                        </span>

                        <span
                            class="font-medium text-slate-700"
                        >
                            {{
                                displayDate(
                                    data.post_until,
                                )
                            }}
                        </span>
                    </div>
                </div>
            </template>

            <template
                #cell-status="{ value }"
            >
                <PrimeTag
                    :value="
                        String(
                            value
                            ?? 'Active',
                        )
                    "
                    severity="success"
                    rounded
                />
            </template>
        </Datatable>
    </div>
</template>
