<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Avatar from 'primevue/avatar';
import Dialog from 'primevue/dialog';
import Message from 'primevue/message';
import PrimeTag from 'primevue/tag';
import { computed, onMounted, ref } from 'vue';

import Calendar from '@/components/Calendar.vue';
import Datatable from '@/components/Datatable.vue';
import { dashboard } from '@/routes';

import type { CalendarEvent } from '@/types/calendar';
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
                title: 'Calendar',
                href: '/alerts/calendar/datatable',
            },
        ],
    },
});

type EventsResponse = {
    data: CalendarEvent[];
};

type DetailsResponse = {
    data: DataTableRow[];
};

const API_BASE = '/api/v1/alerts/calendar';

const month = ref(currentMonth());
const events = ref<CalendarEvent[]>([]);
const details = ref<DataTableRow[]>([]);

const loading = ref(false);
const detailsLoading = ref(false);

const errorMessage = ref('');
const detailsError = ref('');

const detailsDialogVisible = ref(false);
const selectedEvent = ref<CalendarEvent | null>(null);

const selectedType = computed(() => {
    return selectedEvent.value?.type ?? '';
});

const detailColumns = computed<DataTableColumn[]>(() => {
    if (selectedType.value === 'person_activity') {
        return [
            {
                field: 'fname',
                header: 'Student Information',
                class: 'w-[300px] min-w-[300px]',
            },
            {
                field: 'desc_activity',
                header: 'Activity',
                class:
                    'w-[300px] min-w-[300px] whitespace-normal',
            },
            {
                field: 'start_date',
                header: 'Activity Period',
                class: 'w-[250px] min-w-[250px]',
            },
            {
                field: 'sto_validated',
                header: 'Verified',
                class: 'w-[140px] min-w-[140px]',
            },
            {
                field: 'revise_remarks',
                header: 'Remarks',
                class: 'w-[240px] min-w-[240px]',
            },
        ];
    }

    if (selectedType.value === 'file_upload') {
        return [
            {
                field: 'fname',
                header: 'Student Information',
                class: 'w-[300px] min-w-[300px]',
            },
            {
                field: 'desc_requirement',
                header: 'Requirement Type',
                class:
                    'w-[320px] min-w-[320px] whitespace-normal',
            },
            {
                field: 'date_uploaded',
                header: 'Date Uploaded',
                class: 'w-[210px] min-w-[210px]',
            },
            {
                field: 'sto_validated',
                header: 'Verified',
                class: 'w-[140px] min-w-[140px]',
            },
            {
                field: 'revise_remarks',
                header: 'Remarks',
                class: 'w-[240px] min-w-[240px]',
            },
        ];
    }

    if (selectedType.value === 'person_journal') {
        return [
            {
                field: 'fname',
                header: 'Student Information',
                class: 'w-[300px] min-w-[300px]',
            },
            {
                field: 'date_journal',
                header: 'Journal Details',
                class: 'w-[260px] min-w-[260px]',
            },
            {
                field: 'port_depart',
                header: 'Voyage',
                class: 'w-[220px] min-w-[220px]',
            },
            {
                field: 'status',
                header: 'Status',
                class: 'w-[130px] min-w-[130px]',
            },
        ];
    }

    return [
        {
            field: 'fname',
            header: 'Student Information',
            class: 'w-[300px] min-w-[300px]',
        },
        {
            field: 'ref_no',
            header: 'Task',
            class:
                'w-[420px] min-w-[420px] whitespace-normal',
        },
        {
            field: 'month_no',
            header: 'Month Onboard',
            class: 'w-[160px] min-w-[160px]',
        },
        {
            field: 'completed',
            header: 'Date Completed',
            class: 'w-[190px] min-w-[190px]',
        },
    ];
});

const detailTableMinWidth = computed(() => {
    switch (selectedType.value) {
        case 'person_activity':
            return '1230px';

        case 'file_upload':
            return '1200px';

        case 'person_task':
            return '1100px';

        case 'person_journal':
            return '950px';

        default:
            return '900px';
    }
});

const detailsTitle = computed(() => {
    const event = selectedEvent.value;

    if (!event) {
        return 'Calendar Details';
    }

    return `${event.label} — ${formatDate(event.date)}`;
});

async function loadEvents(): Promise<void> {
    loading.value = true;
    errorMessage.value = '';

    try {
        const response = await axios.get<EventsResponse>(
            `${API_BASE}/events`,
            {
                params: {
                    month: month.value,
                },
                ...requestConfig(),
            },
        );

        events.value = response.data.data;
    } catch (error: unknown) {
        events.value = [];

        errorMessage.value = getErrorMessage(
            error,
            'Unable to load calendar events.',
        );
    } finally {
        loading.value = false;
    }
}

async function changeMonth(value: string): Promise<void> {
    month.value = value;

    await loadEvents();
}

