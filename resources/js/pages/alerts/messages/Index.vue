<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import Avatar from 'primevue/avatar';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import FileUpload from 'primevue/fileupload';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import PrimeImage from 'primevue/image';
import Select from 'primevue/select';
import Textarea from 'primevue/textarea';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';

import { dashboard } from '@/routes';
import type { SharedData } from '@/types';

import ChatHistory from './components/ChatHistory.vue';
import ChatMessages from './components/ChatMessages.vue';

defineOptions({
    inheritAttrs: false,
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Messages', href: '/alerts/messages' },
        ],
    },
});

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

type Recipient = {
    id: string;
    recipient_type: string;
    full_name: string;
    login_name?: string | null;
    code_person?: string | null;
    gender?: string | null;
    photo_url?: string | null;
    email?: string | null;
};

type RecipientResponse = {
    success: boolean;
    message: string;
    data?: Recipient[];
};

type StoreMessageResponse = {
    success: boolean;
    message: string;
    data?: { id?: string };
};

type SelectedAttachment = {
    key: string;
    file: File;
    previewUrl: string | null;
    isImage: boolean;
};

type FileUploadSelectEvent = { files: File[] };
type FileUploadControl = { clear: () => void };

const API = '/api/v1/alerts/messages';
const MAX_ATTACHMENT_SIZE = 20 * 1024 * 1024;
const ACCEPTED_ATTACHMENT_STRING = '.jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv';
const ACCEPTED_EXTENSIONS = new Set([
    'jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx',
    'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv',
]);

const page = usePage<SharedData>();
const currentUserId = computed(() => String(page.props.auth.user?.id ?? '').trim());

const selectedConversation = ref<MessageListItem | null>(null);
const historyRefreshToken = ref(0);
const requestedInboxId = ref<string | null>(null);

const composeVisible = ref(false);
const composeRecipientType = ref<'admin' | 'student' | null>(null);
const composeRecipient = ref<Recipient | null>(null);
const composeRecipients = ref<Recipient[]>([]);
const composeSubject = ref('');
const composeMessage = ref('');
const composeFiles = ref<SelectedAttachment[]>([]);
const composeFileUpload = ref<FileUploadControl | null>(null);
const composeRecipientsLoading = ref(false);
const composing = ref(false);
const composeError = ref('');
const failedAvatarSources = ref<Set<string>>(new Set());

const messagesRoot =
    ref<HTMLElement | null>(
        null,
    );

const messagesHeight =
    ref('0px');

let resizeFrame:
    number | null =
    null;

/*
 * The application layout does not provide a fixed-height ancestor to this
 * Inertia page. Because of that, h-full alone can still allow the Messages
 * page to grow with its children.
 *
 * Measure the remaining visible viewport and give the Messages container an
 * explicit height. This keeps the page itself fixed while ChatHistory and
 * ChatMessages scroll internally.
 */
function updateMessagesHeight():
    void {
    const root =
        messagesRoot.value;

    if (!root) {
        return;
    }

    const rootTop =
        Math.max(
            0,
            root
                .getBoundingClientRect()
                .top,
        );

    const parent =
        root.parentElement;

    const parentPaddingBottom =
        parent
            ? Number.parseFloat(
                  window
                      .getComputedStyle(
                          parent,
                      )
                      .paddingBottom,
              ) || 0
            : 0;

    /*
     * Leave room for the existing application footer, but never count the
     * chat composer's own <footer>.
     */
    const externalFooterHeight =
        Array.from(
            document
                .querySelectorAll(
                    'footer',
                ),
        )
            .filter(
                (footer) =>
                    !root.contains(
                        footer,
                    ),
            )
            .reduce(
                (
                    largest,
                    footer,
                ) =>
                    Math.max(
                        largest,
                        footer
                            .getBoundingClientRect()
                            .height,
                    ),
                0,
            );

    const availableHeight =
        Math.max(
            320,
            Math.floor(
                window.innerHeight
                - rootTop
                - externalFooterHeight
                - parentPaddingBottom
                - 4,
            ),
        );

    messagesHeight.value =
        `${availableHeight}px`;
}

