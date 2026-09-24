<script setup lang="ts">
import axios from 'axios';
import Avatar from 'primevue/avatar';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import PrimeImage from 'primevue/image';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

type MessageListItem = {
    inbox_id: string;
    login_id?: string | null;
    sender_id?: string | null;
    recipient_id?: string | null;
    recipient_type?: string | null;
    recipient_account_type?: string | null;
    recipient_full_name: string | null;
    recipient_gender?: string | null;
    recipient_photo_url?: string | null;
    subj_inbox: string | null;
    date_inbox: string | null;
    date_read?: string | null;
    last_update?: string | null;
    content_inbox: string | null;
    has_unread?: boolean;
    is_deleted?: boolean;
};

type ConversationResponse = {
    success: boolean;
    message: string;
    data?: MessageListItem[];
};

const props = defineProps<{
    currentUserId: string;
    selectedInboxId?: string | null;
    refreshToken?: number;
    selectInboxId?: string | null;
}>();

const emit = defineEmits<{
    select: [conversation: MessageListItem];
    selectionResolved: [];
    deleted: [inboxId: string];
}>();

const API = '/api/v1/alerts/messages';
const POLL_INTERVAL_MS = 15000;

const conversations = ref<MessageListItem[]>([]);
const search = ref('');
const initialLoading = ref(true);
const backgroundRefreshing = ref(false);
const errorMessage = ref('');
const failedAvatarSources = ref<Set<string>>(new Set());
const confirmDeleteInboxId = ref<string | null>(null);
const deletingInboxId = ref<string | null>(null);

let pollTimer: number | null = null;
let requestRunning = false;

const filteredConversations = computed(() => {
    const keyword = search.value.trim().toLowerCase();
    if (!keyword) return conversations.value;

    return conversations.value.filter((conversation) =>
        [
            conversation.recipient_full_name,
            conversation.subj_inbox,
            conversation.content_inbox,
        ]
            .filter(Boolean)
            .some((value) => String(value).toLowerCase().includes(keyword)),
    );
});

