<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import FileUpload from 'primevue/fileupload';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import PrimeTag from 'primevue/tag';
import Select from 'primevue/select';
import Toast from 'primevue/toast';
import ToggleSwitch from 'primevue/toggleswitch';
import { useToast } from 'primevue/usetoast';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
} from 'vue';

import Datatable from '@/components/Datatable.vue';
import { dashboard } from '@/routes';

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
                href: dashboard(),
            },
            {
                title: 'Requirement Types Setup',
                href: '/setup/requirement-types/datatable/index',
            },
        ],
    },
});

type LookupOption = {
    label: string;
    value: string;
};

type ApiListResponse = {
    data: DataTableRow[];

    meta: {
        currentPage: number;
        perPage: number;
        total: number;
    };
};

type OptionsResponse = {
    data: {
        ranks: LookupOption[];
        vesselTypes: LookupOption[];
    };
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

type FileSelectEvent = {
    files: File[];
};

type Errors =
    Record<string, string>;

const DATATABLE_URL =
    '/api/v1/setup/requirement-types/datatable';

const API_BASE =
    '/api/v1/setup/requirement-types';


const ACCEPTED_TEMPLATE_TYPES =
    '.jpg,.jpeg,.png,.gif,.bmp,.pdf,.doc,.docx,.xls,.xlsx,.csv';

const toast = useToast();

const requirementTypes =
    ref<DataTableRow[]>([]);

const ranks =
    ref<LookupOption[]>([]);

const vesselTypes =
    ref<LookupOption[]>([]);

const loading = ref(false);
const optionsLoading = ref(false);
const saving = ref(false);
const deleting = ref(false);

const errorMessage = ref('');

const totalRecords = ref(0);
const first = ref(0);
const perPage = ref(10);
const search = ref('');

const sortField =
    ref('desc_requirement');

const sortDirection =
    ref<'asc' | 'desc'>(
        'asc',
    );

const dialogVisible = ref(false);
const deleteVisible = ref(false);

const selectedRequirementType =
    ref<DataTableRow | null>(
        null,
    );

const selectedTemplateFile =
    ref<File | null>(
        null,
    );

const fileUploadKey =
    ref(0);

const formErrors =
    ref<Errors>({});

const form = reactive({
    id: '',
    code_requirement: '',
    desc_requirement: '',
    cci_reqd: true,
    rank_id: null as string | null,
    vessel_type: null as string | null,
    cat_requirement: '',
    prio: 1 as number | null,
    template: '',
    template_url: '',
});

let requestController:
    | AbortController
    | null = null;

let optionsController:
    | AbortController
    | null = null;

const columns: DataTableColumn[] = [
    {
        field: 'code_requirement',
        header: 'Code',
        sortable: false,
        searchable: true,
        class: 'min-w-[180px]',
    },
    {
        field: 'desc_requirement',
        header: 'Requirement Name',
        sortable: false,
        searchable: true,
        class: 'min-w-[320px]',
    },
    {
        field: 'cci_reqd',
        header: 'CCI Required',
        sortable: true,
        searchable: false,
        class: 'min-w-[140px]',
    },
    {
        field: 'rank_name',
        header: 'Rank',
        sortable: false,
        searchable: false,
        class: 'min-w-[200px]',
    },
    {
        field: 'vessel_type_name',
        header: 'Vessel Type',
        sortable: false,
        searchable: false,
        class: 'min-w-[220px]',
    },
    {
        field: 'cat_requirement',
        header: 'Category',
        sortable: false,
        searchable: false,
        class: 'min-w-[180px]',
    },
    {
        field: 'prio',
        header: 'Priority',
        sortable: false,
        searchable: false,
        class: 'min-w-[120px]',
    },
    {
        field: 'template',
        header: 'Template',
        sortable: false,
        searchable: false,
        class: 'min-w-[200px]',
    },
];

const actions: DataTableAction[] = [
    {
        key: 'edit',
        label: 'Edit requirement type',
        icon: 'pi pi-pencil',
        severity: 'warn',
    },
    {
        key: 'delete',
        label: 'Delete requirement type',
        icon: 'pi pi-trash',
        severity: 'danger',
    },
];

const currentPage = computed(
    () =>
        Math.floor(
            first.value /
                perPage.value,
        ) + 1,
);

const dialogTitle = computed(
    () =>
        form.id
            ? 'Edit Requirement Type'
            : 'Create Requirement Type',
);

async function loadRequirementTypes(
    page = 1,
): Promise<void> {
    requestController?.abort();

    const controller =
        new AbortController();

    requestController =
        controller;

    loading.value = true;
    errorMessage.value = '';

    try {
        const response =
            await axios.get<ApiListResponse>(
                DATATABLE_URL,
                {
                    signal:
                        controller.signal,

                    params: {
                        page,
                        per_page:
                            perPage.value,
                        search:
                            search.value,
                        sort_field:
                            sortField.value,
                        sort_direction:
                            sortDirection.value,
                    },

                    ...requestConfig(),
                },
            );

        requirementTypes.value =
            response.data.data;

        totalRecords.value =
            response.data.meta.total;

        perPage.value =
            response.data.meta
                .perPage;

        first.value =
            (
                response.data.meta
                    .currentPage -
                1
            ) *
            response.data.meta
                .perPage;
    } catch (error: unknown) {
        if (isCanceled(error)) {
            return;
        }

        requirementTypes.value = [];
        totalRecords.value = 0;

        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to load requirement types.',
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

async function loadOptions(): Promise<void> {
    optionsController?.abort();

    const controller =
        new AbortController();

    optionsController =
        controller;

    optionsLoading.value = true;

    try {
        const response =
            await axios.get<OptionsResponse>(
                `${API_BASE}/options`,
                {
                    signal:
                        controller.signal,

                    ...requestConfig(),
                },
            );

        ranks.value =
            response.data.data.ranks;

        vesselTypes.value =
            response.data.data
                .vesselTypes;
    } catch (error: unknown) {
        if (isCanceled(error)) {
            return;
        }

        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to load requirement setup options.',
            );
    } finally {
        if (
            optionsController ===
            controller
        ) {
            optionsLoading.value = false;
        }
    }
}

function handlePage(
    event: PageEvent,
): void {
    perPage.value =
        event.rows;

    first.value =
        event.first;

    void loadRequirementTypes(
        event.page + 1,
    );
}

function handleSort(
    event: SortEvent,
): void {
    sortField.value =
        event.sortField
        ||
        'desc_requirement';

    sortDirection.value =
        event.sortOrder === -1
            ? 'desc'
            : 'asc';

    first.value = 0;

    void loadRequirementTypes(1);
}

function handleSearch(
    value: string,
): void {
    search.value = value;
    first.value = 0;

    void loadRequirementTypes(1);
}

function handleAction(
    action: string,
    row: DataTableRow,
): void {
    if (action === 'edit') {
        openEdit(row);
        return;
    }

    if (action === 'delete') {
        selectedRequirementType.value =
            row;

        deleteVisible.value =
            true;
    }
}

function openCreate(): void {
    resetForm();

    dialogVisible.value =
        true;
}

function openEdit(
    row: DataTableRow,
): void {
    formErrors.value = {};
    selectedTemplateFile.value = null;
    fileUploadKey.value += 1;

    form.id =
        String(row.id ?? '');

    form.code_requirement =
        String(
            row.code_requirement
            ?? '',
        );

    form.desc_requirement =
        String(
            row.desc_requirement
            ?? '',
        );

    form.cci_reqd =
        String(
            row.cci_reqd
            ?? 'Y',
        ) === 'Y';

    form.rank_id =
        row.rank_id
            ? String(row.rank_id)
            : null;

    form.vessel_type =
        row.vessel_type
            ? String(
                row.vessel_type,
            )
            : null;

    form.cat_requirement =
        String(
            row.cat_requirement
            ?? '',
        );

    const priority =
        Number(row.prio ?? 1);

    form.prio =
        Number.isFinite(priority)
            ? priority
            : 1;

    form.template =
        String(
            row.template
            ?? '',
        );

    form.template_url =
        String(
            row.template_url
            ?? '',
        );

    dialogVisible.value =
        true;
}

function handleTemplateSelect(
    event: FileSelectEvent,
): void {
    const file =
        event.files?.[0]
        ?? null;

    selectedTemplateFile.value =
        file;

    delete formErrors.value
        .template_file;
}

async function saveRequirementType(): Promise<void> {
    formErrors.value = {};
    errorMessage.value = '';

    if (
        !form.code_requirement
            .trim()
    ) {
        formErrors.value
            .code_requirement =
            'Code is required.';
    }

    if (
        !form.desc_requirement
            .trim()
    ) {
        formErrors.value
            .desc_requirement =
            'Description is required.';
    }

    if (
        Object.keys(
            formErrors.value,
        ).length
    ) {
        return;
    }

    saving.value = true;

    const wasNew =
        !form.id;

    const payload =
        new FormData();

    payload.append(
        'code_requirement',
        form.code_requirement.trim(),
    );

    payload.append(
        'desc_requirement',
        form.desc_requirement.trim(),
    );

    payload.append(
        'cci_reqd',
        form.cci_reqd
            ? 'Y'
            : 'N',
    );

    payload.append(
        'rank_id',
        form.rank_id
        ?? '',
    );

    payload.append(
        'vessel_type',
        form.vessel_type
        ?? '',
    );

    payload.append(
        'cat_requirement',
        form.cat_requirement.trim(),
    );

    payload.append(
        'prio',
        form.prio !== null
            ? String(form.prio)
            : '',
    );

    if (
        selectedTemplateFile.value
    ) {
        payload.append(
            'template_file',
            selectedTemplateFile.value,
        );
    }

    if (!wasNew) {
        payload.append(
            '_method',
            'PUT',
        );
    }

    try {
        const response =
            await axios.post<{
                message: string;
                data: DataTableRow;
            }>(
                wasNew
                    ? API_BASE
                    : `${API_BASE}/${encodeURIComponent(
                        form.id,
                    )}`,
                payload,
                requestConfig(),
            );

        const data =
            response.data.data;

        form.id =
            String(data.id ?? '');

        form.template =
            String(
                data.template ?? '',
            );

        form.template_url =
            String(
                data.template_url
                ?? '',
            );

        selectedTemplateFile.value =
            null;

        fileUploadKey.value += 1;

        toast.add({
            severity: 'success',
            summary: wasNew
                ? 'Requirement Type Created'
                : 'Requirement Type Updated',
            detail:
                response.data.message,
            life: 4000,
        });

        await loadRequirementTypes(
            wasNew
                ? 1
                : currentPage.value,
        );
    } catch (error: unknown) {
        formErrors.value =
            getValidationErrors(
                error,
            );

        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to save the requirement type.',
            );
    } finally {
        saving.value = false;
    }
}

async function deleteRequirementType(): Promise<void> {
    const id = String(
        selectedRequirementType.value
            ?.id
        ?? '',
    );

    if (!id) {
        return;
    }

    deleting.value = true;
    errorMessage.value = '';

    try {
        const response =
            await axios.delete<{
                message: string;
            }>(
                `${API_BASE}/${encodeURIComponent(
                    id,
                )}`,
                requestConfig(),
            );

        deleteVisible.value =
            false;

        selectedRequirementType.value =
            null;

        toast.add({
            severity: 'success',
            summary:
                'Requirement Type Deleted',
            detail:
                response.data.message,
            life: 4000,
        });

        await reloadCurrentPage();
    } catch (error: unknown) {
        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to delete the requirement type.',
            );
    } finally {
        deleting.value = false;
    }
}

