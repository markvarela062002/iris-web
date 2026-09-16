<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import PrimeTag from 'primevue/tag';
import Toast from 'primevue/toast';
import ToggleSwitch from 'primevue/toggleswitch';
import { useToast } from 'primevue/usetoast';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

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
            { title: 'Dashboard', href: dashboard() },
            {
                title: 'Exam Packages',
                href: '/assessment-setup/exam-package',
            },
        ],
    },
});

type ApiListResponse = {
    data: DataTableRow[];
    meta: {
        currentPage: number;
        perPage: number;
        total: number;
    };
};

type Subject = {
    id: string;
    bs_course_id: string;
    desc_topic: string;
    no_quest: number;
    passing_mark: number;
    order_no: number;
};

type PageEvent = { page: number; rows: number; first: number };
type SortEvent = { sortField: string; sortOrder: number };
type Errors = Record<string, string>;

const DATATABLE_URL =
    '/api/v1/assessment-setup/datatable/exam-packages';
const API_BASE = '/api/v1/assessment-setup/exam-packages';

const toast = useToast();
const packages = ref<DataTableRow[]>([]);
const subjects = ref<Subject[]>([]);
const loading = ref(false);
const saving = ref(false);
const subjectLoading = ref(false);
const deleting = ref(false);
const errorMessage = ref('');
const totalRecords = ref(0);
const first = ref(0);
const perPage = ref(10);
const search = ref('');
const sortField = ref('name_course');
const sortDirection = ref<'asc' | 'desc'>('asc');
const packageDialogVisible = ref(false);
const packageDeleteVisible = ref(false);
const subjectDialogVisible = ref(false);
const subjectDeleteVisible = ref(false);
const selectedPackage = ref<DataTableRow | null>(null);
const selectedSubject = ref<Subject | null>(null);
const packageErrors = ref<Errors>({});
const subjectErrors = ref<Errors>({});

const packageForm = reactive({
    id: '',
    code_course: '',
    name_course: '',
    randomize: false,
});

const subjectForm = reactive({
    id: '',
    desc_topic: '',
    no_quest: null as number | null,
    passing_mark: null as number | null,
    order_no: null as number | null,
});

let requestController: AbortController | null = null;

const columns: DataTableColumn[] = [

    {
        field: 'code_course',
        header: 'Code',
        sortable: true,
        searchable: true,
        class: 'min-w-[180px]',
    },
    {
        field: 'name_course',
        header: 'Exam Name',
        sortable: true,
        searchable: true,
        class: 'min-w-[320px]',
    },
    {
        field: 'randomize',
        header: 'Randomize',
        sortable: true,
        searchable: false,
        class: 'min-w-[140px]',
    },
    {
        field: 'total_subjects',
        header: 'Subjects',
        sortable: false,
        searchable: false,
        class: 'min-w-[130px]',
    },
];

const actions: DataTableAction[] = [
    {
        key: 'edit',
        label: 'Edit exam package',
        icon: 'pi pi-pencil',
        severity: 'warn',
    },
    {
        key: 'delete',
        label: 'Delete exam package',
        icon: 'pi pi-trash',
        severity: 'danger',
    },
];

const currentPage = computed(() =>
    Math.floor(first.value / perPage.value) + 1,
);

const packageDialogTitle = computed(() =>
    packageForm.id ? 'Edit Exam Package' : 'Create Exam Package',
);

const subjectDialogTitle = computed(() =>
    subjectForm.id ? 'Edit Subject' : 'Add Subject',
);

