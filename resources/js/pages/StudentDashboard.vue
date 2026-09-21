<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Tag from 'primevue/tag';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
} from 'vue';

import type { SharedData } from '@/types';

defineOptions({
    inheritAttrs: false,

    layout: {
        breadcrumbs: [
            {
                title: 'Student Dashboard',
                href: '/student-dashboard',
            },
        ],
    },
});

type StudentAccount = SharedData['auth']['user'] & {
    id?: string;
    code_person?: string | null;
    school_id_no?: string | null;
    login_name?: string | null;
    fname?: string | null;
    mname?: string | null;
    lname?: string | null;
    full_name?: string | null;
    gender?: string | null;
    dept?: string | null;
    batch_no?: string | null;
    avatar?: string | null;
    profile_image?: string | null;
    account_type?: string | null;
    role?: string | null;
};

type StudentActivity = {
    id: string;
    activity_id: string | null;
    description: string;
    filename: string | null;
    start_date: string | null;
    end_date: string | null;
    last_update: string | null;
    sto_validated: string | null;
    for_app: string | null;
    revise_remarks: string | null;
    status: string;
};

type StudentActivityResponse = {
    total: number;
    data: StudentActivity[];
};

type StatusSeverity =
    | 'success'
    | 'warn'
    | 'danger'
    | 'secondary'
    | 'info';

const page = usePage<SharedData>();

const student = computed(() => {
    return page.props.auth.user as StudentAccount;
});

const school = computed(() => {
    return page.props.school;
});

const activities = ref<StudentActivity[]>([]);
const activitiesTotal = ref<number | null>(null);
const activitiesLoading = ref(true);
const activitiesError = ref('');

let activitiesController: AbortController | null = null;

const displayName = computed(() => {
    if (student.value.full_name) {
        return student.value.full_name;
    }

    const fullName = [
        student.value.fname,
        student.value.mname,
        student.value.lname,
    ]
        .filter(
            (name): name is string =>
                typeof name === 'string' &&
                name.trim() !== '',
        )
        .map((name) => name.trim())
        .join(' ');

    return (
        fullName ||
        student.value.login_name ||
        'Student'
    );
});

const profileImage = computed(() => {
    if (student.value.avatar) {
        return student.value.avatar;
    }

    if (student.value.profile_image) {
        return student.value.profile_image;
    }

    const gender = String(
        student.value.gender ?? '',
    )
        .trim()
        .toUpperCase();

    if (gender === 'F') {
        return '/images/female.png';
    }

    if (gender === 'M') {
        return '/images/male.png';
    }

    return '/images/default-user.png';
});

const studentInformation = computed(() => [
    {
        label: 'Student ID',
        value:
            student.value.school_id_no ||
            'Not assigned',
        icon: 'pi pi-id-card',
    },
    {
        label: 'Student Code',
        value:
            student.value.code_person ||
            'Not assigned',
        icon: 'pi pi-hashtag',
    },
    {
        label: 'Department',
        value:
            student.value.dept ||
            'Not assigned',
        icon: 'pi pi-building',
    },
    {
        label: 'Batch Number',
        value:
            student.value.batch_no ||
            'Not assigned',
        icon: 'pi pi-users',
    },
]);

async function loadActivities(): Promise<void> {
    activitiesController?.abort();

    const controller = new AbortController();

    activitiesController = controller;
    activitiesLoading.value = true;
    activitiesError.value = '';

    try {
        const response =
            await axios.get<StudentActivityResponse>(
                '/api/v1/student-dashboard/activities',
                {
                    signal: controller.signal,

                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With':
                            'XMLHttpRequest',
                    },

                    withCredentials: true,
                },
            );

        if (activitiesController !== controller) {
            return;
        }

        activitiesTotal.value =
            Number(response.data.total) || 0;

        activities.value = Array.isArray(
            response.data.data,
        )
            ? response.data.data
            : [];
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

        if (activitiesController !== controller) {
            return;
        }

        activities.value = [];
        activitiesTotal.value = null;

        activitiesError.value =
            axios.isAxiosError(error)
                ? String(
                      error.response?.data?.message ??
                          'Unable to load activities.',
                  )
                : 'Unable to load activities.';

        console.error(
            'Unable to load student activities:',
            error,
        );
    } finally {
        if (activitiesController === controller) {
            activitiesLoading.value = false;
        }
    }
}

function formatDate(
    value: string | null,
): string {
    if (! value) {
        return '—';
    }

    const normalized = value.includes('T')
        ? value
        : value.replace(' ', 'T');

    const date = new Date(normalized);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('en-PH', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(date);
}

function formatDateTime(
    value: string | null,
): string {
    if (! value) {
        return '—';
    }

    const normalized = value.includes('T')
        ? value
        : value.replace(' ', 'T');

    const date = new Date(normalized);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    }).format(date);
}

