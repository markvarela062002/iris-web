<script setup lang="ts">
import {
    Head,
    router,
} from '@inertiajs/vue3';
import axios from 'axios';
import Avatar from 'primevue/avatar';
import Button from 'primevue/button';
import Card from 'primevue/card';
import DatePicker from 'primevue/datepicker';
import FileUpload from 'primevue/fileupload';
import PrimeImage from 'primevue/image';
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
    ref,
} from 'vue';

const props = defineProps<{
    journalId: string;
}>();

defineOptions({
    inheritAttrs: false,

    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: '/dashboard',
            },
            {
                title: 'Daily Journals',
                href: '/monitoring/daily-journals',
            },
            {
                title: 'Edit Journal',
                href: '#',
            },
        ],
    },
});

type JournalRecord = {
    id: string;
    person_id: string;
    school_id_no: string | null;
    fname: string | null;
    mname: string | null;
    lname: string | null;
    gender: string | null;
    department: string | null;
    student_name: string;
    date_journal: string | null;
    journal_time: string | null;
    journal_time_to: string | null;
    duty_hours: string;
    vessel_name: string | null;
    ship_lat: string | null;
    ship_long: string | null;
    ship_vicinity: string | null;
    port_depart: string | null;
    port_dest: string | null;
    pos_fix: string | null;
    course_speed: string | null;
    fo_rob: string | null;
    fo_dob: string | null;
    fo_lob: string | null;
    fo_cons: string | null;
    do_cons: string | null;
    average_rpm: string | null;
    average_speed: string | null;
    activities: string | null;
    key_areas: string | null;
    sto_name: string | null;
    file_name: string | null;
    gdrive_link: string | null;
    evidence_url: string | null;
    evidence_source: string | null;
    officer_signature_file: string | null;
    officer_signature_url: string | null;
    validated: boolean;
    status: string;
};

type JournalResponse = {
    message?: string;
    data: JournalRecord;
};

type JournalForm = {
    date_journal: Date | null;
    journal_time: Date | null;
    journal_time_to: Date | null;
    vessel_name: string;
    ship_lat: string;
    ship_long: string;
    ship_vicinity: string;
    port_depart: string;
    port_dest: string;
    pos_fix: string;
    course_speed: string;
    fo_rob: string;
    fo_dob: string;
    fo_lob: string;
    fo_cons: string;
    do_cons: string;
    average_rpm: string;
    average_speed: string;
    activities: string;
    key_areas: string;
    sto_name: string;
};

type FileUploadSelectEvent = {
    files?: File[];
};

type DepartmentSeverity =
    | 'success'
    | 'info'
    | 'secondary';

const API =
    '/api/v1/monitoring/daily-journals';

const toast = useToast();

const loading = ref(false);
const saving = ref(false);
const evidenceUploading = ref(false);
const signing = ref(false);
const pageError = ref('');

const journal =
    ref<JournalRecord | null>(null);

const selectedEvidence =
    ref<File | null>(null);

const evidenceUploadKey = ref(0);

let requestController:
    | AbortController
    | null = null;

const signatureCanvas =
    ref<HTMLCanvasElement | null>(null);

const signatureDrawing = ref(false);
const signatureHasInk = ref(false);
const signaturePointerId =
    ref<number | null>(null);

function emptyForm(): JournalForm {
    return {
        date_journal: null,
        journal_time: null,
        journal_time_to: null,
        vessel_name: '',
        ship_lat: '',
        ship_long: '',
        ship_vicinity: '',
        port_depart: '',
        port_dest: '',
        pos_fix: '',
        course_speed: '',
        fo_rob: '',
        fo_dob: '',
        fo_lob: '',
        fo_cons: '',
        do_cons: '',
        average_rpm: '',
        average_speed: '',
        activities: '',
        key_areas: '',
        sto_name: '',
    };
}

const form = ref<JournalForm>(
    emptyForm(),
);

const isValidated = computed(
    () => journal.value?.validated === true,
);

const isDeck = computed(
    () =>
        String(
            journal.value?.department ?? '',
        )
            .trim()
            .toUpperCase() === 'DECK',
);

const studentName = computed(() => {
    return (
        journal.value?.student_name ||
        'DAILY JOURNAL'
    );
});

const studentInitials = computed(() => {
    const first = String(
        journal.value?.fname ?? '',
    )
        .trim()
        .charAt(0);

    const last = String(
        journal.value?.lname ?? '',
    )
        .trim()
        .charAt(0);

    return (
        `${first}${last}`.toUpperCase() ||
        'ST'
    );
});

const defaultAvatarUrl = computed<
    string | null
>(() => {
    const gender = String(
        journal.value?.gender ?? '',
    )
        .trim()
        .toUpperCase();

    if (
        gender === 'MALE' ||
        gender === 'M'
    ) {
        return '/images/male-cadet.png';
    }

    if (
        gender === 'FEMALE' ||
        gender === 'F'
    ) {
        return '/images/female-cadet.png';
    }

    return null;
});