async function loadPackages(page = 1): Promise<void> {
    requestController?.abort();
    const controller = new AbortController();
    requestController = controller;
    loading.value = true;
    errorMessage.value = '';

    try {
        const response = await axios.get<ApiListResponse>(DATATABLE_URL, {
            signal: controller.signal,
            params: {
                page,
                per_page: perPage.value,
                search: search.value,
                sort_field: sortField.value,
                sort_direction: sortDirection.value,
            },
            ...requestConfig(),
        });

        packages.value = response.data.data;
        totalRecords.value = response.data.meta.total;
        perPage.value = response.data.meta.perPage;
        first.value =
            (response.data.meta.currentPage - 1) *
            response.data.meta.perPage;
    } catch (error: unknown) {
        if (isCanceled(error)) return;
        packages.value = [];
        totalRecords.value = 0;
        errorMessage.value = getErrorMessage(
            error,
            'Unable to load exam packages.',
        );
    } finally {
        if (requestController === controller) loading.value = false;
    }
}

function handlePage(event: PageEvent): void {
    perPage.value = event.rows;
    first.value = event.first;
    void loadPackages(event.page + 1);
}

function handleSort(event: SortEvent): void {
    sortField.value = event.sortField || 'name_course';
    sortDirection.value = event.sortOrder === -1 ? 'desc' : 'asc';
    first.value = 0;
    void loadPackages(1);
}

function handleSearch(value: string): void {
    search.value = value;
    first.value = 0;
    void loadPackages(1);
}

function handleAction(action: string, row: DataTableRow): void {
    if (action === 'edit') {
        openEditPackage(row);
    } else if (action === 'delete') {
        selectedPackage.value = row;
        packageDeleteVisible.value = true;
    }
}

function openCreatePackage(): void {
    resetPackageForm();
    subjects.value = [];
    packageDialogVisible.value = true;
}

function openEditPackage(row: DataTableRow): void {
    packageErrors.value = {};
    packageForm.id = String(row.id ?? '');
    packageForm.code_course = String(row.code_course ?? '');
    packageForm.name_course = String(row.name_course ?? '');
    packageForm.randomize = String(row.randomize ?? 'N') === 'Y';
    packageDialogVisible.value = true;
    void loadSubjects();
}

async function savePackage(): Promise<void> {
    packageErrors.value = {};

    if (!packageForm.code_course.trim()) {
        packageErrors.value.code_course = 'Code is required.';
    }
    if (!packageForm.name_course.trim()) {
        packageErrors.value.name_course = 'Exam name is required.';
    }
    if (Object.keys(packageErrors.value).length) return;

    saving.value = true;
    const wasNew = !packageForm.id;

    try {
        const payload = {
            code_course: packageForm.code_course.trim(),
            name_course: packageForm.name_course.trim(),
            randomize: packageForm.randomize ? 'Y' : 'N',
        };
        const response = wasNew
            ? await axios.post<{ id: string; message: string }>(
                  API_BASE,
                  payload,
                  requestConfig(),
              )
            : await axios.put<{ id: string; message: string }>(
                  `${API_BASE}/${encodeURIComponent(packageForm.id)}`,
                  payload,
                  requestConfig(),
              );

        packageForm.id = response.data.id;
        toast.add({
            severity: 'success',
            summary: wasNew ? 'Package Created' : 'Package Updated',
            detail: response.data.message,
            life: 4000,
        });
        await loadPackages(wasNew ? 1 : currentPage.value);
        await loadSubjects();
    } catch (error: unknown) {
        packageErrors.value = getValidationErrors(error);
        errorMessage.value = getErrorMessage(error, 'Unable to save the exam package.');
    } finally {
        saving.value = false;
    }
}

async function deletePackage(): Promise<void> {
    const id = String(selectedPackage.value?.id ?? '');
    if (!id) return;
    deleting.value = true;

    try {
        const response = await axios.delete<{ message: string }>(
            `${API_BASE}/${encodeURIComponent(id)}`,
            requestConfig(),
        );
        packageDeleteVisible.value = false;
        selectedPackage.value = null;
        toast.add({
            severity: 'success',
            summary: 'Package Deleted',
            detail: response.data.message,
            life: 4000,
        });
        await reloadCurrentPage();
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(error, 'Unable to delete the exam package.');
    } finally {
        deleting.value = false;
    }
}

