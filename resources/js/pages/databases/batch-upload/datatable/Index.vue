<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import { computed, onBeforeUnmount, ref } from 'vue';


defineOptions({
    inheritAttrs: false,

    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: '/dashboard',
            },
            {
                title: 'Batch Upload',
                href: '/databases/batch-upload/datatable',
            },
        ],
    },
});

const toast = useToast();

type Issue = {
    sheet: string;
    row: number;
    message: string;
};

type CreatedAccount = {
    row: number;
    school_id_no: string;
    name: string;
    email: string;
    login_name: string;
    password: string;
    email_status?: 'sent' | 'failed';
};

type Result = {
    message: string;
    added: number;
    updated: number;
    processed: number;
    skipped: number;
    emails_sent: number;
    emails_failed: number;
    created_accounts: CreatedAccount[];
};

const API =
    '/api/v1/databases/students/batch-upload';

const file = ref<File | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const confirmed = ref(false);
const importing = ref(false);
const downloadingTemplate = ref(false);
const isDraggingFile = ref(false);
const issues = ref<Issue[]>([]);
const issueCount = ref(0);
const result = ref<Result | null>(null);

let disposed = false;

function showError(
    detail: string,
): void {
    toast.add({
        severity: 'error',
        summary: 'Error',
        detail,
        life: 4500,
    });
}

function showSuccess(
    summary: string,
    detail: string,
): void {
    toast.add({
        severity: 'success',
        summary,
        detail,
        life: 4000,
    });
}

const canImport = computed(
    () =>
        file.value !== null &&
        confirmed.value &&
        !importing.value,
);

const selectedFileSize = computed(
    () => {
        if (!file.value) {
            return '';
        }

        const kilobytes =
            file.value.size / 1024;

        if (kilobytes < 1024) {
            return `${kilobytes.toFixed(1)} KB`;
        }

        return `${(
            kilobytes / 1024
        ).toFixed(2)} MB`;
    },
);

function openFilePicker(): void {
    if (importing.value) {
        return;
    }

    fileInput.value?.click();
}

function downloadTemplate(): void {
    if (
        downloadingTemplate.value ||
        importing.value
    ) {
        return;
    }

    downloadingTemplate.value = true;

    try {
        const link =
            document.createElement(
                'a',
            );

        link.href =
            '/templates/student-template.xlsx';

        link.download =
            'student-template.xlsx';

        link.style.display =
            'none';

        document.body.appendChild(
            link,
        );

        link.click();
        link.remove();

        toast.add({
            severity: 'success',
            summary: 'Template Download Started',
            detail:
                'student-template.xlsx is being downloaded.',
            life: 3000,
        });
    } catch {
        showError(
            'Unable to start the Excel template download.',
        );
    } finally {
        window.setTimeout(
            () => {
                downloadingTemplate.value =
                    false;
            },
            500,
        );
    }
}

function applySelectedFile(
    selected: File | null,
): void {
    resetFeedback();

    if (!selected) {
        showError(
            'No file was detected. Please choose or drop an Excel workbook.',
        );

        return;
    }

    if (
        selected.size >
        5 * 1024 * 1024
    ) {
        showError(
            'The Excel file must not exceed 5 MB.',
        );

        return;
    }

    /*
     * Do not enforce a specific filename.
     * The Laravel importer validates the actual workbook content
     * and accepts genuine XLS or XLSX files.
     */
    file.value = selected;
    confirmed.value = false;

    toast.add({
        severity: 'info',
        summary: 'Workbook Selected',
        detail:
            `${selected.name} is ready for validation.`,
        life: 3000,
    });
}

function selectFile(
    event: Event,
): void {
    const input =
        event.target as HTMLInputElement;

    const selected =
        input.files?.[0] ??
        null;

    applySelectedFile(
        selected,
    );
}

function handleFileDragOver(
    event: DragEvent,
): void {
    if (importing.value) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();

    if (event.dataTransfer) {
        event.dataTransfer.dropEffect =
            'copy';
    }

    isDraggingFile.value = true;
}

function handleFileDragLeave(
    event: DragEvent,
): void {
    event.preventDefault();
    event.stopPropagation();

    isDraggingFile.value = false;
}

