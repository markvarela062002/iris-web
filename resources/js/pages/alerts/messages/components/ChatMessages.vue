<script setup lang="ts">
import axios from 'axios';
import Avatar from 'primevue/avatar';
import Button from 'primevue/button';
import FileUpload from 'primevue/fileupload';
import Message from 'primevue/message';
import PrimeImage from 'primevue/image';
import Textarea from 'primevue/textarea';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

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
};

type MessageAttachment = {
    id: string;
    inbox_id?: string | null;
    inbox_reply_id?: string | null;
    filename: string | null;
    order_no: string | null;
    file_desc: string | null;
    url: string | null;
};

type InboxDetails = {
    inbox_id: string | null;
    login_id: string | null;
    sender_id: string | null;
    recipient_id: string | null;
    recipient_full_name?: string | null;
    subject: string | null;
    content: string | null;
    date: string | null;
    date_read: string | null;
    is_deleted?: boolean;
};

type ReplyItem = {
    reply_id: string;
    inbox_id: string;
    reply_msg: string | null;
    reply_date: string | null;
    date_read: string | null;
    sender_id: string | null;
    recipient_id: string | null;
    sender_full_name?: string | null;
    recipient_full_name?: string | null;
    attachments?: MessageAttachment[];
    is_deleted?: boolean;
};

type ThreadResponse = {
    success: boolean;
    message: string;
    data?: {
        inbox?: InboxDetails;
        attachments?: MessageAttachment[];
        replies?: ReplyItem[];
        moderation?: {
            available?: boolean;
            is_blocked?: boolean;
            other_user_id?: string | null;
        };
    };
};

type ThreadItem = {
    id: string;
    kind: 'original' | 'reply';
    content: string;
    date: string | null;
    sender_id: string | null;
    attachments: MessageAttachment[];
    is_deleted: boolean;
};

type SelectedAttachment = {
    key: string;
    file: File;
    previewUrl: string | null;
    isImage: boolean;
};

type FileUploadSelectEvent = { files: File[] };
type FileUploadControl = { clear: () => void };

const props = defineProps<{
    currentUserId: string;
    conversation: MessageListItem;
}>();

const emit = defineEmits<{
    back: [];
    conversationUpdated: [];
}>();

const API = '/api/v1/alerts/messages';
const POLL_INTERVAL_MS = 5000;
const MAX_ATTACHMENT_SIZE = 20 * 1024 * 1024;
const ACCEPTED_ATTACHMENT_STRING = '.jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv';
const ACCEPTED_EXTENSIONS = new Set([
    'jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx',
    'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv',
]);

const inbox = ref<InboxDetails | null>(null);
const threadAttachments = ref<MessageAttachment[]>([]);
const replies = ref<ReplyItem[]>([]);
const initialLoading = ref(true);
const backgroundRefreshing = ref(false);
const sending = ref(false);
const errorMessage = ref('');
const isBlocked = ref(false);
const replyMessage = ref('');
const replyFiles = ref<SelectedAttachment[]>([]);
const replyFileUpload = ref<FileUploadControl | null>(null);
const messageScroller = ref<HTMLElement | null>(null);
const failedAvatarSources = ref<Set<string>>(new Set());
const confirmDeleteMessageId = ref<string | null>(null);
const deletingMessageId = ref<string | null>(null);

let pollTimer: number | null = null;
let requestRunning = false;
let loadedInboxId = '';

const participantName = computed(() =>
    props.conversation.recipient_full_name?.trim() ||
    participantFallbackName(props.conversation),
);

const subject = computed(() =>
    inbox.value?.subject?.trim() || props.conversation.subj_inbox?.trim() || 'No subject',
);