async function openEvent(
    event: CalendarEvent,
): Promise<void> {
    selectedEvent.value = event;

    details.value = [];
    detailsError.value = '';

    detailsDialogVisible.value = true;
    detailsLoading.value = true;

    try {
        const response = await axios.get<DetailsResponse>(
            `${API_BASE}/events/${encodeURIComponent(
                event.type,
            )}/${encodeURIComponent(event.date)}`,
            requestConfig(),
        );

        details.value = response.data.data;
    } catch (error: unknown) {
        details.value = [];

        detailsError.value = getErrorMessage(
            error,
            'Unable to load calendar event details.',
        );
    } finally {
        detailsLoading.value = false;
    }
}

function requestConfig() {
    return {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        withCredentials: true,
    };
}

function currentMonth(): string {
    const date = new Date();

    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
    ].join('-');
}

function getStudentFullName(
    row: DataTableRow,
): string {
    const lastName = String(
        row.lname ?? '',
    ).trim();

    const otherNames = [
        row.fname,
        row.mname,
    ]
        .filter((name) => {
            return (
                typeof name === 'string'
                && name.trim() !== ''
            );
        })
        .map((name) => {
            return String(name).trim();
        })
        .join(' ');

    if (lastName && otherNames) {
        return `${lastName}, ${otherNames}`.toUpperCase();
    }

    return (
        lastName
        || otherNames
        || String(row.student ?? '')
    ).toUpperCase();
}

function getStudentInitials(
    row: DataTableRow,
): string {
    const firstName = String(
        row.fname ?? '',
    ).trim();

    const lastName = String(
        row.lname ?? '',
    ).trim();

    const initials =
        `${firstName.charAt(0)}${lastName.charAt(0)}`;

    return initials.toUpperCase() || 'ST';
}

function getStudentAvatar(
    gender: unknown,
): string | undefined {
    const value = String(gender ?? '')
        .trim()
        .toUpperCase();

    if (
        value === 'M'
        || value === 'MALE'
    ) {
        return '/images/male-cadet.png';
    }

    if (
        value === 'F'
        || value === 'FEMALE'
    ) {
        return '/images/female-cadet.png';
    }

    return undefined;
}

function getSchoolIdLabel(
    value: unknown,
): string {
    return (
        String(value ?? '').trim()
        || 'No School ID'
    );
}

function isVerified(
    row: DataTableRow,
): boolean {
    return (
        String(
            row.sto_validated ?? '',
        )
            .trim()
            .toUpperCase() === 'Y'
    );
}

function hasRevisionRemarks(
    row: DataTableRow,
): boolean {
    const remarks = String(
        row.revise_remarks ?? '',
    ).trim();

    return (
        remarks !== ''
        && remarks !== '-'
    );
}

function formatDate(
    value: unknown,
): string {
    if (
        !value
        || value === '1970-01-01'
        || value === '1970-01-01 00:00:00'
    ) {
        return '—';
    }

    const raw = String(value);
    const dateOnly = raw.substring(0, 10);

    const date = new Date(
        `${dateOnly}T00:00:00`,
    );

    if (Number.isNaN(date.getTime())) {
        return raw;
    }

    return new Intl.DateTimeFormat(
        'en-PH',
        {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        },
    ).format(date);
}

function formatTime(
    value: unknown,
): string {
    const raw = String(
        value ?? '',
    ).trim();

    if (!raw) {
        return '—';
    }

    const [hourValue, minuteValue] =
        raw.split(':');

    const hour = Number(hourValue);
    const minute = Number(minuteValue);

    if (
        !Number.isFinite(hour)
        || !Number.isFinite(minute)
    ) {
        return raw;
    }

    const date = new Date();

    date.setHours(
        hour,
        minute,
        0,
        0,
    );

    return new Intl.DateTimeFormat(
        'en-PH',
        {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
        },
    ).format(date);
}

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
    const raw = String(
        value ?? '',
    ).trim();

    if (!raw) {
        return '—';
    }

    const monthNumber =
        Number.parseInt(raw, 10);

    if (
        Number.isNaN(monthNumber)
    ) {
        return raw;
    }

    return `Month ${monthNumber}`;
}

function getErrorMessage(
    error: unknown,
    fallback: string,
): string {
    if (!axios.isAxiosError(error)) {
        return fallback;
    }

    return (
        (
            error.response?.data as
                | {
                      message?: string;
                  }
                | undefined
        )?.message
        || fallback
    );
}

onMounted(() => {
    void loadEvents();
});
</script>