function formatActivityPeriod(
    activity: StudentActivity,
): string {
    const start = formatDate(
        activity.start_date,
    );

    const end = formatDate(
        activity.end_date,
    );

    if (start === '—' && end === '—') {
        return '—';
    }

    if (start === end) {
        return start;
    }

    return `${start} – ${end}`;
}

function getStatusSeverity(
    status: string,
): StatusSeverity {
    switch (status) {
        case 'Verified':
            return 'success';

        case 'For Verification':
            return 'warn';

        case 'For Revision':
            return 'danger';

        case 'Draft':
            return 'secondary';

        default:
            return 'info';
    }
}

onMounted(() => {
    void loadActivities();
});

onBeforeUnmount(() => {
    activitiesController?.abort();
});
</script>

<template>
    <Head title="Student Dashboard" />

    <div
        class="flex h-full min-h-0 flex-1 flex-col gap-5 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5"
    >
        <!-- Student Hero -->
        <section
            class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[#377EC0] via-[#123A63] to-[#07182D] shadow-xl shadow-[#123A63]/15"
        >
            <div
                class="pointer-events-none absolute -top-28 -right-20 size-72 rounded-full bg-cyan-300/10 blur-3xl"
            ></div>

            <div
                class="pointer-events-none absolute -bottom-36 -left-24 size-80 rounded-full bg-blue-900/40 blur-3xl"
            ></div>

            <div
                class="pointer-events-none absolute inset-0 bg-gradient-to-r from-transparent via-white/[0.03] to-white/[0.08]"
            ></div>

            <div
                class="relative z-10 flex flex-col gap-6 px-6 py-7 md:flex-row md:items-center md:justify-between lg:px-9 lg:py-8"
            >
                <div
                    class="flex min-w-0 items-center gap-5"
                >
                    <div
                        class="flex size-24 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-white/20 bg-white p-1 shadow-xl"
                    >
                        <img
                            :src="profileImage"
                            :alt="displayName"
                            class="h-full w-full rounded-xl object-cover"
                        />
                    </div>

                    <div class="min-w-0">
                        <div
                            class="mb-3 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1 text-[11px] font-bold tracking-[0.14em] text-blue-100 uppercase backdrop-blur-sm"
                        >
                            <span
                                class="size-1.5 rounded-full bg-emerald-400"
                            ></span>

                            {{ student.role || 'Student' }}
                        </div>

                        <p
                            class="text-sm font-medium text-blue-100/80"
                        >
                            Welcome BAI,
                        </p>

                        <h1
                            class="mt-1 text-2xl leading-tight font-bold tracking-tight text-white sm:text-3xl"
                        >
                            {{ displayName }}
                        </h1>

                        <p
                            class="mt-2 text-sm text-blue-100/70"
                        >
                            IRIS — Student Activity Monitoring
                            System
                        </p>
                    </div>
                </div>

                <div
                    class="flex shrink-0 items-center gap-3 rounded-2xl border border-white/15 bg-white/10 px-4 py-3 backdrop-blur-md"
                >
                    <div
                        class="flex size-12 items-center justify-center overflow-hidden rounded-xl bg-white p-1.5"
                    >
                        <img
                            :src="school.logo"
                            :alt="school.name"
                            class="h-full w-full object-contain"
                        />
                    </div>

                    <div class="min-w-0">
                        <p
                            class="text-[10px] font-bold tracking-wider text-blue-100/65 uppercase"
                        >
                            School
                        </p>

                        <p
                            class="max-w-64 text-sm font-semibold text-white"
                        >
                            {{ school.name }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Student Details -->
        <section
            class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm"
        >
            <div class="mb-5">
                <p
                    class="text-xs font-bold tracking-[0.14em] text-[#377EC0] uppercase"
                >
                    Student Profile
                </p>

                <h2
                    class="mt-1 text-xl font-bold text-[#21365A]"
                >
                    Account Information
                </h2>

                <p class="mt-1 text-sm text-slate-500">
                    Your current student and enrollment
                    information.
                </p>
            </div>

            <div
                class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
            >
                <div
                    v-for="item in studentInformation"
                    :key="item.label"
                    class="rounded-2xl border border-slate-200 bg-slate-50 p-4"
                >
                    <div
                        class="flex size-10 items-center justify-center rounded-xl bg-[#21365A] text-white"
                    >
                        <i :class="item.icon"></i>
                    </div>

                    <p
                        class="mt-4 text-xs font-semibold tracking-wide text-slate-500 uppercase"
                    >
                        {{ item.label }}
                    </p>

                    <p
                        class="mt-1 break-words text-base font-bold text-[#21365A]"
                    >
                        {{ item.value }}
                    </p>
                </div>
            </div>
        </section>

        <!-- Activity Summary -->
        <section
            class="grid gap-5 xl:grid-cols-[280px_minmax(0,1fr)]"
        >
            <!-- Total Activities Card -->
            <div
                class="relative min-h-56 overflow-hidden rounded-3xl bg-gradient-to-br from-orange-400 to-orange-600 p-6 text-white shadow-lg"
            >
                <div
                    class="pointer-events-none absolute -top-12 -right-12 size-36 rounded-full bg-white/10"
                ></div>

                <div
                    class="pointer-events-none absolute -right-8 -bottom-14 size-28 rounded-full border-[16px] border-white/10"
                ></div>

                <div
                    class="relative z-10 flex h-full flex-col"
                >
                    <div
                        class="flex items-start justify-between"
                    >
                        <div
                            class="flex size-12 items-center justify-center rounded-2xl border border-white/20 bg-white/15"
                        >
                            <i
                                class="pi pi-bolt text-xl"
                            ></i>
                        </div>

                        <span
                            class="rounded-full border border-white/20 bg-white/10 px-2.5 py-1 text-[10px] font-bold tracking-wider uppercase"
                        >
                            Activities
                        </span>
                    </div>

                    <div
                        class="mt-6 flex min-h-12 items-center"
                    >
                        <div
                            v-if="activitiesLoading"
                            class="flex items-center gap-3"
                            role="status"
                        >
                            <i
                                class="pi pi-spin pi-spinner text-3xl"
                            ></i>

                            <span class="text-sm font-semibold">
                                Loading…
                            </span>
                        </div>

                        <p
                            v-else-if="activitiesError"
                            class="text-sm font-semibold"
                        >
                            Total unavailable
                        </p>

                        <p
                            v-else
                            class="text-5xl font-bold"
                        >
                            {{ activitiesTotal }}
                        </p>
                    </div>

                    <div class="mt-auto pt-5">
                        <p
                            class="text-sm font-semibold"
                        >
                            Total Activities
                        </p>

                        <p
                            class="mt-1 text-xs text-white/75"
                        >
                            All activities submitted under
                            your account.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Recent Activities -->
            <div
                class="min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm"
            >
                <div
                    class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div>
                        <p
                            class="text-xs font-bold tracking-[0.14em] text-[#377EC0] uppercase"
                        >
                            Activity History
                        </p>

                        <h2
                            class="mt-1 text-xl font-bold text-[#21365A]"
                        >
                            Recent Activities
                        </h2>

                        <p
                            class="mt-1 text-sm text-slate-500"
                        >
                            Your 10 most recently updated
                            activities.
                        </p>
                    </div>

                    <button
                        type="button"
                        class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-[#21365A] transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="activitiesLoading"
                        @click="loadActivities"
                    >
                        <i
                            :class="[
                                'pi pi-refresh',
                                {
                                    'pi-spin':
                                        activitiesLoading,
                                },
                            ]"
                        ></i>

                        Refresh
                    </button>
                </div>

                <div
                    v-if="activitiesError"
                    class="m-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700"
                >
                    {{ activitiesError }}
                </div>

                <DataTable
                    :value="activities"
                    :loading="activitiesLoading"
                    data-key="id"
                    striped-rows
                    scrollable
                    responsive-layout="scroll"
                    class="student-activities-table"
                >
                    <template #empty>
                        <div
                            class="flex min-h-40 flex-col items-center justify-center py-8 text-center"
                        >
                            <i
                                class="pi pi-inbox text-3xl text-slate-300"
                            ></i>

                            <p
                                class="mt-3 text-sm font-semibold text-slate-600"
                            >
                                No activities found
                            </p>

                            <p
                                class="mt-1 text-xs text-slate-400"
                            >
                                Your submitted activities
                                will appear here.
                            </p>
                        </div>
                    </template>

                    <Column
                        field="description"
                        header="Activity"
                        class="min-w-64"
                    >
                        <template #body="{ data }">
                            <div>
                                <p
                                    class="font-semibold text-[#21365A]"
                                >
                                    {{ data.description }}
                                </p>

                                <p
                                    v-if="data.revise_remarks"
                                    class="mt-1 text-xs text-red-500"
                                >
                                    {{ data.revise_remarks }}
                                </p>
                            </div>
                        </template>
                    </Column>

                    <Column
                        header="Activity Period"
                        class="min-w-52"
                    >
                        <template #body="{ data }">
                            <span class="text-sm text-slate-600">
                                {{ formatActivityPeriod(data) }}
                            </span>
                        </template>
                    </Column>

                    <Column
                        field="last_update"
                        header="Last Updated"
                        class="min-w-48"
                    >
                        <template #body="{ data }">
                            <span class="text-sm text-slate-600">
                                {{
                                    formatDateTime(
                                        data.last_update,
                                    )
                                }}
                            </span>
                        </template>
                    </Column>

                    <Column
                        field="status"
                        header="Status"
                        class="min-w-40"
                    >
                        <template #body="{ data }">
                            <Tag
                                :value="data.status"
                                :severity="
                                    getStatusSeverity(
                                        data.status,
                                    )
                                "
                                rounded
                            />
                        </template>
                    </Column>
                </DataTable>
            </div>
        </section>
    </div>
</template>