const threadItems = computed<ThreadItem[]>(() => {
    const items: ThreadItem[] = [];

    if (inbox.value) {
        items.push({
            id: inbox.value.inbox_id || `original-${props.conversation.inbox_id}`,
            kind: 'original',
            content: inbox.value.is_deleted
                ? 'This message was deleted'
                : cleanLegacyText(inbox.value.content) || 'Attachment',
            date: inbox.value.date,
            sender_id: inbox.value.sender_id,
            attachments: inbox.value.is_deleted ? [] : threadAttachments.value,
            is_deleted: Boolean(inbox.value.is_deleted),
        });
    }

    for (const reply of replies.value) {
        items.push({
            id: reply.reply_id,
            kind: 'reply',
            content: reply.is_deleted
                ? 'This message was deleted'
                : cleanLegacyText(reply.reply_msg) || 'Attachment',
            date: reply.reply_date,
            sender_id: reply.sender_id,
            attachments: reply.is_deleted
                ? []
                : Array.isArray(reply.attachments)
                    ? reply.attachments
                    : [],
            is_deleted: Boolean(reply.is_deleted),
        });
    }

    return items.sort((a, b) => timestampValue(a.date) - timestampValue(b.date));
});

function participantFallbackName(conversation: MessageListItem): string {
    const type = String(
        conversation.recipient_account_type || conversation.recipient_type || 'User',
    ).trim();
    const id = String(conversation.recipient_id ?? '').trim();
    return id ? `${type || 'User'} ${id.slice(0, 8)}` : type || 'User';
}

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

function avatarSource(): string | null {
    const photo = String(props.conversation.recipient_photo_url ?? '').trim();
    if (photo && !failedAvatarSources.value.has(photo)) return photo;

    const fallback = genderAvatar(props.conversation.recipient_gender);
    if (fallback && !failedAvatarSources.value.has(fallback)) return fallback;

    return null;
}

function markAvatarFailed(source: string | null): void {
    if (!source) return;
    const next = new Set(failedAvatarSources.value);
    next.add(source);
    failedAvatarSources.value = next;
}

function timestampValue(value: string | null): number {
    if (!value) return 0;
    const normalized = value.length === 10 ? `${value}T00:00:00` : value.replace(' ', 'T');
    const parsed = new Date(normalized);
    return Number.isNaN(parsed.getTime()) ? 0 : parsed.getTime();
}

function parseDate(value: string | null): Date | null {
    if (!value) return null;
    const normalized = value.length === 10 ? `${value}T00:00:00` : value.replace(' ', 'T');
    const parsed = new Date(normalized);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
}

function formatTime(value: string | null): string {
    const date = parseDate(value);
    return date
        ? date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
        : value ?? '';
}

function formatDate(value: string | null): string {
    const date = parseDate(value);
    if (!date) return '';

    const today = new Date();
    if (
        date.getFullYear() === today.getFullYear() &&
        date.getMonth() === today.getMonth() &&
        date.getDate() === today.getDate()
    ) return 'Today';

    const yesterday = new Date(today);
    yesterday.setDate(today.getDate() - 1);
    if (
        date.getFullYear() === yesterday.getFullYear() &&
        date.getMonth() === yesterday.getMonth() &&
        date.getDate() === yesterday.getDate()
    ) return 'Yesterday';

    return date.toLocaleDateString('en-US', {
        month: 'long', day: 'numeric', year: 'numeric',
    });
}

function shouldShowDate(index: number): boolean {
    if (index === 0) return true;
    return formatDate(threadItems.value[index].date) !== formatDate(threadItems.value[index - 1].date);
}

function isMine(item: ThreadItem): boolean {
    return String(item.sender_id ?? '') === props.currentUserId;
}

function isImageAttachment(attachment: MessageAttachment): boolean {
    const name = String(attachment.file_desc || attachment.filename || '').trim().toLowerCase();
    return /\.(jpg|jpeg|png|gif|webp)$/i.test(name);
}