async function loadSubjects(): Promise<void> {
    if (!packageForm.id) {
        subjects.value = [];
        return;
    }
    subjectLoading.value = true;
    try {
        const response = await axios.get<{ data: Subject[] }>(
            `${API_BASE}/${encodeURIComponent(packageForm.id)}/subjects`,
            requestConfig(),
        );
        subjects.value = response.data.data;
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(error, 'Unable to load subjects.');
    } finally {
        subjectLoading.value = false;
    }
}

function openNewSubject(): void {
    resetSubjectForm();
    subjectDialogVisible.value = true;
}

function openEditSubject(subject: Subject): void {
    subjectForm.id = subject.id;
    subjectForm.desc_topic = subject.desc_topic;
    subjectForm.no_quest = Number(subject.no_quest);
    subjectForm.passing_mark = Number(subject.passing_mark);
    subjectForm.order_no = Number(subject.order_no);
    subjectErrors.value = {};
    subjectDialogVisible.value = true;
}

async function saveSubject(): Promise<void> {
    if (!packageForm.id) return;
    subjectErrors.value = {};
    if (!subjectForm.desc_topic.trim()) subjectErrors.value.desc_topic = 'Description is required.';
    if (subjectForm.no_quest === null) subjectErrors.value.no_quest = 'Number of items is required.';
    if (subjectForm.passing_mark === null) subjectErrors.value.passing_mark = 'Passing mark is required.';
    if (subjectForm.order_no === null) subjectErrors.value.order_no = 'Order number is required.';
    if (Object.keys(subjectErrors.value).length) return;

    subjectLoading.value = true;
    try {
        const base = `${API_BASE}/${encodeURIComponent(packageForm.id)}/subjects`;
        const payload = {
            desc_topic: subjectForm.desc_topic.trim(),
            no_quest: subjectForm.no_quest,
            passing_mark: subjectForm.passing_mark,
            order_no: subjectForm.order_no,
        };
        const response = subjectForm.id
            ? await axios.put<{ message: string }>(
                  `${base}/${encodeURIComponent(subjectForm.id)}`,
                  payload,
                  requestConfig(),
              )
            : await axios.post<{ message: string }>(base, payload, requestConfig());

        toast.add({
            severity: 'success',
            summary: subjectForm.id ? 'Subject Updated' : 'Subject Added',
            detail: response.data.message,
            life: 4000,
        });
        subjectDialogVisible.value = false;
        resetSubjectForm();
        await loadSubjects();
        await loadPackages(currentPage.value);
    } catch (error: unknown) {
        subjectErrors.value = getValidationErrors(error);
        errorMessage.value = getErrorMessage(error, 'Unable to save the subject.');
    } finally {
        subjectLoading.value = false;
    }
}

async function deleteSubject(): Promise<void> {
    if (!packageForm.id || !selectedSubject.value?.id) return;
    subjectLoading.value = true;
    try {
        const response = await axios.delete<{ message: string }>(
            `${API_BASE}/${encodeURIComponent(packageForm.id)}/subjects/${encodeURIComponent(selectedSubject.value.id)}`,
            requestConfig(),
        );
        subjectDeleteVisible.value = false;
        selectedSubject.value = null;
        toast.add({
            severity: 'success',
            summary: 'Subject Deleted',
            detail: response.data.message,
            life: 4000,
        });
        await loadSubjects();
        await loadPackages(currentPage.value);
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(error, 'Unable to delete the subject.');
    } finally {
        subjectLoading.value = false;
    }
}

function resetPackageForm(): void {
    Object.assign(packageForm, {
        id: '',
        code_course: '',
        name_course: '',
        randomize: false,
    });
    packageErrors.value = {};
}

function resetSubjectForm(): void {
    Object.assign(subjectForm, {
        id: '',
        desc_topic: '',
        no_quest: null,
        passing_mark: null,
        order_no: null,
    });
    subjectErrors.value = {};
}

async function reloadCurrentPage(): Promise<void> {
    await loadPackages(currentPage.value);
    if (!packages.value.length && currentPage.value > 1) {
        await loadPackages(currentPage.value - 1);
    }
}

