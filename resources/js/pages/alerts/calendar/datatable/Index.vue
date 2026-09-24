<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Dialog from 'primevue/dialog';
import Message from 'primevue/message';
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
                href: '/alerts/calendar/datatable/index',
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

const detailColumns = computed<DataTableColumn[]>(() => {
    if (selectedEvent.value?.type === 'person_activity') {
        return [
            { field: 'student', header: 'Student' },
            { field: 'activity', header: 'Activity' },
            { field: 'status', header: 'Status' },
        ];
    }

    return [
        { field: 'student', header: 'Student' },
        { field: 'task', header: 'Task' },
        { field: 'status', header: 'Status' },
    ];
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

async function openEvent(event: CalendarEvent): Promise<void> {
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

function formatDate(value: string): string {
    const [year, monthValue, day] = value.split('-').map(Number);

    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(new Date(year, monthValue - 1, day));
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
                | { message?: string }
                | undefined
        )?.message
        || fallback
    );
}

onMounted(() => void loadEvents());
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
            title="Alerts Calendar"
            description="Review activity updates and completed OTG tasks by date."
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
            class="w-[min(96vw,1000px)]"
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
                table-min-width="720px"
                empty-title="No records found"
                empty-description="No matching records were found for this calendar event."
                empty-icon="pi pi-calendar-times"
            >
                <template #cell-status="{ value }">
                    <span
                        :class="[
                            'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold',
                            value === 'Revise'
                                ? 'bg-red-100 text-red-700'
                                : value === 'Validated'
                                    || value === 'Completed'
                                    || value === 'Verified'
                                    || value === 'Signed'
                                    ? 'bg-emerald-100 text-emerald-700'
                                    : 'bg-amber-100 text-amber-700',
                        ]"
                    >
                        {{ value }}
                    </span>
                </template>
            </Datatable>
        </Dialog>
    </div>
</template>