function threadSignature(
    nextInbox: InboxDetails | null,
    nextAttachments: MessageAttachment[],
    nextReplies: ReplyItem[],
): string {
    return JSON.stringify({
        inbox: nextInbox,
        attachments: nextAttachments.map((item) => [item.id, item.filename, item.url]),
        replies: nextReplies.map((item) => [
            item.reply_id,
            item.reply_msg,
            item.reply_date,
            item.date_read,
            item.sender_id,
            Boolean(item.is_deleted),
            (item.attachments ?? []).map((file) => [file.id, file.filename, file.url]),
        ]),
    });
}

function currentSignature(): string {
    return threadSignature(inbox.value, threadAttachments.value, replies.value);
}

function errorText(error: unknown): string {
    if (axios.isAxiosError(error)) {
        const message = error.response?.data?.message;
        if (typeof message === 'string' && message.trim() !== '') return message;
    }
    return error instanceof Error ? error.message : 'Unable to load the conversation.';
}

async function scrollToBottom(): Promise<void> {
    await nextTick();
    if (!messageScroller.value) return;
    messageScroller.value.scrollTop = messageScroller.value.scrollHeight;
}

async function loadConversation(initial = false): Promise<void> {
    if (!props.conversation.inbox_id || !props.currentUserId || requestRunning) return;

    const requestInboxId = props.conversation.inbox_id;
    requestRunning = true;
    if (initial) initialLoading.value = true;
    else backgroundRefreshing.value = true;

    try {
        const response = await axios.get<ThreadResponse>(
            `${API}/${encodeURIComponent(requestInboxId)}/replies`,
            { params: { user_id: props.currentUserId } },
        );

        const nextInbox = response.data.data?.inbox ?? null;
        const nextAttachments = Array.isArray(response.data.data?.attachments)
            ? response.data.data?.attachments ?? []
            : [];
        const nextReplies = Array.isArray(response.data.data?.replies)
            ? response.data.data?.replies ?? []
            : [];

        const changed = threadSignature(nextInbox, nextAttachments, nextReplies) !== currentSignature();

        if (changed) {
            inbox.value = nextInbox;
            threadAttachments.value = nextAttachments;
            replies.value = nextReplies;
            await scrollToBottom();
            emit('conversationUpdated');
        }

        isBlocked.value = Boolean(response.data.data?.moderation?.is_blocked);
        errorMessage.value = '';
        loadedInboxId = requestInboxId;
    } catch (error) {
        if (
            axios.isAxiosError(error) &&
            error.response?.status === 404
        ) {
            emit('back');
            emit('conversationUpdated');
            return;
        }

        if (initial || !inbox.value) errorMessage.value = errorText(error);
    } finally {
        requestRunning = false;
        initialLoading.value = false;
        backgroundRefreshing.value = false;

        if (props.conversation.inbox_id !== requestInboxId) {
            void loadConversation(true);
        }
    }
}

function attachmentKey(file: File): string {
    return `${file.name}:${file.size}:${file.lastModified}`;
}

function createAttachment(file: File): SelectedAttachment {
    const isImage = file.type.toLowerCase().startsWith('image/') || /\.(jpg|jpeg|png|gif)$/i.test(file.name);
    return {
        key: attachmentKey(file),
        file,
        previewUrl: isImage ? URL.createObjectURL(file) : null,
        isImage,
    };
}

function clearReplyFiles(): void {
    for (const attachment of replyFiles.value) {
        if (attachment.previewUrl) URL.revokeObjectURL(attachment.previewUrl);
    }
    replyFiles.value = [];
    replyFileUpload.value?.clear();
}

function removeReplyFile(attachment: SelectedAttachment): void {
    if (attachment.previewUrl) URL.revokeObjectURL(attachment.previewUrl);
    replyFiles.value = replyFiles.value.filter((item) => item.key !== attachment.key);
}