const activityLabel = computed(() => {
    return isDeck.value
        ? 'Bridge Watchkeeping Activities, Specific Duties and Events During the Watch'
        : 'Engine-Room Watchkeeping Activities, Specific Duties and Events During the Watch';
});

const isEvidenceImage = computed(() => {
    const filename = String(
        journal.value?.file_name ?? '',
    )
        .trim()
        .toLowerCase();

    return /\.(jpg|jpeg|png|gif|webp)$/i.test(
        filename,
    );
});

function departmentSeverity(): DepartmentSeverity {
    const department = String(
        journal.value?.department ?? '',
    )
        .trim()
        .toUpperCase();

    if (department === 'DECK') {
        return 'success';
    }

    if (department === 'ENGINE') {
        return 'info';
    }

    return 'secondary';
}

function departmentIcon(): string {
    const department = String(
        journal.value?.department ?? '',
    )
        .trim()
        .toUpperCase();

    if (department === 'DECK') {
        return 'pi pi-compass';
    }

    if (department === 'ENGINE') {
        return 'pi pi-cog';
    }

    return 'pi pi-building';
}

function stringValue(
    value: unknown,
): string {
    return String(value ?? '').trim();
}

function parseDate(
    value: unknown,
): Date | null {
    const text = stringValue(value);

    if (!text) {
        return null;
    }

    const date = new Date(
        `${text.substring(0, 10)}T00:00:00`,
    );

    return Number.isNaN(date.getTime())
        ? null
        : date;
}

function parseTime(
    value: unknown,
): Date | null {
    const text = stringValue(value);

    if (!text) {
        return null;
    }

    const [hours, minutes] =
        text.split(':').map(Number);

    if (
        !Number.isFinite(hours) ||
        !Number.isFinite(minutes)
    ) {
        return null;
    }

    const date = new Date();
    date.setHours(hours, minutes, 0, 0);

    return date;
}

