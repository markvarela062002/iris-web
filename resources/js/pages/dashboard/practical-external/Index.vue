<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PrimeTag from 'primevue/tag';
import { computed, ref } from 'vue';

type ActivityUpdate = {
    id: number;
    studentName: string;
    schoolId: string;
    department: string;
    activity: string;
    submittedAt: string;
    status: 'Pending' | 'Verified' | 'Rejected';
};

defineOptions({
    inheritAttrs: false,

    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: '/dashboard',
            },
            {
                title: 'Activity Updates',
                href: '/dashboard/activity-updates',
            },
        ],
    },
});

const search = ref('');
const statusFilter = ref('All');

/*
|--------------------------------------------------------------------------
| Sample Activity Update Data
|--------------------------------------------------------------------------
|
| Replace this with data from your API once the backend endpoint is ready.
|
*/

const activities = ref<ActivityUpdate[]>([
    {
        id: 1,
        studentName: 'JUAN MIGUEL DELA CRUZ',
        schoolId: '2026-0001',
        department: 'Deck',
        activity: 'Navigation Watchkeeping',
        submittedAt: 'September 4, 2026 8:30 AM',
        status: 'Pending',
    },
    {
        id: 2,
        studentName: 'MARIA ANGELA SANTOS',
        schoolId: '2026-0002',
        department: 'Engine',
        activity: 'Engine Room Familiarization',
        submittedAt: 'September 4, 2026 9:15 AM',
        status: 'Verified',
    },
    {
        id: 3,
        studentName: 'CARLO REYES',
        schoolId: '2026-0003',
        department: 'Deck',
        activity: 'Mooring Operations',
        submittedAt: 'September 4, 2026 10:05 AM',
        status: 'Pending',
    },
    {
        id: 4,
        studentName: 'ANNA MAE GARCIA',
        schoolId: '2026-0004',
        department: 'Engine',
        activity: 'Safety Equipment Inspection',
        submittedAt: 'September 3, 2026 3:45 PM',
        status: 'Rejected',
    },
    {
        id: 5,
        studentName: 'MARK JOSEPH RAMOS',
        schoolId: '2026-0005',
        department: 'Deck',
        activity: 'Cargo Handling Procedures',
        submittedAt: 'September 3, 2026 1:20 PM',
        status: 'Verified',
    },
]);

const filteredActivities = computed(() => {
    const keyword = search.value.trim().toLowerCase();

    return activities.value.filter((activity) => {
        const matchesStatus =
            statusFilter.value === 'All' ||
            activity.status === statusFilter.value;

        const matchesSearch =
            keyword === '' ||
            activity.studentName.toLowerCase().includes(keyword) ||
            activity.schoolId.toLowerCase().includes(keyword) ||
            activity.department.toLowerCase().includes(keyword) ||
            activity.activity.toLowerCase().includes(keyword);

        return matchesStatus && matchesSearch;
    });
});

const pendingCount = computed(() => {
    return activities.value.filter((item) => item.status === 'Pending').length;
});

const verifiedCount = computed(() => {
    return activities.value.filter((item) => item.status === 'Verified').length;
});

const rejectedCount = computed(() => {
    return activities.value.filter((item) => item.status === 'Rejected').length;
});

function getStatusSeverity(
    status: ActivityUpdate['status'],
): 'warn' | 'success' | 'danger' {
    if (status === 'Verified') {
        return 'success';
    }

    if (status === 'Rejected') {
        return 'danger';
    }

    return 'warn';
}

function verifyActivity(activity: ActivityUpdate): void {
    activity.status = 'Verified';
}

function rejectActivity(activity: ActivityUpdate): void {
    activity.status = 'Rejected';
}
</script>