function onReplyFilesSelected(event: FileUploadSelectEvent): void {
    const existing = new Set(replyFiles.value.map((item) => item.key));
    const rejected: string[] = [];

    for (const file of Array.from(event.files ?? [])) {
        const extension = file.name.split('.').pop()?.toLowerCase() ?? '';
        if (!ACCEPTED_EXTENSIONS.has(extension)) {
            rejected.push(`${file.name}: unsupported file type`);
            continue;
        }
        if (file.size > MAX_ATTACHMENT_SIZE) {
            rejected.push(`${file.name}: exceeds 20 MB`);
            continue;
        }

        const attachment = createAttachment(file);
        if (existing.has(attachment.key)) {
            if (attachment.previewUrl) URL.revokeObjectURL(attachment.previewUrl);
            continue;
        }
        existing.add(attachment.key);
        replyFiles.value.push(attachment);
    }

    replyFileUpload.value?.clear();
    if (rejected.length > 0) errorMessage.value = rejected.join('. ');
}

async function sendReply(): Promise<void> {
    const content = replyMessage.value.trim();
    if (sending.value || isBlocked.value) return;

    if (!content && replyFiles.value.length === 0) {
        errorMessage.value = 'Enter a reply or attach a file.';
        return;
    }

    sending.value = true;
    errorMessage.value = '';

    try {
        const formData = new FormData();
        formData.append('user_id', props.currentUserId);
        formData.append('reply_msg', content);
        for (const attachment of replyFiles.value) {
            formData.append('files[]', attachment.file);
        }

        await axios.post(
            `${API}/${encodeURIComponent(props.conversation.inbox_id)}/replies`,
            formData,
        );

        replyMessage.value = '';
        clearReplyFiles();
        await loadConversation(false);
    } catch (error) {
        errorMessage.value = errorText(error);
    } finally {
        sending.value = false;
    }
}


function askToDeleteMessage(item: ThreadItem): void {
    if (!isMine(item) || item.is_deleted || deletingMessageId.value) return;
    confirmDeleteMessageId.value = item.id;
}

function cancelDeleteMessage(): void {
    confirmDeleteMessageId.value = null;
}

function applyDeletedMessageState(item: ThreadItem): void {
    if (item.kind === 'original') {
        if (inbox.value) {
            inbox.value.content = 'This message was deleted';
            inbox.value.is_deleted = true;
        }
        threadAttachments.value = [];
        return;
    }

    const reply = replies.value.find(
        (candidate) => candidate.reply_id === item.id,
    );

    if (!reply) return;

    reply.reply_msg = 'This message was deleted';
    reply.is_deleted = true;
    reply.attachments = [];
}

async function deleteThreadMessage(item: ThreadItem): Promise<void> {
    if (
        !props.currentUserId ||
        !isMine(item) ||
        item.is_deleted ||
        deletingMessageId.value
    ) {
        return;
    }

    deletingMessageId.value = item.id;
    errorMessage.value = '';

    try {
        const endpoint =
            item.kind === 'original'
                ? `${API}/${encodeURIComponent(props.conversation.inbox_id)}/original`
                : `${API}/${encodeURIComponent(props.conversation.inbox_id)}/replies/${encodeURIComponent(item.id)}`;

        await axios.delete(endpoint, {
            data: {
                user_id: props.currentUserId,
            },
        });

        applyDeletedMessageState(item);
        confirmDeleteMessageId.value = null;
        emit('conversationUpdated');
        await scrollToBottom();
        void loadConversation(false);
    } catch (error) {
        errorMessage.value = errorText(error);
    } finally {
        deletingMessageId.value = null;
    }
}

function openAttachment(attachment: MessageAttachment): void {
    const url = attachment.url?.trim();
    if (!url) {
        errorMessage.value = 'The attachment is unavailable.';
        return;
    }
    window.open(url, '_blank', 'noopener,noreferrer');
}

function handleVisibilityChange(): void {
    if (document.visibilityState === 'visible') void loadConversation(false);
}

function startPolling(): void {
    if (pollTimer !== null) window.clearInterval(pollTimer);
    pollTimer = window.setInterval(() => {
        if (document.visibilityState === 'visible') void loadConversation(false);
    }, POLL_INTERVAL_MS);
}