function cleanLegacyText(value: string | null | undefined): string {
    return String(value ?? '')
        .replace(/<br\s*\/?>/gi, '\n')
        .replace(/&nbsp;/gi, ' ')
        .replace(/&amp;/gi, '&')
        .replace(/&lt;/gi, '<')
        .replace(/&gt;/gi, '>')
        .replace(/&quot;/gi, '"')
        .replace(/&#039;/gi, "'")
        .replace(/<[^>]*>/g, '')
        .trim();
}

function participantFallbackName(conversation: MessageListItem): string {
    const type = String(
        conversation.recipient_account_type || conversation.recipient_type || 'User',
    ).trim();
    const id = String(conversation.recipient_id ?? '').trim();
    return id ? `${type || 'User'} ${id.slice(0, 8)}` : type || 'User';
}

function displayName(conversation: MessageListItem): string {
    return conversation.recipient_full_name?.trim() || participantFallbackName(conversation);
}

function initials(value: string | null | undefined): string {
    const parts = String(value ?? '').trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return 'US';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return `${parts[0].charAt(0)}${parts[parts.length - 1].charAt(0)}`.toUpperCase();
}

function genderAvatar(gender: string | null | undefined): string | null {
    const value = String(gender ?? '').trim().toUpperCase();
    if (value === 'M' || value === 'MALE') return '/images/male-cadet.png';
    if (value === 'F' || value === 'FEMALE') return '/images/female-cadet.png';
    return null;
}

function avatarSource(conversation: MessageListItem): string | null {
    const photo = String(conversation.recipient_photo_url ?? '').trim();
    if (photo && !failedAvatarSources.value.has(photo)) return photo;

    const fallback = genderAvatar(conversation.recipient_gender);
    if (fallback && !failedAvatarSources.value.has(fallback)) return fallback;

    return null;
}

function markAvatarFailed(source: string | null): void {
    if (!source) return;
    const next = new Set(failedAvatarSources.value);
    next.add(source);
    failedAvatarSources.value = next;
}

function parseDate(value: string | null | undefined): Date | null {
    if (!value) return null;
    const normalized = value.length === 10 ? `${value}T00:00:00` : value.replace(' ', 'T');
    const date = new Date(normalized);
    return Number.isNaN(date.getTime()) ? null : date;
}

function formatListDate(value: string | null | undefined): string {
    const date = parseDate(value);
    if (!date) return value ?? '';

    const today = new Date();
    if (
        date.getFullYear() === today.getFullYear() &&
        date.getMonth() === today.getMonth() &&
        date.getDate() === today.getDate()
    ) {
        return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    }

    const yesterday = new Date(today);
    yesterday.setDate(today.getDate() - 1);
    if (
        date.getFullYear() === yesterday.getFullYear() &&
        date.getMonth() === yesterday.getMonth() &&
        date.getDate() === yesterday.getDate()
    ) {
        return 'Yesterday';
    }

    return date.toLocaleDateString(
        'en-US',
        date.getFullYear() === today.getFullYear()
            ? { month: 'short', day: 'numeric' }
            : { month: 'short', day: 'numeric', year: 'numeric' },
    );
}

function isUnread(conversation: MessageListItem): boolean {
    if (typeof conversation.has_unread === 'boolean') {
        return conversation.has_unread;
    }

    return (
        String(conversation.recipient_id ?? '') === props.currentUserId &&
        !String(conversation.date_read ?? '').trim()
    );
}

function conversationSignature(items: MessageListItem[]): string {
    return items
        .map((item) =>
            [
                item.inbox_id,
                item.last_update ?? '',
                item.date_read ?? '',
                String(item.has_unread ?? ''),
                String(item.is_deleted ?? ''),
                item.recipient_full_name ?? '',
                item.recipient_photo_url ?? '',
                item.recipient_gender ?? '',
                item.subj_inbox ?? '',
                item.content_inbox ?? '',
            ].join('|'),
        )
        .join('||');
}

function errorText(error: unknown): string {
    if (axios.isAxiosError(error)) {
        const message = error.response?.data?.message;
        if (typeof message === 'string' && message.trim() !== '') return message;
    }
    return error instanceof Error ? error.message : 'Unable to load messages.';
}

function askToDeleteConversation(conversation: MessageListItem): void {
    confirmDeleteInboxId.value = conversation.inbox_id;
}

function cancelDeleteConversation(): void {
    confirmDeleteInboxId.value = null;
}

async function deleteConversationHistory(conversation: MessageListItem): Promise<void> {
    if (!props.currentUserId || deletingInboxId.value) return;

    deletingInboxId.value = conversation.inbox_id;
    errorMessage.value = '';

    try {
        await axios.delete(
            `${API}/${encodeURIComponent(conversation.inbox_id)}/history`,
            {
                data: {
                    user_id: props.currentUserId,
                },
            },
        );

        conversations.value = conversations.value.filter(
            (item) => item.inbox_id !== conversation.inbox_id,
        );

        if (confirmDeleteInboxId.value === conversation.inbox_id) {
            confirmDeleteInboxId.value = null;
        }

        emit('deleted', conversation.inbox_id);
    } catch (error) {
        errorMessage.value = errorText(error);
    } finally {
        deletingInboxId.value = null;
    }
}

function resolveRequestedSelection(): void {
    const requestedInboxId = props.selectInboxId?.trim();
    if (!requestedInboxId) return;

    const conversation = conversations.value.find(
        (item) => item.inbox_id === requestedInboxId,
    );

    if (conversation) emit('select', conversation);
    emit('selectionResolved');
}

async function loadConversations(initial = false): Promise<void> {
    if (!props.currentUserId || requestRunning) return;

    requestRunning = true;
    if (initial) initialLoading.value = true;
    else backgroundRefreshing.value = true;

    try {
        const response = await axios.get<ConversationResponse>(API, {
            params: { user_id: props.currentUserId },
        });
        const next = Array.isArray(response.data.data) ? response.data.data : [];

        if (conversationSignature(conversations.value) !== conversationSignature(next)) {
            conversations.value = next;
        }

        errorMessage.value = '';

        if (
            props.selectedInboxId &&
            !conversations.value.some(
                (item) => item.inbox_id === props.selectedInboxId,
            )
        ) {
            emit('deleted', props.selectedInboxId);
        }

        resolveRequestedSelection();

        if (
            initial &&
            !props.selectedInboxId &&
            !props.selectInboxId &&
            conversations.value.length > 0 &&
            window.innerWidth >= 1024
        ) {
            emit('select', conversations.value[0]);
        }
    } catch (error) {
        if (initial || conversations.value.length === 0) {
            errorMessage.value = errorText(error);
        }
    } finally {
        requestRunning = false;
        initialLoading.value = false;
        backgroundRefreshing.value = false;
    }
}

function handleVisibilityChange(): void {
    if (document.visibilityState === 'visible') void loadConversations(false);
}

function startPolling(): void {
    if (pollTimer !== null) window.clearInterval(pollTimer);
    pollTimer = window.setInterval(() => {
        if (document.visibilityState === 'visible') void loadConversations(false);
    }, POLL_INTERVAL_MS);
}

watch(() => props.refreshToken, () => void loadConversations(false));
watch(() => props.selectInboxId, (value) => {
    if (value) void loadConversations(false);
});

onMounted(() => {
    void loadConversations(true);
    startPolling();
    document.addEventListener('visibilitychange', handleVisibilityChange);
});

onBeforeUnmount(() => {
    if (pollTimer !== null) window.clearInterval(pollTimer);
    document.removeEventListener('visibilitychange', handleVisibilityChange);
});
</script>

<template>
    <aside class="h-full min-h-0 overflow-hidden flex-col border-r border-slate-200 bg-white">
        <div class="shrink-0 border-b border-slate-200 bg-gradient-to-r from-slate-50 via-white to-blue-50/50 p-4">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl font-bold text-[#21365A]">Messages</h1>
                        <i
                            v-if="backgroundRefreshing"
                            class="pi pi-spin pi-spinner text-xs text-slate-400"
                        ></i>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ conversations.length }} conversation{{ conversations.length === 1 ? '' : 's' }}
                    </p>
                </div>
                <slot name="new-message" />
            </div>

            <div class="relative">
                <i
                    class="pi pi-search pointer-events-none absolute left-3 top-1/2 z-10 -translate-y-1/2 text-sm text-slate-400"
                ></i>
                <InputText
                    v-model="search"
                    class="w-full !pl-9"
                    placeholder="Search messages"
                />
            </div>
        </div>

        <Message v-if="errorMessage" severity="error" :closable="false" class="m-3 shrink-0">
            {{ errorMessage }}
        </Message>

        <div
            v-if="initialLoading"
            class="flex flex-1 items-center justify-center p-8 text-sm text-slate-500"
        >
            <i class="pi pi-spin pi-spinner mr-2"></i>
            Loading messages...
        </div>

        <div v-else class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
            <div
                v-for="conversation in filteredConversations"
                :key="conversation.inbox_id"
                :class="[
                    'group relative flex w-full items-stretch border-b border-slate-100 transition',
                    selectedInboxId === conversation.inbox_id
                        ? 'bg-[#377EC0]/5'
                        : 'bg-white hover:bg-slate-50',
                ]"
            >
                <Button
                    type="button"
                    unstyled
                    class="flex min-w-0 flex-1 items-center gap-3 px-4 py-3 text-left"
                    @click="emit('select', conversation)"
                >
                    <div class="relative h-12 w-12 shrink-0 overflow-hidden rounded-full">
                        <PrimeImage
                            v-if="avatarSource(conversation)"
                            :src="avatarSource(conversation) || ''"
                            :alt="displayName(conversation)"
                            class="block h-full w-full"
                            image-class="h-full w-full object-cover"
                            @error="markAvatarFailed(avatarSource(conversation))"
                        />
                        <Avatar
                            v-else
                            :label="initials(displayName(conversation))"
                            shape="circle"
                            class="!h-full !w-full !bg-[#377EC0]/10 !text-sm !font-bold !text-[#377EC0]"
                        />
                        <span
                            v-if="isUnread(conversation)"
                            class="absolute right-0 top-0 h-3 w-3 rounded-full border-2 border-white bg-[#377EC0]"
                        ></span>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span
                                :class="[
                                    'min-w-0 flex-1 truncate text-sm',
                                    isUnread(conversation)
                                        ? 'font-bold text-slate-950'
                                        : 'font-normal text-slate-800',
                                ]"
                            >
                                {{ displayName(conversation) }}
                            </span>
                            <span
                                :class="[
                                    'shrink-0 text-[11px] text-slate-400',
                                    isUnread(conversation) ? 'font-bold' : 'font-normal',
                                ]"
                            >
                                {{ formatListDate(conversation.last_update || conversation.date_inbox) }}
                            </span>
                        </div>

                        <div class="mt-1 flex items-center gap-2">
                            <span
                                :class="[
                                    'min-w-0 flex-1 truncate text-xs',
                                    isUnread(conversation)
                                        ? 'font-bold text-slate-700'
                                        : 'font-normal text-slate-500',
                                    conversation.is_deleted ? 'italic' : '',
                                ]"
                            >
                                {{
                                    cleanLegacyText(conversation.content_inbox) ||
                                    conversation.subj_inbox ||
                                    'Open conversation'
                                }}
                            </span>
                            <span
                                v-if="isUnread(conversation)"
                                class="h-2.5 w-2.5 shrink-0 rounded-full bg-[#377EC0]"
                            ></span>
                        </div>
                    </div>
                </Button>

                <div class="flex shrink-0 items-center pr-2">
                    <Button
                        type="button"
                        icon="pi pi-trash"
                        severity="danger"
                        text
                        rounded
                        size="small"
                        :disabled="Boolean(deletingInboxId)"
                        aria-label="Delete chat history"
                        title="Delete chat history"
                        @click.stop="askToDeleteConversation(conversation)"
                    />
                </div>

                <div
                    v-if="confirmDeleteInboxId === conversation.inbox_id"
                    class="absolute inset-0 z-20 flex items-center justify-end gap-2 bg-white/95 px-3 backdrop-blur-sm"
                >
                    <span class="mr-auto  text-xs font-semibold text-slate-700">
                        Delete this chat for both participants?
                    </span>
                    <Button
                        type="button"
                        label="Cancel"
                        severity="secondary"
                        size="small"
                        text
                        :disabled="deletingInboxId === conversation.inbox_id"
                        @click.stop="cancelDeleteConversation"
                    />
                    <Button
                        type="button"
                        label="Delete"
                        icon="pi pi-trash"
                        severity="danger"
                        size="small"
                        :loading="deletingInboxId === conversation.inbox_id"
                        :disabled="
                            Boolean(deletingInboxId) &&
                            deletingInboxId !== conversation.inbox_id
                        "
                        @click.stop="deleteConversationHistory(conversation)"
                    />
                </div>
            </div>

            <div
                v-if="filteredConversations.length === 0"
                class="flex flex-col items-center justify-center px-6 py-14 text-center"
            >
                <div class="mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-[#377EC0]/10">
                    <i class="pi pi-comments text-2xl text-[#377EC0]"></i>
                </div>
                <p class="font-semibold text-slate-800">No messages found</p>
                <p class="mt-1 text-xs text-slate-500">
                    Start a new conversation or try another search.
                </p>
            </div>
        </div>
    </aside>
</template>