function scheduleMessagesHeightUpdate():
    void {
    if (
        resizeFrame !== null
    ) {
        window.cancelAnimationFrame(
            resizeFrame,
        );
    }

    resizeFrame =
        window.requestAnimationFrame(
            () => {
                resizeFrame = null;

                updateMessagesHeight();
            },
        );
}

const recipientTypeOptions = [
    { label: 'Administrator', value: 'admin' },
    { label: 'Student', value: 'student' },
];

function selectConversation(conversation: MessageListItem): void {
    selectedConversation.value = conversation;
}

function closeConversation(): void {
    selectedConversation.value = null;
}

function handleConversationDeleted(inboxId: string): void {
    if (selectedConversation.value?.inbox_id === inboxId) {
        selectedConversation.value = null;
    }

    if (requestedInboxId.value === inboxId) {
        requestedInboxId.value = null;
    }

    refreshHistory();
}

function refreshHistory(): void {
    historyRefreshToken.value += 1;
}

function openCompose(): void {
    clearComposeFiles();
    composeVisible.value = true;
    composeRecipientType.value = null;
    composeRecipient.value = null;
    composeRecipients.value = [];
    composeSubject.value = '';
    composeMessage.value = '';
    composeError.value = '';
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

function avatarSource(recipient: Recipient): string | null {
    const photo = String(recipient.photo_url ?? '').trim();
    if (photo && !failedAvatarSources.value.has(photo)) return photo;
    const fallback = genderAvatar(recipient.gender);
    if (fallback && !failedAvatarSources.value.has(fallback)) return fallback;
    return null;
}

function markAvatarFailed(source: string | null): void {
    if (!source) return;
    const next = new Set(failedAvatarSources.value);
    next.add(source);
    failedAvatarSources.value = next;
}

function errorText(error: unknown, fallback: string): string {
    if (axios.isAxiosError(error)) {
        const message = error.response?.data?.message;
        if (typeof message === 'string' && message.trim() !== '') return message;
    }
    return error instanceof Error ? error.message : fallback;
}

async function loadRecipients(type: 'admin' | 'student'): Promise<void> {
    composeRecipient.value = null;
    composeRecipients.value = [];
    composeRecipientsLoading.value = true;
    composeError.value = '';

    try {
        const response = await axios.get<RecipientResponse>(
            `${API}/${type === 'admin' ? 'administrators' : 'students'}`,
        );
        composeRecipients.value = Array.isArray(response.data.data) ? response.data.data : [];
    } catch (error) {
        composeError.value = errorText(error, 'Unable to load recipients.');
    } finally {
        composeRecipientsLoading.value = false;
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

function clearComposeFiles(): void {
    for (const attachment of composeFiles.value) {
        if (attachment.previewUrl) URL.revokeObjectURL(attachment.previewUrl);
    }
    composeFiles.value = [];
    composeFileUpload.value?.clear();
}

function removeComposeFile(attachment: SelectedAttachment): void {
    if (attachment.previewUrl) URL.revokeObjectURL(attachment.previewUrl);
    composeFiles.value = composeFiles.value.filter((item) => item.key !== attachment.key);
}

function onComposeFilesSelected(event: FileUploadSelectEvent): void {
    const existing = new Set(composeFiles.value.map((item) => item.key));
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
        composeFiles.value.push(attachment);
    }

    composeFileUpload.value?.clear();
    if (rejected.length > 0) composeError.value = rejected.join('. ');
}

async function sendNewMessage(): Promise<void> {
    if (composing.value) return;
    if (!composeRecipient.value) {
        composeError.value = 'Select a recipient.';
        return;
    }
    if (!composeSubject.value.trim()) {
        composeError.value = 'Subject is required.';
        return;
    }
    if (!composeMessage.value.trim() && composeFiles.value.length === 0) {
        composeError.value = 'Message or attachment is required.';
        return;
    }

    composing.value = true;
    composeError.value = '';

    try {
        const formData = new FormData();
        formData.append('user_id', currentUserId.value);
        formData.append('recipient_id', composeRecipient.value.id);
        formData.append('recipient_type', composeRecipient.value.recipient_type);
        formData.append('subject', composeSubject.value.trim());
        formData.append('content', composeMessage.value.trim());
        for (const attachment of composeFiles.value) {
            formData.append('files[]', attachment.file);
        }

        const response = await axios.post<StoreMessageResponse>(API, formData);
        requestedInboxId.value = response.data.data?.id ?? null;
        clearComposeFiles();
        composeVisible.value = false;
        refreshHistory();
    } catch (error) {
        composeError.value = errorText(error, 'Unable to send message.');
    } finally {
        composing.value = false;
    }
}

watch(composeRecipientType, (value) => {
    if (!value) {
        composeRecipient.value = null;
        composeRecipients.value = [];
        return;
    }
    void loadRecipients(value);
});

watch(composeVisible, (visible) => {
    if (!visible && !composing.value) clearComposeFiles();
});

onMounted(async () => {
    await nextTick();

    scheduleMessagesHeightUpdate();

    window.addEventListener(
        'resize',
        scheduleMessagesHeightUpdate,
    );
});

onBeforeUnmount(() => {
    clearComposeFiles();

    window.removeEventListener(
        'resize',
        scheduleMessagesHeightUpdate,
    );

    if (
        resizeFrame !== null
    ) {
        window.cancelAnimationFrame(
            resizeFrame,
        );
    }
});
</script>

<template>
    <Head title="Messages" />

    <section
        ref="messagesRoot"
        :style="{
            height: messagesHeight,
            maxHeight: messagesHeight,
        }"
        class="flex min-h-0 w-full shrink-0 overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-xl shadow-slate-200/40"
    >
        <div
            class="grid h-full min-h-0 w-full flex-1 grid-rows-[minmax(0,1fr)] overflow-hidden lg:grid-cols-[340px_minmax(0,1fr)]"
        >
            <ChatHistory
                :class="selectedConversation ? 'hidden lg:flex' : 'flex'"
                :current-user-id="currentUserId"
                :selected-inbox-id="selectedConversation?.inbox_id ?? null"
                :refresh-token="historyRefreshToken"
                :select-inbox-id="requestedInboxId"
                @select="selectConversation"
                @selection-resolved="requestedInboxId = null"
                @deleted="handleConversationDeleted"
            >
                <template #new-message>
                    <Button
                        type="button"
                        icon="pi pi-plus"
                        severity="success"
                        rounded
                        aria-label="Create new message"
                        @click="openCompose"
                    />
                </template>
            </ChatHistory>

            <ChatMessages
                v-if="selectedConversation"
                :current-user-id="currentUserId"
                :conversation="selectedConversation"
                @back="closeConversation"
                @conversation-updated="refreshHistory"
            />

            <main
                v-else
                class="hidden h-full min-h-0 min-w-0 overflow-hidden flex-col items-center justify-center bg-[#F4F8FD] px-6 text-center lg:flex"
            >
                <div class="mb-4 flex h-20 w-20 items-center justify-center rounded-full bg-[#377EC0]/10">
                    <i class="pi pi-comments text-4xl text-[#377EC0]"></i>
                </div>
                <h2 class="text-lg font-bold text-slate-900">Your messages</h2>
                <p class="mt-1 max-w-sm text-sm text-slate-500">
                    Select a conversation from the left or create a new message.
                </p>
                <Button
                    type="button"
                    label="New Message"
                    icon="pi pi-plus"
                    severity="success"
                    class="mt-4"
                    @click="openCompose"
                />
            </main>
        </div>
    </section>

    <Dialog
        v-model:visible="composeVisible"
        modal
        header="New Message"
        :style="{ width: 'min(680px, 95vw)' }"
        :draggable="false"
    >
        <div class="space-y-4">
            <Message v-if="composeError" severity="error" :closable="false">
                {{ composeError }}
            </Message>

            <div>
                <label class="mb-1.5 block text-sm font-semibold text-slate-700">
                    Recipient Type <span class="text-red-500">*</span>
                </label>
                <Select
                    v-model="composeRecipientType"
                    :options="recipientTypeOptions"
                    option-label="label"
                    option-value="value"
                    placeholder="Select recipient type"
                    class="w-full"
                    :disabled="composing"
                />
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-semibold text-slate-700">
                    Recipient <span class="text-red-500">*</span>
                </label>
                <Select
                    v-model="composeRecipient"
                    :options="composeRecipients"
                    option-label="full_name"
                    placeholder="Select recipient"
                    filter
                    class="w-full"
                    :loading="composeRecipientsLoading"
                    :disabled="!composeRecipientType || composeRecipientsLoading || composing"
                >
                    <template #option="slotProps">
                        <div class="flex items-center gap-3">
                            <div class="h-9 w-9 shrink-0 overflow-hidden rounded-full">
                                <PrimeImage
                                    v-if="avatarSource(slotProps.option)"
                                    :src="avatarSource(slotProps.option) || ''"
                                    :alt="slotProps.option.full_name"
                                    class="block h-full w-full"
                                    image-class="h-full w-full object-cover"
                                    @error="markAvatarFailed(avatarSource(slotProps.option))"
                                />
                                <Avatar
                                    v-else
                                    :label="initials(slotProps.option.full_name)"
                                    shape="circle"
                                    class="!h-full !w-full !bg-[#377EC0]/10 !text-xs !font-bold !text-[#377EC0]"
                                />
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-800">
                                    {{ slotProps.option.full_name }}
                                </p>
                                <p
                                    v-if="slotProps.option.login_name || slotProps.option.code_person"
                                    class="truncate text-xs text-slate-500"
                                >
                                    {{ slotProps.option.login_name || slotProps.option.code_person }}
                                </p>
                            </div>
                        </div>
                    </template>
                </Select>
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-semibold text-slate-700">
                    Subject <span class="text-red-500">*</span>
                </label>
                <InputText
                    v-model="composeSubject"
                    maxlength="100"
                    class="w-full"
                    placeholder="Enter message subject"
                    :disabled="composing"
                />
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-semibold text-slate-700">
                    Message <span class="text-red-500">*</span>
                </label>

                <div v-if="composeFiles.length > 0" class="mb-2 rounded-2xl bg-slate-50 p-2">
                    <div class="flex flex-wrap gap-2">
                        <div
                            v-for="attachment in composeFiles"
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
                                @click="removeComposeFile(attachment)"
                            />
                        </div>
                    </div>
                </div>

                <div class="flex items-end gap-2">
                    <FileUpload
                        ref="composeFileUpload"
                        name="files[]"
                        mode="advanced"
                        multiple
                        custom-upload
                        :auto="false"
                        :accept="ACCEPTED_ATTACHMENT_STRING"
                        :max-file-size="MAX_ATTACHMENT_SIZE"
                        :show-upload-button="false"
                        :show-cancel-button="false"
                        :disabled="composing"
                        :pt="{
                            root: { class: '!border-0 !bg-transparent' },
                            header: { class: '!border-0 !bg-transparent !p-0' },
                            content: { class: '!hidden' },
                        }"
                        @select="onComposeFilesSelected"
                    >
                        <template #header="{ chooseCallback }">
                            <Button
                                type="button"
                                icon="pi pi-paperclip"
                                severity="secondary"
                                text
                                rounded
                                :disabled="composing"
                                aria-label="Attach files"
                                @click="chooseCallback()"
                            />
                        </template>
                        <template #content />
                        <template #empty />
                    </FileUpload>

                    <Textarea
                        v-model="composeMessage"
                        rows="5"
                        auto-resize
                        class="flex-1"
                        placeholder="Write your message..."
                        :disabled="composing"
                    />
                </div>

                <p class="mt-1 pl-12 text-[10px] text-slate-400">
                    Maximum 20 MB per attachment.
                </p>
            </div>
        </div>

        <template #footer>
            <Button
                type="button"
                label="Cancel"
                severity="secondary"
                outlined
                :disabled="composing"
                @click="composeVisible = false"
            />
            <Button
                type="button"
                label="Send Message"
                icon="pi pi-send"
                severity="success"
                :loading="composing"
                :disabled="
                    composing ||
                    !composeRecipient ||
                    !composeSubject.trim() ||
                    (!composeMessage.trim() && composeFiles.length === 0)
                "
                @click="sendNewMessage"
            />
        </template>
    </Dialog>
</template>