<template>
    <Head title="Activity Updates" />

    <div
        class="flex h-full min-h-0 flex-1 flex-col gap-5 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5"
    >
        <!-- PAGE HEADER -->

        <section
            class="rounded-2xl border border-[#377EC0]/15 bg-white p-5 shadow-sm"
        >
            <div
                class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex items-center gap-4">
                    <div
                        class="flex h-14 w-14 items-center justify-center rounded-xl bg-orange-500 text-white"
                    >
                        <i class="pi pi-bell text-2xl"></i>
                    </div>

                    <div>
                        <h1 class="text-2xl font-bold text-[#21365A]">
                            Activity Updates
                        </h1>

                        <p class="mt-1 text-sm text-slate-500">
                            Review and verify submitted student activities.
                        </p>
                    </div>
                </div>

                <Link
                    href="/dashboard"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-600 transition hover:border-[#377EC0] hover:text-[#377EC0]"
                >
                    <i class="pi pi-arrow-left"></i>
                    Back to Dashboard
                </Link>
            </div>
        </section>

        <!-- SUMMARY CARDS -->

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div
                class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
            >
                <p class="text-sm font-semibold text-slate-500">
                    Total Submissions
                </p>

                <p class="mt-2 text-3xl font-bold text-[#21365A]">
                    {{ activities.length }}
                </p>
            </div>

            <div
                class="rounded-2xl border border-orange-200 bg-orange-50 p-5"
            >
                <p class="text-sm font-semibold text-orange-600">Pending</p>

                <p class="mt-2 text-3xl font-bold text-orange-600">
                    {{ pendingCount }}
                </p>
            </div>

            <div
                class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5"
            >
                <p class="text-sm font-semibold text-emerald-600">Verified</p>

                <p class="mt-2 text-3xl font-bold text-emerald-600">
                    {{ verifiedCount }}
                </p>
            </div>

            <div class="rounded-2xl border border-red-200 bg-red-50 p-5">
                <p class="text-sm font-semibold text-red-600">Rejected</p>

                <p class="mt-2 text-3xl font-bold text-red-600">
                    {{ rejectedCount }}
                </p>
            </div>
        </div>

        <!-- ACTIVITY TABLE -->

        <section
            class="min-h-0 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
        >
            <div
                class="flex flex-col gap-4 border-b border-slate-200 p-5 lg:flex-row lg:items-center lg:justify-between"
            >
                <div>
                    <h2 class="text-lg font-bold text-[#21365A]">
                        Activity Verification
                    </h2>

                    <p class="mt-1 text-sm text-slate-500">
                        Showing {{ filteredActivities.length }} activity
                        submissions.
                    </p>
                </div>

                <div class="flex flex-col gap-2 sm:flex-row">
                    <div class="relative">
                        <i
                            class="pi pi-search absolute top-1/2 left-3 -translate-y-1/2 text-sm text-slate-400"
                        ></i>

                        <input
                            v-model="search"
                            type="text"
                            placeholder="Search activities..."
                            class="h-11 w-full rounded-xl border border-slate-300 bg-white pr-4 pl-10 text-sm text-slate-700 outline-none transition focus:border-[#377EC0] focus:ring-2 focus:ring-[#377EC0]/15 sm:w-72"
                        />
                    </div>

                    <select
                        v-model="statusFilter"
                        class="h-11 rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-600 outline-none transition focus:border-[#377EC0] focus:ring-2 focus:ring-[#377EC0]/15"
                    >
                        <option value="All">All statuses</option>
                        <option value="Pending">Pending</option>
                        <option value="Verified">Verified</option>
                        <option value="Rejected">Rejected</option>
                    </select>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[1050px] border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-left">
                            <th
                                class="px-5 py-4 text-xs font-bold tracking-wide text-slate-500 uppercase"
                            >
                                Student
                            </th>

                            <th
                                class="px-5 py-4 text-xs font-bold tracking-wide text-slate-500 uppercase"
                            >
                                Department
                            </th>

                            <th
                                class="px-5 py-4 text-xs font-bold tracking-wide text-slate-500 uppercase"
                            >
                                Activity
                            </th>

                            <th
                                class="px-5 py-4 text-xs font-bold tracking-wide text-slate-500 uppercase"
                            >
                                Submitted
                            </th>

                            <th
                                class="px-5 py-4 text-xs font-bold tracking-wide text-slate-500 uppercase"
                            >
                                Status
                            </th>

                            <th
                                class="px-5 py-4 text-center text-xs font-bold tracking-wide text-slate-500 uppercase"
                            >
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                            v-for="activity in filteredActivities"
                            :key="activity.id"
                            class="border-t border-slate-100 transition hover:bg-slate-50"
                        >
                            <td class="px-5 py-4">
                                <p class="font-semibold text-slate-700">
                                    {{ activity.studentName }}
                                </p>

                                <p class="mt-1 text-xs text-slate-400">
                                    {{ activity.schoolId }}
                                </p>
                            </td>

                            <td class="px-5 py-4 text-sm text-slate-600">
                                {{ activity.department }}
                            </td>

                            <td class="px-5 py-4">
                                <p class="font-medium text-slate-700">
                                    {{ activity.activity }}
                                </p>
                            </td>

                            <td class="px-5 py-4 text-sm text-slate-500">
                                {{ activity.submittedAt }}
                            </td>

                            <td class="px-5 py-4">
                                <PrimeTag
                                    :value="activity.status"
                                    :severity="
                                        getStatusSeverity(activity.status)
                                    "
                                />
                            </td>

                            <td class="px-5 py-4">
                                <div class="flex justify-center gap-2">
                                    <button
                                        type="button"
                                        class="inline-flex h-9 items-center justify-center gap-1.5 rounded-lg bg-emerald-500 px-3 text-xs font-semibold text-white transition hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-50"
                                        :disabled="
                                            activity.status === 'Verified'
                                        "
                                        @click="verifyActivity(activity)"
                                    >
                                        <i class="pi pi-check"></i>
                                        Verify
                                    </button>

                                    <button
                                        type="button"
                                        class="inline-flex h-9 items-center justify-center gap-1.5 rounded-lg bg-red-500 px-3 text-xs font-semibold text-white transition hover:bg-red-600 disabled:cursor-not-allowed disabled:opacity-50"
                                        :disabled="
                                            activity.status === 'Rejected'
                                        "
                                        @click="rejectActivity(activity)"
                                    >
                                        <i class="pi pi-times"></i>
                                        Reject
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <tr v-if="filteredActivities.length === 0">
                            <td colspan="6" class="px-5 py-16 text-center">
                                <i
                                    class="pi pi-inbox text-4xl text-slate-300"
                                ></i>

                                <p class="mt-3 font-semibold text-slate-600">
                                    No activity updates found
                                </p>

                                <p class="mt-1 text-sm text-slate-400">
                                    Try changing the search or status filter.
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>