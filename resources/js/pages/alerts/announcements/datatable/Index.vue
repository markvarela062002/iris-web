<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import DatePicker from 'primevue/datepicker';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import PrimeTag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
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
                title: 'Announcements',
                href: '/alerts/announcements/datatable',
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

type Errors = Record<string, string>;

const DATATABLE_URL =
    '/api/v1/alerts/datatable/announcements';

const API_BASE =
    '/api/v1/alerts/announcements';

const toast = useToast();

const announcements =
    ref<DataTableRow[]>([]);

const loading = ref(false);
const saving = ref(false);
const deleting = ref(false);
const errorMessage = ref('');
const totalRecords = ref(0);
const first = ref(0);
const perPage = ref(10);
const search = ref('');
const sortField = ref('post_by');
const sortDirection =
    ref<'asc' | 'desc'>('desc');

const announcementDialogVisible =
    ref(false);

const deleteDialogVisible =
    ref(false);

const selectedAnnouncement =
    ref<DataTableRow | null>(null);

const errors = ref<Errors>({});

const postingDateRange =
    ref<Date[] | null>(null);

const form = reactive({
    id: '',
    post_by: null as Date | null,
    post_until: null as Date | null,
    subject: '',
    details: '',
});

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

const actions: DataTableAction[] = [
    {
        key: 'edit',
        label: 'Edit announcement',
        icon: 'pi pi-pencil',
        severity: 'warn',
    },
];

const currentPage = computed(
    () =>
        Math.floor(
            first.value
                /
                perPage.value,
        ) + 1,
);

const dialogTitle = computed(
    () =>
        form.id
            ? 'Edit Announcement'
            : 'Create Announcement',
);

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

function handleAction(
    action: string,
    row: DataTableRow,
): void {
    if (action === 'edit') {
        openEditAnnouncement(row);
    }
}

function openCreateAnnouncement(): void {
    resetForm();

    announcementDialogVisible.value =
        true;
}

function openEditAnnouncement(
    row: DataTableRow,
): void {
    errors.value = {};

    selectedAnnouncement.value =
        row;

    form.id = String(
        row.id
        ?? '',
    );

    const postFrom =
        parseMysqlDate(
            String(
                row.post_by
                ?? '',
            ),
        );

    const postUntil =
        parseMysqlDate(
            String(
                row.post_until
                ?? '',
            ),
        );

    postingDateRange.value =
        postFrom && postUntil
            ? [
                  postFrom,
                  postUntil,
              ]
            : null;

    form.post_by = postFrom;
    form.post_until = postUntil;

    form.subject = String(
        row.subject
        ?? '',
    );

    form.details = String(
        row.details
        ?? '',
    );

    announcementDialogVisible.value =
        true;
}

async function saveAnnouncement(): Promise<void> {
    errors.value = {};

    const postFrom =
        postingDateRange.value?.[0]
        ?? null;

    const postUntil =
        postingDateRange.value?.[1]
        ?? null;

    if (
        !postFrom
        ||
        !postUntil
    ) {
        errors.value.date_range =
            'Posting date range is required.';
    }

    form.post_by = postFrom;
    form.post_until = postUntil;

    if (!form.subject.trim()) {
        errors.value.subject =
            'Subject is required.';
    }

    if (!form.details.trim()) {
        errors.value.details =
            'Details are required.';
    }

    if (
        Object.keys(
            errors.value,
        ).length
    ) {
        return;
    }

    saving.value = true;

    const wasNew = !form.id;

    try {
        const payload = {
            post_by:
                toMysqlDate(
                    form.post_by!,
                ),
            post_until:
                toMysqlDate(
                    form.post_until!,
                ),
            subject:
                form.subject.trim(),
            details:
                form.details.trim(),
        };

        const response =
            wasNew
                ? await axios.post<{
                      id: string;
                      message: string;
                  }>(
                      API_BASE,
                      payload,
                      requestConfig(),
                  )
                : await axios.put<{
                      id: string;
                      message: string;
                  }>(
                      `${API_BASE}/${encodeURIComponent(
                          form.id,
                      )}`,
                      payload,
                      requestConfig(),
                  );

        form.id =
            response.data.id;

        toast.add({
            severity: 'success',
            summary:
                wasNew
                    ? 'Announcement Created'
                    : 'Announcement Updated',
            detail:
                response.data.message,
            life: 4000,
        });

        announcementDialogVisible.value =
            false;

        resetForm();

        await loadAnnouncements(
            wasNew
                ? 1
                : currentPage.value,
        );
    } catch (error: unknown) {
        errors.value =
            getValidationErrors(
                error,
            );

        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to save the announcement.',
            );
    } finally {
        saving.value = false;
    }
}