function handleFileDrop(
    event: DragEvent,
): void {
    event.preventDefault();
    event.stopPropagation();

    isDraggingFile.value = false;

    if (importing.value) {
        return;
    }

    let selected: File | null =
        event.dataTransfer
            ?.files?.[0]
        ??
        null;

    /*
     * Some Chromium/Safari drag sources expose the dropped file
     * through DataTransferItem before files[0].
     */
    if (
        !selected &&
        event.dataTransfer
            ?.items?.length
    ) {
        const item =
            Array.from(
                event.dataTransfer.items,
            ).find(
                (entry) =>
                    entry.kind ===
                    'file',
            );

        selected =
            item?.getAsFile()
            ??
            null;
    }

    if (!selected) {
        showError(
            'The dropped item could not be read as a file. Try dropping it from Finder or use Choose Excel File.',
        );

        return;
    }

    applySelectedFile(
        selected,
    );
}

async function importStudents(): Promise<void> {
    resetFeedback();

    if (!file.value) {
        showError(
            'Select an Excel file before importing.',
        );

        return;
    }

    if (!confirmed.value) {
        showError(
            'Confirm the update notice before importing.',
        );

        return;
    }

    importing.value = true;

    const body = new FormData();

    body.append('file', file.value);
    body.append('confirm_update', '1');

    try {
        const response = await axios.post<Result>(
            `${API}/import`,
            body,
            {
                headers: {
                    Accept:
                        'application/json',
                    'X-Requested-With':
                        'XMLHttpRequest',
                },

                withCredentials: true,
            },
        );

        if (disposed) {
            return;
        }

        result.value = response.data;
        file.value = null;
        confirmed.value = false;

        if (fileInput.value) {
            fileInput.value.value = '';
        }

        const emailSummary =
            response.data.emails_sent > 0 ||
            response.data.emails_failed > 0
                ? ` ${response.data.emails_sent} credential email(s) sent` +
                  (
                      response.data.emails_failed > 0
                          ? `, ${response.data.emails_failed} failed.`
                          : '.'
                  )
                : '';

        showSuccess(
            'Students Imported',
            `${response.data.added} added, ${response.data.updated} updated, ${response.data.processed} processed, and ${response.data.skipped} blank rows skipped.${emailSummary}`,
        );
    } catch (caught: unknown) {
        if (disposed) {
            return;
        }

        showError(
            errorMessage(
                caught,
                'Unable to import students.',
            ),
        );

        if (axios.isAxiosError(caught)) {
            issues.value =
                caught.response?.data?.issues ?? [];

            issueCount.value = Number(
                caught.response?.data?.issue_count
                ?? issues.value.length,
            );
        }
    } finally {
        importing.value = false;
    }
}

function errorMessage(
    caught: unknown,
    fallback: string,
): string {
    if (!axios.isAxiosError(caught)) {
        return fallback;
    }

    const body = caught.response?.data;
    const first = Object.values(
        body?.errors ?? {},
    )[0];

    return Array.isArray(first)
        ? String(first[0])
        : String(
              body?.message ?? fallback,
          );
}

function resetFeedback(): void {
    issues.value = [];
    issueCount.value = 0;
    result.value = null;
}

function clearForm(): void {
    const hadSelection =
        file.value !== null ||
        confirmed.value;

    file.value = null;
    confirmed.value = false;

    if (fileInput.value) {
        fileInput.value.value = '';
    }

    resetFeedback();

    if (hadSelection) {
        toast.add({
            severity: 'info',
            summary: 'Selection Cleared',
            detail:
                'The selected workbook and confirmation were cleared.',
            life: 2500,
        });
    }
}

function accountText(
    account: CreatedAccount,
): string {
    return [
        `School ID: ${account.school_id_no}`,
        `Name: ${account.name}`,
        `Login Name: ${account.login_name}`,
        `Password: ${account.password}`,
    ].join('\n');
}

async function copyAccount(
    account: CreatedAccount,
): Promise<void> {
    try {
        await navigator.clipboard.writeText(
            accountText(account),
        );

        showSuccess(
            'Credentials Copied',
            `Credentials copied for ${account.name}.`,
        );
    } catch {
        showError(
            'Unable to copy the credentials automatically. Please copy them manually.',
        );
    }
}

