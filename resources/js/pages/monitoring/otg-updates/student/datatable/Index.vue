<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import DatePicker from 'primevue/datepicker';
import Dialog from 'primevue/dialog';
import PrimeImage from 'primevue/image';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Select from 'primevue/select';
import PrimeTag from 'primevue/tag';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import {
    computed,
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
                title:
                    'Training Record Book (OTG)',
                href:
                    '/monitoring/otg-updates/student/datatable',
            },
        ],
    },
});

type TrainingVessel = {
    id: string;
    vessel_type_id: string | null;
    vessel_name: string | null;
    vessel_type: string | null;
    ship_company: string | null;
    flag: string | null;
    sign_on_date: string | null;
    sign_off_date: string | null;
};

type TrainingTask = {
    person_task_id: string | null;
    task_id: string;
    ref_no: string | null;
    description: string | null;
    competence: string | null;
    topic: string | null;
    completion_date: string | null;
    not_applicable: boolean;
    month_no: string | null;
};

type TrainingWorkbook = {
    workbook_assignment_id: string;
    trb_type_id: string;
    workbook: string | null;
    reference_count: number;
    effective_task_count: number;
    completed_task_count: number;
    completed_na_task_count: number;
    completion_percentage: number;
    task_references: TrainingTask[];
};

type TrainingData = {
    training_vessels: TrainingVessel[];
    training_workbooks: TrainingWorkbook[];
    overall_task_count: number;
    overall_completed_task_count: number;
    overall_completed_na_task_count: number;
    overall_completion_percentage: number;
};

type TrainingResponse = {
    success: boolean;
    message: string;
    data: TrainingData;
};

type VesselTypeOption = {
    id: string;
    description: string | null;
};

type VesselOptionsResponse = {
    success: boolean;
    data: {
        vessel_types: VesselTypeOption[];
    };
};

type WorkbookTypeOption = {
    id: string;
    description: string | null;
    trb: string | null;
    department: string | null;
};

type WorkbookOptionsResponse = {
    success: boolean;
    message?: string;
    data: {
        workbook_types: WorkbookTypeOption[];
    };
};

type TaskFile = {
    id: string;
    filename: string | null;
    file_description: string | null;
    uploaded_date: string | null;
    file_url: string | null;
};

type TaskDetails = {
    task: {
        person_task_id: string;
        task_id: string;
        ref_no: string | null;
        description: string | null;
        completion_date: string | null;
        month_no: string | null;
        not_applicable: boolean;
    };

    competence: {
        ref_no: string | null;
        description: string | null;
    };

    sub_competence: {
        ref_no: string | null;
        description: string | null;
    };

    objective_evidence: TaskFile[];
    proof_of_assessment: TaskFile[];
};

type TaskDetailsResponse = {
    success: boolean;
    message?: string;
    data: TaskDetails;
};

type MutationResponse = {
    success: boolean;
    message?: string;
};

type SelectedTaskAttachment = {
    key: string;
    file: File;
    previewUrl: string | null;
    isImage: boolean;
};

type TaskStatus =
    | 'completed'
    | 'not_applicable'
    | 'default';

type DeleteTarget =
    | {
          type: 'vessel';
          id: string;
          label: string;
      }
    | {
          type: 'workbook';
          id: string;
          label: string;
      };

const API_URL =
    '/api/v1/student/otg';

const PRINT_URL =
    '/monitoring/otg-updates/student/print';

const VESSEL_PAGE_SIZE = 5;
const TASK_PAGE_SIZE = 20;

const MAX_TASK_ATTACHMENT_SIZE =
    20 * 1024 * 1024;

const TASK_ATTACHMENT_ACCEPT =
    '.jpg,.jpeg,.png,.gif,.pdf,.doc,.docx';

const TASK_ATTACHMENT_EXTENSIONS =
    new Set([
        'jpg',
        'jpeg',
        'png',
        'gif',
        'pdf',
        'doc',
        'docx',
    ]);

const MONTH_OPTIONS = Array.from(
    {
        length: 12,
    },
    (_, index) => ({
        label: String(index + 1),
        value: String(index + 1).padStart(
            2,
            '0',
        ),
    }),
);

const toast = useToast();

const loading = ref(true);
const errorMessage = ref('');

const training = ref<TrainingData>({
    training_vessels: [],
    training_workbooks: [],
    overall_task_count: 0,
    overall_completed_task_count: 0,
    overall_completed_na_task_count: 0,
    overall_completion_percentage: 0,
});

const expandedWorkbookId =
    ref<string | null>(null);

const taskPages =
    ref<Record<string, number>>({});

let requestController:
    | AbortController
    | null = null;

/*
|--------------------------------------------------------------------------
| Vessel modal
|--------------------------------------------------------------------------
*/

const vesselDialogVisible = ref(false);
const vesselSaving = ref(false);
const vesselOptionsLoading =
    ref(false);
const vesselFormError = ref('');
const editingVessel =
    ref<TrainingVessel | null>(
        null,
    );

const vesselTypes =
    ref<VesselTypeOption[]>([]);

const vesselName = ref('');
const vesselTypeId = ref<
    string | null
>(null);
const shipCompany = ref('');
const vesselFlag = ref('');

const vesselOnboardRange =
    ref<(Date | null)[]>([
        null,
        null,
    ]);

const signOnDate = computed<Date | null>(
    () =>
        vesselOnboardRange.value[0] ??
        null,
);

const signOffDate = computed<Date | null>(
    () =>
        vesselOnboardRange.value[1] ??
        null,
);

/*
|--------------------------------------------------------------------------
| Workbook modal
|--------------------------------------------------------------------------
*/

const workbookDialogVisible =
    ref(false);
const workbookSaving = ref(false);
const workbookOptionsLoading =
    ref(false);
const workbookError = ref('');
const workbookTypes =
    ref<WorkbookTypeOption[]>([]);
const selectedWorkbookTypeId =
    ref<string | null>(null);

/*
|--------------------------------------------------------------------------
| Task modal
|--------------------------------------------------------------------------
*/

const taskDialogVisible = ref(false);
const taskLoading = ref(false);
const taskSaving = ref(false);
const taskError = ref('');

const selectedTask =
    ref<TrainingTask | null>(null);

const selectedWorkbook =
    ref<TrainingWorkbook | null>(
        null,
    );

const taskDetails =
    ref<TaskDetails | null>(null);

const taskCompletionDate =
    ref<Date | null>(null);

const taskMonthNo =
    ref<string | null>(null);

const taskNotApplicable =
    ref(false);

const objectiveFiles =
    ref<SelectedTaskAttachment[]>([]);

const proofFiles =
    ref<SelectedTaskAttachment[]>([]);

const objectiveFileInput =
    ref<HTMLInputElement | null>(null);

const proofFileInput =
    ref<HTMLInputElement | null>(null);

const objectiveDragging = ref(false);
const proofDragging = ref(false);

const removedObjectiveFileIds =
    ref<string[]>([]);

const removedProofFileIds =
    ref<string[]>([]);

/*
|--------------------------------------------------------------------------
| Delete confirmation
|--------------------------------------------------------------------------
*/

const deleteDialogVisible =
    ref(false);

const deleting = ref(false);

const deleteTarget =
    ref<DeleteTarget | null>(null);

/*
|--------------------------------------------------------------------------
| Vessel Datatable
|--------------------------------------------------------------------------
|
| Keep the existing OTG page layout and shared Datatable.
| Match the existing shared web patterns:
| - Vessel Type + Shipping Company share one stacked cell like Dashboard.
| - Onboard Period uses the exact Started/Ended PrimeTag layout from
|   Activity Updates.
| - Existing PrimeVue action severities remain unchanged.
|
*/

const vesselColumns: DataTableColumn[] = [
    {
        field: 'vessel_name',
        header: 'Vessel',
        sortable: false,
        searchable: false,
        class: 'min-w-[230px]',
    },
    {
        field: 'vessel_type',
        header: 'Vessel Type / Company',
        sortable: false,
        searchable: false,
        class:
            'w-[290px] min-w-[290px] whitespace-normal',
    },
    {
        field: 'flag',
        header: 'Flag',
        sortable: false,
        searchable: false,
        class: 'min-w-[140px]',
    },
    {
        field: 'onboard_period',
        header: 'Onboard Period',
        sortable: true,
        searchable: false,
        class:
            'w-[260px] min-w-[260px] whitespace-normal',
    },
];

const vesselActions: DataTableAction[] = [
    {
        key: 'edit',
        label: 'Revise Vessel',
        icon: 'pi pi-pencil',
        severity: 'warn',
    },
    {
        key: 'delete',
        label: 'Remove Vessel',
        icon: 'pi pi-trash',
        severity: 'danger',
    },
];

function handleVesselAction(
    action: string,
    row: DataTableRow,
): void {
    const vesselId = String(
        row.id ?? '',
    ).trim();

    const vessel =
        training.value.training_vessels.find(
            (candidate) =>
                candidate.id === vesselId,
        );

    if (!vessel) {
        return;
    }

    if (action === 'edit') {
        void openReviseVessel(vessel);

        return;
    }

    if (action === 'delete') {
        askDeleteVessel(vessel);
    }
}

/*
|--------------------------------------------------------------------------
| Computed values
|--------------------------------------------------------------------------
*/

const overallPercentage = computed(
    () =>
        Math.max(
            0,
            Math.min(
                100,
                Number(
                    training.value
                        .overall_completion_percentage ??
                        0,
                ),
            ),
        ),
);

const vesselRows = computed<DataTableRow[]>(
    () =>
        training.value.training_vessels.map(
            (vessel) => ({
                ...vessel,
                onboard_period:
                    vesselRange(vessel),
            }),
        ),
);

const vesselDialogTitle = computed(
    () =>
        editingVessel.value
            ? 'Revise Vessel'
            : 'Add Vessel',
);

const visibleObjectiveEvidence =
    computed(
        () =>
            (
                taskDetails.value
                    ?.objective_evidence ??
                []
            ).filter(
                (file) =>
                    !removedObjectiveFileIds.value.includes(
                        file.id,
                    ),
            ),
    );