function requestDelete(): void {
    if (!form.id) {
        return;
    }

    selectedAnnouncement.value = {
        id: form.id,
        subject: form.subject,
    };

    deleteDialogVisible.value =
        true;
}

async function deleteAnnouncement(): Promise<void> {
    const id = String(
        selectedAnnouncement.value
            ?.id
        ?? '',
    );

    if (!id) {
        return;
    }

    deleting.value = true;

    try {
        const response =
            await axios.delete<{
                message: string;
            }>(
                `${API_BASE}/${encodeURIComponent(
                    id,
                )}`,
                requestConfig(),
            );

        deleteDialogVisible.value =
            false;

        announcementDialogVisible.value =
            false;

        selectedAnnouncement.value =
            null;

        resetForm();

        toast.add({
            severity: 'success',
            summary:
                'Announcement Deleted',
            detail:
                response.data.message,
            life: 4000,
        });

        await reloadCurrentPage();
    } catch (error: unknown) {
        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to delete the announcement.',
            );
    } finally {
        deleting.value = false;
    }
}

function resetForm(): void {
    Object.assign(
        form,
        {
            id: '',
            post_by: null,
            post_until: null,
            subject: '',
            details: '',
        },
    );

    errors.value = {};
    postingDateRange.value =
        null;
    selectedAnnouncement.value =
        null;
}