function formatDateParameter(
    value: Date | null,
): string | null {
    if (!value) {
        return null;
    }

    const year = value.getFullYear();
    const month = String(
        value.getMonth() + 1,
    ).padStart(2, '0');
    const day = String(
        value.getDate(),
    ).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function formatTimeParameter(
    value: Date | null,
): string | null {
    if (!value) {
        return null;
    }

    const hours = String(
        value.getHours(),
    ).padStart(2, '0');

    const minutes = String(
        value.getMinutes(),
    ).padStart(2, '0');

    return `${hours}:${minutes}`;
}

function loadIntoForm(
    record: JournalRecord,
): void {
    form.value = {
        date_journal:
            parseDate(record.date_journal),
        journal_time:
            parseTime(record.journal_time),
        journal_time_to:
            parseTime(record.journal_time_to),
        vessel_name:
            stringValue(record.vessel_name),
        ship_lat:
            stringValue(record.ship_lat),
        ship_long:
            stringValue(record.ship_long),
        ship_vicinity:
            stringValue(record.ship_vicinity),
        port_depart:
            stringValue(record.port_depart),
        port_dest:
            stringValue(record.port_dest),
        pos_fix:
            stringValue(record.pos_fix),
        course_speed:
            stringValue(record.course_speed),
        fo_rob:
            stringValue(record.fo_rob),
        fo_dob:
            stringValue(record.fo_dob),
        fo_lob:
            stringValue(record.fo_lob),
        fo_cons:
            stringValue(record.fo_cons),
        do_cons:
            stringValue(record.do_cons),
        average_rpm:
            stringValue(record.average_rpm),
        average_speed:
            stringValue(record.average_speed),
        activities:
            stringValue(record.activities),
        key_areas:
            stringValue(record.key_areas),
        sto_name:
            stringValue(record.sto_name),
    };
}

async function loadJournal(): Promise<void> {
    requestController?.abort();

    const controller =
        new AbortController();

    requestController = controller;
    loading.value = true;
    pageError.value = '';

    try {
        const response =
            await axios.get<JournalResponse>(
                `${API}/${encodeURIComponent(
                    props.journalId,
                )}`,
                {
                    signal:
                        controller.signal,
                    headers: {
                        Accept:
                            'application/json',
                        'X-Requested-With':
                            'XMLHttpRequest',
                    },
                    withCredentials: true,
                },
            );

        journal.value =
            response.data.data;

        loadIntoForm(
            response.data.data,
        );

        selectedEvidence.value = null;
        evidenceUploadKey.value += 1;
        clearSignature();
    } catch (error: unknown) {
        if (
            axios.isCancel(error) ||
            (
                axios.isAxiosError(error) &&
                error.code ===
                    'ERR_CANCELED'
            )
        ) {
            return;
        }

        pageError.value =
            getErrorMessage(
                error,
                'Unable to load the daily journal.',
            );
    } finally {
        if (
            requestController ===
            controller
        ) {
            loading.value = false;
        }
    }
}

function buildPayload() {
    return {
        date_journal:
            formatDateParameter(
                form.value.date_journal,
            ),
        journal_time:
            formatTimeParameter(
                form.value.journal_time,
            ),
        journal_time_to:
            formatTimeParameter(
                form.value.journal_time_to,
            ),
        vessel_name:
            form.value.vessel_name.trim(),
        ship_lat:
            form.value.ship_lat.trim(),
        ship_long:
            form.value.ship_long.trim(),
        ship_vicinity:
            form.value.ship_vicinity.trim(),
        port_depart:
            form.value.port_depart.trim(),
        port_dest:
            form.value.port_dest.trim(),
        pos_fix:
            form.value.pos_fix.trim(),
        course_speed:
            form.value.course_speed.trim(),
        fo_rob:
            form.value.fo_rob.trim(),
        fo_dob:
            form.value.fo_dob.trim(),
        fo_lob:
            form.value.fo_lob.trim(),
        fo_cons:
            form.value.fo_cons.trim(),
        do_cons:
            form.value.do_cons.trim(),
        average_rpm:
            form.value.average_rpm.trim(),
        average_speed:
            form.value.average_speed.trim(),
        activities:
            form.value.activities.trim(),
        key_areas:
            form.value.key_areas.trim(),
        sto_name:
            form.value.sto_name.trim(),
    };
}

async function saveJournal(
    showSuccessToast = true,
): Promise<boolean> {
    if (!journal.value) {
        return false;
    }

    saving.value = true;
    pageError.value = '';

    try {
        const response =
            await axios.put<JournalResponse>(
                `${API}/${encodeURIComponent(
                    props.journalId,
                )}`,
                buildPayload(),
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

        journal.value =
            response.data.data;

        loadIntoForm(
            response.data.data,
        );

        if (showSuccessToast) {
            toast.add({
                severity: 'success',
                summary: 'Journal Saved',
                detail:
                    response.data.message ||
                    'Daily journal saved successfully.',
                life: 3500,
            });
        }

        return true;
    } catch (error: unknown) {
        pageError.value =
            getErrorMessage(
                error,
                'Unable to save the daily journal.',
            );

        return false;
    } finally {
        saving.value = false;
    }
}

function handleEvidenceSelect(
    event: FileUploadSelectEvent,
): void {
    selectedEvidence.value =
        event.files?.[0] ?? null;
}

async function uploadEvidence(): Promise<void> {
    if (!selectedEvidence.value) {
        return;
    }

    evidenceUploading.value = true;
    pageError.value = '';

    const formData = new FormData();
    formData.append(
        'evidence',
        selectedEvidence.value,
    );

    try {
        const response =
            await axios.post<JournalResponse>(
                `${API}/${encodeURIComponent(
                    props.journalId,
                )}/evidence`,
                formData,
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

        journal.value =
            response.data.data;

        loadIntoForm(
            response.data.data,
        );

        selectedEvidence.value = null;
        evidenceUploadKey.value += 1;

        toast.add({
            severity: 'success',
            summary: 'Evidence Updated',
            detail:
                response.data.message ||
                'Objective evidence uploaded successfully.',
            life: 3500,
        });
    } catch (error: unknown) {
        pageError.value =
            getErrorMessage(
                error,
                'Unable to upload the objective evidence.',
            );
    } finally {
        evidenceUploading.value = false;
    }
}

function openEvidence(): void {
    const url = String(
        journal.value?.evidence_url ?? '',
    ).trim();

    if (!url) {
        return;
    }

    window.open(
        url,
        '_blank',
        'noopener,noreferrer',
    );
}

function openOfficerSignature(): void {
    const url = String(
        journal.value?.officer_signature_url ?? '',
    ).trim();

    if (!url) {
        return;
    }

    window.open(
        url,
        '_blank',
        'noopener,noreferrer',
    );
}

function pointerPosition(
    event: PointerEvent,
): {
    x: number;
    y: number;
} | null {
    const canvas =
        signatureCanvas.value;

    if (!canvas) {
        return null;
    }

    const rectangle =
        canvas.getBoundingClientRect();

    return {
        x:
            (event.clientX -
                rectangle.left) *
            (canvas.width /
                rectangle.width),
        y:
            (event.clientY -
                rectangle.top) *
            (canvas.height /
                rectangle.height),
    };
}

function beginSignature(
    event: PointerEvent,
): void {
    if (isValidated.value) {
        return;
    }

    const canvas =
        signatureCanvas.value;

    const position =
        pointerPosition(event);

    if (!canvas || !position) {
        return;
    }

    signatureDrawing.value = true;
    signaturePointerId.value =
        event.pointerId;

    canvas.setPointerCapture(
        event.pointerId,
    );

    const context =
        canvas.getContext('2d');

    if (!context) {
        return;
    }

    context.beginPath();
    context.moveTo(
        position.x,
        position.y,
    );
}

function drawSignature(
    event: PointerEvent,
): void {
    if (
        !signatureDrawing.value ||
        signaturePointerId.value !==
            event.pointerId
    ) {
        return;
    }

    const canvas =
        signatureCanvas.value;

    const position =
        pointerPosition(event);

    if (!canvas || !position) {
        return;
    }

    const context =
        canvas.getContext('2d');

    if (!context) {
        return;
    }

    context.lineWidth = 4;
    context.lineCap = 'round';
    context.lineJoin = 'round';
    context.strokeStyle = '#0f172a';
    context.lineTo(
        position.x,
        position.y,
    );
    context.stroke();

    signatureHasInk.value = true;
}

function endSignature(
    event: PointerEvent,
): void {
    if (
        signaturePointerId.value !==
        event.pointerId
    ) {
        return;
    }

    const canvas =
        signatureCanvas.value;

    signatureDrawing.value = false;
    signaturePointerId.value = null;

    if (
        canvas?.hasPointerCapture(
            event.pointerId,
        )
    ) {
        canvas.releasePointerCapture(
            event.pointerId,
        );
    }
}

function clearSignature(): void {
    const canvas =
        signatureCanvas.value;

    if (canvas) {
        const context =
            canvas.getContext('2d');

        context?.clearRect(
            0,
            0,
            canvas.width,
            canvas.height,
        );
    }

    signatureHasInk.value = false;
    signatureDrawing.value = false;
    signaturePointerId.value = null;
}

function signatureBlob(): Promise<Blob> {
    return new Promise(
        (
            resolve,
            reject,
        ) => {
            const canvas =
                signatureCanvas.value;

            if (!canvas) {
                reject(
                    new Error(
                        'Signature canvas is unavailable.',
                    ),
                );

                return;
            }

            canvas.toBlob(
                (blob) => {
                    if (!blob) {
                        reject(
                            new Error(
                                'Unable to create the STO signature image.',
                            ),
                        );

                        return;
                    }

                    resolve(blob);
                },
                'image/png',
                1,
            );
        },
    );
}

async function signJournal(): Promise<void> {
    if (
        isValidated.value ||
        signing.value
    ) {
        return;
    }

    if (!form.value.sto_name.trim()) {
        pageError.value =
            'Enter the Supervising Officer name before signing.';

        return;
    }

    if (!signatureHasInk.value) {
        pageError.value =
            'The Supervising Officer signature is required.';

        return;
    }

    pageError.value = '';
    signing.value = true;

    try {
        const saved =
            await saveJournal(false);

        if (!saved) {
            return;
        }

        const blob =
            await signatureBlob();

        const signatureFile =
            new File(
                [blob],
                `sto-signature-${props.journalId}.png`,
                {
                    type: 'image/png',
                    lastModified:
                        Date.now(),
                },
            );

        const formData =
            new FormData();

        formData.append(
            'sto_name',
            form.value.sto_name.trim(),
        );

        formData.append(
            'signature',
            signatureFile,
        );

        const response =
            await axios.post<JournalResponse>(
                `${API}/${encodeURIComponent(
                    props.journalId,
                )}/signature`,
                formData,
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

        journal.value =
            response.data.data;

        loadIntoForm(
            response.data.data,
        );

        clearSignature();

        toast.add({
            severity: 'success',
            summary: 'Journal Signed',
            detail:
                response.data.message ||
                'STO signature saved successfully.',
            life: 4500,
        });
    } catch (error: unknown) {
        pageError.value =
            getErrorMessage(
                error,
                'Unable to save the STO signature.',
            );
    } finally {
        signing.value = false;
    }
}

function goBack(): void {
    router.visit(
        '/monitoring/daily-journals',
    );
}

function getErrorMessage(
    error: unknown,
    fallback: string,
): string {
    if (!axios.isAxiosError(error)) {
        return fallback;
    }

    const responseData =
        error.response?.data as
            | {
                  message?: string;
                  errors?: Record<
                      string,
                      string[]
                  >;
              }
            | undefined;

    const firstValidationError =
        responseData?.errors
            ? Object.values(
                  responseData.errors,
              )[0]?.[0]
            : undefined;

    return (
        firstValidationError ||
        responseData?.message ||
        fallback
    );
}

onMounted(() => {
    void loadJournal();
});

onBeforeUnmount(() => {
    requestController?.abort();
});
</script>

<template>
    <Head title="Edit Daily Journal" />

    <Toast position="top-right" />

    <div
        class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5"
    >
        <Message
            v-if="pageError"
            severity="error"
            closable
            @close="pageError = ''"
        >
            {{ pageError }}
        </Message>

        <Card
            class="!rounded-2xl !border !border-slate-200 !shadow-sm [&_.p-card-body]:!p-5 [&_.p-card-content]:!p-0"
        >
            <template #content>
                <div
                    class="flex flex-col gap-5 xl:flex-row xl:items-center xl:justify-between"
                >
                    <div
                        class="flex items-center gap-4"
                    >
                        <Avatar
                            v-if="defaultAvatarUrl"
                            :image="defaultAvatarUrl"
                            :aria-label="studentName"
                            shape="circle"
                            size="xlarge"
                            class="shrink-0"
                        />

                        <Avatar
                            v-else
                            :label="studentInitials"
                            shape="circle"
                            size="xlarge"
                            class="shrink-0 !bg-blue-50 !font-bold !text-blue-600"
                        />

                        <div class="min-w-0">
                            <p
                                class="text-xs font-semibold tracking-wide text-slate-400 uppercase"
                            >
                                Daily Journal
                            </p>

                            <h1
                                class="mt-1 truncate text-xl font-bold text-slate-800"
                            >
                                {{ studentName }}
                            </h1>

                            <div
                                class="mt-2 flex flex-wrap items-center gap-2"
                            >
                                <PrimeTag
                                    :value="journal?.school_id_no || 'No School ID'"
                                    icon="pi pi-id-card"
                                    severity="info"
                                    rounded
                                />

                                <PrimeTag
                                    :value="journal?.department || 'No Department'"
                                    :severity="departmentSeverity()"
                                    :icon="departmentIcon()"
                                    rounded
                                />

                                <PrimeTag
                                    :value="isValidated ? 'Signed' : 'Pending'"
                                    :severity="isValidated ? 'success' : 'secondary'"
                                    :icon="isValidated ? 'pi pi-check-circle' : 'pi pi-clock'"
                                    rounded
                                />

                                <PrimeTag
                                    :value="journal?.duty_hours || '0 hr 0 min'"
                                    icon="pi pi-clock"
                                    severity="info"
                                    rounded
                                />
                            </div>
                        </div>
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-2"
                    >
                        <Button
                            type="button"
                            label="Back to Journals"
                            icon="pi pi-arrow-left"
                            severity="secondary"
                            variant="outlined"
                            :disabled="saving || signing"
                            @click="goBack"
                        />

                        <Button
                            type="button"
                            label="Save Changes"
                            icon="pi pi-save"
                            severity="success"
                            :loading="saving"
                            :disabled="loading || signing"
                            @click="saveJournal()"
                        />
                    </div>
                </div>
            </template>
        </Card>

        <div
            v-if="loading"
            class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm font-medium text-slate-500 shadow-sm"
        >
            <i class="pi pi-spin pi-spinner mr-2"></i>
            Loading daily journal...
        </div>

        <template v-else-if="journal">
            <Card
                class="!rounded-2xl !border !border-slate-200 !shadow-sm [&_.p-card-body]:!p-5 [&_.p-card-content]:!p-0"
            >
                <template #title>
                    <div
                        class="flex items-center gap-2 text-slate-800"
                    >
                        <i class="pi pi-calendar text-[#377EC0]"></i>
                        Journal Details
                    </div>
                </template>

                <template #content>
                    <div
                        class="grid gap-4 md:grid-cols-2 xl:grid-cols-4"
                    >
                        <div class="flex flex-col gap-2">
                            <label class="text-sm font-semibold text-slate-700">
                                Journal Date
                                <span class="text-red-500">*</span>
                            </label>

                            <DatePicker
                                v-model="form.date_journal"
                                date-format="M d, yy"
                                show-icon
                                icon-display="input"
                                :manual-input="false"
                                :disabled="saving || signing"
                                class="w-full"
                                input-class="w-full"
                            />
                        </div>

                        <div class="flex flex-col gap-2">
                            <label class="text-sm font-semibold text-slate-700">
                                From Time
                                <span class="text-red-500">*</span>
                            </label>

                            <DatePicker
                                v-model="form.journal_time"
                                time-only
                                hour-format="12"
                                show-icon
                                icon-display="input"
                                :manual-input="false"
                                :disabled="saving || signing"
                                class="w-full"
                                input-class="w-full"
                            />
                        </div>

                        <div class="flex flex-col gap-2">
                            <label class="text-sm font-semibold text-slate-700">
                                To Time
                                <span class="text-red-500">*</span>
                            </label>

                            <DatePicker
                                v-model="form.journal_time_to"
                                time-only
                                hour-format="12"
                                show-icon
                                icon-display="input"
                                :manual-input="false"
                                :disabled="saving || signing"
                                class="w-full"
                                input-class="w-full"
                            />
                        </div>

                        <div class="flex flex-col gap-2">
                            <label class="text-sm font-semibold text-slate-700">
                                Name of Vessel
                                <span class="text-red-500">*</span>
                            </label>

                            <InputText
                                v-model="form.vessel_name"
                                class="w-full"
                                :disabled="saving || signing"
                            />
                        </div>
                    </div>
                </template>
            </Card>

            <Card
                class="!rounded-2xl !border !border-slate-200 !shadow-sm [&_.p-card-body]:!p-5 [&_.p-card-content]:!p-0"
            >
                <template #title>
                    <div
                        class="flex items-center gap-2 text-slate-800"
                    >
                        <i class="pi pi-map-marker text-[#377EC0]"></i>
                        {{ isDeck ? 'Navigation & Vessel Information' : 'Engine & Vessel Information' }}
                    </div>
                </template>

                <template #content>
                    <template v-if="isDeck">
                        <div class="space-y-4">
                            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                <div class="flex flex-col gap-2">
                                    <label class="text-sm font-semibold text-slate-700">Latitude</label>
                                    <InputText v-model="form.ship_lat" :disabled="saving || signing" class="w-full" />
                                </div>
                                <div class="flex flex-col gap-2">
                                    <label class="text-sm font-semibold text-slate-700">Longitude</label>
                                    <InputText v-model="form.ship_long" :disabled="saving || signing" class="w-full" />
                                </div>
                                <div class="flex flex-col gap-2">
                                    <label class="text-sm font-semibold text-slate-700">Vicinity</label>
                                    <InputText v-model="form.ship_vicinity" :disabled="saving || signing" class="w-full" />
                                </div>
                            </div>

                            <div class="grid gap-4 md:grid-cols-2">
                                <div class="flex flex-col gap-2">
                                    <label class="text-sm font-semibold text-slate-700">Port Departure</label>
                                    <InputText v-model="form.port_depart" :disabled="saving || signing" class="w-full" />
                                </div>
                                <div class="flex flex-col gap-2">
                                    <label class="text-sm font-semibold text-slate-700">Port Destination</label>
                                    <InputText v-model="form.port_dest" :disabled="saving || signing" class="w-full" />
                                </div>
                            </div>

                            <div class="grid gap-4 md:grid-cols-2">
                                <div class="flex flex-col gap-2">
                                    <label class="text-sm font-semibold text-slate-700">Position-Fixing Method</label>
                                    <InputText v-model="form.pos_fix" :disabled="saving || signing" class="w-full" />
                                </div>
                                <div class="flex flex-col gap-2">
                                    <label class="text-sm font-semibold text-slate-700">Course and Speed</label>
                                    <InputText v-model="form.course_speed" :disabled="saving || signing" class="w-full" />
                                </div>
                            </div>

                            <div class="grid gap-4 md:grid-cols-3">
                                <div class="flex flex-col gap-2">
                                    <label class="text-sm font-semibold text-slate-700">F.O ROB</label>
                                    <InputText v-model="form.fo_rob" :disabled="saving || signing" class="w-full" />
                                </div>
                                <div class="flex flex-col gap-2">
                                    <label class="text-sm font-semibold text-slate-700">F.O DOB</label>
                                    <InputText v-model="form.fo_dob" :disabled="saving || signing" class="w-full" />
                                </div>
                                <div class="flex flex-col gap-2">
                                    <label class="text-sm font-semibold text-slate-700">F.O LOB</label>
                                    <InputText v-model="form.fo_lob" :disabled="saving || signing" class="w-full" />
                                </div>
                            </div>
                        </div>
                    </template>

                    <template v-else>
                        <div class="space-y-4">
                            <div class="grid gap-4 md:grid-cols-2">
                                <div class="flex flex-col gap-2">
                                    <label class="text-sm font-semibold text-slate-700">Port Departure</label>
                                    <InputText v-model="form.port_depart" :disabled="saving || signing" class="w-full" />
                                </div>
                                <div class="flex flex-col gap-2">
                                    <label class="text-sm font-semibold text-slate-700">Port Destination</label>
                                    <InputText v-model="form.port_dest" :disabled="saving || signing" class="w-full" />
                                </div>
                            </div>

                            <div class="grid gap-4 md:grid-cols-2">
                                <div class="flex flex-col gap-2">
                                    <label class="text-sm font-semibold text-slate-700">F.O. Consumption</label>
                                    <InputText v-model="form.fo_cons" :disabled="saving || signing" class="w-full" />
                                </div>
                                <div class="flex flex-col gap-2">
                                    <label class="text-sm font-semibold text-slate-700">D.O. Consumption</label>
                                    <InputText v-model="form.do_cons" :disabled="saving || signing" class="w-full" />
                                </div>
                            </div>

                            <div class="grid gap-4 md:grid-cols-2">
                                <div class="flex flex-col gap-2">
                                    <label class="text-sm font-semibold text-slate-700">Average RPM</label>
                                    <InputText v-model="form.average_rpm" :disabled="saving || signing" class="w-full" />
                                </div>
                                <div class="flex flex-col gap-2">
                                    <label class="text-sm font-semibold text-slate-700">Average Engine Speed</label>
                                    <InputText v-model="form.average_speed" :disabled="saving || signing" class="w-full" />
                                </div>
                            </div>
                        </div>
                    </template>
                </template>
            </Card>

            <Card
                class="!rounded-2xl !border !border-slate-200 !shadow-sm [&_.p-card-body]:!p-5 [&_.p-card-content]:!p-0"
            >
                <template #title>
                    <div
                        class="flex items-center gap-2 text-slate-800"
                    >
                        <i class="pi pi-book text-[#377EC0]"></i>
                        Watchkeeping Record
                    </div>
                </template>

                <template #content>
                    <div class="grid gap-4">
                        <div class="flex flex-col gap-2">
                            <label class="text-sm font-semibold text-slate-700">
                                {{ activityLabel }}
                                <span class="text-red-500">*</span>
                            </label>

                            <Textarea
                                v-model="form.activities"
                                rows="5"
                                auto-resize
                                fluid
                                :disabled="saving || signing"
                            />
                        </div>

                        <div class="flex flex-col gap-2">
                            <label class="text-sm font-semibold text-slate-700">
                                Key Areas Learned During the Watch
                            </label>

                            <Textarea
                                v-model="form.key_areas"
                                rows="4"
                                auto-resize
                                fluid
                                :disabled="saving || signing"
                            />
                        </div>
                    </div>
                </template>
            </Card>

            <Card
                class="!rounded-2xl !border !border-slate-200 !shadow-sm [&_.p-card-body]:!p-5 [&_.p-card-content]:!p-0"
            >
                <template #title>
                    <div
                        class="flex items-center gap-2 text-slate-800"
                    >
                        <i class="pi pi-image text-[#377EC0]"></i>
                        Objective Evidence
                    </div>
                </template>

                <template #content>
                    <div
                        class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px]"
                    >
                        <div
                            class="rounded-2xl border border-slate-200 bg-slate-50 p-4"
                        >
                            <template v-if="journal.evidence_url">
                                <PrimeImage
                                    v-if="isEvidenceImage"
                                    :src="journal.evidence_url"
                                    :alt="journal.file_name || 'Journal evidence'"
                                    preview
                                    image-class="max-h-[360px] w-full rounded-xl object-contain"
                                    class="block w-full"
                                />

                                <div
                                    v-else
                                    class="flex min-h-[220px] flex-col items-center justify-center gap-3 text-center"
                                >
                                    <i class="pi pi-file text-5xl text-slate-300"></i>
                                    <p class="font-semibold text-slate-700">
                                        {{ journal.file_name || 'Objective Evidence' }}
                                    </p>
                                </div>

                                <div
                                    class="mt-4 flex flex-wrap items-center gap-2"
                                >
                                    <Button
                                        type="button"
                                        label="Open Evidence"
                                        icon="pi pi-external-link"
                                        severity="info"
                                        variant="outlined"
                                        @click="openEvidence"
                                    />
                                </div>
                            </template>

                            <div
                                v-else
                                class="flex min-h-[220px] flex-col items-center justify-center gap-2 text-center text-slate-500"
                            >
                                <i class="pi pi-image text-5xl text-slate-300"></i>
                                <p class="font-semibold">No objective evidence uploaded</p>
                            </div>
                        </div>

                        <div
                            class="rounded-2xl border border-slate-200 bg-white p-4"
                        >
                            <p class="font-semibold text-slate-800">
                                {{ journal.evidence_url ? 'Replace Evidence' : 'Upload Evidence' }}
                            </p>

                            <p class="mt-1 text-xs text-slate-500">
                                Administrators may correct objective evidence even after STO validation.
                            </p>

                            <div class="mt-4 space-y-3">
                                <FileUpload
                                    :key="evidenceUploadKey"
                                    mode="basic"
                                    name="evidence"
                                    choose-label="Choose Evidence"
                                    choose-icon="pi pi-folder-open"
                                    :custom-upload="true"
                                    :auto="false"
                                    accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv"
                                    :max-file-size="20971520"
                                    :disabled="saving || signing || evidenceUploading"
                                    class="w-full"
                                    @select="handleEvidenceSelect"
                                />

                                <p
                                    v-if="selectedEvidence"
                                    class="break-all text-sm font-medium text-slate-600"
                                >
                                    Selected: {{ selectedEvidence.name }}
                                </p>

                                <Button
                                    type="button"
                                    label="Upload Evidence"
                                    icon="pi pi-upload"
                                    severity="info"
                                    class="w-full"
                                    :loading="evidenceUploading"
                                    :disabled="!selectedEvidence || evidenceUploading || saving || signing"
                                    @click="uploadEvidence"
                                />
                            </div>
                        </div>
                    </div>
                </template>
            </Card>

            <Card
                class="!rounded-2xl !border !border-slate-200 !shadow-sm [&_.p-card-body]:!p-5 [&_.p-card-content]:!p-0"
            >
                <template #title>
                    <div class="flex items-center gap-2 text-slate-800">
                        <i class="pi pi-verified text-[#377EC0]"></i>
                        Supervising Officer Signature
                    </div>
                </template>

                <template #content>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="font-semibold text-slate-800">
                                    Supervising Officer (Master or Qualified Officer)
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    The stored STO signature validates this journal.
                                </p>
                            </div>

                            <PrimeTag
                                :value="isValidated ? 'Signed' : 'Signature Required'"
                                :severity="isValidated ? 'success' : 'warn'"
                                :icon="isValidated ? 'pi pi-check-circle' : 'pi pi-clock'"
                                rounded
                            />
                        </div>

                        <div class="mt-4 flex flex-col gap-2">
                            <label
                                for="journal-sto-name"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Supervising Officer Name
                                <span v-if="!isValidated" class="text-red-500">*</span>
                            </label>

                            <InputText
                                id="journal-sto-name"
                                v-model="form.sto_name"
                                class="w-full"
                                :disabled="saving || signing"
                                placeholder="Enter Master or Qualified Officer name"
                            />
                        </div>

                        <template v-if="isValidated">
                            <div
                                class="mt-4 flex min-h-[180px] items-center justify-center rounded-xl border border-slate-200 bg-white p-4"
                            >
                                <PrimeImage
                                    v-if="journal.officer_signature_url"
                                    :src="journal.officer_signature_url"
                                    :alt="`${form.sto_name || 'Supervising Officer'} signature`"
                                    preview
                                    image-class="max-h-[140px] max-w-full object-contain"
                                />

                                <div
                                    v-else
                                    class="text-center text-sm text-slate-400"
                                >
                                    <i class="pi pi-image text-3xl"></i>
                                    <p class="mt-2">
                                        The database marks this journal as validated, but its STO signature file could not be located.
                                    </p>
                                    <p
                                        v-if="journal.officer_signature_file"
                                        class="mt-1 break-all text-xs"
                                    >
                                        {{ journal.officer_signature_file }}
                                    </p>
                                </div>
                            </div>

                            <div
                                v-if="journal.officer_signature_url"
                                class="mt-3 flex justify-end"
                            >
                                <Button
                                    type="button"
                                    label="Open Signature"
                                    icon="pi pi-external-link"
                                    severity="secondary"
                                    variant="outlined"
                                    size="small"
                                    @click="openOfficerSignature"
                                />
                            </div>
                        </template>

                        <template v-else>
                            <Message severity="warn" :closable="false" class="mt-4">
                                Review the journal information before saving the STO signature.
                            </Message>

                            <div
                                class="mt-4 overflow-hidden rounded-xl border border-slate-300 bg-white"
                            >
                                <canvas
                                    ref="signatureCanvas"
                                    width="900"
                                    height="260"
                                    class="h-44 w-full touch-none cursor-crosshair bg-white"
                                    @pointerdown="beginSignature"
                                    @pointermove="drawSignature"
                                    @pointerup="endSignature"
                                    @pointercancel="endSignature"
                                    @pointerleave="endSignature"
                                ></canvas>
                            </div>

                            <div class="mt-3 flex flex-wrap justify-between gap-2">
                                <Button
                                    type="button"
                                    label="Clear Signature"
                                    icon="pi pi-eraser"
                                    severity="secondary"
                                    variant="outlined"
                                    :disabled="signing"
                                    @click="clearSignature"
                                />

                                <Button
                                    type="button"
                                    label="Save STO Signature & Validate"
                                    icon="pi pi-check-circle"
                                    severity="success"
                                    :loading="signing"
                                    :disabled="!signatureHasInk || !form.sto_name.trim() || saving"
                                    @click="signJournal"
                                />
                            </div>
                        </template>
                    </div>
                </template>
            </Card>

            <div
                class="flex justify-end gap-2 pb-2"
            >
                <Button
                    type="button"
                    label="Back"
                    icon="pi pi-arrow-left"
                    severity="secondary"
                    variant="outlined"
                    :disabled="saving || signing"
                    @click="goBack"
                />

                <Button
                    type="button"
                    label="Save Changes"
                    icon="pi pi-save"
                    severity="success"
                    :loading="saving"
                    :disabled="saving || signing"
                    @click="saveJournal()"
                />
            </div>
        </template>
    </div>
</template>