<template>
    <Head title="Calendar" />

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

        <Calendar
            title="Calendar"
            description="Review activity updates, uploaded documents, completed OTG tasks, and daily journals by date."
            header-icon="pi pi-calendar"
            :month="month"
            :events="events"
            :loading="loading"
            @month-change="changeMonth"
            @event="openEvent"
        />

        <Dialog
            v-model:visible="detailsDialogVisible"
            modal
            :header="detailsTitle"
            class="w-[min(96vw,1200px)]"
            :draggable="false"
        >
            <Message
                v-if="detailsError"
                severity="error"
                :closable="false"
                class="mb-4"
            >
                {{ detailsError }}
            </Message>

            <Datatable
                title=""
                description=""
                :data="details"
                :columns="detailColumns"
                :loading="detailsLoading"
                :searchable="false"
                :paginator="false"
                :show-actions="false"
                :scrollable="true"
                scroll-height="420px"
                :table-min-width="detailTableMinWidth"
                data-key="id"
                empty-title="No records found"
                empty-description="No matching records were found for this calendar event."
                empty-icon="pi pi-calendar-times"
            >
                <template #cell-fname="{ data }">
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
                                        getSchoolIdLabel(
                                            data.school_id_no,
                                        )
                                    "
                                    icon="pi pi-id-card"
                                    severity="info"
                                    class="!px-2 !py-0.5 !text-xs !font-semibold"
                                />
                            </div>
                        </div>
                    </div>
                </template>

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

                <template
                    #cell-desc_requirement="{ value }"
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

                <template
                    #cell-date_uploaded="{ data }"
                >
                    <div
                        class="flex items-center gap-2"
                    >
                        <i
                            class="pi pi-clock text-lg text-yellow-500"
                        ></i>

                        <span
                            class="text-sm font-medium whitespace-nowrap text-slate-600"
                        >
                            {{
                                formatDate(
                                    data.date_uploaded,
                                )
                            }}

                            <template
                                v-if="
                                    data.time_uploaded
                                "
                            >
                                ·
                                {{
                                    formatTime(
                                        data.time_uploaded,
                                    )
                                }}
                            </template>
                        </span>
                    </div>
                </template>

                <template
                    #cell-sto_validated="{ data }"
                >
                    <PrimeTag
                        v-if="isVerified(data)"
                        value="Verified"
                        severity="success"
                        icon="pi pi-check-circle"
                    />

                    <PrimeTag
                        v-else-if="
                            hasRevisionRemarks(data)
                        "
                        value="Revise"
                        severity="danger"
                        icon="pi pi-undo"
                    />

                    <PrimeTag
                        v-else
                        value="Pending"
                        severity="warn"
                        icon="pi pi-clock"
                    />
                </template>

                <template
                    #cell-revise_remarks="{ value }"
                >
                    <span
                        v-if="
                            value
                            && String(
                                value,
                            ).trim() !== '-'
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
                                    data.ref_no
                                    || 'No reference'
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

                <template
                    #cell-month_no="{ value }"
                >
                    <PrimeTag
                        :value="
                            getMonthLabel(value)
                        "
                        severity="info"
                        icon="pi pi-calendar"
                    />
                </template>

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
                                formatDate(
                                    value,
                                )
                            }}
                        </span>
                    </div>
                </template>

                <template
                    #cell-date_journal="{ data }"
                >
                    <div class="space-y-2">
                        <div
                            class="flex items-center gap-2"
                        >
                            <i
                                class="pi pi-calendar text-sm font-bold text-blue-500"
                            ></i>

                            <span
                                class="font-semibold text-slate-700"
                            >
                                {{
                                    formatDate(
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
                            ></i>

                            <span
                                class="text-sm text-slate-500"
                            >
                                {{
                                    formatTime(
                                        data.journal_time,
                                    )
                                }}
                                –
                                {{
                                    formatTime(
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
                            ></i>

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

                <template
                    #cell-port_depart="{ data }"
                >
                    <div class="space-y-2">
                        <div
                            class="flex items-start gap-2"
                        >
                            <PrimeTag
                                value="From"
                                severity="success"
                                class="!px-2 !py-0.5 !font-semibold"
                            />

                            <span
                                class="pt-0.5 text-sm font-medium text-slate-700"
                            >
                                {{
                                    data.port_depart
                                    || '—'
                                }}
                            </span>
                        </div>

                        <div
                            class="flex items-start gap-2"
                        >
                            <PrimeTag
                                value="To"
                                severity="info"
                                class="!px-2 !py-0.5 !font-semibold"
                            />

                            <span
                                class="pt-0.5 text-sm font-medium text-slate-700"
                            >
                                {{
                                    data.port_dest
                                    || '—'
                                }}
                            </span>
                        </div>
                    </div>
                </template>

                <template
                    #cell-status="{ data }"
                >
                    <PrimeTag
                        :value="
                            String(
                                data.status
                                || 'Pending',
                            )
                        "
                        :severity="
                            data.status ===
                            'Signed'
                                ? 'success'
                                : 'warn'
                        "
                        :icon="
                            data.status ===
                            'Signed'
                                ? 'pi pi-check-circle'
                                : 'pi pi-clock'
                        "
                    />
                </template>
            </Datatable>
        </Dialog>
    </div>
</template>