watch(
    () => props.conversation.inbox_id,
    async (nextInboxId) => {
        if (!nextInboxId || nextInboxId === loadedInboxId) return;

        inbox.value = null;
        threadAttachments.value = [];
        replies.value = [];
        isBlocked.value = false;
        errorMessage.value = '';
        replyMessage.value = '';
        confirmDeleteMessageId.value = null;
        deletingMessageId.value = null;
        clearReplyFiles();
        await loadConversation(true);
    },
);

onMounted(() => {
    void loadConversation(true);
    startPolling();
    document.addEventListener('visibilitychange', handleVisibilityChange);
});

onBeforeUnmount(() => {
    if (pollTimer !== null) window.clearInterval(pollTimer);
    document.removeEventListener('visibilitychange', handleVisibilityChange);
    clearReplyFiles();
});
</script>

<template>
    <main class="flex h-full min-h-0 min-w-0 overflow-hidden flex-col bg-[#F4F8FD]">
        <header class="flex shrink-0 items-center gap-3 border-b border-slate-200 bg-white px-4 py-3">
            <Button
                type="button"
                icon="pi pi-angle-left"
                severity="secondary"
                text
                rounded
                class="lg:hidden"
                aria-label="Back to conversations"
                @click="emit('back')"
            />

            <div class="h-11 w-11 shrink-0 overflow-hidden rounded-full">
                <PrimeImage
                    v-if="avatarSource()"
                    :src="avatarSource() || ''"
                    :alt="participantName"
                    class="block h-full w-full"
                    image-class="h-full w-full object-cover"
                    @error="markAvatarFailed(avatarSource())"
                />
                <Avatar
                    v-else
                    :label="initials(participantName)"
                    shape="circle"
                    class="!h-full !w-full !bg-[#377EC0]/10 !font-bold !text-[#377EC0]"
                />
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <p class="truncate font-bold text-slate-900">{{ participantName }}</p>
                    <i
                        v-if="backgroundRefreshing"
                        class="pi pi-spin pi-spinner text-[10px] text-slate-400"
                    ></i>
                </div>
                <p class="truncate text-xs text-slate-500">{{ subject }}</p>
            </div>

            <Button
                type="button"
                icon="pi pi-refresh"
                severity="secondary"
                text
                rounded
                :disabled="backgroundRefreshing"
                aria-label="Refresh conversation"
                @click="loadConversation(false)"
            />
        </header>

        <Message v-if="errorMessage" severity="error" :closable="false" class="m-3 shrink-0">
            {{ errorMessage }}
        </Message>

        <div
            v-if="initialLoading"
            class="flex flex-1 items-center justify-center p-8 text-sm text-slate-500"
        >
            <i class="pi pi-spin pi-spinner mr-2"></i>
            Loading conversation...
        </div>

        <div
            v-else-if="isBlocked"
            class="flex flex-1 flex-col items-center justify-center px-6 text-center"
        >
            <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-red-50">
                <i class="pi pi-user-minus text-2xl text-red-500"></i>
            </div>
            <p class="text-lg font-bold text-slate-900">User blocked</p>
            <p class="mt-1 max-w-sm text-sm text-slate-500">
                Messaging is unavailable for this conversation.
            </p>
        </div>

        <div
            v-else
            ref="messageScroller"
            class="min-h-0 flex-1 overflow-y-auto overflow-x-hidden overscroll-contain px-3 py-4 sm:px-5"
        >
            <div v-for="(item, index) in threadItems" :key="item.id">
                <div v-if="shouldShowDate(index)" class="my-4 flex justify-center">
                    <span class="rounded-full bg-slate-200/80 px-3 py-1 text-[11px] font-semibold text-slate-500">
                        {{ formatDate(item.date) }}
                    </span>
                </div>

                <div
                    :class="[
                        'mb-1.5 flex w-full items-end gap-2',
                        isMine(item) ? 'justify-end pl-12' : 'justify-start pr-12',
                    ]"
                >
                    <div
                        v-if="!isMine(item)"
                        class="h-8 w-8 shrink-0 overflow-hidden rounded-full"
                    >
                        <PrimeImage
                            v-if="avatarSource()"
                            :src="avatarSource() || ''"
                            :alt="participantName"
                            class="block h-full w-full"
                            image-class="h-full w-full object-cover"
                            @error="markAvatarFailed(avatarSource())"
                        />
                        <Avatar
                            v-else
                            :label="initials(participantName)"
                            shape="circle"
                            class="!h-full !w-full !bg-[#377EC0]/10 !text-[10px] !font-bold !text-[#377EC0]"
                        />
                    </div>

                    <div class="max-w-[78%]">
                        <div
                            :class="[
                                'rounded-2xl px-3.5 pb-2 pt-2.5 shadow-sm',
                                item.is_deleted
                                    ? 'border border-slate-200 bg-slate-100 text-slate-500'
                                    : isMine(item)
                                        ? 'rounded-br-md bg-[#377EC0] text-white'
                                        : 'rounded-bl-md border border-slate-200 bg-white text-slate-800',
                            ]"
                        >
                            <p
                                :class="[
                                    'whitespace-pre-wrap break-words text-sm leading-5',
                                    item.is_deleted ? 'italic' : '',
                                ]"
                            >
                                <i
                                    v-if="item.is_deleted"
                                    class="pi pi-ban mr-1 text-[11px]"
                                ></i>
                                {{ item.content }}
                            </p>

                            <div
                                v-if="!item.is_deleted && item.attachments.length > 0"
                                class="mt-2 space-y-1.5"
                            >
                                <template v-for="attachment in item.attachments" :key="attachment.id">
                                    <Button
                                        v-if="!isImageAttachment(attachment)"
                                        type="button"
                                        :label="attachment.file_desc || attachment.filename || 'Attachment'"
                                        icon="pi pi-paperclip"
                                        :severity="isMine(item) ? 'secondary' : 'info'"
                                        size="small"
                                        outlined
                                        class="w-full justify-start overflow-hidden"
                                        @click="openAttachment(attachment)"
                                    />
                                    <Button
                                        v-else
                                        type="button"
                                        unstyled
                                        class="block overflow-hidden rounded-xl"
                                        @click="openAttachment(attachment)"
                                    >
                                        <PrimeImage
                                            :src="attachment.url || ''"
                                            :alt="attachment.file_desc || attachment.filename || 'Image attachment'"
                                            image-class="max-h-72 max-w-full rounded-xl object-cover"
                                        />
                                    </Button>
                                </template>
                            </div>

                            <div
                                :class="[
                                    'mt-1 flex items-center justify-end gap-1 text-[10px]',
                                    item.is_deleted
                                        ? 'text-slate-400'
                                        : isMine(item)
                                            ? 'text-blue-100'
                                            : 'text-slate-400',
                                ]"
                            >
                                <span>{{ formatTime(item.date) }}</span>
                                <i
                                    v-if="isMine(item) && !item.is_deleted"
                                    class="pi pi-check-circle text-[10px]"
                                ></i>
                                <Button
                                    v-if="isMine(item) && !item.is_deleted"
                                    type="button"
                                    icon="pi pi-trash"
                                    severity="danger"
                                    text
                                    rounded
                                    size="small"
                                    class="!ml-1 !h-6 !w-6 !min-w-0 !p-0"
                                    :disabled="Boolean(deletingMessageId)"
                                    aria-label="Delete message"
                                    title="Delete message"
                                    @click="askToDeleteMessage(item)"
                                />
                            </div>
                        </div>

                        <div
                            v-if="confirmDeleteMessageId === item.id"
                            class="mt-1.5 flex items-center justify-end gap-1.5"
                        >
                            <span class="mr-1 text-[11px] font-semibold text-slate-500">
                                Delete for everyone?
                            </span>
                            <Button
                                type="button"
                                label="Cancel"
                                severity="secondary"
                                text
                                size="small"
                                :disabled="deletingMessageId === item.id"
                                @click="cancelDeleteMessage"
                            />
                            <Button
                                type="button"
                                label="Delete"
                                icon="pi pi-trash"
                                severity="danger"
                                size="small"
                                :loading="deletingMessageId === item.id"
                                :disabled="
                                    Boolean(deletingMessageId) &&
                                    deletingMessageId !== item.id
                                "
                                @click="deleteThreadMessage(item)"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <footer
            v-if="!initialLoading && !isBlocked"
            class="shrink-0 border-t border-slate-200 bg-white p-3"
        >
            <div v-if="replyFiles.length > 0" class="mb-2 rounded-2xl bg-slate-50 p-2">
                <div class="flex flex-wrap gap-2">
                    <div
                        v-for="attachment in replyFiles"
                        :key="attachment.key"
                        class="relative"
                    >
                        <div
                            v-if="attachment.isImage && attachment.previewUrl"
                            class="h-20 w-20 overflow-hidden rounded-xl border border-slate-200 bg-white"
                        >
                            <PrimeImage
                                :src="attachment.previewUrl"
                                :alt="attachment.file.name"
                                class="block h-full w-full"
                                image-class="h-full w-full object-cover"
                            />
                        </div>
                        <div
                            v-else
                            class="flex h-20 min-w-[180px] max-w-[240px] items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 pr-9"
                        >
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#377EC0]/10">
                                <i class="pi pi-file text-[#377EC0]"></i>
                            </div>
                            <span class="min-w-0 flex-1 truncate text-xs font-semibold text-slate-700">
                                {{ attachment.file.name }}
                            </span>
                        </div>
                        <Button
                            type="button"
                            icon="pi pi-times"
                            severity="secondary"
                            rounded
                            size="small"
                            class="!absolute -right-2 -top-2 !h-7 !w-7"
                            aria-label="Remove attachment"
                            @click="removeReplyFile(attachment)"
                        />
                    </div>
                </div>
            </div>

            <div class="flex items-end gap-2">
                <FileUpload
                    ref="replyFileUpload"
                    name="files[]"
                    mode="advanced"
                    multiple
                    custom-upload
                    :auto="false"
                    :accept="ACCEPTED_ATTACHMENT_STRING"
                    :max-file-size="MAX_ATTACHMENT_SIZE"
                    :show-upload-button="false"
                    :show-cancel-button="false"
                    :disabled="sending"
                    :pt="{
                        root: { class: '!border-0 !bg-transparent' },
                        header: { class: '!border-0 !bg-transparent !p-0' },
                        content: { class: '!hidden' },
                    }"
                    @select="onReplyFilesSelected"
                >
                    <template #header="{ chooseCallback }">
                        <Button
                            type="button"
                            icon="pi pi-paperclip"
                            severity="secondary"
                            text
                            rounded
                            :disabled="sending"
                            aria-label="Attach files"
                            @click="chooseCallback()"
                        />
                    </template>
                    <template #content />
                    <template #empty />
                </FileUpload>

                <Textarea
                    v-model="replyMessage"
                    auto-resize
                    rows="1"
                    class="min-h-[44px] max-h-32 flex-1 overflow-y-auto"
                    placeholder="Type a message..."
                    @keydown.enter.exact.prevent="sendReply"
                />

                <Button
                    type="button"
                    icon="pi pi-send"
                    severity="success"
                    rounded
                    :loading="sending"
                    :disabled="sending || (!replyMessage.trim() && replyFiles.length === 0)"
                    aria-label="Send reply"
                    @click="sendReply"
                />
            </div>

            <p class="mt-1 pl-12 text-[10px] text-slate-400">
                Maximum 20 MB per attachment.
            </p>
        </footer>
    </main>
</template>