function openTemplate(
    row: DataTableRow,
): void {
    const url = String(
        row.template_url
        ?? '',
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

function openCurrentTemplate(): void {
    const url =
        form.template_url.trim();

    if (!url) {
        return;
    }

    window.open(
        url,
        '_blank',
        'noopener,noreferrer',
    );
}

function resetForm(): void {
    Object.assign(
        form,
        {
            id: '',
            code_requirement: '',
            desc_requirement: '',
            cci_reqd: true,
            rank_id: null,
            vessel_type: null,
            cat_requirement: '',
            prio: 1,
            template: '',
            template_url: '',
        },
    );

    selectedTemplateFile.value =
        null;

    formErrors.value = {};

    fileUploadKey.value += 1;
}

async function reloadCurrentPage(): Promise<void> {
    await loadRequirementTypes(
        currentPage.value,
    );

    if (
        !requirementTypes.value
            .length
        &&
        currentPage.value > 1
    ) {
        await loadRequirementTypes(
            currentPage.value - 1,
        );
    }
}

function requestConfig() {
    return {
        headers: {
            Accept:
                'application/json',
            'X-Requested-With':
                'XMLHttpRequest',
        },

        withCredentials: true,
    };
}

function isCanceled(
    error: unknown,
): boolean {
    return (
        axios.isCancel(error)
        ||
        (
            axios.isAxiosError(
                error,
            )
            &&
            error.code ===
                'ERR_CANCELED'
        )
    );
}

function getErrorMessage(
    error: unknown,
    fallback: string,
): string {
    if (
        !axios.isAxiosError(
            error,
        )
    ) {
        return fallback;
    }

    return (
        (
            error.response
                ?.data as
                | {
                      message?: string;
                  }
                | undefined
        )?.message
        ||
        fallback
    );
}

function getValidationErrors(
    error: unknown,
): Errors {
    if (
        !axios.isAxiosError(
            error,
        )
    ) {
        return {};
    }

    const source =
        (
            error.response
                ?.data as
                | {
                      errors?: Record<
                          string,
                          string[]
                      >;
                  }
                | undefined
        )?.errors
        ?? {};

    return Object.fromEntries(
        Object.entries(
            source,
        ).map(
            ([
                key,
                messages,
            ]) => [
                key,
                messages[0]
                ?? 'Invalid value.',
            ],
        ),
    );
}

onMounted(() => {
    void loadRequirementTypes(1);
    void loadOptions();
});

onBeforeUnmount(() => {
    requestController?.abort();
    optionsController?.abort();
});
</script>

<template>
    <Head title="Requirement Types Setup" />

    <Toast position="top-right" />

    <div
        class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5"
    >
        <Message
            v-if="errorMessage"
            severity="error"
            closable
            @close="errorMessage = ''"
        >
            {{ errorMessage }}
        </Message>

        <Datatable
            title="Requirement Types Setup"
            description="Create and maintain document requirement types used throughout IRIS-SAM."
            header-icon="pi pi-file-check"
            search-placeholder="Search code, requirement, rank, vessel type, or category..."
            empty-title="No requirement types found"
            empty-description="Create a requirement type to get started."
            empty-icon="pi pi-file-check"
            table-min-width="1650px"
            actions-header="Actions"
            actions-width="130px"
            data-key="id"
            lazy
            :loading="loading"
            :data="requirementTypes"
            :columns="columns"
            :actions="actions"
            :total-records="totalRecords"
            :first="first"
            :rows="perPage"
            :rows-per-page-options="[10, 20, 50, 100]"
            @page="handlePage"
            @sort="handleSort"
            @search="handleSearch"
            @action="handleAction"
        >
            <template #header-actions>
                <Button
                    label="Create Requirement Type"
                    icon="pi pi-plus"
                    severity="success"
                    size="small"
                    @click="openCreate"
                />
            </template>
            <template #cell-code_requirement="{ value }">
                <span
                    class="font-semibold text-slate-700"
                >
                    {{ value || '—' }}
                </span>
            </template>

            <template #cell-desc_requirement="{ value }">
                <div class="flex items-start gap-2">
                    <i
                        class="pi pi-list-check mt-0.5 shrink-0 text-green-500"
                    ></i>

                    <span
                        class="font-medium text-slate-700"
                    >
                        {{ value || '—' }}
                    </span>
                </div>
            </template>

            <template #cell-cci_reqd="{ value }">
                
                <PrimeTag
                    :value="
                        value === 'Y'
                            ? 'Required'
                            : 'Not Required'
                    "
                    :severity="
                        value === 'Y'
                            ? 'success'
                            : 'secondary'
                    "
                    :icon="
                        value === 'Y'
                            ? 'pi pi-check-circle'
                            : 'pi pi-minus-circle'
                    "
                    rounded
                />
            </template>

            <template #cell-rank_name="{ value }">
                <span
                    class="text-sm text-slate-600"
                >
                    {{ value || 'All Ranks' }}
                </span>
            </template>

            <template #cell-vessel_type_name="{ value }">
                <span
                    class="text-sm text-slate-600"
                >
                    {{ value || 'All Vessel Types' }}
                </span>
            </template>

            <template #cell-cat_requirement="{ value }">
                <span
                    class="text-sm text-slate-600"
                >
                    {{ value || '—' }}
                </span>
            </template>

            <template #cell-template="{ data }">
                <Button
                    v-if="data.template_url"
                    type="button"
                    :label="
                        String(
                            data.template
                            || 'Open Template',
                        )
                    "
                    icon="pi pi-download"
                    severity="info"
                    text
                    size="small"
                    @click="openTemplate(data)"
                />

                <span
                    v-else
                    class="text-sm text-slate-400"
                >
                    —
                </span>
            </template>
        </Datatable>

        <Dialog
            v-model:visible="dialogVisible"
            modal
            :header="dialogTitle"
            :closable="!saving"
            class="w-[min(96vw,940px)]"
        >
            <div
                class="grid gap-4 md:grid-cols-2"
            >
                <div>
                    <label
                        class="mb-2 block text-sm font-semibold text-slate-700"
                    >
                        Code
                        <span class="text-red-500">
                            *
                        </span>
                    </label>

                    <InputText
                        v-model="form.code_requirement"
                        class="w-full"
                        maxlength="100"
                        :invalid="
                            Boolean(
                                formErrors.code_requirement,
                            )
                        "
                        :disabled="saving"
                    />

                    <small
                        v-if="
                            formErrors.code_requirement
                        "
                        class="text-red-500"
                    >
                        {{
                            formErrors.code_requirement
                        }}
                    </small>
                </div>

                <div>
                    <label
                        class="mb-2 block text-sm font-semibold text-slate-700"
                    >
                        Requirement Name
                        <span class="text-red-500">
                            *
                        </span>
                    </label>

                    <InputText
                        v-model="form.desc_requirement"
                        class="w-full"
                        maxlength="200"
                        :invalid="
                            Boolean(
                                formErrors.desc_requirement,
                            )
                        "
                        :disabled="saving"
                    />

                    <small
                        v-if="
                            formErrors.desc_requirement
                        "
                        class="text-red-500"
                    >
                        {{
                            formErrors.desc_requirement
                        }}
                    </small>
                </div>

                <div>
                    <label
                        class="mb-2 block text-sm font-semibold text-slate-700"
                    >
                        Rank
                    </label>

                    <Select
                        v-model="form.rank_id"
                        :options="ranks"
                        option-label="label"
                        option-value="value"
                        placeholder="All ranks"
                        show-clear
                        filter
                        class="w-full"
                        :loading="optionsLoading"
                        :disabled="
                            saving
                            || optionsLoading
                        "
                    />

                    <small
                        v-if="formErrors.rank_id"
                        class="text-red-500"
                    >
                        {{ formErrors.rank_id }}
                    </small>
                </div>

                <div>
                    <label
                        class="mb-2 block text-sm font-semibold text-slate-700"
                    >
                        Vessel Type
                    </label>

                    <Select
                        v-model="form.vessel_type"
                        :options="vesselTypes"
                        option-label="label"
                        option-value="value"
                        placeholder="All vessel types"
                        show-clear
                        filter
                        class="w-full"
                        :loading="optionsLoading"
                        :disabled="
                            saving
                            || optionsLoading
                        "
                    />

                    <small
                        v-if="
                            formErrors.vessel_type
                        "
                        class="text-red-500"
                    >
                        {{
                            formErrors.vessel_type
                        }}
                    </small>
                </div>

                <div>
                    <label
                        class="mb-2 block text-sm font-semibold text-slate-700"
                    >
                        Category
                    </label>

                    <InputText
                        v-model="form.cat_requirement"
                        class="w-full"
                        maxlength="20"
                        :invalid="
                            Boolean(
                                formErrors.cat_requirement,
                            )
                        "
                        :disabled="saving"
                    />

                    <small
                        v-if="
                            formErrors.cat_requirement
                        "
                        class="text-red-500"
                    >
                        {{
                            formErrors.cat_requirement
                        }}
                    </small>
                </div>

                <div>
                    <label
                        class="mb-2 block text-sm font-semibold text-slate-700"
                    >
                        Priority
                    </label>

                    <InputNumber
                        v-model="form.prio"
                        class="w-full"
                        :use-grouping="false"
                        :invalid="
                            Boolean(
                                formErrors.prio,
                            )
                        "
                        :disabled="saving"
                    />

                    <small
                        v-if="formErrors.prio"
                        class="text-red-500"
                    >
                        {{ formErrors.prio }}
                    </small>
                </div>

                <div
                    class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 md:col-span-2"
                >
                    <ToggleSwitch
                        v-model="form.cci_reqd"
                        input-id="requirement-cci-required"
                        :disabled="saving"
                    />

                    <div>
                        <label
                            for="requirement-cci-required"
                            class="cursor-pointer font-semibold text-slate-700"
                        >
                            CCI Required
                        </label>

                        <p
                            class="mt-0.5 text-xs text-slate-500"
                        >
                            Enable this when the requirement is required for CCI.
                        </p>
                    </div>
                </div>

                <div
                    class="rounded-xl border border-slate-200 bg-slate-50 p-4 md:col-span-2"
                >
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div>
                            <p
                                class="text-sm font-semibold text-slate-700"
                            >
                                Requirement Template
                            </p>

                            <p
                                class="mt-1 text-xs text-slate-500"
                            >
                                Allowed: JPG, JPEG, PNG, GIF, BMP, PDF, DOC, DOCX, XLS, XLSX, CSV.
                            </p>
                        </div>

                        <Button
                            v-if="form.template_url"
                            type="button"
                            label="Open Current Template"
                            icon="pi pi-external-link"
                            severity="info"
                            outlined
                            size="small"
                            @click="openCurrentTemplate"
                        />
                    </div>

                    <div class="mt-4">
                        <FileUpload
                            :key="fileUploadKey"
                            mode="basic"
                            name="template_file"
                            choose-label="Choose Template"
                            choose-icon="pi pi-upload"
                            :accept="ACCEPTED_TEMPLATE_TYPES"
                            custom-upload
                            :auto="false"
                            :disabled="saving"
                            @select="handleTemplateSelect"
                        />
                    </div>

                    <div
                        v-if="selectedTemplateFile"
                        class="mt-3 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-700"
                    >
                        Selected:
                        <strong>
                            {{ selectedTemplateFile.name }}
                        </strong>
                    </div>

                    <div
                        v-else-if="form.template"
                        class="mt-3 text-sm text-slate-600"
                    >
                        Current file:
                        <strong>
                            {{ form.template }}
                        </strong>
                    </div>

                    <small
                        v-if="
                            formErrors.template_file
                        "
                        class="mt-2 block text-red-500"
                    >
                        {{
                            formErrors.template_file
                        }}
                    </small>
                </div>
            </div>

            <template #footer>
                <Button
                    label="Close"
                    icon="pi pi-times"
                    severity="secondary"
                    outlined
                    :disabled="saving"
                    @click="
                        dialogVisible =
                            false
                    "
                />

                <Button
                    label="Save"
                    icon="pi pi-save"
                    severity="success"
                    :loading="saving"
                    @click="saveRequirementType"
                />
            </template>
        </Dialog>

        <Dialog
            v-model:visible="deleteVisible"
            modal
            header="Delete Requirement Type"
            class="w-[min(92vw,520px)]"
        >
            <div
                class="w-full rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-700"
            >
                Are you sure you want to delete
                <strong>
                    {{
                        selectedRequirementType?.desc_requirement
                        ||
                        selectedRequirementType?.code_requirement
                        ||
                        'this requirement type'
                    }}
                </strong>
                ?
            </div>

            <template #footer>
                <Button
                    label="Cancel"
                    severity="secondary"
                    outlined
                    :disabled="deleting"
                    @click="
                        deleteVisible =
                            false
                    "
                />

                <Button
                    label="Delete"
                    icon="pi pi-trash"
                    severity="danger"
                    :loading="deleting"
                    @click="deleteRequirementType"
                />
            </template>
        </Dialog>
    </div>
</template>