async function copyAllAccounts(): Promise<void> {
    const accounts =
        result.value?.created_accounts ?? [];

    if (accounts.length === 0) {
        return;
    }

    const text = accounts
        .map((account) => accountText(account))
        .join('\n\n');

    try {
        await navigator.clipboard.writeText(text);

        showSuccess(
            'Credentials Copied',
            `${accounts.length} newly created student account(s) copied.`,
        );
    } catch {
        showError(
            'Unable to copy the credentials automatically. Please copy them manually.',
        );
    }
}

onBeforeUnmount(() => {
    disposed = true;

    /*
     * Do not imply that leaving the page cancels an import already
     * running on the Laravel server.
     */
});
</script>

<template>
    <Head title="Batch Upload Students" />

    <Toast position="top-right" />

    <div
        class="flex flex-1 flex-col gap-5 bg-[#F8FAFC] p-4 lg:p-6"
    >
        <section
            class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:p-6"
        >
            <div
                class="mb-5 flex flex-wrap items-center justify-between gap-4"
            >
                <div class="flex items-center gap-3">
                    <div
                        class="relative flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-gradient-to-br from-[#123A63] to-[#377EC0] text-white shadow-lg shadow-[#377EC0]/20"
                    >
                        <div
                            class="pointer-events-none absolute -top-3 -right-3 size-8 rounded-full bg-white/15"
                        ></div>

                        <i
                            class="pi pi-upload relative z-10 !text-[1.65rem] !leading-none !text-white"
                        ></i>
                    </div>

                    <div>
                        <h1
                            class="text-xl font-bold text-slate-800"
                        >
                            Batch Upload Students
                        </h1>

                        <p
                            class="text-sm text-slate-500"
                        >
                            Add new students or update existing student records from an XLS or XLSX workbook.
                        </p>
                    </div>
                </div>

            </div>

            <div
                class="mb-5 grid gap-3 md:grid-cols-3"
            >
                <div
                    class="flex items-start gap-3 rounded-xl border border-blue-200 bg-blue-50 p-3"
                >
                    <div
                        class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-blue-100 font-bold text-blue-700"
                    >
                        1
                    </div>

                    <div>
                        <p
                            class="text-sm font-semibold text-slate-800"
                        >
                            Download the template
                        </p>

                        <p
                            class="mt-1 text-xs text-slate-600"
                        >
                            Download the Excel template, fill in the student rows, then upload it below.
                        </p>
                    </div>
                </div>

                <div
                    class="flex items-start gap-3 rounded-xl border border-blue-200 bg-blue-50 p-3"
                >
                    <div
                        class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-blue-100 font-bold text-blue-700"
                    >
                        2
                    </div>

                    <div>
                        <p
                            class="text-sm font-semibold text-slate-800"
                        >
                            Complete the student rows
                        </p>

                        <p
                            class="mt-1 text-xs text-slate-600"
                        >
                            Keep the 9 columns in the same order. Gender and Email are required; Middle Name may be blank.
                        </p>
                    </div>
                </div>

                <div
                    class="flex items-start gap-3 rounded-xl border border-blue-200 bg-blue-50 p-3"
                >
                    <div
                        class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-blue-100 font-bold text-blue-700"
                    >
                        3
                    </div>

                    <div>
                        <p
                            class="text-sm font-semibold text-slate-800"
                        >
                            Upload and confirm
                        </p>

                        <p
                            class="mt-1 text-xs text-slate-600"
                        >
                            Drag or choose any XLS/XLSX workbook. The filename does not need to match the template.
                        </p>
                    </div>
                </div>
            </div>

            <section
                class="mb-5 max-w-full overflow-hidden rounded-xl border border-slate-300 bg-white shadow-sm"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-300 bg-[#217346] px-4 py-3 text-white"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="flex size-9 items-center justify-center rounded-lg bg-white/15"
                        >
                            <i class="pi pi-file-excel text-lg"></i>
                        </div>

                        <div>
                            <h2 class="font-semibold">
                                Student Batch Upload Template
                            </h2>

                            <p class="text-xs text-white/80">
                                Preview of the required Excel format
                            </p>
                        </div>
                    </div>

                    <span
                        class="rounded-md bg-white/15 px-3 py-1.5 text-xs font-semibold"
                    >
                        student-template.xlsx
                    </span>
                </div>

                <div class="w-full overflow-x-auto bg-[#F3F3F3]">
                    <div class="min-w-[1620px] p-4">
                        <div class="overflow-hidden border border-slate-300 bg-white">
                            <table
                                class="w-full table-fixed border-collapse text-left text-sm"
                            >
                                <colgroup>
                                    <col style="width: 48px" />
                                    <col style="width: 145px" />
                                    <col style="width: 145px" />
                                    <col style="width: 145px" />
                                    <col style="width: 145px" />
                                    <col style="width: 105px" />
                                    <col style="width: 105px" />
                                    <col style="width: 145px" />
                                    <col style="width: 130px" />
                                    <col style="width: 260px" />
                                </colgroup>

                            <thead>
                                <tr>
                                    <th
                                        class="h-8 border-r border-b border-slate-300 bg-[#E7E6E6]"
                                    ></th>

                                    <th
                                        v-for="letter in [
                                            'A',
                                            'B',
                                            'C',
                                            'D',
                                            'E',
                                            'F',
                                            'G',
                                            'H',
                                            'I',
                                        ]"
                                        :key="letter"
                                        class="h-8 border-r border-b border-slate-300 bg-[#E7E6E6] px-3 text-center text-xs font-semibold text-slate-700 last:border-r-0"
                                    >
                                        {{ letter }}
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                <tr>
                                    <th
                                        class="border-r border-b border-slate-300 bg-[#E7E6E6] text-center text-xs font-semibold text-slate-700"
                                    >
                                        1
                                    </th>

                                    <td
                                        class="border-r border-b border-slate-300 bg-[#E2F0D9] px-3 py-2 font-semibold text-slate-800"
                                    >
                                        SCHOOL ID NO.
                                    </td>

                                    <td
                                        class="border-r border-b border-slate-300 bg-[#E2F0D9] px-3 py-2 font-semibold text-slate-800"
                                    >
                                        LAST NAME
                                    </td>

                                    <td
                                        class="border-r border-b border-slate-300 bg-[#E2F0D9] px-3 py-2 font-semibold text-slate-800"
                                    >
                                        FIRST NAME
                                    </td>

                                    <td
                                        class="border-r border-b border-slate-300 bg-[#E2F0D9] px-3 py-2 font-semibold text-slate-800"
                                    >
                                        MIDDLE NAME
                                    </td>

                                    <td
                                        class="border-r border-b border-slate-300 bg-[#E2F0D9] px-3 py-2 font-semibold text-slate-800"
                                    >
                                        GENDER
                                    </td>

                                    <td
                                        class="border-r border-b border-slate-300 bg-[#E2F0D9] px-3 py-2 font-semibold text-slate-800"
                                    >
                                        CCI YEAR
                                    </td>

                                    <td
                                        class="border-r border-b border-slate-300 bg-[#E2F0D9] px-3 py-2 font-semibold text-slate-800"
                                    >
                                        DEPARTMENT
                                    </td>

                                    <td
                                        class="border-r border-b border-slate-300 bg-[#E2F0D9] px-3 py-2 font-semibold text-slate-800"
                                    >
                                        TRB TYPE
                                    </td>

                                    <td
                                        class="border-r-0 border-b border-slate-300 bg-[#E2F0D9] px-3 py-2 font-semibold text-slate-800"
                                    >
                                        EMAIL
                                    </td>
                                </tr>

                                <tr>
                                    <th
                                        class="border-r border-b border-slate-300 bg-[#E7E6E6] text-center text-xs font-semibold text-slate-700"
                                    >
                                        2
                                    </th>

                                    <td
                                        class="border-r border-b border-slate-300 px-3 py-2"
                                    >
                                        2026-001
                                    </td>

                                    <td
                                        class="border-r border-b border-slate-300 px-3 py-2"
                                    >
                                        DELA CRUZ
                                    </td>

                                    <td
                                        class="border-r border-b border-slate-300 px-3 py-2"
                                    >
                                        JUAN
                                    </td>

                                    <td
                                        class="border-r border-b border-slate-300 bg-[#FAFAFA] px-3 py-2"
                                        title="Middle Name may be left blank"
                                    >
                                        &nbsp;
                                    </td>

                                    <td
                                        class="border-r border-b border-slate-300 px-3 py-2"
                                    >
                                        MALE
                                    </td>

                                    <td
                                        class="border-r border-b border-slate-300 px-3 py-2"
                                    >
                                        2026
                                    </td>

                                    <td
                                        class="border-r border-b border-slate-300 px-3 py-2"
                                    >
                                        DECK
                                    </td>

                                    <td
                                        class="border-r border-b border-slate-300 px-3 py-2"
                                    >
                                        GMET
                                    </td>

                                    <td
                                        class="border-r-0 border-b border-slate-300 px-3 py-2"
                                    >
                                        juan.delacruz@example.com
                                    </td>
                                </tr>

                                <tr>
                                    <th
                                        class="border-r border-b border-slate-300 bg-[#E7E6E6] text-center text-xs font-semibold text-slate-700"
                                    >
                                        3
                                    </th>

                                    <td
                                        class="border-r border-b border-slate-300 px-3 py-2"
                                    >
                                        2026-002
                                    </td>

                                    <td
                                        class="border-r border-b border-slate-300 px-3 py-2"
                                    >
                                        SANTOS
                                    </td>

                                    <td
                                        class="border-r border-b border-slate-300 px-3 py-2"
                                    >
                                        MARIA
                                    </td>

                                    <td
                                        class="border-r border-b border-slate-300 px-3 py-2"
                                    >
                                        LUISA
                                    </td>

                                    <td
                                        class="border-r border-b border-slate-300 px-3 py-2"
                                    >
                                        FEMALE
                                    </td>

                                    <td
                                        class="border-r border-b border-slate-300 px-3 py-2"
                                    >
                                        2026
                                    </td>

                                    <td
                                        class="border-r border-b border-slate-300 px-3 py-2"
                                    >
                                        ENGINE
                                    </td>

                                    <td
                                        class="border-r border-b border-slate-300 px-3 py-2"
                                    >
                                        ISF
                                    </td>

                                    <td
                                        class="border-r-0 border-b border-slate-300 px-3 py-2"
                                    >
                                        maria.santos@example.com
                                    </td>
                                </tr>

                                <tr>
                                    <th
                                        class="border-r border-b border-slate-300 bg-[#E7E6E6] text-center text-xs font-semibold text-slate-700"
                                    >
                                        4
                                    </th>

                                    <td
                                        v-for="column in 9"
                                        :key="column"
                                        class="h-9 border-r border-b border-slate-300 bg-white last:border-r-0"
                                    >
                                        &nbsp;
                                    </td>
                                </tr>

                                <tr>
                                    <th
                                        class="border-r border-slate-300 bg-[#E7E6E6] text-center text-xs font-semibold text-slate-700"
                                    >
                                        5
                                    </th>

                                    <td
                                        v-for="column in 9"
                                        :key="column"
                                        class="h-9 border-r border-slate-300 bg-white last:border-r-0"
                                    >
                                        &nbsp;
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                            <div
                                class="flex h-9 items-end border-t border-slate-300 bg-[#F3F3F3] pl-14"
                            >
                                <div
                                    class="flex h-9 min-w-42 items-center justify-center border-x border-t-2 border-[#217346] bg-white px-4 text-xs font-semibold text-[#217346]"
                                >
                                    Students
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    class="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-slate-300 bg-slate-50 px-4 py-3 text-xs text-slate-600"
                >
                    <span>
                        <strong>Required:</strong>
                        School ID No., Last Name, First Name, Gender, CCI Year,
                        Department, TRB Type, Email
                    </span>

                    <span>
                        <strong>May be blank:</strong>
                        Middle Name
                    </span>
                </div>
            </section>

            <form
                class="space-y-5"
                @submit.prevent="importStudents"
            >
                <div
                    class="rounded-xl border-2 border-dashed p-5 transition"
                    :class="[
                        isDraggingFile
                            ? 'border-blue-400 bg-blue-50'
                            : file
                              ? 'border-emerald-300 bg-emerald-50'
                              : 'border-slate-300 bg-slate-50',
                    ]"
                    @dragover="
                        handleFileDragOver
                    "
                    @dragenter="
                        handleFileDragOver
                    "
                    @dragleave="
                        handleFileDragLeave
                    "
                    @drop="
                        handleFileDrop
                    "
                >
                    <input
                        id="student-workbook"
                        ref="fileInput"
                        type="file"
                        accept=".xls,.xlsx"
                        :disabled="importing"
                        class="hidden"
                        @change="
                            selectFile
                        "
                    />

                    <div
                        v-if="!file"
                        class="flex flex-col items-center justify-center py-3 text-center"
                    >
                        <div
                            class="mb-3 flex size-12 items-center justify-center rounded-xl bg-blue-100 text-blue-700"
                        >
                            <i
                                class="pi pi-cloud-upload text-xl"
                            ></i>
                        </div>

                        <p
                            class="font-semibold text-slate-800"
                        >
                            Drag and drop your completed Excel file here
                        </p>

                        <p
                            class="mt-1 text-sm text-slate-500"
                        >
                            or choose the file from your computer
                        </p>

                        <Button
                            type="button"
                            label="Choose Excel File"
                            icon="pi pi-folder-open"
                            severity="info"
                            outlined
                            class="mt-4"
                            :disabled="importing"
                            @click="
                                openFilePicker
                            "
                        />

                        <p
                            class="mt-3 text-xs text-slate-400"
                        >
                            XLS or XLSX · Any filename · Maximum 5 MB
                        </p>
                    </div>

                    <div
                        v-else
                        class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div
                            class="flex min-w-0 items-center gap-3"
                        >
                            <div
                                class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700"
                            >
                                <i
                                    class="pi pi-file-excel text-lg"
                                ></i>
                            </div>

                            <div class="min-w-0">
                                <p
                                    class="truncate text-sm font-semibold text-emerald-900"
                                >
                                    {{ file.name }}
                                </p>

                                <p
                                    class="mt-1 text-xs text-emerald-700"
                                >
                                    {{ selectedFileSize }}
                                    · Ready for confirmation
                                </p>
                            </div>
                        </div>

                        <Button
                            type="button"
                            label="Change File"
                            icon="pi pi-refresh"
                            severity="secondary"
                            outlined
                            :disabled="importing"
                            @click="
                                openFilePicker
                            "
                        />
                    </div>
                </div>

                <div
                    class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50/70 p-4"
                >
                    <input
                        id="student-update-confirmation"
                        v-model="confirmed"
                        type="checkbox"
                        class="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 accent-[#377EC0]"
                        :disabled="importing"
                    />

                    <label
                        for="student-update-confirmation"
                        class="cursor-pointer text-sm text-amber-900"
                    >
                        <span class="font-semibold">
                            Confirm before importing:
                        </span>

                        If a School ID No. already exists, the student's name,
                        gender, email, department, CCI Year and TRB Type will be updated.
                        Existing login credentials will not be changed.
                    </label>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <Button
                        type="submit"
                        label="Import Students"
                        icon="pi pi-upload"
                        severity="success"
                        :loading="importing"
                        :disabled="!canImport"
                    />

                    <Button
                        type="button"
                        label="Download Excel Template"
                        icon="pi pi-download"
                        severity="info"
                        outlined
                        :loading="downloadingTemplate"
                        :disabled="importing || downloadingTemplate"
                        @click="downloadTemplate"
                    />

                    <Button
                        type="button"
                        label="Clear"
                        icon="pi pi-times"
                        severity="secondary"
                        outlined
                        :disabled="importing"
                        @click="clearForm"
                    />

                    <span
                        v-if="importing"
                        class="self-center text-sm text-slate-500"
                    >
                        Please keep this page open until import finishes.
                    </span>
                </div>

                <div
                    v-if="!importing"
                    class="flex items-center gap-2 text-xs"
                    :class="
                        canImport
                            ? 'text-emerald-700'
                            : 'text-slate-500'
                    "
                >
                    <i
                        :class="
                            canImport
                                ? 'pi pi-check-circle'
                                : 'pi pi-info-circle'
                        "
                    ></i>

                    <span>
                        {{
                            canImport
                                ? 'Ready to import.'
                                : 'Choose an XLS or XLSX file and confirm the update notice to enable Import Students.'
                        }}
                    </span>
                </div>
            </form>
        </section>

        <section
            v-if="issues.length"
            class="overflow-x-auto rounded-xl border border-red-200 bg-white p-4"
        >
            <h2 class="mb-3 font-semibold text-red-700">
                Import Issues ({{ issueCount }})
            </h2>

            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b">
                        <th class="p-2">Worksheet</th>
                        <th class="p-2">Row</th>
                        <th class="p-2">What needs attention</th>
                    </tr>
                </thead>

                <tbody>
                    <tr
                        v-for="(issue, index) in issues"
                        :key="index"
                        class="border-b border-slate-100"
                    >
                        <td class="p-2">{{ issue.sheet }}</td>
                        <td class="p-2">{{ issue.row }}</td>
                        <td class="p-2">{{ issue.message }}</td>
                    </tr>
                </tbody>
            </table>

            <p
                v-if="issueCount > issues.length"
                class="mt-3 text-sm text-slate-500"
            >
                Showing the first {{ issues.length }} issues.
            </p>
        </section>

        <section
            v-if="result?.created_accounts?.length"
            class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:p-6"
        >
            <div
                class="mb-4 flex flex-wrap items-center justify-between gap-3"
            >
                <div>
                    <h2 class="font-semibold text-slate-800">
                        Newly Created Student Accounts
                    </h2>

                    <p class="mt-1 text-sm text-slate-500">
                        These generated passwords are shown from this import response.
                        Copy them before leaving or refreshing the page.
                    </p>
                </div>

                <Button
                    type="button"
                    label="Copy All Credentials"
                    icon="pi pi-copy"
                    severity="info"
                    outlined
                    @click="copyAllAccounts"
                />
            </div>

            <div class="overflow-x-auto">
                <table
                    class="w-full min-w-[900px] text-left text-sm"
                >
                    <thead>
                        <tr
                            class="border-b border-slate-200 text-slate-600"
                        >
                            <th class="p-3">Row</th>
                            <th class="p-3">School ID</th>
                            <th class="p-3">Student</th>
                            <th class="p-3">Email</th>
                            <th class="p-3">Login Name</th>
                            <th class="p-3">Password</th>
                            <th class="p-3">Email Status</th>
                            <th class="p-3 text-right">Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                            v-for="account in result.created_accounts"
                            :key="`${account.row}-${account.school_id_no}`"
                            class="border-b border-slate-100"
                        >
                            <td class="p-3">{{ account.row }}</td>

                            <td class="p-3 font-medium text-slate-700">
                                {{ account.school_id_no }}
                            </td>

                            <td class="p-3">
                                {{ account.name }}
                            </td>

                            <td class="p-3">
                                {{ account.email || '—' }}
                            </td>

                            <td class="p-3 font-mono">
                                {{ account.login_name }}
                            </td>

                            <td class="p-3 font-mono font-semibold">
                                {{ account.password }}
                            </td>

                            <td class="p-3">
                                <span
                                    v-if="account.email_status === 'sent'"
                                    class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700"
                                >
                                    <i class="pi pi-check-circle"></i>
                                    Sent
                                </span>

                                <span
                                    v-else-if="account.email_status === 'failed'"
                                    class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700"
                                >
                                    <i class="pi pi-exclamation-circle"></i>
                                    Failed
                                </span>

                                <span
                                    v-else
                                    class="text-xs text-slate-400"
                                >
                                    —
                                </span>
                            </td>

                            <td class="p-3 text-right">
                                <Button
                                    type="button"
                                    label="Copy"
                                    icon="pi pi-copy"
                                    severity="secondary"
                                    outlined
                                    size="small"
                                    @click="copyAccount(account)"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