function requestConfig() {
    return {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        withCredentials: true,
    };
}

function isCanceled(error: unknown): boolean {
    return axios.isCancel(error) ||
        (axios.isAxiosError(error) && error.code === 'ERR_CANCELED');
}

function getErrorMessage(error: unknown, fallback: string): string {
    if (!axios.isAxiosError(error)) return fallback;
    return (error.response?.data as { message?: string } | undefined)?.message || fallback;
}

function getValidationErrors(error: unknown): Errors {
    if (!axios.isAxiosError(error)) return {};
    const source = (error.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors ?? {};
    return Object.fromEntries(
        Object.entries(source).map(([key, messages]) => [key, messages[0] ?? 'Invalid value.']),
    );
}

onMounted(() => void loadPackages(1));
onBeforeUnmount(() => requestController?.abort());
</script>

<template>
    <Head title="Exam Packages" />
    <Toast position="top-right" />

    <div class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5">
        <Message v-if="errorMessage" severity="error" closable @close="errorMessage = ''">
            {{ errorMessage }}
        </Message>

        <Datatable
            title="Exam Packages"
            description="Create exam packages and manage their subjects."
            header-icon="pi pi-box"
            search-placeholder="Search code or exam name..."
            empty-title="No exam packages found"
            empty-description="Create an exam package to get started."
            empty-icon="pi pi-box"
            table-min-width="1100px"
            actions-header="Actions"
            actions-width="130px"
            data-key="id"
            lazy
            :loading="loading"
            :data="packages"
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
                <Button label="Create Exam Package" icon="pi pi-plus" severity="success" size="small" @click="openCreatePackage" />
            </template>

            <template #cell-code_course="{ value }">
                <span class="font-semibold text-slate-700">{{ value || '—' }}</span>
            </template>

            <template #cell-randomize="{ value }">
                <PrimeTag :value="value === 'Y' ? 'Yes' : 'No'" :severity="value === 'Y' ? 'success' : 'secondary'" rounded />
            </template>

            <template #cell-total_subjects="{ value }">
                <PrimeTag :value="String(value ?? 0)" severity="info" icon="pi pi-book" rounded />
            </template>
        </Datatable>

        <Dialog v-model:visible="packageDialogVisible" modal :header="packageDialogTitle" :closable="!saving" class="w-[min(96vw,920px)]">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-2 block text-sm font-semibold text-slate-700">Code</label>
                    <InputText v-model="packageForm.code_course" class="w-full" :invalid="Boolean(packageErrors.code_course)" />
                    <small v-if="packageErrors.code_course" class="text-red-500">{{ packageErrors.code_course }}</small>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-semibold text-slate-700">Exam Name</label>
                    <InputText v-model="packageForm.name_course" class="w-full" :invalid="Boolean(packageErrors.name_course)" />
                    <small v-if="packageErrors.name_course" class="text-red-500">{{ packageErrors.name_course }}</small>
                </div>
                <div class="flex items-center gap-3 sm:col-span-2">
                    <ToggleSwitch v-model="packageForm.randomize" input-id="randomize" />
                    <label for="randomize" class="font-semibold text-slate-700">Randomize questions</label>
                </div>
            </div>

            <div class="mt-6 border-t border-slate-200 pt-5">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <h3 class="font-bold text-slate-800">Subjects</h3>
                        <p class="text-sm text-slate-500">Subjects are ordered using their order number.</p>
                    </div>
                    <Button label="Add Subject" icon="pi pi-plus" size="small" :disabled="!packageForm.id" @click="openNewSubject" />
                </div>

                <Message v-if="!packageForm.id" severity="info" :closable="false">Save the exam package before adding subjects.</Message>

                <div v-else class="overflow-x-auto rounded-xl border border-slate-200">
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th class="px-4 py-3">Description</th>
                                <th class="px-4 py-3 text-center">Items</th>
                                <th class="px-4 py-3 text-center">Passing Mark</th>
                                <th class="px-4 py-3 text-center">Order</th>
                                <th class="px-4 py-3 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="subjectLoading"><td colspan="5" class="px-4 py-6 text-center text-slate-500">Loading subjects...</td></tr>
                            <tr v-else-if="!subjects.length"><td colspan="5" class="px-4 py-6 text-center text-slate-500">No subjects added.</td></tr>
                            <tr v-for="subject in subjects" :key="subject.id" class="border-t border-slate-200">
                                <td class="px-4 py-3 font-medium text-slate-700">{{ subject.desc_topic }}</td>
                                <td class="px-4 py-3 text-center">{{ subject.no_quest }}</td>
                                <td class="px-4 py-3 text-center">{{ subject.passing_mark }}</td>
                                <td class="px-4 py-3 text-center">{{ subject.order_no }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-center gap-2">
                                        <Button icon="pi pi-pencil" severity="warn" rounded size="small" aria-label="Edit subject" @click="openEditSubject(subject)" />
                                        <Button icon="pi pi-trash" severity="danger" rounded size="small" aria-label="Delete subject" @click="selectedSubject = subject; subjectDeleteVisible = true" />
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <template #footer>
                <Button label="Close" icon="pi pi-times" severity="secondary" outlined :disabled="saving" @click="packageDialogVisible = false" />
                <Button label="Save" icon="pi pi-save" severity="success" :loading="saving" @click="savePackage" />
            </template>
        </Dialog>

        <Dialog v-model:visible="subjectDialogVisible" modal :header="subjectDialogTitle" class="w-[min(92vw,620px)]">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="mb-2 block text-sm font-semibold">Description</label>
                    <InputText v-model="subjectForm.desc_topic" class="w-full" :invalid="Boolean(subjectErrors.desc_topic)" />
                    <small v-if="subjectErrors.desc_topic" class="text-red-500">{{ subjectErrors.desc_topic }}</small>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-semibold">No. of Items</label>
                    <InputNumber v-model="subjectForm.no_quest" class="w-full" :min="1" :use-grouping="false" />
                    <small v-if="subjectErrors.no_quest" class="text-red-500">{{ subjectErrors.no_quest }}</small>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-semibold">Passing Mark</label>
                    <InputNumber v-model="subjectForm.passing_mark" class="w-full" :min="0" :use-grouping="false" />
                    <small v-if="subjectErrors.passing_mark" class="text-red-500">{{ subjectErrors.passing_mark }}</small>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-semibold">Order No.</label>
                    <InputNumber v-model="subjectForm.order_no" class="w-full" :min="1" :use-grouping="false" />
                    <small v-if="subjectErrors.order_no" class="text-red-500">{{ subjectErrors.order_no }}</small>
                </div>
            </div>
            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="subjectDialogVisible = false" />
                <Button label="Save" icon="pi pi-save" severity="success" :loading="subjectLoading" @click="saveSubject" />
            </template>
        </Dialog>

        <Dialog v-model:visible="packageDeleteVisible" modal header="Delete Exam Package" class="w-[min(92vw,520px)]">
            <Message severity="warn" :closable="false">Delete <strong>{{ selectedPackage?.name_course || 'this exam package' }}</strong>? All subjects must be removed first.</Message>
            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="packageDeleteVisible = false" />
                <Button label="Delete" icon="pi pi-trash" severity="danger" :loading="deleting" @click="deletePackage" />
            </template>
        </Dialog>

        <Dialog v-model:visible="subjectDeleteVisible" modal header="Delete Subject" class="w-[min(92vw,520px)]">
            <Message severity="warn" :closable="false">Delete <strong>{{ selectedSubject?.desc_topic || 'this subject' }}</strong>?</Message>
            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="subjectDeleteVisible = false" />
                <Button label="Delete" icon="pi pi-trash" severity="danger" :loading="subjectLoading" @click="deleteSubject" />
            </template>
        </Dialog>
    </div>
</template>