async function reloadCurrentPage(): Promise<void> {
    await loadAnnouncements(
        currentPage.value,
    );

    if (
        !announcements.value.length
        &&
        currentPage.value > 1
    ) {
        await loadAnnouncements(
            currentPage.value - 1,
        );
    }
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

function toMysqlDate(
    date: Date,
): string {
    return [
        date.getFullYear(),
        String(
            date.getMonth()
            + 1,
        ).padStart(
            2,
            '0',
        ),
        String(
            date.getDate(),
        ).padStart(
            2,
            '0',
        ),
    ].join('-');
}

function statusSeverity(
    status: unknown,
):
    | 'success'
    | 'info'
    | 'secondary' {
    if (status === 'Active') {
        return 'success';
    }

    if (status === 'Upcoming') {
        return 'info';
    }

    return 'secondary';
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

function getValidationErrors(
    error: unknown,
): Errors {
    if (
        !axios.isAxiosError(
            error,
        )
    ) {
        return {};
    }

    const source =
        (
            error.response
                ?.data as
                | {
                      errors?: Record<
                          string,
                          string[]
                      >;
                  }
                | undefined
        )?.errors
        ?? {};

    return Object.fromEntries(
        Object.entries(
            source,
        ).map(
            ([
                key,
                messages,
            ]) => [
                key,
                messages[0]
                    ?? 'Invalid value.',
            ],
        ),
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

    <Toast position="top-right" />

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
            description="Create and manage announcements published to IRIS-SAM users."
            header-icon="pi pi-megaphone"
            search-placeholder="Search announcements..."
            empty-title="No announcements found"
            empty-description="Create an announcement to get started."
            empty-icon="pi pi-megaphone"
            table-min-width="1080px"
            actions-header="Actions"
            actions-width="90px"
            data-key="id"
            lazy
            :loading="loading"
            :data="announcements"
            :columns="columns"
            :actions="actions"
            :total-records="
                totalRecords
            "
            :first="first"
            :rows="perPage"
            :rows-per-page-options="
                [10, 20, 50, 100]
            "
            @page="handlePage"
            @sort="handleSort"
            @search="handleSearch"
            @action="handleAction"
        >
            <template
                #header-actions
            >
                <Button
                    type="button"
                    label="Create Announcement"
                    icon="pi pi-plus"
                    severity="success"
                    size="small"
                    @click="
                        openCreateAnnouncement
                    "
                />
            </template>

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
                    :severity="
                        statusSeverity(
                            value,
                        )
                    "
                    rounded
                />
            </template>
        </Datatable>

        <Dialog
            v-model:visible="
                announcementDialogVisible
            "
            modal
            :header="dialogTitle"
            :closable="!saving"
            class="w-[min(96vw,900px)]"
            @hide="resetForm"
        >
            <div
                class="grid gap-5 sm:grid-cols-2"
            >
                <div
                    class="sm:col-span-2"
                >
                    <label
                        class="mb-2 block text-sm font-semibold text-slate-700"
                    >
                        Posting Date Range
                        <span
                            class="text-red-500"
                        >
                            *
                        </span>
                    </label>

                    <DatePicker
                        v-model="
                            postingDateRange
                        "
                        class="w-full"
                        selection-mode="range"
                        date-format="M d, yy"
                        show-icon
                        fluid
                        :manual-input="false"
                        placeholder="Select post from and post until"
                        :invalid="
                            Boolean(
                                errors.date_range,
                            )
                        "
                    />

                    <small
                        v-if="
                            errors.date_range
                        "
                        class="mt-1 block text-red-500"
                    >
                        {{
                            errors.date_range
                        }}
                    </small>
                </div>

                <div
                    class="sm:col-span-2"
                >
                    <label
                        class="mb-2 block text-sm font-semibold text-slate-700"
                    >
                        Subject
                        <span
                            class="text-red-500"
                        >
                            *
                        </span>
                    </label>

                    <InputText
                        v-model="
                            form.subject
                        "
                        class="w-full"
                        maxlength="200"
                        :invalid="
                            Boolean(
                                errors.subject,
                            )
                        "
                    />

                    <small
                        v-if="
                            errors.subject
                        "
                        class="mt-1 block text-red-500"
                    >
                        {{
                            errors.subject
                        }}
                    </small>
                </div>

                <div
                    class="sm:col-span-2"
                >
                    <label
                        class="mb-2 block text-sm font-semibold text-slate-700"
                    >
                        Details
                        <span
                            class="text-red-500"
                        >
                            *
                        </span>
                    </label>

                    <Textarea
                        v-model="
                            form.details
                        "
                        class="w-full"
                        rows="8"
                        auto-resize
                        :invalid="
                            Boolean(
                                errors.details,
                            )
                        "
                    />

                    <small
                        v-if="
                            errors.details
                        "
                        class="mt-1 block text-red-500"
                    >
                        {{
                            errors.details
                        }}
                    </small>
                </div>
            </div>

            <template #footer>
                <div
                    class="flex w-full flex-wrap items-center justify-between gap-2"
                >
                    <Button
                        v-if="form.id"
                        type="button"
                        label="Delete"
                        icon="pi pi-trash"
                        severity="danger"
                        outlined
                        :disabled="
                            saving
                        "
                        @click="
                            requestDelete
                        "
                    />

                    <span v-else></span>

                    <div
                        class="flex gap-2"
                    >
                        <Button
                            type="button"
                            label="Cancel"
                            icon="pi pi-times"
                            severity="secondary"
                            outlined
                            :disabled="
                                saving
                            "
                            @click="
                                announcementDialogVisible = false
                            "
                        />

                        <Button
                            type="button"
                            label="Save"
                            icon="pi pi-save"
                            severity="success"
                            :loading="
                                saving
                            "
                            @click="
                                saveAnnouncement
                            "
                        />
                    </div>
                </div>
            </template>
        </Dialog>

        <Dialog
            v-model:visible="
                deleteDialogVisible
            "
            modal
            header="Delete Announcement"
            class="w-[min(92vw,520px)]"
        >
            <Message
                severity="warn"
                :closable="false"
            >
                Delete
                <strong>
                    {{
                        selectedAnnouncement
                            ?.subject
                        || 'this announcement'
                    }}
                </strong>
                ?
            </Message>

            <template #footer>
                <Button
                    type="button"
                    label="Cancel"
                    severity="secondary"
                    outlined
                    :disabled="
                        deleting
                    "
                    @click="
                        deleteDialogVisible = false
                    "
                />

                <Button
                    type="button"
                    label="Delete"
                    icon="pi pi-trash"
                    severity="danger"
                    :loading="
                        deleting
                    "
                    @click="
                        deleteAnnouncement
                    "
                />
            </template>
        </Dialog>
    </div>
</template>