const visibleProofOfAssessment =
    computed(
        () =>
            (
                taskDetails.value
                    ?.proof_of_assessment ??
                []
            ).filter(
                (file) =>
                    !removedProofFileIds.value.includes(
                        file.id,
                    ),
            ),
    );

/*
|--------------------------------------------------------------------------
| Common helpers
|--------------------------------------------------------------------------
*/

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

function hasValidDate(
    value: string | null,
): boolean {
    const normalized =
        String(value ?? '').trim();

    return (
        normalized !== '' &&
        normalized !==
            '1970-01-01' &&
        normalized !==
            '1970-01-01 00:00:00'
    );
}

function parseDate(
    value: string | null,
): Date | null {
    if (!hasValidDate(value)) {
        return null;
    }

    const date = new Date(
        `${String(value).substring(
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

function dateForApi(
    value: Date | null,
): string {
    if (!value) {
        return '';
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

function formatDate(
    value: string | null,
): string {
    const date = parseDate(value);

    if (!date) {
        return 'Not set';
    }

    return new Intl.DateTimeFormat(
        'en-PH',
        {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        },
    ).format(date);
}

function textOrFallback(
    value: unknown,
    fallback = 'Not set',
): string {
    const text =
        String(
            value ?? '',
        ).trim();

    return text || fallback;
}

function formatPercentage(
    value: unknown,
): string {
    const numberValue =
        Number(value);

    if (
        !Number.isFinite(
            numberValue,
        )
    ) {
        return '0.00%';
    }

    return `${numberValue.toFixed(
        2,
    )}%`;
}

function vesselRange(
    vessel: TrainingVessel,
): string {
    return `${formatDate(
        vessel.sign_on_date,
    )} - ${formatDate(
        vessel.sign_off_date,
    )}`;
}

function taskStatus(
    task: TrainingTask,
): TaskStatus {
    if (
        task.not_applicable &&
        hasValidDate(
            task.completion_date,
        )
    ) {
        return 'not_applicable';
    }

    if (
        hasValidDate(
            task.completion_date,
        )
    ) {
        return 'completed';
    }

    return 'default';
}

function taskStatusLabel(
    task: TrainingTask,
): string {
    const status =
        taskStatus(task);

    if (status === 'completed') {
        return 'Completed';
    }

    if (
        status ===
        'not_applicable'
    ) {
        return 'Completed (N/A)';
    }

    return 'Default';
}

function taskTagSeverity(
    task: TrainingTask,
):
    | 'success'
    | 'warn'
    | 'info' {
    const status =
        taskStatus(task);

    if (status === 'completed') {
        return 'success';
    }

    if (
        status ===
        'not_applicable'
    ) {
        return 'warn';
    }

    return 'info';
}

function taskPage(
    workbook: TrainingWorkbook,
): number {
    return (
        taskPages.value[
            workbook
                .workbook_assignment_id
        ] ?? 1
    );
}

function totalTaskPages(
    workbook: TrainingWorkbook,
): number {
    return Math.max(
        1,
        Math.ceil(
            workbook
                .task_references
                .length /
                TASK_PAGE_SIZE,
        ),
    );
}

function visibleTasks(
    workbook: TrainingWorkbook,
): TrainingTask[] {
    const page =
        taskPage(workbook);

    const startIndex =
        (page - 1) *
        TASK_PAGE_SIZE;

    return workbook
        .task_references
        .slice(
            startIndex,
            startIndex +
                TASK_PAGE_SIZE,
        );
}

function setTaskPage(
    workbook: TrainingWorkbook,
    page: number,
): void {
    taskPages.value = {
        ...taskPages.value,

        [workbook
            .workbook_assignment_id]:
            Math.max(
                1,
                Math.min(
                    totalTaskPages(
                        workbook,
                    ),
                    page,
                ),
            ),
    };
}

function toggleWorkbook(
    workbook: TrainingWorkbook,
): void {
    expandedWorkbookId.value =
        expandedWorkbookId.value ===
        workbook
            .workbook_assignment_id
            ? null
            : workbook
                  .workbook_assignment_id;
}

function openFile(
    url: string | null,
): void {
    if (!url) {
        return;
    }

    window.open(
        url,
        '_blank',
        'noopener,noreferrer',
    );
}

/*
|--------------------------------------------------------------------------
| Main OTG loader / print
|--------------------------------------------------------------------------
*/

async function loadTraining(): Promise<void> {
    requestController?.abort();

    const controller =
        new AbortController();

    requestController = controller;

    loading.value = true;
    errorMessage.value = '';

    try {
        const response =
            await axios.get<TrainingResponse>(
                API_URL,
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

        training.value = {
            training_vessels:
                Array.isArray(
                    response.data.data
                        ?.training_vessels,
                )
                    ? response.data.data
                          .training_vessels
                    : [],

            training_workbooks:
                Array.isArray(
                    response.data.data
                        ?.training_workbooks,
                )
                    ? response.data.data
                          .training_workbooks
                    : [],

            overall_task_count:
                Number(
                    response.data.data
                        ?.overall_task_count ??
                        0,
                ),

            overall_completed_task_count:
                Number(
                    response.data.data
                        ?.overall_completed_task_count ??
                        0,
                ),

            overall_completed_na_task_count:
                Number(
                    response.data.data
                        ?.overall_completed_na_task_count ??
                        0,
                ),

            overall_completion_percentage:
                Number(
                    response.data.data
                        ?.overall_completion_percentage ??
                        0,
                ),
        };
        if (
            expandedWorkbookId.value &&
            !training.value
                .training_workbooks
                .some(
                    (workbook) =>
                        workbook
                            .workbook_assignment_id ===
                        expandedWorkbookId.value,
                )
        ) {
            expandedWorkbookId.value =
                null;
        }
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

        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to load your Training Record Book.',
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

function printEtrb(): void {
    window.open(
        PRINT_URL,
        '_blank',
        'noopener,noreferrer',
    );
}

/*
|--------------------------------------------------------------------------
| Vessel CRUD
|--------------------------------------------------------------------------
*/

function resetVesselForm(): void {
    editingVessel.value = null;
    vesselName.value = '';
    vesselTypeId.value = null;
    shipCompany.value = '';
    vesselFlag.value = '';

    vesselOnboardRange.value = [
        null,
        null,
    ];

    vesselFormError.value = '';
}

async function loadVesselOptions(): Promise<void> {
    vesselOptionsLoading.value = true;

    try {
        const response =
            await axios.get<VesselOptionsResponse>(
                `${API_URL}/vessel-options`,
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

        vesselTypes.value =
            response.data.data
                ?.vessel_types ??
            [];
    } catch (error: unknown) {
        vesselFormError.value =
            getErrorMessage(
                error,
                'Unable to load vessel types.',
            );
    } finally {
        vesselOptionsLoading.value =
            false;
    }
}

async function openAddVessel(): Promise<void> {
    resetVesselForm();

    vesselDialogVisible.value =
        true;

    await loadVesselOptions();
}

async function openReviseVessel(
    vessel: TrainingVessel,
): Promise<void> {
    resetVesselForm();

    editingVessel.value =
        vessel;

    vesselName.value =
        vessel.vessel_name ?? '';

    vesselTypeId.value =
        vessel.vessel_type_id;

    shipCompany.value =
        vessel.ship_company ?? '';

    vesselFlag.value =
        vessel.flag ?? '';

    vesselOnboardRange.value = [
        parseDate(
            vessel.sign_on_date,
        ),
        parseDate(
            vessel.sign_off_date,
        ),
    ];

    vesselDialogVisible.value =
        true;

    await loadVesselOptions();
}

function validateVesselForm(): boolean {
    if (!vesselName.value.trim()) {
        vesselFormError.value =
            'Name of Vessel is required.';

        return false;
    }

    if (!vesselTypeId.value) {
        vesselFormError.value =
            'Vessel Type is required.';

        return false;
    }

    if (!shipCompany.value.trim()) {
        vesselFormError.value =
            'Shipping Company is required.';

        return false;
    }

    if (!vesselFlag.value.trim()) {
        vesselFormError.value =
            'Flag Nationality is required.';

        return false;
    }

    if (
        !signOnDate.value ||
        !signOffDate.value
    ) {
        vesselFormError.value =
            'Sign-On Date and Sign-Off Date are required.';

        return false;
    }

    if (
        signOffDate.value.getTime() <
        signOnDate.value.getTime()
    ) {
        vesselFormError.value =
            'The Sign-Off Date cannot be earlier than the Sign-On Date.';

        return false;
    }

    vesselFormError.value = '';

    return true;
}

async function saveVessel(): Promise<void> {
    if (
        vesselSaving.value ||
        !validateVesselForm()
    ) {
        return;
    }

    vesselSaving.value = true;
    vesselFormError.value = '';

    const payload = {
        vessel_name:
            vesselName.value.trim(),

        vessel_type_id:
            vesselTypeId.value,

        ship_company:
            shipCompany.value.trim(),

        flag:
            vesselFlag.value.trim(),

        sign_on_date:
            dateForApi(
                signOnDate.value,
            ),

        sign_off_date:
            dateForApi(
                signOffDate.value,
            ),
    };

    try {
        const response =
            editingVessel.value
                ? await axios.put<MutationResponse>(
                      `${API_URL}/vessels/${encodeURIComponent(
                          editingVessel.value
                              .id,
                      )}`,
                      payload,
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
                : await axios.post<MutationResponse>(
                      `${API_URL}/vessels`,
                      payload,
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

        toast.add({
            severity: 'success',
            summary:
                editingVessel.value
                    ? 'Vessel Revised'
                    : 'Vessel Added',
            detail:
                response.data.message ||
                (
                    editingVessel.value
                        ? 'Vessel updated successfully.'
                        : 'Vessel added successfully.'
                ),
            life: 3500,
        });

        vesselDialogVisible.value =
            false;

        resetVesselForm();

        await loadTraining();
    } catch (error: unknown) {
        vesselFormError.value =
            getErrorMessage(
                error,
                'Unable to save the vessel.',
            );
    } finally {
        vesselSaving.value = false;
    }
}

/*
|--------------------------------------------------------------------------
| Workbook add/remove
|--------------------------------------------------------------------------
*/

async function openAddWorkbook(): Promise<void> {
    workbookDialogVisible.value =
        true;

    workbookOptionsLoading.value =
        true;

    workbookError.value = '';

    selectedWorkbookTypeId.value =
        null;

    try {
        const response =
            await axios.get<WorkbookOptionsResponse>(
                `${API_URL}/workbook-options`,
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

        workbookTypes.value =
            response.data.data
                ?.workbook_types ??
            [];

        if (
            workbookTypes.value.length ===
            0
        ) {
            workbookError.value =
                response.data.message ||
                'No additional eligible workbooks are available.';
        }
    } catch (error: unknown) {
        workbookError.value =
            getErrorMessage(
                error,
                'Unable to load workbook options.',
            );
    } finally {
        workbookOptionsLoading.value =
            false;
    }
}

async function addWorkbook(): Promise<void> {
    if (
        workbookSaving.value ||
        !selectedWorkbookTypeId.value
    ) {
        if (
            !selectedWorkbookTypeId.value
        ) {
            workbookError.value =
                'Select a TRB / Workbook.';
        }

        return;
    }

    workbookSaving.value = true;
    workbookError.value = '';

    try {
        const response =
            await axios.post<MutationResponse>(
                `${API_URL}/workbooks`,
                {
                    trb_type_id:
                        selectedWorkbookTypeId.value,
                },
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
                'Workbook Added',
            detail:
                response.data.message ||
                'Workbook added successfully.',
            life: 3500,
        });

        workbookDialogVisible.value =
            false;

        selectedWorkbookTypeId.value =
            null;

        await loadTraining();
    } catch (error: unknown) {
        workbookError.value =
            getErrorMessage(
                error,
                'Unable to add the workbook.',
            );
    } finally {
        workbookSaving.value = false;
    }
}

/*
|--------------------------------------------------------------------------
| Compact OTG attachment uploader
|--------------------------------------------------------------------------
|
| Follow the senior Student Batch Upload behavior:
| - native hidden file input
| - drag/drop surface
| - PrimeVue info/outlined choose button
|
| Follow the Student Messages attachment presentation:
| - image thumbnail when an image can be previewed
| - generic file tile when preview is unavailable
| - never show the filename in the UI
|
*/

function taskAttachmentKey(
    file: File,
): string {
    return `${file.name}:${file.size}:${file.lastModified}`;
}

function isImagePath(
    value: string | null | undefined,
): boolean {
    return /\.(jpg|jpeg|png|gif)(?:[?#].*)?$/i.test(
        String(value ?? ''),
    );
}

function isExistingTaskImage(
    file: TaskFile,
): boolean {
    return (
        isImagePath(file.filename) ||
        isImagePath(file.file_url)
    );
}

function createTaskAttachment(
    file: File,
): SelectedTaskAttachment {
    const isImage =
        file.type
            .toLowerCase()
            .startsWith('image/') ||
        isImagePath(file.name);

    return {
        key: taskAttachmentKey(file),
        file,
        previewUrl: isImage
            ? URL.createObjectURL(file)
            : null,
        isImage,
    };
}

function revokeTaskAttachments(
    attachments: SelectedTaskAttachment[],
): void {
    for (const attachment of attachments) {
        if (attachment.previewUrl) {
            URL.revokeObjectURL(
                attachment.previewUrl,
            );
        }
    }
}

function clearTaskAttachmentSelections(): void {
    revokeTaskAttachments(
        objectiveFiles.value,
    );

    revokeTaskAttachments(
        proofFiles.value,
    );

    objectiveFiles.value = [];
    proofFiles.value = [];

    objectiveDragging.value = false;
    proofDragging.value = false;

    if (objectiveFileInput.value) {
        objectiveFileInput.value.value =
            '';
    }

    if (proofFileInput.value) {
        proofFileInput.value.value = '';
    }
}

function validateTaskAttachment(
    file: File,
): string | null {
    const extension =
        file.name
            .split('.')
            .pop()
            ?.toLowerCase() ??
        '';

    if (
        !TASK_ATTACHMENT_EXTENSIONS.has(
            extension,
        )
    ) {
        return 'Unsupported file type. Use JPG, JPEG, PNG, GIF, PDF, DOC or DOCX.';
    }

    if (
        file.size >
        MAX_TASK_ATTACHMENT_SIZE
    ) {
        return 'Each attachment must not exceed 20 MB.';
    }

    return null;
}

function appendTaskAttachments(
    target:
        | 'objective'
        | 'proof',
    files: File[],
): void {
    const collection =
        target === 'objective'
            ? objectiveFiles
            : proofFiles;

    const existingKeys =
        new Set(
            collection.value.map(
                (attachment) =>
                    attachment.key,
            ),
        );

    const rejected: string[] = [];

    for (const file of files) {
        const validationError =
            validateTaskAttachment(file);

        if (validationError) {
            rejected.push(
                validationError,
            );

            continue;
        }

        const attachment =
            createTaskAttachment(file);

        if (
            existingKeys.has(
                attachment.key,
            )
        ) {
            if (
                attachment.previewUrl
            ) {
                URL.revokeObjectURL(
                    attachment.previewUrl,
                );
            }

            continue;
        }

        existingKeys.add(
            attachment.key,
        );

        collection.value.push(
            attachment,
        );
    }

    if (rejected.length > 0) {
        taskError.value =
            [...new Set(rejected)].join(
                ' ',
            );
    } else {
        taskError.value = '';
    }
}

function openTaskFilePicker(
    target:
        | 'objective'
        | 'proof',
): void {
    if (taskSaving.value) {
        return;
    }

    if (target === 'objective') {
        objectiveFileInput.value
            ?.click();

        return;
    }

    proofFileInput.value?.click();
}

function handleTaskFileInput(
    event: Event,
    target:
        | 'objective'
        | 'proof',
): void {
    const input =
        event.target as HTMLInputElement;

    appendTaskAttachments(
        target,
        Array.from(
            input.files ?? [],
        ),
    );

    input.value = '';
}

function handleTaskFileDragOver(
    event: DragEvent,
    target:
        | 'objective'
        | 'proof',
): void {
    if (taskSaving.value) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();

    if (event.dataTransfer) {
        event.dataTransfer.dropEffect =
            'copy';
    }

    if (target === 'objective') {
        objectiveDragging.value = true;

        return;
    }

    proofDragging.value = true;
}

function handleTaskFileDragLeave(
    event: DragEvent,
    target:
        | 'objective'
        | 'proof',
): void {
    event.preventDefault();
    event.stopPropagation();

    if (target === 'objective') {
        objectiveDragging.value = false;

        return;
    }

    proofDragging.value = false;
}

function handleTaskFileDrop(
    event: DragEvent,
    target:
        | 'objective'
        | 'proof',
): void {
    event.preventDefault();
    event.stopPropagation();

    if (target === 'objective') {
        objectiveDragging.value = false;
    } else {
        proofDragging.value = false;
    }

    if (taskSaving.value) {
        return;
    }

    appendTaskAttachments(
        target,
        Array.from(
            event.dataTransfer
                ?.files ?? [],
        ),
    );
}

function removeSelectedTaskAttachment(
    target:
        | 'objective'
        | 'proof',
    attachment:
        SelectedTaskAttachment,
): void {
    if (attachment.previewUrl) {
        URL.revokeObjectURL(
            attachment.previewUrl,
        );
    }

    const collection =
        target === 'objective'
            ? objectiveFiles
            : proofFiles;

    collection.value =
        collection.value.filter(
            (item) =>
                item.key !==
                attachment.key,
        );
}

/*
|--------------------------------------------------------------------------
| Task revise / evidence / proof
|--------------------------------------------------------------------------
*/

function resetTaskForm(): void {
    if (!taskDetails.value) {
        taskCompletionDate.value =
            null;

        taskMonthNo.value = null;
        taskNotApplicable.value =
            false;

        clearTaskAttachmentSelections();

        removedObjectiveFileIds.value =
            [];

        removedProofFileIds.value =
            [];

        return;
    }

    taskCompletionDate.value =
        parseDate(
            taskDetails.value.task
                .completion_date,
        );

    taskMonthNo.value =
        taskDetails.value.task
            .month_no;

    taskNotApplicable.value =
        taskDetails.value.task
            .not_applicable;

    clearTaskAttachmentSelections();

    removedObjectiveFileIds.value =
        [];

    removedProofFileIds.value = [];

    taskError.value = '';
}

async function openTask(
    task: TrainingTask,
    workbook: TrainingWorkbook,
): Promise<void> {
    if (!task.person_task_id) {
        errorMessage.value =
            'The selected task has not been initialized yet. Refresh the page and try again.';

        return;
    }

    selectedTask.value = task;
    selectedWorkbook.value =
        workbook;

    taskDialogVisible.value =
        true;

    taskLoading.value = true;
    taskError.value = '';
    taskDetails.value = null;

    try {
        const response =
            await axios.get<TaskDetailsResponse>(
                `${API_URL}/tasks/${encodeURIComponent(
                    task.person_task_id,
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

        taskDetails.value =
            response.data.data;

        resetTaskForm();
    } catch (error: unknown) {
        taskError.value =
            getErrorMessage(
                error,
                'Unable to load the task.',
            );
    } finally {
        taskLoading.value = false;
    }
}

function markObjectiveForRemoval(
    fileId: string,
): void {
    if (
        !removedObjectiveFileIds.value.includes(
            fileId,
        )
    ) {
        removedObjectiveFileIds.value = [
            ...removedObjectiveFileIds.value,
            fileId,
        ];
    }
}

function undoObjectiveRemoval(
    fileId: string,
): void {
    removedObjectiveFileIds.value =
        removedObjectiveFileIds.value.filter(
            (id) => id !== fileId,
        );
}

function markProofForRemoval(
    fileId: string,
): void {
    if (
        !removedProofFileIds.value.includes(
            fileId,
        )
    ) {
        removedProofFileIds.value = [
            ...removedProofFileIds.value,
            fileId,
        ];
    }
}

function undoProofRemoval(
    fileId: string,
): void {
    removedProofFileIds.value =
        removedProofFileIds.value.filter(
            (id) => id !== fileId,
        );
}

function validateTaskForm(): boolean {
    if (!taskCompletionDate.value) {
        taskError.value =
            'Please select the Completion Date.';

        return false;
    }

    if (!taskMonthNo.value) {
        taskError.value =
            'Please select the Amount of Months Onboard.';

        return false;
    }

    /*
     * Match the current mobile task editor:
     * N/A does not require Objective Evidence.
     */
    if (taskNotApplicable.value) {
        taskError.value = '';

        return true;
    }

    const objectiveCount =
        visibleObjectiveEvidence.value
            .length +
        objectiveFiles.value.length;

    const proofCount =
        visibleProofOfAssessment.value
            .length +
        proofFiles.value.length;

    if (objectiveCount === 0) {
        taskError.value =
            'Add at least one Objective Evidence file before submitting this task.';

        return false;
    }

    if (proofCount === 0) {
        taskError.value =
            'Add at least one Proof of Assessment file before submitting this task.';

        return false;
    }

    taskError.value = '';

    return true;
}

async function removeMarkedTaskFiles(): Promise<void> {
    for (
        const fileId of
        removedObjectiveFileIds.value
    ) {
        await axios.delete<MutationResponse>(
            `${API_URL}/tasks/objective-evidence/${encodeURIComponent(
                fileId,
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
    }

    for (
        const fileId of
        removedProofFileIds.value
    ) {
        await axios.delete<MutationResponse>(
            `${API_URL}/tasks/proof-of-assessment/${encodeURIComponent(
                fileId,
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
    }
}

async function saveTask(): Promise<void> {
    if (
        taskSaving.value ||
        !taskDetails.value ||
        !validateTaskForm()
    ) {
        return;
    }

    taskSaving.value = true;
    taskError.value = '';

    const personTaskId =
        taskDetails.value.task
            .person_task_id;

    const formData =
        new FormData();

    formData.append(
        'completion_date',
        dateForApi(
            taskCompletionDate.value,
        ),
    );

    formData.append(
        'month_no',
        taskMonthNo.value ?? '',
    );

    formData.append(
        'not_app',
        taskNotApplicable.value
            ? 'Y'
            : 'N',
    );

    if (!taskNotApplicable.value) {
        for (
            const attachment of
            objectiveFiles.value
        ) {
            formData.append(
                'objective_evidence_files[]',
                attachment.file,
            );
        }
    }

    for (
        const attachment of
        proofFiles.value
    ) {
        formData.append(
            'proof_assessment_files[]',
            attachment.file,
        );
    }

    try {
        const response =
            await axios.post<MutationResponse>(
                `${API_URL}/tasks/${encodeURIComponent(
                    personTaskId,
                )}`,
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

        /*
         * Match mobile behavior: existing files marked for removal are
         * actually deleted only when Submit/Save is pressed.
         */
        await removeMarkedTaskFiles();

        toast.add({
            severity: 'success',
            summary:
                'Task Updated',
            detail:
                response.data.message ||
                'Training task updated successfully.',
            life: 3500,
        });

        clearTaskAttachmentSelections();

        taskDialogVisible.value =
            false;

        taskDetails.value = null;
        selectedTask.value = null;
        selectedWorkbook.value = null;

        await loadTraining();
    } catch (error: unknown) {
        taskError.value =
            getErrorMessage(
                error,
                'Unable to update the training task.',
            );
    } finally {
        taskSaving.value = false;
    }
}

/*
|--------------------------------------------------------------------------
| Delete vessel/workbook
|--------------------------------------------------------------------------
*/

function askDeleteVessel(
    vessel: TrainingVessel,
): void {
    deleteTarget.value = {
        type: 'vessel',
        id: vessel.id,
        label:
            vessel.vessel_name ||
            'this vessel',
    };

    deleteDialogVisible.value =
        true;
}

function askRemoveWorkbook(
    workbook: TrainingWorkbook,
): void {
    deleteTarget.value = {
        type: 'workbook',
        id:
            workbook
                .workbook_assignment_id,
        label:
            workbook.workbook ||
            'this workbook',
    };

    deleteDialogVisible.value =
        true;
}

async function confirmDelete(): Promise<void> {
    const target =
        deleteTarget.value;

    if (!target || deleting.value) {
        return;
    }

    deleting.value = true;

    try {
        const response =
            target.type === 'vessel'
                ? await axios.delete<MutationResponse>(
                      `${API_URL}/vessels/${encodeURIComponent(
                          target.id,
                      )}`,
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
                : await axios.delete<MutationResponse>(
                      `${API_URL}/workbooks/${encodeURIComponent(
                          target.id,
                      )}`,
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

        toast.add({
            severity: 'success',
            summary:
                target.type ===
                'vessel'
                    ? 'Vessel Removed'
                    : 'Workbook Removed',
            detail:
                response.data.message ||
                'Record removed successfully.',
            life: 3500,
        });

        deleteDialogVisible.value =
            false;

        deleteTarget.value = null;

        await loadTraining();
    } catch (error: unknown) {
        errorMessage.value =
            getErrorMessage(
                error,
                target.type ===
                    'vessel'
                    ? 'Unable to remove the vessel.'
                    : 'Unable to remove the workbook.',
            );
    } finally {
        deleting.value = false;
    }
}

onMounted(() => {
    void loadTraining();
});

onBeforeUnmount(() => {
    requestController?.abort();
    clearTaskAttachmentSelections();
});
</script>

<template>
    <Head
        title="Training Record Book (OTG)"
    />

    <Toast position="top-right" />

    <div
        class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5"
    >
        <Message
            v-if="errorMessage"
            severity="error"
            closable
            class="mb-4"
            @close="errorMessage = ''"
        >
            {{ errorMessage }}
        </Message>

        <!-- WEB APPLICATION HEADER -->
        <div
            class="mb-4 flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-5 shadow-sm md:flex-row md:items-center md:justify-between"
        >
            <div
                class="flex min-w-0 items-center gap-3"
            >
                <!-- Same module icon treatment used by the shared Datatable header. -->
                <div
                    class="relative flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-gradient-to-br from-[#123A63] to-[#377EC0] text-white shadow-lg shadow-[#377EC0]/20"
                >
                    <div
                        class="pointer-events-none absolute -top-3 -right-3 size-8 rounded-full bg-white/15"
                    ></div>

                    <i
                        class="pi pi-book relative z-10 !text-[1.65rem] !leading-none !text-white"
                    ></i>
                </div>

                <div class="min-w-0">
                    <h1
                        class="text-xl font-semibold text-slate-900"
                    >
                        Training Record Book
                        (OTG)
                    </h1>

                    <p
                        class="mt-1 text-sm text-slate-500"
                    >
                        Manage your vessels,
                        assigned workbooks, and
                        OTG task completion.
                    </p>
                </div>
            </div>

            <div
                class="flex flex-wrap items-center gap-2"
            >
                <Button
                    type="button"
                    label="Refresh"
                    icon="pi pi-refresh"
                    severity="secondary"
                    variant="outlined"
                    :loading="loading"
                    @click="loadTraining"
                />

                <Button
                    type="button"
                    label="Print eTRB"
                    icon="pi pi-print"
                    severity="secondary"
                    variant="outlined"
                    @click="printEtrb"
                />
            </div>
        </div>

        <!-- INITIAL LOADING -->
        <div
            v-if="loading"
            class="flex min-h-[320px] items-center justify-center"
            role="status"
            aria-label="Loading Training Record Book"
        >
            <i
                class="pi pi-spin pi-spinner text-3xl text-[#377EC0]"
                aria-hidden="true"
            ></i>
        </div>

        <template v-else>
            <div
                class="mb-4 grid gap-4 md:grid-cols-2 xl:grid-cols-5"
            >
                <div
                    class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"
                >
                    <div
                        class="flex items-center justify-between"
                    >
                        <div>
                            <p
                                class="text-xs font-medium uppercase tracking-wide text-slate-500"
                            >
                                Vessels
                            </p>

                            <p
                                class="mt-2 text-2xl font-semibold text-slate-900"
                            >
                                {{
                                    training
                                        .training_vessels
                                        .length
                                }}
                            </p>
                        </div>

                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-lg border border-blue-100 bg-blue-50 text-[#377EC0]"
                        >
                            <i
                                class="pi pi-compass"
                            ></i>
                        </div>
                    </div>
                </div>

                <div
                    class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"
                >
                    <div
                        class="flex items-center justify-between"
                    >
                        <div>
                            <p
                                class="text-xs font-medium uppercase tracking-wide text-slate-500"
                            >
                                Tasks Required
                            </p>

                            <p
                                class="mt-2 text-2xl font-semibold text-slate-900"
                            >
                                {{
                                    training
                                        .overall_task_count
                                }}
                            </p>
                        </div>

                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-lg border border-blue-100 bg-blue-50 text-blue-500"
                        >
                            <i
                                class="pi pi-list-check"
                            ></i>
                        </div>
                    </div>
                </div>

                <div
                    class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"
                >
                    <div
                        class="flex items-center justify-between"
                    >
                        <div>
                            <p
                                class="text-xs font-medium uppercase tracking-wide text-slate-500"
                            >
                                Completed
                            </p>

                            <p
                                class="mt-2 text-2xl font-semibold text-[#26b565]"
                            >
                                {{
                                    training
                                        .overall_completed_task_count
                                }}
                            </p>
                        </div>

                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-lg border border-[#cfeedd] bg-[#eaf9f0] text-[#26b565]"
                        >
                            <i
                                class="pi pi-check-circle"
                            ></i>
                        </div>
                    </div>
                </div>

                <div
                    class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"
                >
                    <div
                        class="flex items-center justify-between"
                    >
                        <div>
                            <p
                                class="text-xs font-medium uppercase tracking-wide text-slate-500"
                            >
                                Completed (N/A)
                            </p>

                            <p
                                class="mt-2 text-2xl font-semibold text-[#f59e0b]"
                            >
                                {{
                                    training
                                        .overall_completed_na_task_count
                                }}
                            </p>
                        </div>

                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-lg border border-[#fde7a7] bg-[#fff7df] text-[#f59e0b]"
                        >
                            <i
                                class="pi pi-check-circle"
                            ></i>
                        </div>
                    </div>
                </div>

                <div
                    class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"
                >
                    <div
                        class="flex items-center justify-between"
                    >
                        <div>
                            <p
                                class="text-xs font-medium uppercase tracking-wide text-slate-500"
                            >
                                Completion
                            </p>

                            <p
                                class="mt-2 text-2xl font-semibold text-[#377EC0]"
                            >
                                {{
                                    formatPercentage(
                                        overallPercentage,
                                    )
                                }}
                            </p>
                        </div>

                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-lg border border-blue-100 bg-blue-50 text-[#377EC0]"
                        >
                            <i
                                class="pi pi-chart-line"
                            ></i>
                        </div>
                    </div>

                    <div
                        class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100"
                    >
                        <div
                            class="h-full rounded-full bg-[#377EC0] transition-all"
                            :style="{
                                width:
                                    `${overallPercentage}%`,
                            }"
                        ></div>
                    </div>
                </div>
            </div>

            <!-- VESSEL MANAGEMENT -->
            <Datatable
                title="Vessel Information"
                description="Add, revise, or remove your onboard vessel assignments."
                header-icon=""
                search-placeholder="Search vessels..."
                empty-title="No vessel records"
                empty-description="Add your first onboard vessel assignment."
                empty-icon="pi pi-compass"
                table-min-width="930px"
                actions-width="120px"
                actions-header="Actions"
                data-key="id"
                :loading="false"
                :data="vesselRows"
                :columns="vesselColumns"
                :actions="vesselActions"
                :searchable="false"
                :paginator="
                    training.training_vessels
                        .length >
                    VESSEL_PAGE_SIZE
                "
                :rows="VESSEL_PAGE_SIZE"
                :rows-per-page-options="[
                    5,
                    10,
                    20,
                ]"
                @action="handleVesselAction"
            >
                <template #header-actions>
                    <Button
                        type="button"
                        label="Add Vessel"
                        icon="pi pi-plus"
                        severity="success"
                        size="small"
                        @click="openAddVessel"
                    />
                </template>

                <template
                    #cell-vessel_name="{
                        value,
                    }"
                >
                    <div
                        class="flex min-w-0 items-center gap-3"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-blue-100 bg-blue-50 text-[#377EC0]"
                        >
                            <i
                                class="pi pi-compass text-sm"
                            ></i>
                        </div>

                        <span
                            class="font-medium text-slate-900"
                        >
                            {{
                                textOrFallback(
                                    value,
                                    'Vessel name unavailable',
                                )
                            }}
                        </span>
                    </div>
                </template>

                <template
                    #cell-vessel_type="{ data }"
                >
                    <div
                        class="w-full min-w-0 space-y-1.5 whitespace-normal"
                    >
                        <div
                            class="flex min-w-0 items-start gap-2"
                        >
                            <i
                                class="pi pi-compass mt-0.5 shrink-0 text-sm font-bold text-[#377EC0]"
                            ></i>

                            <span
                                class="min-w-0 flex-1 text-sm leading-5 font-semibold break-words whitespace-normal text-slate-700 uppercase [overflow-wrap:anywhere]"
                            >
                                {{
                                    textOrFallback(
                                        data.vessel_type,
                                    )
                                }}
                            </span>
                        </div>

                        <div
                            class="flex min-w-0 items-start gap-2"
                        >
                            <i
                                class="pi pi-building mt-0.5 shrink-0 text-sm font-bold text-slate-400"
                            ></i>

                            <span
                                class="min-w-0 flex-1 text-xs leading-4 font-medium break-words whitespace-normal text-slate-500 uppercase [overflow-wrap:anywhere]"
                            >
                                {{
                                    textOrFallback(
                                        data.ship_company,
                                    )
                                }}
                            </span>
                        </div>
                    </div>
                </template>

                <template
                    #cell-flag="{ value }"
                >
                    <span
                        class="text-sm text-slate-600"
                    >
                        {{
                            textOrFallback(
                                value,
                            )
                        }}
                    </span>
                </template>

                <template
                    #cell-onboard_period="{ data }"
                >
                    <div class="space-y-2">
                        <div
                            class="flex items-center gap-2"
                        >
                            <PrimeTag
                                value="Started"
                                severity="success"
                                class="w-16 !justify-center !px-2 !py-1 !text-xs !font-semibold"
                            />

                            <span
                                class="whitespace-nowrap text-sm font-medium text-slate-600"
                            >
                                {{
                                    formatDate(
                                        data.sign_on_date,
                                    )
                                }}
                            </span>
                        </div>

                        <div
                            class="flex items-center gap-2"
                        >
                            <PrimeTag
                                value="Ended"
                                severity="danger"
                                class="w-16 !justify-center !px-2 !py-1 !text-xs !font-semibold"
                            />

                            <span
                                class="whitespace-nowrap text-sm font-medium text-slate-600"
                            >
                                {{
                                    formatDate(
                                        data.sign_off_date,
                                    )
                                }}
                            </span>
                        </div>
                    </div>
                </template>
            </Datatable>

            <!-- WORKBOOK MANAGEMENT -->
            <section
                class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm"
            >
                <div
                    class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 md:flex-row md:items-center md:justify-between"
                >
                    <div>
                        <h2
                            class="font-semibold text-slate-900"
                        >
                            TRB / Workbooks
                        </h2>

                        <p
                            class="mt-1 text-sm text-slate-500"
                        >
                            Add or remove eligible
                            workbooks and revise
                            their assigned OTG
                            tasks.
                        </p>
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-2"
                    >
                        <Button
                            type="button"
                            label="Add Workbook"
                            icon="pi pi-plus"
                            severity="success"
                            size="small"
                            @click="openAddWorkbook"
                        />

                        <Button
                            type="button"
                            label="Print eTRB"
                            icon="pi pi-print"
                            severity="secondary"
                            variant="outlined"
                            size="small"
                            @click="printEtrb"
                        />
                    </div>
                </div>

                <div
                    v-if="
                        training
                            .training_workbooks
                            .length === 0
                    "
                    class="px-5 py-12 text-center"
                >
                    <div
                        class="mx-auto flex h-12 w-12 items-center justify-center rounded-lg border border-blue-100 bg-blue-50 text-[#377EC0]"
                    >
                        <i
                            class="pi pi-book text-xl"
                        ></i>
                    </div>

                    <p
                        class="mt-3 font-medium text-slate-700"
                    >
                        No TRB workbooks assigned
                    </p>

                    <Button
                        type="button"
                        label="Add Workbook"
                        icon="pi pi-plus"
                        severity="success"
                        class="mt-4"
                        @click="openAddWorkbook"
                    />
                </div>

                <div v-else>
                    <article
                        v-for="(
                            workbook,
                            workbookIndex
                        ) in training
                            .training_workbooks"
                        :key="
                            workbook.workbook_assignment_id
                        "
                        class="border-b border-slate-200 last:border-b-0"
                    >
                        <div
                            class="flex items-center gap-2 px-5 py-4 hover:bg-slate-50"
                        >
                            <button
                                type="button"
                                class="flex min-w-0 flex-1 items-center gap-4 text-left"
                                @click="
                                    toggleWorkbook(
                                        workbook,
                                    )
                                "
                            >
                                <div
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-blue-100 bg-gradient-to-br from-blue-50 to-blue-100 text-[#377EC0]"
                                >
                                    <i
                                        class="pi pi-book"
                                    ></i>
                                </div>

                                <div
                                    class="min-w-0 flex-1"
                                >
                                    <div
                                        class="font-medium text-slate-900"
                                    >
                                        {{
                                            workbookIndex +
                                            1
                                        }}.
                                        {{
                                            textOrFallback(
                                                workbook.workbook,
                                                'Workbook name unavailable',
                                            )
                                        }}
                                    </div>

                                    <div
                                        class="mt-1 text-sm text-slate-500"
                                    >
                                        {{
                                            workbook.completed_task_count
                                        }}
                                        completed
                                        of
                                        {{
                                            workbook.effective_task_count
                                        }}
                                        required
                                        tasks
                                    </div>
                                </div>

                                <PrimeTag
                                    :value="
                                        formatPercentage(
                                            workbook.completion_percentage,
                                        )
                                    "
                                    severity="info"
                                    rounded
                                    class="hidden sm:inline-flex"
                                />

                                <i
                                    class="pi text-slate-400"
                                    :class="
                                        expandedWorkbookId ===
                                        workbook.workbook_assignment_id
                                            ? 'pi-chevron-up'
                                            : 'pi-chevron-down'
                                    "
                                ></i>
                            </button>

                            <Button
                                type="button"
                                icon="pi pi-trash"
                                icon-only
                                rounded
                                raised
                                aria-label="Remove workbook"
                                title="Remove workbook"
                                severity="danger"
                                class="!size-10 !min-h-10 !min-w-10 !shrink-0 !p-0 transition-all duration-200 enabled:hover:!-translate-y-0.5 enabled:hover:!shadow-lg disabled:!cursor-not-allowed disabled:!opacity-40"
                                @click="
                                    askRemoveWorkbook(
                                        workbook,
                                    )
                                "
                            />
                        </div>

                        <div
                            v-if="
                                expandedWorkbookId ===
                                workbook.workbook_assignment_id
                            "
                            class="border-t border-slate-100 bg-slate-50/50 px-5 py-5"
                        >
                            <div
                                class="mb-4 grid gap-3 sm:grid-cols-3"
                            >
                                <div
                                    class="rounded-lg border border-slate-200 bg-white p-3"
                                >
                                    <p
                                        class="text-xs font-medium text-slate-500"
                                    >
                                        References
                                    </p>

                                    <p
                                        class="mt-1 text-xl font-semibold text-slate-900"
                                    >
                                        {{
                                            workbook.reference_count
                                        }}
                                    </p>
                                </div>

                                <div
                                    class="rounded-lg border border-slate-200 bg-white p-3"
                                >
                                    <p
                                        class="text-xs font-medium text-slate-500"
                                    >
                                        Completed
                                    </p>

                                    <p
                                        class="mt-1 text-xl font-semibold text-emerald-600"
                                    >
                                        {{
                                            workbook.completed_task_count
                                        }}
                                    </p>
                                </div>

                                <div
                                    class="rounded-lg border border-slate-200 bg-white p-3"
                                >
                                    <p
                                        class="text-xs font-medium text-slate-500"
                                    >
                                        Completed (N/A)
                                    </p>

                                    <p
                                        class="mt-1 text-xl font-semibold text-[#f59e0b]"
                                    >
                                        {{
                                            workbook.completed_na_task_count
                                        }}
                                    </p>
                                </div>
                            </div>

                            <div
                                class="mb-4 flex flex-wrap items-center gap-3 text-xs font-medium text-slate-500"
                            >
                                <span
                                    class="inline-flex items-center gap-1.5"
                                >
                                    <span
                                        class="h-2.5 w-2.5 rounded-full bg-[#26b565]"
                                    ></span>
                                    Completed
                                </span>

                                <span
                                    class="inline-flex items-center gap-1.5"
                                >
                                    <span
                                        class="h-2.5 w-2.5 rounded-full bg-[#f59e0b]"
                                    ></span>
                                    Completed (N/A)
                                </span>

                                <span
                                    class="inline-flex items-center gap-1.5"
                                >
                                    <span
                                        class="h-2.5 w-2.5 rounded-full bg-[#377EC0]"
                                    ></span>
                                    Default
                                </span>
                            </div>

                            <div
                                v-if="
                                    workbook
                                        .task_references
                                        .length >
                                    0
                                "
                                class="grid grid-cols-2 gap-2 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8 xl:grid-cols-10"
                            >
                                <Button
                                    v-for="(
                                        task,
                                        taskIndex
                                    ) in visibleTasks(
                                        workbook,
                                    )"
                                    :key="
                                        task.person_task_id ||
                                        `${task.task_id}-${taskIndex}`
                                    "
                                    type="button"
                                    :label="
                                        task.ref_no ||
                                        'Task'
                                    "
                                    :severity="
                                        taskTagSeverity(
                                            task,
                                        )
                                    "
                                    size="small"
                                    class="!min-h-10 !w-full !justify-center !px-2 !py-2 !text-xs !font-semibold"
                                    :title="
                                        task.description ??
                                        task.ref_no ??
                                        'OTG Task'
                                    "
                                    @click="
                                        openTask(
                                            task,
                                            workbook,
                                        )
                                    "
                                />
                            </div>

                            <div
                                v-else
                                class="rounded-lg border border-dashed border-slate-300 bg-white px-4 py-8 text-center text-sm text-slate-500"
                            >
                                No indexed tasks
                                configured for
                                this workbook.
                            </div>

                            <div
                                v-if="
                                    totalTaskPages(
                                        workbook,
                                    ) > 1
                                "
                                class="mt-4 flex items-center justify-between border-t border-slate-200 pt-4"
                            >
                                <span
                                    class="text-sm text-slate-500"
                                >
                                    Page
                                    {{
                                        taskPage(
                                            workbook,
                                        )
                                    }}
                                    of
                                    {{
                                        totalTaskPages(
                                            workbook,
                                        )
                                    }}
                                </span>

                                <div
                                    class="flex gap-2"
                                >
                                    <Button
                                        type="button"
                                        icon="pi pi-chevron-left"
                                        severity="secondary"
                                        variant="outlined"
                                        size="small"
                                        :disabled="
                                            taskPage(
                                                workbook,
                                            ) ===
                                            1
                                        "
                                        @click="
                                            setTaskPage(
                                                workbook,
                                                taskPage(
                                                    workbook,
                                                ) -
                                                    1,
                                            )
                                        "
                                    />

                                    <Button
                                        type="button"
                                        icon="pi pi-chevron-right"
                                        severity="secondary"
                                        variant="outlined"
                                        size="small"
                                        :disabled="
                                            taskPage(
                                                workbook,
                                            ) ===
                                            totalTaskPages(
                                                workbook,
                                            )
                                        "
                                        @click="
                                            setTaskPage(
                                                workbook,
                                                taskPage(
                                                    workbook,
                                                ) +
                                                    1,
                                            )
                                        "
                                    />
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
            </section>
        </template>
    </div>

    <!-- VESSEL ADD / REVISE -->
    <Dialog
        v-model:visible="vesselDialogVisible"
        modal
        :draggable="false"
        :header="vesselDialogTitle"
        :style="{
            width: 'min(720px, 95vw)',
        }"
    >
        <Message
            v-if="vesselFormError"
            severity="error"
            closable
            class="mb-4"
            @close="vesselFormError = ''"
        >
            {{ vesselFormError }}
        </Message>

        <div
            class="grid gap-4 sm:grid-cols-2"
        >
            <div
                class="flex flex-col gap-2 sm:col-span-2"
            >
                <label
                    class="text-sm font-semibold text-slate-700"
                >
                    Name of Vessel
                    <span class="text-red-500">
                        *
                    </span>
                </label>

                <InputText
                    v-model="vesselName"
                    :disabled="vesselSaving"
                    class="w-full"
                />
            </div>

            <div
                class="flex flex-col gap-2"
            >
                <label
                    class="text-sm font-semibold text-slate-700"
                >
                    Vessel Type
                    <span class="text-red-500">
                        *
                    </span>
                </label>

                <Select
                    v-model="vesselTypeId"
                    :options="vesselTypes"
                    option-label="description"
                    option-value="id"
                    placeholder="Select vessel type"
                    class="w-full"
                    :loading="vesselOptionsLoading"
                    :disabled="
                        vesselSaving ||
                        vesselOptionsLoading
                    "
                />
            </div>

            <div
                class="flex flex-col gap-2"
            >
                <label
                    class="text-sm font-semibold text-slate-700"
                >
                    Flag Nationality
                    <span class="text-red-500">
                        *
                    </span>
                </label>

                <InputText
                    v-model="vesselFlag"
                    :disabled="vesselSaving"
                    class="w-full"
                />
            </div>

            <div
                class="flex flex-col gap-2 sm:col-span-2"
            >
                <label
                    class="text-sm font-semibold text-slate-700"
                >
                    Shipping Company
                    <span class="text-red-500">
                        *
                    </span>
                </label>

                <InputText
                    v-model="shipCompany"
                    :disabled="vesselSaving"
                    class="w-full"
                />
            </div>

            <div
                class="flex flex-col gap-2 sm:col-span-2"
            >
                <label
                    for="vessel-onboard-period"
                    class="text-sm font-semibold text-slate-700"
                >
                    Onboard Period
                    <span class="text-red-500">
                        *
                    </span>
                </label>

                <DatePicker
                    id="vessel-onboard-period"
                    v-model="vesselOnboardRange"
                    selection-mode="range"
                    date-format="M d, yy"
                    show-icon
                    icon-display="input"
                    show-button-bar
                    :manual-input="false"
                    :disabled="vesselSaving"
                    class="w-full"
                    input-class="w-full"
                    placeholder="Select sign-on and sign-off dates"
                />

                <div class="space-y-2">
                    <div
                        class="flex items-center gap-2"
                    >
                        <PrimeTag
                            value="Started"
                            severity="success"
                            class="w-16 !justify-center !px-2 !py-1 !text-xs !font-semibold"
                        />

                        <span
                            class="whitespace-nowrap text-sm font-medium text-slate-600"
                        >
                            {{
                                signOnDate
                                    ? new Intl.DateTimeFormat(
                                          'en-PH',
                                          {
                                              month: 'short',
                                              day: 'numeric',
                                              year: 'numeric',
                                          },
                                      ).format(
                                          signOnDate,
                                      )
                                    : 'Not selected'
                            }}
                        </span>
                    </div>

                    <div
                        class="flex items-center gap-2"
                    >
                        <PrimeTag
                            value="Ended"
                            severity="danger"
                            class="w-16 !justify-center !px-2 !py-1 !text-xs !font-semibold"
                        />

                        <span
                            class="whitespace-nowrap text-sm font-medium text-slate-600"
                        >
                            {{
                                signOffDate
                                    ? new Intl.DateTimeFormat(
                                          'en-PH',
                                          {
                                              month: 'short',
                                              day: 'numeric',
                                              year: 'numeric',
                                          },
                                      ).format(
                                          signOffDate,
                                      )
                                    : 'Not selected'
                            }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <template #footer>
            <Button
                type="button"
                label="Cancel"
                icon="pi pi-times"
                severity="secondary"
                variant="outlined"
                :disabled="vesselSaving"
                @click="
                    vesselDialogVisible =
                        false
                "
            />

            <Button
                type="button"
                :label="
                    editingVessel
                        ? 'Save Changes'
                        : 'Add Vessel'
                "
                icon="pi pi-save"
                severity="success"
                :loading="vesselSaving"
                @click="saveVessel"
            />
        </template>
    </Dialog>

    <!-- ADD WORKBOOK -->
    <Dialog
        v-model:visible="workbookDialogVisible"
        modal
        :draggable="false"
        header="Add TRB / Workbook"
        :style="{
            width: 'min(620px, 95vw)',
        }"
    >
        <Message
            v-if="workbookError"
            :severity="
                workbookTypes.length ===
                0
                    ? 'info'
                    : 'error'
            "
            closable
            class="mb-4"
            @close="workbookError = ''"
        >
            {{ workbookError }}
        </Message>

        <div
            class="flex flex-col gap-2"
        >
            <label
                class="text-sm font-semibold text-slate-700"
            >
                TRB / Workbook
                <span class="text-red-500">
                    *
                </span>
            </label>

            <Select
                v-model="selectedWorkbookTypeId"
                :options="workbookTypes"
                option-label="description"
                option-value="id"
                placeholder="Select an eligible workbook"
                class="w-full"
                :loading="workbookOptionsLoading"
                :disabled="
                    workbookSaving ||
                    workbookOptionsLoading ||
                    workbookTypes.length ===
                        0
                "
            />

            <p
                class="text-xs text-slate-500"
            >
                Only workbooks matching your
                configured eTRB type and
                department are available.
            </p>
        </div>

        <template #footer>
            <Button
                type="button"
                label="Cancel"
                icon="pi pi-times"
                severity="secondary"
                variant="outlined"
                :disabled="workbookSaving"
                @click="
                    workbookDialogVisible =
                        false
                "
            />

            <Button
                type="button"
                label="Add Workbook"
                icon="pi pi-plus"
                severity="success"
                :loading="workbookSaving"
                :disabled="
                    !selectedWorkbookTypeId
                "
                @click="addWorkbook"
            />
        </template>
    </Dialog>

    <!-- TASK REVISE MODAL -->
    <Dialog
        v-model:visible="taskDialogVisible"
        modal
        :draggable="false"
        header="Revise OTG Task"
        :style="{
            width: 'min(900px, 96vw)',
        }"
        :content-style="{
            maxHeight: '76vh',
            overflowY: 'auto',
        }"
    >
        <Message
            v-if="taskError"
            severity="error"
            closable
            class="mb-4"
            @close="taskError = ''"
        >
            {{ taskError }}
        </Message>

        <div
            v-if="taskLoading"
            class="flex min-h-64 items-center justify-center"
        >
            <i
                class="pi pi-spin pi-spinner text-2xl text-[#377EC0]"
            ></i>
        </div>

        <div
            v-else-if="taskDetails"
            class="space-y-5"
        >
            <div
                class="flex flex-col gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-start sm:justify-between"
            >
                <div>
                    <p
                        class="font-semibold text-slate-900"
                    >
                        {{
                            selectedWorkbook
                                ?.workbook ||
                            'Training Record Book'
                        }}
                    </p>

                    <p
                        class="mt-1 text-sm text-slate-500"
                    >
                        {{
                            taskDetails.task
                                .ref_no ||
                            'OTG Task'
                        }}
                    </p>
                </div>

                <PrimeTag
                    v-if="selectedTask"
                    :value="
                        taskStatusLabel(
                            selectedTask,
                        )
                    "
                    :severity="
                        taskTagSeverity(
                            selectedTask,
                        )
                    "
                    rounded
                />
            </div>

            <div
                class="grid gap-4"
            >
                <div>
                    <label
                        class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500"
                    >
                        Competence
                    </label>

                    <div
                        class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm leading-6 text-slate-700"
                    >
                        {{
                            textOrFallback(
                                taskDetails
                                    .competence
                                    .description,
                                'No Record Found',
                            )
                        }}
                    </div>
                </div>

                <div>
                    <label
                        class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500"
                    >
                        Topic
                    </label>

                    <div
                        class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm leading-6 text-slate-700"
                    >
                        {{
                            textOrFallback(
                                taskDetails
                                    .sub_competence
                                    .description,
                                'No Record Found',
                            )
                        }}
                    </div>
                </div>

                <div>
                    <label
                        class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500"
                    >
                        Task
                    </label>

                    <div
                        class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm leading-6 text-slate-700"
                    >
                        <strong
                            v-if="
                                taskDetails.task
                                    .ref_no
                            "
                            class="mr-1 text-[#377EC0]"
                        >
                            {{
                                taskDetails.task
                                    .ref_no
                            }}
                        </strong>

                        {{
                            textOrFallback(
                                taskDetails.task
                                    .description,
                                'No Record Found',
                            )
                        }}
                    </div>
                </div>
            </div>

            <div
                class="grid gap-4 sm:grid-cols-2"
            >
                <div
                    class="flex flex-col gap-2"
                >
                    <label
                        class="text-sm font-semibold text-slate-700"
                    >
                        Completion Date
                        <span class="text-red-500">
                            *
                        </span>
                    </label>

                    <DatePicker
                        v-model="taskCompletionDate"
                        date-format="M d, yy"
                        show-icon
                        :manual-input="false"
                        :disabled="taskSaving"
                        class="w-full"
                    />
                </div>

                <div
                    class="flex flex-col gap-2"
                >
                    <label
                        class="text-sm font-semibold text-slate-700"
                    >
                        Amount of Months Onboard
                        <span class="text-red-500">
                            *
                        </span>
                    </label>

                    <Select
                        v-model="taskMonthNo"
                        :options="MONTH_OPTIONS"
                        option-label="label"
                        option-value="value"
                        placeholder="Select month"
                        class="w-full"
                        :disabled="taskSaving"
                    />
                </div>
            </div>

            <div
                class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4"
            >
                <Checkbox
                    v-model="taskNotApplicable"
                    input-id="task-not-applicable"
                    binary
                    :disabled="taskSaving"
                />

                <label
                    for="task-not-applicable"
                    class="cursor-pointer text-sm font-medium text-slate-700"
                >
                    Task Not Applicable
                </label>
            </div>

            <!-- Objective Evidence -->
            <section
                v-if="!taskNotApplicable"
                class="rounded-lg border border-slate-200 bg-white p-4"
            >
                <div class="mb-3">
                    <h3
                        class="font-semibold text-slate-900"
                    >
                        Objective Evidence
                    </h3>

                    <p
                        class="mt-1 text-xs text-slate-500"
                    >
                        JPG, JPEG, PNG, GIF, PDF,
                        DOC or DOCX. Maximum 20 MB
                        per file.
                    </p>
                </div>

                <!-- Existing + newly selected preview tiles. No filenames. -->
                <div
                    v-if="
                        taskDetails
                            .objective_evidence
                            .length >
                            0 ||
                        objectiveFiles.length >
                            0
                    "
                    class="mb-3 rounded-xl bg-slate-50 p-2.5"
                >
                    <div
                        class="flex flex-wrap gap-2.5"
                    >
                        <div
                            v-for="file in taskDetails.objective_evidence"
                            :key="file.id"
                            class="relative"
                            :class="{
                                'opacity-50':
                                    removedObjectiveFileIds.includes(
                                        file.id,
                                    ),
                            }"
                        >
                            <button
                                type="button"
                                class="flex h-20 w-20 items-center justify-center overflow-hidden rounded-xl border bg-white transition hover:border-[#377EC0]/40 hover:shadow-sm"
                                :class="
                                    removedObjectiveFileIds.includes(
                                        file.id,
                                    )
                                        ? 'border-red-200 bg-red-50'
                                        : 'border-slate-200'
                                "
                                :disabled="
                                    !file.file_url
                                "
                                aria-label="Open objective evidence"
                                title="Open objective evidence"
                                @click="
                                    openFile(
                                        file.file_url,
                                    )
                                "
                            >
                                <PrimeImage
                                    v-if="
                                        isExistingTaskImage(
                                            file,
                                        ) &&
                                        file.file_url
                                    "
                                    :src="
                                        file.file_url
                                    "
                                    alt="Objective evidence preview"
                                    class="block h-full w-full"
                                    image-class="h-full w-full object-cover"
                                />

                                <div
                                    v-else
                                    class="flex h-full w-full items-center justify-center bg-[#377EC0]/5 text-[#377EC0]"
                                >
                                    <i
                                        class="pi pi-file text-2xl"
                                    ></i>
                                </div>
                            </button>

                            <Button
                                v-if="
                                    !removedObjectiveFileIds.includes(
                                        file.id,
                                    )
                                "
                                type="button"
                                icon="pi pi-trash"
                                severity="danger"
                                rounded
                                size="small"
                                class="!absolute -right-2 -top-2 !h-7 !w-7 !min-w-7 !p-0"
                                aria-label="Mark objective evidence for removal"
                                title="Mark objective evidence for removal"
                                @click="
                                    markObjectiveForRemoval(
                                        file.id,
                                    )
                                "
                            />

                            <Button
                                v-else
                                type="button"
                                icon="pi pi-undo"
                                severity="secondary"
                                rounded
                                size="small"
                                class="!absolute -right-2 -top-2 !h-7 !w-7 !min-w-7 !p-0"
                                aria-label="Undo objective evidence removal"
                                title="Undo objective evidence removal"
                                @click="
                                    undoObjectiveRemoval(
                                        file.id,
                                    )
                                "
                            />
                        </div>

                        <div
                            v-for="attachment in objectiveFiles"
                            :key="attachment.key"
                            class="relative"
                        >
                            <div
                                class="flex h-20 w-20 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-white"
                            >
                                <PrimeImage
                                    v-if="
                                        attachment.isImage &&
                                        attachment.previewUrl
                                    "
                                    :src="
                                        attachment.previewUrl
                                    "
                                    alt="Selected objective evidence preview"
                                    class="block h-full w-full"
                                    image-class="h-full w-full object-cover"
                                />

                                <div
                                    v-else
                                    class="flex h-full w-full items-center justify-center bg-[#377EC0]/5 text-[#377EC0]"
                                >
                                    <i
                                        class="pi pi-file text-2xl"
                                    ></i>
                                </div>
                            </div>

                            <Button
                                type="button"
                                icon="pi pi-times"
                                severity="secondary"
                                rounded
                                size="small"
                                class="!absolute -right-2 -top-2 !h-7 !w-7 !min-w-7 !p-0"
                                aria-label="Remove selected objective evidence"
                                title="Remove selected objective evidence"
                                @click="
                                    removeSelectedTaskAttachment(
                                        'objective',
                                        attachment,
                                    )
                                "
                            />
                        </div>
                    </div>
                </div>

                <!-- Compact Student Batch Upload style dropzone. -->
                <div
                    class="rounded-xl border-2 border-dashed p-3 transition"
                    :class="
                        objectiveDragging
                            ? 'border-blue-400 bg-blue-50'
                            : objectiveFiles.length >
                                0
                              ? 'border-emerald-300 bg-emerald-50/40'
                              : 'border-slate-300 bg-slate-50'
                    "
                    @dragover="
                        handleTaskFileDragOver(
                            $event,
                            'objective',
                        )
                    "
                    @dragenter="
                        handleTaskFileDragOver(
                            $event,
                            'objective',
                        )
                    "
                    @dragleave="
                        handleTaskFileDragLeave(
                            $event,
                            'objective',
                        )
                    "
                    @drop="
                        handleTaskFileDrop(
                            $event,
                            'objective',
                        )
                    "
                >
                    <input
                        ref="objectiveFileInput"
                        type="file"
                        multiple
                        :accept="
                            TASK_ATTACHMENT_ACCEPT
                        "
                        :disabled="taskSaving"
                        class="hidden"
                        @change="
                            handleTaskFileInput(
                                $event,
                                'objective',
                            )
                        "
                    />

                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div
                            class="flex min-w-0 items-center gap-3"
                        >
                            <div
                                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-blue-100 text-blue-700"
                            >
                                <i
                                    class="pi pi-cloud-upload"
                                ></i>
                            </div>

                            <div class="min-w-0">
                                <p
                                    class="text-sm font-semibold text-slate-800"
                                >
                                    Drag and drop files here
                                </p>

                                <p
                                    class="mt-0.5 text-xs text-slate-500"
                                >
                                    Images and documents · Maximum 20 MB each
                                </p>
                            </div>
                        </div>

                        <Button
                            type="button"
                            label="Choose Files"
                            icon="pi pi-folder-open"
                            severity="info"
                            outlined
                            size="small"
                            :disabled="taskSaving"
                            @click="
                                openTaskFilePicker(
                                    'objective',
                                )
                            "
                        />
                    </div>
                </div>
            </section>

            <Message
                v-else
                severity="info"
                :closable="false"
            >
                Objective Evidence is not required
                while this task is marked Not
                Applicable.
            </Message>

            <!-- Proof of Assessment -->
            <section
                class="rounded-lg border border-slate-200 bg-white p-4"
            >
                <div class="mb-3">
                    <h3
                        class="font-semibold text-slate-900"
                    >
                        Proof of Assessment
                    </h3>

                    <p
                        class="mt-1 text-xs text-slate-500"
                    >
                        JPG, JPEG, PNG, GIF, PDF,
                        DOC or DOCX. Maximum 20 MB
                        per file.
                    </p>
                </div>

                <!-- Existing + newly selected preview tiles. No filenames. -->
                <div
                    v-if="
                        taskDetails
                            .proof_of_assessment
                            .length >
                            0 ||
                        proofFiles.length >
                            0
                    "
                    class="mb-3 rounded-xl bg-slate-50 p-2.5"
                >
                    <div
                        class="flex flex-wrap gap-2.5"
                    >
                        <div
                            v-for="file in taskDetails.proof_of_assessment"
                            :key="file.id"
                            class="relative"
                            :class="{
                                'opacity-50':
                                    removedProofFileIds.includes(
                                        file.id,
                                    ),
                            }"
                        >
                            <button
                                type="button"
                                class="flex h-20 w-20 items-center justify-center overflow-hidden rounded-xl border bg-white transition hover:border-[#377EC0]/40 hover:shadow-sm"
                                :class="
                                    removedProofFileIds.includes(
                                        file.id,
                                    )
                                        ? 'border-red-200 bg-red-50'
                                        : 'border-slate-200'
                                "
                                :disabled="
                                    !file.file_url
                                "
                                aria-label="Open proof of assessment"
                                title="Open proof of assessment"
                                @click="
                                    openFile(
                                        file.file_url,
                                    )
                                "
                            >
                                <PrimeImage
                                    v-if="
                                        isExistingTaskImage(
                                            file,
                                        ) &&
                                        file.file_url
                                    "
                                    :src="
                                        file.file_url
                                    "
                                    alt="Proof of assessment preview"
                                    class="block h-full w-full"
                                    image-class="h-full w-full object-cover"
                                />

                                <div
                                    v-else
                                    class="flex h-full w-full items-center justify-center bg-[#377EC0]/5 text-[#377EC0]"
                                >
                                    <i
                                        class="pi pi-file text-2xl"
                                    ></i>
                                </div>
                            </button>

                            <Button
                                v-if="
                                    !removedProofFileIds.includes(
                                        file.id,
                                    )
                                "
                                type="button"
                                icon="pi pi-trash"
                                severity="danger"
                                rounded
                                size="small"
                                class="!absolute -right-2 -top-2 !h-7 !w-7 !min-w-7 !p-0"
                                aria-label="Mark proof for removal"
                                title="Mark proof for removal"
                                @click="
                                    markProofForRemoval(
                                        file.id,
                                    )
                                "
                            />

                            <Button
                                v-else
                                type="button"
                                icon="pi pi-undo"
                                severity="secondary"
                                rounded
                                size="small"
                                class="!absolute -right-2 -top-2 !h-7 !w-7 !min-w-7 !p-0"
                                aria-label="Undo proof removal"
                                title="Undo proof removal"
                                @click="
                                    undoProofRemoval(
                                        file.id,
                                    )
                                "
                            />
                        </div>

                        <div
                            v-for="attachment in proofFiles"
                            :key="attachment.key"
                            class="relative"
                        >
                            <div
                                class="flex h-20 w-20 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-white"
                            >
                                <PrimeImage
                                    v-if="
                                        attachment.isImage &&
                                        attachment.previewUrl
                                    "
                                    :src="
                                        attachment.previewUrl
                                    "
                                    alt="Selected proof of assessment preview"
                                    class="block h-full w-full"
                                    image-class="h-full w-full object-cover"
                                />

                                <div
                                    v-else
                                    class="flex h-full w-full items-center justify-center bg-[#377EC0]/5 text-[#377EC0]"
                                >
                                    <i
                                        class="pi pi-file text-2xl"
                                    ></i>
                                </div>
                            </div>

                            <Button
                                type="button"
                                icon="pi pi-times"
                                severity="secondary"
                                rounded
                                size="small"
                                class="!absolute -right-2 -top-2 !h-7 !w-7 !min-w-7 !p-0"
                                aria-label="Remove selected proof of assessment"
                                title="Remove selected proof of assessment"
                                @click="
                                    removeSelectedTaskAttachment(
                                        'proof',
                                        attachment,
                                    )
                                "
                            />
                        </div>
                    </div>
                </div>

                <!-- Compact Student Batch Upload style dropzone. -->
                <div
                    class="rounded-xl border-2 border-dashed p-3 transition"
                    :class="
                        proofDragging
                            ? 'border-blue-400 bg-blue-50'
                            : proofFiles.length >
                                0
                              ? 'border-emerald-300 bg-emerald-50/40'
                              : 'border-slate-300 bg-slate-50'
                    "
                    @dragover="
                        handleTaskFileDragOver(
                            $event,
                            'proof',
                        )
                    "
                    @dragenter="
                        handleTaskFileDragOver(
                            $event,
                            'proof',
                        )
                    "
                    @dragleave="
                        handleTaskFileDragLeave(
                            $event,
                            'proof',
                        )
                    "
                    @drop="
                        handleTaskFileDrop(
                            $event,
                            'proof',
                        )
                    "
                >
                    <input
                        ref="proofFileInput"
                        type="file"
                        multiple
                        :accept="
                            TASK_ATTACHMENT_ACCEPT
                        "
                        :disabled="taskSaving"
                        class="hidden"
                        @change="
                            handleTaskFileInput(
                                $event,
                                'proof',
                            )
                        "
                    />

                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div
                            class="flex min-w-0 items-center gap-3"
                        >
                            <div
                                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-blue-100 text-blue-700"
                            >
                                <i
                                    class="pi pi-cloud-upload"
                                ></i>
                            </div>

                            <div class="min-w-0">
                                <p
                                    class="text-sm font-semibold text-slate-800"
                                >
                                    Drag and drop files here
                                </p>

                                <p
                                    class="mt-0.5 text-xs text-slate-500"
                                >
                                    Images and documents · Maximum 20 MB each
                                </p>
                            </div>
                        </div>

                        <Button
                            type="button"
                            label="Choose Files"
                            icon="pi pi-folder-open"
                            severity="info"
                            outlined
                            size="small"
                            :disabled="taskSaving"
                            @click="
                                openTaskFilePicker(
                                    'proof',
                                )
                            "
                        />
                    </div>
                </div>
            </section>

            <Message
                severity="warn"
                :closable="false"
            >
                Existing files marked for removal
                are deleted only after you press
                Save Task. Reset discards the
                unsaved changes.
            </Message>
        </div>

        <template #footer>
            <Button
                type="button"
                label="Reset"
                icon="pi pi-undo"
                severity="secondary"
                variant="outlined"
                :disabled="
                    taskLoading ||
                    taskSaving
                "
                @click="resetTaskForm"
            />

            <Button
                type="button"
                label="Close"
                icon="pi pi-times"
                severity="secondary"
                variant="outlined"
                :disabled="taskSaving"
                @click="
                    taskDialogVisible =
                        false
                "
            />

            <Button
                type="button"
                label="Save Task"
                icon="pi pi-save"
                severity="success"
                :loading="taskSaving"
                :disabled="
                    taskLoading ||
                    !taskDetails
                "
                @click="saveTask"
            />
        </template>
    </Dialog>

    <!-- DELETE / REMOVE CONFIRMATION -->
    <Dialog
        v-model:visible="deleteDialogVisible"
        modal
        :draggable="false"
        :header="
            deleteTarget?.type ===
            'vessel'
                ? 'Remove Vessel'
                : 'Remove Workbook'
        "
        :style="{
            width: 'min(480px, 94vw)',
        }"
    >
        <div
            v-if="deleteTarget"
            class="flex items-start gap-3"
        >
            <div
                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600"
            >
                <i
                    class="pi pi-trash"
                ></i>
            </div>

            <div>
                <p
                    class="font-semibold text-slate-800"
                >
                    Remove
                    {{ deleteTarget.label }}?
                </p>

                <p
                    v-if="
                        deleteTarget.type ===
                        'workbook'
                    "
                    class="mt-1 text-sm leading-5 text-slate-500"
                >
                    The workbook assignment will
                    be removed, but existing task
                    records, Objective Evidence,
                    Proof of Assessment, and
                    uploaded files are preserved.
                </p>

                <p
                    v-else
                    class="mt-1 text-sm leading-5 text-slate-500"
                >
                    This removes only the vessel
                    assignment record, matching
                    the mobile OTG behavior.
                </p>
            </div>
        </div>

        <template #footer>
            <Button
                type="button"
                label="Cancel"
                icon="pi pi-times"
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
                label="Remove"
                icon="pi pi-trash"
                severity="danger"
                :loading="deleting"
                @click="confirmDelete"
            />
        </template>
    </Dialog>
</template>
