<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Card from 'primevue/card';
import DatePicker from 'primevue/datepicker';
import Dialog from 'primevue/dialog';
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
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
} from 'vue';

import Datatable from '@/components/Datatable.vue';
import type {
    DataTableAction,
    DataTableColumn,
    DataTableRow,
} from '@/types';

defineOptions({
    inheritAttrs: false,

    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: '/student-dashboard',
            },
            {
                title: 'Daily Journals',
                href: '/monitoring/daily-journals/student/datatable',
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

type StudentMeta = {
    id: string;
    school_id_no: string | null;
    gender: string | null;
    department: string | null;
};

type JournalListResponse = {
    data: DataTableRow[];
    meta: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    student: StudentMeta;
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

type PageEvent = {
    page: number;
    rows: number;
    first: number;
};

type SortEvent = {
    sortField: string;
    sortOrder: number;
};

const API =
    '/api/v1/student/daily-journals';

const PRINT_URL =
    '/monitoring/daily-journals/student/print';

const toast = useToast();

const loading = ref(false);
const modalLoading = ref(false);
const saving = ref(false);
const evidenceUploading = ref(false);
const signing = ref(false);
const deleting = ref(false);

const pageError = ref('');
const modalError = ref('');

const journals = ref<DataTableRow[]>([]);
const studentMeta =
    ref<StudentMeta | null>(null);

const totalRecords = ref(0);
const first = ref(0);
const perPage = ref(10);
const search = ref('');
const statusOptions = ['All', 'Pending', 'Validated'] as const;
const statusFilter = ref<(typeof statusOptions)[number]>('All');
const sortField = ref('date_journal');
const sortDirection =
    ref<'asc' | 'desc'>('desc');

const dateRange =
    ref<Date[] | null>(null);

const journalDialogVisible = ref(false);
const deleteDialogVisible = ref(false);

const journal =
    ref<JournalRecord | null>(null);

const deleteTarget =
    ref<DataTableRow | JournalRecord | null>(
        null,
    );

const selectedEvidence =
    ref<File | null>(null);

const evidenceUploadKey = ref(0);

const signatureCanvas =
    ref<HTMLCanvasElement | null>(null);

const signatureDrawing = ref(false);
const signatureHasInk = ref(false);
const signaturePointerId =
    ref<number | null>(null);

let listController:
    | AbortController
    | null = null;

let journalController:
    | AbortController
    | null = null;

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

const isCreateMode = computed(
    () => journal.value === null,
);

const isValidated = computed(
    () => journal.value?.validated === true,
);

const currentDepartment = computed(
    () =>
        String(
            journal.value?.department ??
                studentMeta.value
                    ?.department ??
                '',
        )
            .trim()
            .toUpperCase(),
);

const isDeck = computed(
    () =>
        currentDepartment.value ===
        'DECK',
);

const activityLabel = computed(
    () =>
        isDeck.value
            ? 'Bridge Watchkeeping Activities, Specific Duties and Events During the Watch'
            : 'Engine-Room Watchkeeping Activities, Specific Duties and Events During the Watch',
);

const isEvidenceImage = computed(
    () => {
        const filename = String(
            journal.value?.file_name ??
                '',
        )
            .trim()
            .toLowerCase();

        return /\.(jpg|jpeg|png|gif|webp)$/i.test(
            filename,
        );
    },
);

const dateFrom =
    computed<Date | null>(
        () =>
            dateRange.value?.[0] ??
            null,
    );

const dateTo =
    computed<Date | null>(
        () =>
            dateRange.value?.[1] ??
            null,
    );

const modalTitle = computed(
    () =>
        isCreateMode.value
            ? 'Add Daily Journal'
            : 'Edit Daily Journal',
);

const columns: DataTableColumn[] = [
    {
        field: 'date_journal',
        header: 'Journal Date',
        sortable: true,
        searchable: false,
        class:
            'min-w-[190px]',
    },
    {
        field: 'journal_time',
        header: 'Duty Time',
        sortable: false,
        searchable: false,
        class:
            'min-w-[190px]',
    },
    {
        field: 'vessel_name',
        header: 'Vessel',
        sortable: false,
        searchable: true,
        class:
            'min-w-[240px] whitespace-normal',
    },
    {
        field: 'port_depart',
        header: 'Voyage',
        sortable: false,
        searchable: true,
        class:
            'min-w-[240px] whitespace-normal',
    },
    {
        field: 'activities',
        header: 'Activities',
        sortable: false,
        searchable: true,
        class:
            'min-w-[280px] whitespace-normal',
    },
    {
        field: 'status',
        header: 'Status',
        sortable: false,
        searchable: false,
        class:
            'min-w-[150px]',
    },
];

const actions: DataTableAction[] = [
    {
        key: 'edit',
        label: 'Open Journal',
        icon: 'pi pi-pencil',
        severity: 'info',
    },
    {
        key: 'delete',
        label: 'Delete Journal',
        icon: 'pi pi-trash',
        severity: 'danger',
    },
];

function stringValue(
    value: unknown,
): string {
    return String(
        value ?? '',
    ).trim();
}

function parseDate(
    value: unknown,
): Date | null {
    const text =
        stringValue(value);

    if (!text) {
        return null;
    }

    const date = new Date(
        `${text.substring(
            0,
            10,
        )}T00:00:00`,
    );

    return Number.isNaN(
        date.getTime(),
    )
        ? null
        : date;
}

function parseTime(
    value: unknown,
): Date | null {
    const text =
        stringValue(value);

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

    date.setHours(
        hours,
        minutes,
        0,
        0,
    );

    return date;
}

function formatDateParameter(
    value: Date | null,
): string | null {
    if (!value) {
        return null;
    }

    const year =
        value.getFullYear();

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

function formatDate(
    value: unknown,
): string {
    const parsed = parseDate(value);

    if (!parsed) {
        return '—';
    }

    return new Intl.DateTimeFormat(
        'en-PH',
        {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        },
    ).format(parsed);
}

function formatTime(
    value: unknown,
): string {
    const parsed = parseTime(value);

    if (!parsed) {
        return '—';
    }

    return new Intl.DateTimeFormat(
        'en-PH',
        {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
        },
    ).format(parsed);
}

function dutyTime(
    row: DataTableRow,
): string {
    return `${formatTime(
        row.journal_time,
    )} - ${formatTime(
        row.journal_time_to,
    )}`;
}

function voyage(
    row: DataTableRow,
): string {
    const from = stringValue(
        row.port_depart,
    );

    const to = stringValue(
        row.port_dest,
    );

    if (from && to) {
        return `${from} → ${to}`;
    }

    return from || to || '—';
}

function valueOrDash(
    value: unknown,
): string {
    return (
        stringValue(value) || '—'
    );
}

function displayStatus(
    value: unknown,
): string {
    return String(value) ===
        'Validated'
        ? 'Signed'
        : 'Pending';
}

function statusSeverity(
    value: unknown,
):
    | 'success'
    | 'secondary' {
    return String(value) ===
        'Validated'
        ? 'success'
        : 'secondary';
}

function loadIntoForm(
    record: JournalRecord,
): void {
    form.value = {
        date_journal:
            parseDate(
                record.date_journal,
            ),
        journal_time:
            parseTime(
                record.journal_time,
            ),
        journal_time_to:
            parseTime(
                record.journal_time_to,
            ),
        vessel_name:
            stringValue(
                record.vessel_name,
            ),
        ship_lat:
            stringValue(
                record.ship_lat,
            ),
        ship_long:
            stringValue(
                record.ship_long,
            ),
        ship_vicinity:
            stringValue(
                record.ship_vicinity,
            ),
        port_depart:
            stringValue(
                record.port_depart,
            ),
        port_dest:
            stringValue(
                record.port_dest,
            ),
        pos_fix:
            stringValue(
                record.pos_fix,
            ),
        course_speed:
            stringValue(
                record.course_speed,
            ),
        fo_rob:
            stringValue(
                record.fo_rob,
            ),
        fo_dob:
            stringValue(
                record.fo_dob,
            ),
        fo_lob:
            stringValue(
                record.fo_lob,
            ),
        fo_cons:
            stringValue(
                record.fo_cons,
            ),
        do_cons:
            stringValue(
                record.do_cons,
            ),
        average_rpm:
            stringValue(
                record.average_rpm,
            ),
        average_speed:
            stringValue(
                record.average_speed,
            ),
        activities:
            stringValue(
                record.activities,
            ),
        key_areas:
            stringValue(
                record.key_areas,
            ),
        sto_name:
            stringValue(
                record.sto_name,
            ),
    };
}

function resetJournalModal(): void {
    journalController?.abort();
    journalController = null;

    journal.value = null;
    form.value = emptyForm();

    selectedEvidence.value =
        null;

    evidenceUploadKey.value += 1;

    modalError.value = '';

    clearSignature();
}

function validateDateRange(): boolean {
    const hasFrom =
        dateFrom.value !== null;

    const hasTo =
        dateTo.value !== null;

    if (hasFrom !== hasTo) {
        pageError.value =
            'Select both the start and end date.';

        return false;
    }

    return true;
}

function getErrorMessage(
    error: unknown,
    fallback: string,
): string {
    if (!axios.isAxiosError(error)) {
        return error instanceof Error
            ? error.message
            : fallback;
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

function listParameters(
    pageNumber: number,
): Record<string, unknown> {
    const params: Record<
        string,
        unknown
    > = {
        page: pageNumber,
        per_page: perPage.value,
        search: search.value,
        ...(statusFilter.value !== 'All' ? { status: statusFilter.value } : {}),
        sort_field:
            sortField.value,
        sort_direction:
            sortDirection.value,
    };

    if (
        dateFrom.value &&
        dateTo.value
    ) {
        params.date_from =
            formatDateParameter(
                dateFrom.value,
            );

        params.date_to =
            formatDateParameter(
                dateTo.value,
            );
    }

    return params;
}

async function loadJournals(
    pageNumber = 1,
): Promise<void> {
    if (!validateDateRange()) {
        return;
    }

    listController?.abort();

    const controller =
        new AbortController();

    listController = controller;

    loading.value = true;
    pageError.value = '';

    try {
        const response =
            await axios.get<JournalListResponse>(
                API,
                {
                    params:
                        listParameters(
                            pageNumber,
                        ),
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

        journals.value =
            Array.isArray(
                response.data.data,
            )
                ? response.data.data
                : [];

        studentMeta.value =
            response.data.student ??
            null;

        totalRecords.value =
            Number(
                response.data.meta
                    ?.total ?? 0,
            );

        perPage.value =
            Number(
                response.data.meta
                    ?.perPage ??
                    perPage.value,
            );

        first.value =
            (
                Number(
                    response.data.meta
                        ?.currentPage ??
                        1,
                ) - 1
            ) * perPage.value;
    } catch (error: unknown) {
        if (
            axios.isCancel(error) ||
            (
                axios.isAxiosError(
                    error,
                ) &&
                error.code ===
                    'ERR_CANCELED'
            )
        ) {
            return;
        }

        journals.value = [];
        totalRecords.value = 0;

        pageError.value =
            getErrorMessage(
                error,
                'Unable to load your daily journals.',
            );
    } finally {
        if (
            listController ===
            controller
        ) {
            loading.value = false;
        }
    }
}

function openAddJournal(): void {
    resetJournalModal();

    journalDialogVisible.value =
        true;

    void nextTick(() => {
        clearSignature();
    });
}

async function openJournal(
    row: DataTableRow,
): Promise<void> {
    const journalId =
        stringValue(row.id);

    if (!journalId) {
        pageError.value =
            'The selected journal record is invalid.';

        return;
    }

    resetJournalModal();

    journalDialogVisible.value =
        true;

    journalController?.abort();

    const controller =
        new AbortController();

    journalController =
        controller;

    modalLoading.value = true;
    modalError.value = '';

    try {
        const response =
            await axios.get<JournalResponse>(
                `${API}/${encodeURIComponent(
                    journalId,
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

        await nextTick();

        clearSignature();
    } catch (error: unknown) {
        if (
            axios.isCancel(error) ||
            (
                axios.isAxiosError(
                    error,
                ) &&
                error.code ===
                    'ERR_CANCELED'
            )
        ) {
            return;
        }

        modalError.value =
            getErrorMessage(
                error,
                'Unable to load the daily journal.',
            );
    } finally {
        if (
            journalController ===
            controller
        ) {
            modalLoading.value =
                false;
        }
    }
}

function closeJournalDialog(): void {
    journalDialogVisible.value =
        false;

    resetJournalModal();
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
                form.value
                    .journal_time_to,
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
    saving.value = true;
    modalError.value = '';

    try {
        const currentId =
            journal.value?.id;

        const response =
            currentId
                ? await axios.put<JournalResponse>(
                      `${API}/${encodeURIComponent(
                          currentId,
                      )}`,
                      buildPayload(),
                      {
                          headers: {
                              Accept:
                                  'application/json',
                              'X-Requested-With':
                                  'XMLHttpRequest',
                          },
                          withCredentials:
                              true,
                      },
                  )
                : await axios.post<JournalResponse>(
                      API,
                      buildPayload(),
                      {
                          headers: {
                              Accept:
                                  'application/json',
                              'X-Requested-With':
                                  'XMLHttpRequest',
                          },
                          withCredentials:
                              true,
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
                summary: currentId
                    ? 'Journal Saved'
                    : 'Journal Added',
                detail:
                    response.data
                        .message ||
                    (
                        currentId
                            ? 'Daily journal saved successfully.'
                            : 'Daily journal added successfully.'
                    ),
                life: 3500,
            });
        }

        await reloadCurrentPage();

        return true;
    } catch (error: unknown) {
        modalError.value =
            getErrorMessage(
                error,
                'Unable to save the daily journal.',
            );

        return false;
    } finally {
        saving.value = false;
    }
}

async function ensureJournalExists(): Promise<boolean> {
    if (journal.value) {
        return true;
    }

    return saveJournal(false);
}

function handleEvidenceSelect(
    event: FileUploadSelectEvent,
): void {
    selectedEvidence.value =
        event.files?.[0] ??
        null;
}

async function uploadEvidence(): Promise<void> {
    if (!selectedEvidence.value) {
        return;
    }

    const ready =
        await ensureJournalExists();

    if (
        !ready ||
        !journal.value
    ) {
        return;
    }

    evidenceUploading.value =
        true;

    modalError.value = '';

    const formData =
        new FormData();

    formData.append(
        'evidence',
        selectedEvidence.value,
    );

    try {
        const response =
            await axios.post<JournalResponse>(
                `${API}/${encodeURIComponent(
                    journal.value.id,
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

        selectedEvidence.value =
            null;

        evidenceUploadKey.value +=
            1;

        toast.add({
            severity: 'success',
            summary:
                'Evidence Updated',
            detail:
                response.data.message ||
                'Objective evidence uploaded successfully.',
            life: 3500,
        });

        await reloadCurrentPage();
    } catch (error: unknown) {
        modalError.value =
            getErrorMessage(
                error,
                'Unable to upload the objective evidence.',
            );
    } finally {
        evidenceUploading.value =
            false;
    }
}

function openEvidence(): void {
    const url = stringValue(
        journal.value?.evidence_url,
    );

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
    const url = stringValue(
        journal.value
            ?.officer_signature_url,
    );

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
            (
                event.clientX -
                rectangle.left
            ) *
            (
                canvas.width /
                rectangle.width
            ),
        y:
            (
                event.clientY -
                rectangle.top
            ) *
            (
                canvas.height /
                rectangle.height
            ),
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
    context.strokeStyle =
        '#0f172a';

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

    if (
        !form.value.sto_name.trim()
    ) {
        modalError.value =
            'Enter the Supervising Officer name before signing.';

        return;
    }

    if (!signatureHasInk.value) {
        modalError.value =
            'The Supervising Officer signature is required.';

        return;
    }

    const ready =
        await ensureJournalExists();

    if (
        !ready ||
        !journal.value
    ) {
        return;
    }

    modalError.value = '';
    signing.value = true;

    try {
        /*
         * Keep the saved text fields in sync with the signature.
         */
        const saved =
            await saveJournal(false);

        if (
            !saved ||
            !journal.value
        ) {
            return;
        }

        const blob =
            await signatureBlob();

        const signatureFile =
            new File(
                [blob],
                `sto-signature-${journal.value.id}.png`,
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
                    journal.value.id,
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
            summary:
                'Journal Signed',
            detail:
                response.data.message ||
                'STO signature saved successfully.',
            life: 4500,
        });

        await reloadCurrentPage();
    } catch (error: unknown) {
        modalError.value =
            getErrorMessage(
                error,
                'Unable to save the STO signature.',
            );
    } finally {
        signing.value = false;
    }
}

function askDelete(
    target:
        | DataTableRow
        | JournalRecord,
): void {
    deleteTarget.value = target;

    deleteDialogVisible.value =
        true;
}

async function deleteJournal(): Promise<void> {
    const journalId =
        stringValue(
            deleteTarget.value?.id,
        );

    if (!journalId) {
        return;
    }

    deleting.value = true;
    pageError.value = '';
    modalError.value = '';

    try {
        const response =
            await axios.delete<{
                message?: string;
            }>(
                `${API}/${encodeURIComponent(
                    journalId,
                )}`,
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

        toast.add({
            severity: 'success',
            summary:
                'Journal Deleted',
            detail:
                response.data.message ||
                'Daily journal deleted successfully.',
            life: 3500,
        });

        const deletedOpenJournal =
            journal.value?.id ===
            journalId;

        deleteDialogVisible.value =
            false;

        deleteTarget.value = null;

        if (deletedOpenJournal) {
            closeJournalDialog();
        }

        await reloadCurrentPage();
    } catch (error: unknown) {
        const message =
            getErrorMessage(
                error,
                'Unable to delete the daily journal.',
            );

        if (
            journalDialogVisible.value
        ) {
            modalError.value = message;
        } else {
            pageError.value = message;
        }
    } finally {
        deleting.value = false;
    }
}

function handleAction(
    action: string,
    row: DataTableRow,
): void {
    if (action === 'edit') {
        void openJournal(row);

        return;
    }

    if (action === 'delete') {
        askDelete(row);
    }
}

function handlePage(
    event: PageEvent,
): void {
    first.value = event.first;
    perPage.value = event.rows;

    void loadJournals(
        event.page + 1,
    );
}

function handleSort(
    event: SortEvent,
): void {
    sortField.value =
        event.sortField ||
        'date_journal';

    sortDirection.value =
        event.sortOrder === -1
            ? 'desc'
            : 'asc';

    first.value = 0;

    void loadJournals(1);
}

function handleSearch(
    value: string,
): void {
    search.value = value;
    first.value = 0;

    void loadJournals(1);
}

function handleColumnFilter(field: string, value: string): void {
    if (field !== 'status' || !statusOptions.includes(value as (typeof statusOptions)[number])) {
        return;
    }

    statusFilter.value = value as (typeof statusOptions)[number];
    first.value = 0;
    void loadJournals(1);
}

async function handleDateRangeChange(
    value: Date | Date[] | (Date | null)[] | null | undefined,
): Promise<void> {
    // Wait until both dates are chosen before filtering the table.
    if (
        value != null &&
        (!Array.isArray(value) || !value[0] || !value[1])
    ) {
        return;
    }

    await nextTick();
    pageError.value = '';
    first.value = 0;
    await loadJournals(1);
}

async function reloadCurrentPage(): Promise<void> {
    const page =
        Math.floor(
            first.value /
                perPage.value,
        ) + 1;

    await loadJournals(page);

    if (
        journals.value.length === 0 &&
        page > 1
    ) {
        await loadJournals(
            page - 1,
        );
    }
}

function printJournals(): void {
    if (!validateDateRange()) {
        return;
    }

    const parameters =
        new URLSearchParams();

    if (
        dateFrom.value &&
        dateTo.value
    ) {
        parameters.set(
            'date_from',
            formatDateParameter(
                dateFrom.value,
            ) ?? '',
        );

        parameters.set(
            'date_to',
            formatDateParameter(
                dateTo.value,
            ) ?? '',
        );
    }

    const query =
        parameters.toString();

    window.open(
        query
            ? `${PRINT_URL}?${query}`
            : PRINT_URL,
        '_blank',
        'noopener,noreferrer',
    );
}

function printCurrentJournal(): void {
    const date =
        journal.value?.date_journal
            ?.substring(0, 10);

    if (!date) {
        return;
    }

    const parameters =
        new URLSearchParams({
            date_from: date,
            date_to: date,
        });

    window.open(
        `${PRINT_URL}?${parameters.toString()}`,
        '_blank',
        'noopener,noreferrer',
    );
}

onMounted(() => {
    void loadJournals(1);
});

onBeforeUnmount(() => {
    listController?.abort();
    journalController?.abort();
});
</script>

<template>
    <Head title="Daily Journals" />

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

        <Datatable
            title="Daily Journals"
            description="View and manage your daily journal records."
            header-icon="pi pi-book"
            search-placeholder="Search daily journals..."
            empty-title="No daily journals found"
            empty-description="No matching daily journal records were found."
            empty-icon="pi pi-book"
            table-min-width="1470px"
            actions-header="Actions"
            actions-width="170px"
            data-key="id"
            lazy
            :loading="loading"
            :data="journals"
            :columns="columns"
            :column-filters="{ status: { value: statusFilter, options: statusOptions } }"
            :actions="actions"
            :total-records="totalRecords"
            :first="first"
            :rows="perPage"
            :rows-per-page-options="[
                10,
                20,
                50,
                100,
            ]"
            @page="handlePage"
            @sort="handleSort"
            @search="handleSearch"
            @filter="handleColumnFilter"
            @action="handleAction"
        >
            <template #header-actions>
                <div class="flex flex-wrap items-center gap-2">
                    <DatePicker
                        v-model="dateRange"
                        selection-mode="range"
                        date-format="M d, yy"
                        show-icon
                        icon-display="input"
                        :manual-input="false"
                        class="w-[350px] max-w-full"
                        input-class="w-full !text-sm"
                        placeholder="Journal date range"
                        aria-label="Journal date range"
                        @update:model-value="handleDateRangeChange"
                    />
                    <Button
                        type="button"
                        label="Print"
                        icon="pi pi-print"
                        severity="info"
                        size="small"
                        @click="printJournals"
                    />

                    <Button
                        type="button"
                        label="Add Journal"
                        icon="pi pi-plus"
                        severity="success"
                        size="small"
                        @click="openAddJournal"
                    />
                </div>
            </template>

            <template #cell-date_journal="{ value }">
                <div
                    class="flex items-center gap-2"
                >
                    <i
                        class="pi pi-calendar text-blue-500"
                    ></i>

                    <span
                        class="font-semibold text-slate-700"
                    >
                        {{
                            formatDate(
                                value,
                            )
                        }}
                    </span>
                </div>
            </template>

            <template #cell-journal_time="{ data }">
                <div
                    class="flex items-center gap-2"
                >
                    <i
                        class="pi pi-clock text-yellow-500"
                    ></i>

                    <div>
                        <div
                            class="font-medium text-slate-700"
                        >
                            {{
                                dutyTime(data)
                            }}
                        </div>

                        <div
                            class="mt-0.5 text-xs text-slate-500"
                        >
                            {{
                                valueOrDash(
                                    data.duty_hours,
                                )
                            }}
                        </div>
                    </div>
                </div>
            </template>

            <template #cell-vessel_name="{ value }">
                <div
                    class="flex min-w-0 items-center gap-2 whitespace-normal"
                >
                    <i
                        class="pi pi-compass shrink-0 text-emerald-500"
                    ></i>

                    <span
                        class="break-words font-medium text-slate-700"
                    >
                        {{
                            valueOrDash(
                                value,
                            )
                        }}
                    </span>
                </div>
            </template>

            <template #cell-port_depart="{ data }">
                <div
                    class="flex min-w-0 items-start gap-2 whitespace-normal"
                >
                    <i
                        class="pi pi-map-marker mt-0.5 shrink-0 text-red-500"
                    ></i>

                    <span
                        class="break-words font-medium text-slate-700"
                    >
                        {{ voyage(data) }}
                    </span>
                </div>
            </template>

            <template #cell-activities="{ value }">
                <p
                    class="line-clamp-3 whitespace-normal text-sm leading-5 text-slate-600"
                >
                    {{
                        valueOrDash(
                            value,
                        )
                    }}
                </p>
            </template>

            <template #cell-status="{ value }">
                <PrimeTag
                    :value="
                        displayStatus(
                            value,
                        )
                    "
                    :severity="
                        statusSeverity(
                            value,
                        )
                    "
                    :icon="
                        displayStatus(
                            value,
                        ) === 'Signed'
                            ? 'pi pi-check-circle'
                            : 'pi pi-clock'
                    "
                    rounded
                />
            </template>
        </Datatable>
    </div>

    <!--
        Student journal modal.
        It intentionally copies the senior administrator edit-card structure,
        but the administrator's student-name / avatar identity card is omitted.
    -->
    <Dialog
        v-model:visible="journalDialogVisible"
        modal
        :draggable="false"
        :closable="!saving && !signing && !evidenceUploading"
        :style="{
            width: 'min(1180px, 96vw)',
        }"
        :content-style="{
            maxHeight: '78vh',
            overflowY: 'auto',
            background: '#F8FAFC',
        }"
        @hide="resetJournalModal"
    >
        <template #header>
            <div
                class="flex w-full items-center justify-between gap-3 pr-3"
            >
                <div>
                    <h2
                        class="text-lg font-bold text-slate-800"
                    >
                        {{ modalTitle }}
                    </h2>

                    <p
                        class="mt-0.5 text-xs text-slate-500"
                    >
                        {{
                            isDeck
                                ? 'DECK Daily Journal'
                                : 'ENGINE Daily Journal'
                        }}
                    </p>
                </div>

                <PrimeTag
                    v-if="journal"
                    :value="
                        isValidated
                            ? 'Signed'
                            : 'Pending'
                    "
                    :severity="
                        isValidated
                            ? 'success'
                            : 'secondary'
                    "
                    :icon="
                        isValidated
                            ? 'pi pi-check-circle'
                            : 'pi pi-clock'
                    "
                    rounded
                />
            </div>
        </template>

        <Message
            v-if="modalError"
            severity="error"
            closable
            class="mb-4"
            @close="modalError = ''"
        >
            {{ modalError }}
        </Message>

        <div
            v-if="modalLoading"
            class="flex min-h-[360px] items-center justify-center"
        >
            <div
                class="text-center text-sm font-medium text-slate-500"
            >
                <i
                    class="pi pi-spin pi-spinner mr-2"
                ></i>

                Loading daily journal...
            </div>
        </div>

        <div
            v-else
            class="space-y-4"
        >
            <!-- Same Journal Details card used by the administrator edit page. -->
            <Card
                class="!rounded-2xl !border !border-slate-200 !shadow-sm [&_.p-card-body]:!p-5 [&_.p-card-content]:!p-0"
            >
                <template #title>
                    <div
                        class="flex items-center gap-2 text-slate-800"
                    >
                        <i
                            class="pi pi-calendar text-[#377EC0]"
                        ></i>

                        Journal Details
                    </div>
                </template>

                <template #content>
                    <div
                        class="grid gap-4 md:grid-cols-2 xl:grid-cols-4"
                    >
                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                class="text-sm font-semibold text-slate-700"
                            >
                                Journal Date
                                <span
                                    class="text-red-500"
                                >
                                    *
                                </span>
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

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                class="text-sm font-semibold text-slate-700"
                            >
                                From Time
                                <span
                                    class="text-red-500"
                                >
                                    *
                                </span>
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

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                class="text-sm font-semibold text-slate-700"
                            >
                                To Time
                                <span
                                    class="text-red-500"
                                >
                                    *
                                </span>
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

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                class="text-sm font-semibold text-slate-700"
                            >
                                Name of Vessel
                                <span
                                    class="text-red-500"
                                >
                                    *
                                </span>
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

            <!-- Same DECK / ENGINE conditional card used by the admin page. -->
            <Card
                class="!rounded-2xl !border !border-slate-200 !shadow-sm [&_.p-card-body]:!p-5 [&_.p-card-content]:!p-0"
            >
                <template #title>
                    <div
                        class="flex items-center gap-2 text-slate-800"
                    >
                        <i
                            class="pi pi-map-marker text-[#377EC0]"
                        ></i>

                        {{
                            isDeck
                                ? 'Navigation & Vessel Information'
                                : 'Engine & Vessel Information'
                        }}
                    </div>
                </template>

                <template #content>
                    <template v-if="isDeck">
                        <div class="space-y-4">
                            <div
                                class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
                            >
                                <div
                                    class="flex flex-col gap-2"
                                >
                                    <label
                                        class="text-sm font-semibold text-slate-700"
                                    >
                                        Latitude
                                    </label>

                                    <InputText
                                        v-model="form.ship_lat"
                                        :disabled="saving || signing"
                                        class="w-full"
                                    />
                                </div>

                                <div
                                    class="flex flex-col gap-2"
                                >
                                    <label
                                        class="text-sm font-semibold text-slate-700"
                                    >
                                        Longitude
                                    </label>

                                    <InputText
                                        v-model="form.ship_long"
                                        :disabled="saving || signing"
                                        class="w-full"
                                    />
                                </div>

                                <div
                                    class="flex flex-col gap-2"
                                >
                                    <label
                                        class="text-sm font-semibold text-slate-700"
                                    >
                                        Vicinity
                                    </label>

                                    <InputText
                                        v-model="form.ship_vicinity"
                                        :disabled="saving || signing"
                                        class="w-full"
                                    />
                                </div>
                            </div>

                            <div
                                class="grid gap-4 md:grid-cols-2"
                            >
                                <div
                                    class="flex flex-col gap-2"
                                >
                                    <label
                                        class="text-sm font-semibold text-slate-700"
                                    >
                                        Port Departure
                                    </label>

                                    <InputText
                                        v-model="form.port_depart"
                                        :disabled="saving || signing"
                                        class="w-full"
                                    />
                                </div>

                                <div
                                    class="flex flex-col gap-2"
                                >
                                    <label
                                        class="text-sm font-semibold text-slate-700"
                                    >
                                        Port Destination
                                    </label>

                                    <InputText
                                        v-model="form.port_dest"
                                        :disabled="saving || signing"
                                        class="w-full"
                                    />
                                </div>
                            </div>

                            <div
                                class="grid gap-4 md:grid-cols-2"
                            >
                                <div
                                    class="flex flex-col gap-2"
                                >
                                    <label
                                        class="text-sm font-semibold text-slate-700"
                                    >
                                        Position-Fixing Method
                                    </label>

                                    <InputText
                                        v-model="form.pos_fix"
                                        :disabled="saving || signing"
                                        class="w-full"
                                    />
                                </div>

                                <div
                                    class="flex flex-col gap-2"
                                >
                                    <label
                                        class="text-sm font-semibold text-slate-700"
                                    >
                                        Course and Speed
                                    </label>

                                    <InputText
                                        v-model="form.course_speed"
                                        :disabled="saving || signing"
                                        class="w-full"
                                    />
                                </div>
                            </div>

                            <div
                                class="grid gap-4 md:grid-cols-3"
                            >
                                <div
                                    class="flex flex-col gap-2"
                                >
                                    <label
                                        class="text-sm font-semibold text-slate-700"
                                    >
                                        F.O ROB
                                    </label>

                                    <InputText
                                        v-model="form.fo_rob"
                                        :disabled="saving || signing"
                                        class="w-full"
                                    />
                                </div>

                                <div
                                    class="flex flex-col gap-2"
                                >
                                    <label
                                        class="text-sm font-semibold text-slate-700"
                                    >
                                        F.O DOB
                                    </label>

                                    <InputText
                                        v-model="form.fo_dob"
                                        :disabled="saving || signing"
                                        class="w-full"
                                    />
                                </div>

                                <div
                                    class="flex flex-col gap-2"
                                >
                                    <label
                                        class="text-sm font-semibold text-slate-700"
                                    >
                                        F.O LOB
                                    </label>

                                    <InputText
                                        v-model="form.fo_lob"
                                        :disabled="saving || signing"
                                        class="w-full"
                                    />
                                </div>
                            </div>
                        </div>
                    </template>

                    <template v-else>
                        <div class="space-y-4">
                            <div
                                class="grid gap-4 md:grid-cols-2"
                            >
                                <div
                                    class="flex flex-col gap-2"
                                >
                                    <label
                                        class="text-sm font-semibold text-slate-700"
                                    >
                                        Port Departure
                                    </label>

                                    <InputText
                                        v-model="form.port_depart"
                                        :disabled="saving || signing"
                                        class="w-full"
                                    />
                                </div>

                                <div
                                    class="flex flex-col gap-2"
                                >
                                    <label
                                        class="text-sm font-semibold text-slate-700"
                                    >
                                        Port Destination
                                    </label>

                                    <InputText
                                        v-model="form.port_dest"
                                        :disabled="saving || signing"
                                        class="w-full"
                                    />
                                </div>
                            </div>

                            <div
                                class="grid gap-4 md:grid-cols-2"
                            >
                                <div
                                    class="flex flex-col gap-2"
                                >
                                    <label
                                        class="text-sm font-semibold text-slate-700"
                                    >
                                        F.O. Consumption
                                    </label>

                                    <InputText
                                        v-model="form.fo_cons"
                                        :disabled="saving || signing"
                                        class="w-full"
                                    />
                                </div>

                                <div
                                    class="flex flex-col gap-2"
                                >
                                    <label
                                        class="text-sm font-semibold text-slate-700"
                                    >
                                        D.O. Consumption
                                    </label>

                                    <InputText
                                        v-model="form.do_cons"
                                        :disabled="saving || signing"
                                        class="w-full"
                                    />
                                </div>
                            </div>

                            <div
                                class="grid gap-4 md:grid-cols-2"
                            >
                                <div
                                    class="flex flex-col gap-2"
                                >
                                    <label
                                        class="text-sm font-semibold text-slate-700"
                                    >
                                        Average RPM
                                    </label>

                                    <InputText
                                        v-model="form.average_rpm"
                                        :disabled="saving || signing"
                                        class="w-full"
                                    />
                                </div>

                                <div
                                    class="flex flex-col gap-2"
                                >
                                    <label
                                        class="text-sm font-semibold text-slate-700"
                                    >
                                        Average Engine Speed
                                    </label>

                                    <InputText
                                        v-model="form.average_speed"
                                        :disabled="saving || signing"
                                        class="w-full"
                                    />
                                </div>
                            </div>
                        </div>
                    </template>
                </template>
            </Card>

            <!-- Same Watchkeeping Record card used by the admin page. -->
            <Card
                class="!rounded-2xl !border !border-slate-200 !shadow-sm [&_.p-card-body]:!p-5 [&_.p-card-content]:!p-0"
            >
                <template #title>
                    <div
                        class="flex items-center gap-2 text-slate-800"
                    >
                        <i
                            class="pi pi-book text-[#377EC0]"
                        ></i>

                        Watchkeeping Record
                    </div>
                </template>

                <template #content>
                    <div class="grid gap-4">
                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                class="text-sm font-semibold text-slate-700"
                            >
                                {{ activityLabel }}
                                <span
                                    class="text-red-500"
                                >
                                    *
                                </span>
                            </label>

                            <Textarea
                                v-model="form.activities"
                                rows="5"
                                auto-resize
                                fluid
                                :disabled="saving || signing"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                class="text-sm font-semibold text-slate-700"
                            >
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

            <!-- Same Objective Evidence card structure used by the admin page. -->
            <Card
                class="!rounded-2xl !border !border-slate-200 !shadow-sm [&_.p-card-body]:!p-5 [&_.p-card-content]:!p-0"
            >
                <template #title>
                    <div
                        class="flex items-center gap-2 text-slate-800"
                    >
                        <i
                            class="pi pi-image text-[#377EC0]"
                        ></i>

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
                            <template
                                v-if="
                                    journal?.evidence_url
                                "
                            >
                                <PrimeImage
                                    v-if="isEvidenceImage"
                                    :src="
                                        journal.evidence_url ??
                                        undefined
                                    "
                                    :alt="
                                        journal.file_name ||
                                        'Journal evidence'
                                    "
                                    preview
                                    image-class="max-h-[360px] w-full rounded-xl object-contain"
                                    class="block w-full"
                                />

                                <div
                                    v-else
                                    class="flex min-h-[220px] flex-col items-center justify-center gap-3 text-center"
                                >
                                    <i
                                        class="pi pi-file text-5xl text-slate-300"
                                    ></i>

                                    <p
                                        class="font-semibold text-slate-700"
                                    >
                                        {{
                                            journal.file_name ||
                                            'Objective Evidence'
                                        }}
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
                                <i
                                    class="pi pi-image text-5xl text-slate-300"
                                ></i>

                                <p
                                    class="font-semibold"
                                >
                                    No objective evidence uploaded
                                </p>
                            </div>
                        </div>

                        <div
                            class="rounded-2xl border border-slate-200 bg-white p-4"
                        >
                            <p
                                class="font-semibold text-slate-800"
                            >
                                {{
                                    journal?.evidence_url
                                        ? 'Replace Evidence'
                                        : 'Upload Evidence'
                                }}
                            </p>

                            <p
                                class="mt-1 text-xs text-slate-500"
                            >
                                Upload objective evidence for this daily journal.
                            </p>

                            <div
                                class="mt-4 space-y-3"
                            >
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
                                    :disabled="
                                        saving ||
                                        signing ||
                                        evidenceUploading
                                    "
                                    class="w-full"
                                    @select="handleEvidenceSelect"
                                />

                                <p
                                    v-if="selectedEvidence"
                                    class="break-all text-sm font-medium text-slate-600"
                                >
                                    Selected:
                                    {{
                                        selectedEvidence.name
                                    }}
                                </p>

                                <Button
                                    type="button"
                                    label="Upload Evidence"
                                    icon="pi pi-upload"
                                    severity="info"
                                    class="w-full"
                                    :loading="evidenceUploading"
                                    :disabled="
                                        !selectedEvidence ||
                                        evidenceUploading ||
                                        saving ||
                                        signing
                                    "
                                    @click="uploadEvidence"
                                />
                            </div>
                        </div>
                    </div>
                </template>
            </Card>

            <!-- Same Supervising Officer Signature card used by the admin page. -->
            <Card
                class="!rounded-2xl !border !border-slate-200 !shadow-sm [&_.p-card-body]:!p-5 [&_.p-card-content]:!p-0"
            >
                <template #title>
                    <div
                        class="flex items-center gap-2 text-slate-800"
                    >
                        <i
                            class="pi pi-verified text-[#377EC0]"
                        ></i>

                        Supervising Officer Signature
                    </div>
                </template>

                <template #content>
                    <div
                        class="rounded-2xl border border-slate-200 bg-slate-50 p-5"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-3"
                        >
                            <div>
                                <p
                                    class="font-semibold text-slate-800"
                                >
                                    Supervising Officer (Master or Qualified Officer)
                                </p>

                                <p
                                    class="mt-1 text-xs text-slate-500"
                                >
                                    The stored STO signature validates this journal.
                                </p>
                            </div>

                            <PrimeTag
                                :value="
                                    isValidated
                                        ? 'Signed'
                                        : 'Signature Required'
                                "
                                :severity="
                                    isValidated
                                        ? 'success'
                                        : 'warn'
                                "
                                :icon="
                                    isValidated
                                        ? 'pi pi-check-circle'
                                        : 'pi pi-clock'
                                "
                                rounded
                            />
                        </div>

                        <div
                            class="mt-4 flex flex-col gap-2"
                        >
                            <label
                                for="student-journal-sto-name"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Supervising Officer Name

                                <span
                                    v-if="!isValidated"
                                    class="text-red-500"
                                >
                                    *
                                </span>
                            </label>

                            <InputText
                                id="student-journal-sto-name"
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
                                    v-if="
                                        journal?.officer_signature_url
                                    "
                                    :src="
                                        journal.officer_signature_url ??
                                        undefined
                                    "
                                    :alt="
                                        `${
                                            form.sto_name ||
                                            'Supervising Officer'
                                        } signature`
                                    "
                                    preview
                                    image-class="max-h-[140px] max-w-full object-contain"
                                />

                                <div
                                    v-else
                                    class="text-center text-sm text-slate-400"
                                >
                                    <i
                                        class="pi pi-image text-3xl"
                                    ></i>

                                    <p class="mt-2">
                                        The database marks this journal as validated, but its STO signature file could not be located.
                                    </p>

                                    <p
                                        v-if="
                                            journal?.officer_signature_file
                                        "
                                        class="mt-1 break-all text-xs"
                                    >
                                        {{
                                            journal.officer_signature_file
                                        }}
                                    </p>
                                </div>
                            </div>

                            <div
                                v-if="
                                    journal?.officer_signature_url
                                "
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
                            <Message
                                severity="warn"
                                :closable="false"
                                class="mt-4"
                            >
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

                            <div
                                class="mt-3 flex flex-wrap justify-between gap-2"
                            >
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
                                    :disabled="
                                        !signatureHasInk ||
                                        !form.sto_name.trim() ||
                                        saving
                                    "
                                    @click="signJournal"
                                />
                            </div>
                        </template>
                    </div>
                </template>
            </Card>
        </div>

        <template #footer>
            <div
                class="flex w-full flex-wrap items-center justify-between gap-2"
            >
                <div>
                    <Button
                        v-if="journal"
                        type="button"
                        label="Delete Journal"
                        icon="pi pi-trash"
                        severity="danger"
                        variant="outlined"
                        :disabled="
                            saving ||
                            signing ||
                            evidenceUploading
                        "
                        @click="askDelete(journal)"
                    />
                </div>

                <div
                    class="flex flex-wrap justify-end gap-2"
                >
                    <Button
                        type="button"
                        label="Close"
                        icon="pi pi-times"
                        severity="secondary"
                        variant="outlined"
                        :disabled="
                            saving ||
                            signing ||
                            evidenceUploading
                        "
                        @click="closeJournalDialog"
                    />

                    <Button
                        v-if="journal"
                        type="button"
                        label="Print"
                        icon="pi pi-print"
                        severity="secondary"
                        variant="outlined"
                        :disabled="
                            saving ||
                            signing ||
                            evidenceUploading
                        "
                        @click="printCurrentJournal"
                    />

                    <Button
                        type="button"
                        :label="
                            isCreateMode
                                ? 'Add Journal'
                                : 'Save Changes'
                        "
                        icon="pi pi-save"
                        severity="success"
                        :loading="saving"
                        :disabled="
                            modalLoading ||
                            signing ||
                            evidenceUploading
                        "
                        @click="saveJournal()"
                    />
                </div>
            </div>
        </template>
    </Dialog>

    <Dialog
        v-model:visible="deleteDialogVisible"
        modal
        header="Delete Daily Journal"
        :draggable="false"
        :style="{
            width: 'min(460px, 94vw)',
        }"
    >
        <div
            class="flex items-start gap-3"
        >
            <div
                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600"
            >
                <i
                    class="pi pi-trash text-lg"
                ></i>
            </div>

            <div>
                <p
                    class="font-semibold text-slate-800"
                >
                    Remove this daily journal?
                </p>

                <p
                    class="mt-1 text-sm leading-5 text-slate-500"
                >
                    This will permanently remove the journal record and its stored journal evidence/signature files.
                </p>
            </div>
        </div>

        <template #footer>
            <Button
                type="button"
                label="Cancel"
                severity="secondary"
                variant="outlined"
                :disabled="deleting"
                @click="
                    deleteDialogVisible =
                        false
                "
            />

            <Button
                type="button"
                label="Delete Journal"
                icon="pi pi-trash"
                severity="danger"
                :loading="deleting"
                @click="deleteJournal"
            />
        </template>
    </Dialog>
</template>
