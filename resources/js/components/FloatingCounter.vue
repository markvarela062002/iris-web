<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, onUnmounted, ref } from 'vue';

import type { SharedData } from '@/types';

type AnnouncementResponse = {
    meta: {
        total: number;
    };
};

type MessageResponse = {
    success: boolean;
    data: unknown[];
};

const page = usePage<SharedData>();

const announcementTotal = ref<number | null>(null);
const messageTotal = ref<number | null>(null);

const isStudent = computed(
    () => page.props.auth.user?.account_type === 'student',
);

const announcementPageUrl = computed(() =>
    isStudent.value
        ? '/alerts/announcements/student/datatable'
        : '/alerts/announcements/datatable',
);

const announcementApiUrl = computed(() =>
    isStudent.value
        ? '/api/v1/student/alerts/datatable/announcements'
        : '/api/v1/alerts/datatable/announcements',
);

const messagePageUrl = computed(() =>
    isStudent.value
        ? '/alerts/messages/student'
        : '/alerts/messages',
);

const messageApiUrl = computed(() =>
    isStudent.value
        ? '/api/v1/student/alerts/messages'
        : '/api/v1/alerts/messages',
);

let refreshTimer: ReturnType<typeof setInterval> | undefined;

async function refreshCounts(): Promise<void> {
    const userId = page.props.auth.user?.id;

    const [announcementResult, messageResult] = await Promise.allSettled([
        axios.get<AnnouncementResponse>(announcementApiUrl.value, {
            params: {
                page: 1,
                per_page: 1,
            },
        }),

        userId
            ? axios.get<MessageResponse>(messageApiUrl.value, {
                  params: {
                      user_id: userId,
                  },
              })
            : Promise.reject(new Error('No signed-in user')),
    ]);

    announcementTotal.value =
        announcementResult.status === 'fulfilled'
            ? announcementResult.value.data.meta.total
            : null;

    messageTotal.value =
        messageResult.status === 'fulfilled' &&
        messageResult.value.data.success &&
        Array.isArray(messageResult.value.data.data)
            ? messageResult.value.data.data.length
            : null;
}

onMounted(() => {
    void refreshCounts();

    refreshTimer = setInterval(() => {
        void refreshCounts();
    }, 60_000);
});

onUnmounted(() => {
    if (refreshTimer) {
        clearInterval(refreshTimer);
    }
});
</script>
<template>
    <div class="fixed right-6 bottom-6 z-50 flex flex-row items-center gap-3">
        <a
            v-if="announcementTotal !== null"
            :href="announcementPageUrl"
            :aria-label="`${announcementTotal} announcements. View announcements.`"
            title="Open announcements"
            class="group relative flex size-12 cursor-pointer items-center justify-center rounded-full border-2 border-white bg-sky-500 text-white shadow-lg shadow-sky-700/30 ring-2 ring-sky-300 transition-all duration-200 hover:scale-110 hover:bg-sky-600 active:scale-95 focus-visible:outline-4 focus-visible:outline-offset-4 focus-visible:outline-sky-500"
        >
            <i
                class="pi pi-megaphone !text-[1.5rem] !leading-none"
                aria-hidden="true"
            ></i>

            <span
                class="absolute -top-2 -right-2 flex min-w-6 items-center justify-center rounded-full border-2 border-white bg-red-500 px-1 py-0.5 text-[10px] font-bold text-white shadow-md"
            >
                {{ announcementTotal }}
            </span>
        </a>

        <a
            :href="messagePageUrl"
            :aria-label="
                messageTotal === null
                    ? 'View messages'
                    : `${messageTotal} messages. View messages.`
            "
            title="Open messages"
            class="group relative flex size-12 cursor-pointer items-center justify-center rounded-full border-2 border-white bg-sky-500 text-white shadow-lg shadow-sky-700/30 ring-2 ring-sky-300 transition-all duration-200 hover:scale-110 hover:bg-sky-600 active:scale-95 focus-visible:outline-4 focus-visible:outline-offset-4 focus-visible:outline-sky-500"
        >
            <i
                class="pi pi-comment !text-[1.5rem] !leading-none"
                aria-hidden="true"
            ></i>

            <span
                v-if="messageTotal !== null"
                class="absolute -top-2 -right-2 flex min-w-6 items-center justify-center rounded-full border-2 border-white bg-red-500 px-1 py-0.5 text-[10px] font-bold text-white shadow-md"
            >
                {{ messageTotal }}
            </span>
        </a>
    </div>
</template>