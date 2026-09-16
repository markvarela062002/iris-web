<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import PrimeTag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

import Datatable from '@/components/Datatable.vue';
import { dashboard } from '@/routes';
import type { DataTableAction, DataTableColumn, DataTableRow } from '@/types';

defineOptions({
    inheritAttrs: false,
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Rubric Setup', href: '/assessment-setup/rubric-setup' },
        ],
    },
});

type ListResponse = {
    data: DataTableRow[];
    meta: { currentPage: number; perPage: number; total: number };
};
type PageEvent = { page: number; rows: number; first: number };
type SortEvent = { sortField: string; sortOrder: number };
type Level = {
    id: string;
    item_point: number;
    item_title: string;
    item_desc: string;
};
type Criterion = {
    id: string;
    criterion_title: string;
    criterion_desc: string;
    maximum_points: number;
    levels: Level[];
};
type Errors = Record<string, string>;

const API_BASE = '/api/v1/assessment-setup/rubrics';
const DATATABLE_URL = '/api/v1/assessment-setup/datatable/rubrics';

const toast = useToast();
const rubrics = ref<DataTableRow[]>([]);
const criteria = ref<Criterion[]>([]);
const loading = ref(false);
const saving = ref(false);
const childLoading = ref(false);
const errorMessage = ref('');
const totalRecords = ref(0);
const first = ref(0);
const perPage = ref(10);
const search = ref('');
const sortField = ref('rubrics_name');
const sortDirection = ref<'asc' | 'desc'>('asc');
const rubricDialogVisible = ref(false);
const rubricDeleteVisible = ref(false);
const criterionDialogVisible = ref(false);
const criterionDeleteVisible = ref(false);
const levelDialogVisible = ref(false);
const levelDeleteVisible = ref(false);
const selectedRubric = ref<DataTableRow | null>(null);
const selectedCriterion = ref<Criterion | null>(null);
const selectedLevel = ref<Level | null>(null);
const rubricErrors = ref<Errors>({});
const criterionErrors = ref<Errors>({});
const levelErrors = ref<Errors>({});
let requestController: AbortController | null = null;

const rubricForm = reactive({ id: '', rubrics_name: '', rubrics_desc: '' });
const criterionForm = reactive({ id: '', criterion_title: '', criterion_desc: '' });
const levelForm = reactive({
    id: '',
    criterion_id: '',
    item_point: null as number | null,
    item_title: '',
    item_desc: '',
});

const columns: DataTableColumn[] = [
    { field: 'index', header: '#', sortable: false, searchable: false, class: 'w-[80px]' },
    { field: 'rubrics_name', header: 'Name', sortable: true, searchable: true, class: 'min-w-[260px]' },
    { field: 'rubrics_desc', header: 'Description', sortable: true, searchable: true, class: 'min-w-[360px] whitespace-normal' },
    { field: 'total_criteria', header: 'Criteria', sortable: false, searchable: false, class: 'min-w-[120px]' },
    { field: 'total_points', header: 'Total Points', sortable: false, searchable: false, class: 'min-w-[150px]' },
];
const actions: DataTableAction[] = [
    { key: 'edit', label: 'Edit rubric', icon: 'pi pi-pencil', severity: 'warn' },
    { key: 'delete', label: 'Delete rubric', icon: 'pi pi-trash', severity: 'danger' },
];

const currentPage = computed(() => Math.floor(first.value / perPage.value) + 1);
const rubricDialogTitle = computed(() => rubricForm.id ? 'Edit Rubric' : 'Create Rubric');
const criterionDialogTitle = computed(() => criterionForm.id ? 'Edit Criterion' : 'Add Criterion');
const levelDialogTitle = computed(() => levelForm.id ? 'Edit Performance Level' : 'Add Performance Level');
const currentTotalPoints = computed(() =>
    criteria.value.reduce((total, criterion) => total + Number(criterion.maximum_points || 0), 0),
);

async function loadRubrics(page = 1): Promise<void> {
    requestController?.abort();
    const controller = new AbortController();
    requestController = controller;
    loading.value = true;
    errorMessage.value = '';
    try {
        const response = await axios.get<ListResponse>(DATATABLE_URL, {
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
        rubrics.value = response.data.data;
        totalRecords.value = response.data.meta.total;
        perPage.value = response.data.meta.perPage;
        first.value = (response.data.meta.currentPage - 1) * response.data.meta.perPage;
    } catch (error: unknown) {
        if (isCanceled(error)) return;
        rubrics.value = [];
        totalRecords.value = 0;
        errorMessage.value = getErrorMessage(error, 'Unable to load rubrics.');
    } finally {
        if (requestController === controller) loading.value = false;
    }
}

function handlePage(event: PageEvent): void {
    perPage.value = event.rows;
    first.value = event.first;
    void loadRubrics(event.page + 1);
}
function handleSort(event: SortEvent): void {
    sortField.value = event.sortField || 'rubrics_name';
    sortDirection.value = event.sortOrder === -1 ? 'desc' : 'asc';
    first.value = 0;
    void loadRubrics(1);
}
function handleSearch(value: string): void {
    search.value = value;
    first.value = 0;
    void loadRubrics(1);
}
function handleAction(action: string, row: DataTableRow): void {
    if (action === 'edit') openEditRubric(row);
    if (action === 'delete') {
        selectedRubric.value = row;
        rubricDeleteVisible.value = true;
    }
}

function openCreateRubric(): void {
    resetRubric();
    criteria.value = [];
    rubricDialogVisible.value = true;
}
function openEditRubric(row: DataTableRow): void {
    rubricForm.id = String(row.id ?? '');
    rubricForm.rubrics_name = String(row.rubrics_name ?? '');
    rubricForm.rubrics_desc = String(row.rubrics_desc ?? '');
    rubricErrors.value = {};
    rubricDialogVisible.value = true;
    void loadCriteria();
}

async function saveRubric(): Promise<void> {
    rubricErrors.value = {};
    if (!rubricForm.rubrics_name.trim()) rubricErrors.value.rubrics_name = 'Name is required.';
    if (Object.keys(rubricErrors.value).length) return;
    const wasNew = !rubricForm.id;
    saving.value = true;
    try {
        const payload = {
            rubrics_name: rubricForm.rubrics_name.trim(),
            rubrics_desc: rubricForm.rubrics_desc.trim(),
        };
        const response = wasNew
            ? await axios.post<{ id: string; message: string }>(API_BASE, payload, requestConfig())
            : await axios.put<{ id: string; message: string }>(`${API_BASE}/${encodeURIComponent(rubricForm.id)}`, payload, requestConfig());
        rubricForm.id = response.data.id;
        notify(wasNew ? 'Rubric Created' : 'Rubric Updated', response.data.message);
        await loadRubrics(wasNew ? 1 : currentPage.value);
        await loadCriteria();
    } catch (error: unknown) {
        rubricErrors.value = getValidationErrors(error);
        errorMessage.value = getErrorMessage(error, 'Unable to save the rubric.');
    } finally {
        saving.value = false;
    }
}

async function deleteRubric(): Promise<void> {
    const id = String(selectedRubric.value?.id ?? '');
    if (!id) return;
    saving.value = true;
    try {
        const response = await axios.delete<{ message: string }>(`${API_BASE}/${encodeURIComponent(id)}`, requestConfig());
        rubricDeleteVisible.value = false;
        selectedRubric.value = null;
        notify('Rubric Deleted', response.data.message);
        await reloadCurrentPage();
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(error, 'Unable to delete the rubric.');
    } finally {
        saving.value = false;
    }
}

async function loadCriteria(): Promise<void> {
    if (!rubricForm.id) {
        criteria.value = [];
        return;
    }
    childLoading.value = true;
    try {
        const response = await axios.get<{ data: Criterion[] }>(
            `${API_BASE}/${encodeURIComponent(rubricForm.id)}/criteria`,
            requestConfig(),
        );
        criteria.value = response.data.data;
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(error, 'Unable to load rubric criteria.');
    } finally {
        childLoading.value = false;
    }
}

function openNewCriterion(): void {
    resetCriterion();
    criterionDialogVisible.value = true;
}
function openEditCriterion(criterion: Criterion): void {
    criterionForm.id = criterion.id;
    criterionForm.criterion_title = criterion.criterion_title;
    criterionForm.criterion_desc = criterion.criterion_desc || '';
    criterionErrors.value = {};
    criterionDialogVisible.value = true;
}
async function saveCriterion(): Promise<void> {
    if (!rubricForm.id) return;
    criterionErrors.value = {};
    if (!criterionForm.criterion_title.trim()) criterionErrors.value.criterion_title = 'Criterion title is required.';
    if (Object.keys(criterionErrors.value).length) return;
    childLoading.value = true;
    try {
        const base = `${API_BASE}/${encodeURIComponent(rubricForm.id)}/criteria`;
        const payload = {
            criterion_title: criterionForm.criterion_title.trim(),
            criterion_desc: criterionForm.criterion_desc.trim(),
        };
        const response = criterionForm.id
            ? await axios.put<{ message: string }>(`${base}/${encodeURIComponent(criterionForm.id)}`, payload, requestConfig())
            : await axios.post<{ message: string }>(base, payload, requestConfig());
        notify(criterionForm.id ? 'Criterion Updated' : 'Criterion Added', response.data.message);
        criterionDialogVisible.value = false;
        resetCriterion();
        await refreshChildrenAndTable();
    } catch (error: unknown) {
        criterionErrors.value = getValidationErrors(error);
        errorMessage.value = getErrorMessage(error, 'Unable to save the criterion.');
    } finally {
        childLoading.value = false;
    }
}
async function deleteCriterion(): Promise<void> {
    if (!rubricForm.id || !selectedCriterion.value?.id) return;
    childLoading.value = true;
    try {
        const response = await axios.delete<{ message: string }>(
            `${API_BASE}/${encodeURIComponent(rubricForm.id)}/criteria/${encodeURIComponent(selectedCriterion.value.id)}`,
            requestConfig(),
        );
        criterionDeleteVisible.value = false;
        selectedCriterion.value = null;
        notify('Criterion Deleted', response.data.message);
        await refreshChildrenAndTable();
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(error, 'Unable to delete the criterion.');
    } finally {
        childLoading.value = false;
    }
}

function openNewLevel(criterion: Criterion): void {
    resetLevel();
    levelForm.criterion_id = criterion.id;
    selectedCriterion.value = criterion;
    levelDialogVisible.value = true;
}
function openEditLevel(criterion: Criterion, level: Level): void {
    levelForm.id = level.id;
    levelForm.criterion_id = criterion.id;
    levelForm.item_point = Number(level.item_point);
    levelForm.item_title = level.item_title;
    levelForm.item_desc = level.item_desc || '';
    selectedCriterion.value = criterion;
    levelErrors.value = {};
    levelDialogVisible.value = true;
}
async function saveLevel(): Promise<void> {
    if (!rubricForm.id || !levelForm.criterion_id) return;
    levelErrors.value = {};
    if (levelForm.item_point === null) levelErrors.value.item_point = 'Points are required.';
    if (!levelForm.item_title.trim()) levelErrors.value.item_title = 'Level title is required.';
    if (Object.keys(levelErrors.value).length) return;
    childLoading.value = true;
    try {
        const base = `${API_BASE}/${encodeURIComponent(rubricForm.id)}/criteria/${encodeURIComponent(levelForm.criterion_id)}/levels`;
        const payload = {
            item_point: levelForm.item_point,
            item_title: levelForm.item_title.trim(),
            item_desc: levelForm.item_desc.trim(),
        };
        const response = levelForm.id
            ? await axios.put<{ message: string }>(`${base}/${encodeURIComponent(levelForm.id)}`, payload, requestConfig())
            : await axios.post<{ message: string }>(base, payload, requestConfig());
        notify(levelForm.id ? 'Level Updated' : 'Level Added', response.data.message);
        levelDialogVisible.value = false;
        resetLevel();
        await refreshChildrenAndTable();
    } catch (error: unknown) {
        levelErrors.value = getValidationErrors(error);
        errorMessage.value = getErrorMessage(error, 'Unable to save the performance level.');
    } finally {
        childLoading.value = false;
    }
}
async function deleteLevel(): Promise<void> {
    if (!rubricForm.id || !selectedCriterion.value?.id || !selectedLevel.value?.id) return;
    childLoading.value = true;
    try {
        const response = await axios.delete<{ message: string }>(
            `${API_BASE}/${encodeURIComponent(rubricForm.id)}/criteria/${encodeURIComponent(selectedCriterion.value.id)}/levels/${encodeURIComponent(selectedLevel.value.id)}`,
            requestConfig(),
        );
        levelDeleteVisible.value = false;
        selectedLevel.value = null;
        notify('Level Deleted', response.data.message);
        await refreshChildrenAndTable();
    } catch (error: unknown) {
        errorMessage.value = getErrorMessage(error, 'Unable to delete the performance level.');
    } finally {
        childLoading.value = false;
    }
}

async function refreshChildrenAndTable(): Promise<void> {
    await Promise.all([loadCriteria(), loadRubrics(currentPage.value)]);
}
function resetRubric(): void {
    Object.assign(rubricForm, { id: '', rubrics_name: '', rubrics_desc: '' });
    rubricErrors.value = {};
}
function resetCriterion(): void {
    Object.assign(criterionForm, { id: '', criterion_title: '', criterion_desc: '' });
    criterionErrors.value = {};
}
function resetLevel(): void {
    Object.assign(levelForm, { id: '', criterion_id: '', item_point: null, item_title: '', item_desc: '' });
    levelErrors.value = {};
}
async function reloadCurrentPage(): Promise<void> {
    await loadRubrics(currentPage.value);
    if (!rubrics.value.length && currentPage.value > 1) await loadRubrics(currentPage.value - 1);
}
function requestConfig() {
    return { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, withCredentials: true };
}
function isCanceled(error: unknown): boolean {
    return axios.isCancel(error) || (axios.isAxiosError(error) && error.code === 'ERR_CANCELED');
}
function getErrorMessage(error: unknown, fallback: string): string {
    if (!axios.isAxiosError(error)) return fallback;
    return (error.response?.data as { message?: string } | undefined)?.message || fallback;
}
function getValidationErrors(error: unknown): Errors {
    if (!axios.isAxiosError(error)) return {};
    const errors = (error.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors ?? {};
    return Object.fromEntries(Object.entries(errors).map(([key, messages]) => [key, messages[0] ?? 'Invalid value.']));
}
function notify(summary: string, detail: string): void {
    toast.add({ severity: 'success', summary, detail, life: 4000 });
}
function formatPoints(value: unknown): string {
    const number = Number(value ?? 0);
    return Number.isInteger(number) ? String(number) : number.toFixed(2);
}

onMounted(() => void loadRubrics(1));
onBeforeUnmount(() => requestController?.abort());
</script>

<template>
    <Head title="Rubric Setup" />
    <Toast position="top-right" />

    <div class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5">
        <Message v-if="errorMessage" severity="error" closable @close="errorMessage = ''">{{ errorMessage }}</Message>

        <Datatable
            title="Rubric Setup"
            description="Create rubrics, criteria, and scored performance levels."
            header-icon="pi pi-list-check"
            search-placeholder="Search rubrics..."
            empty-title="No rubrics found"
            empty-description="Create a rubric to get started."
            empty-icon="pi pi-list-check"
            table-min-width="1150px"
            actions-header="Actions"
            actions-width="130px"
            data-key="id"
            lazy
            :loading="loading"
            :data="rubrics"
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
            <template #header-actions><Button label="Create Rubric" icon="pi pi-plus" severity="success" size="small" @click="openCreateRubric" /></template>
            <template #cell-rubrics_name="{ value }"><span class="font-semibold text-slate-700">{{ value || '—' }}</span></template>
            <template #cell-rubrics_desc="{ value }"><span class="whitespace-normal text-slate-600">{{ value || '—' }}</span></template>
            <template #cell-total_criteria="{ value }"><PrimeTag :value="String(value ?? 0)" severity="info" rounded /></template>
            <template #cell-total_points="{ value }"><PrimeTag :value="`${formatPoints(value)} pts`" severity="success" rounded /></template>
        </Datatable>

        <Dialog v-model:visible="rubricDialogVisible" modal :header="rubricDialogTitle" :closable="!saving" class="w-[min(97vw,1150px)]">
            <div class="grid gap-4 md:grid-cols-2">
                <div><label class="mb-2 block text-sm font-semibold">Name</label><InputText v-model="rubricForm.rubrics_name" class="w-full" :invalid="Boolean(rubricErrors.rubrics_name)" /><small v-if="rubricErrors.rubrics_name" class="text-red-500">{{ rubricErrors.rubrics_name }}</small></div>
                <div><label class="mb-2 block text-sm font-semibold">Description</label><Textarea v-model="rubricForm.rubrics_desc" rows="3" class="w-full" auto-resize /></div>
            </div>

            <div class="mt-6 border-t border-slate-200 pt-5">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <div><h3 class="font-bold text-slate-800">Criteria</h3><p class="text-sm text-slate-500">Total rubric score: <strong>{{ formatPoints(currentTotalPoints) }} points</strong></p></div>
                    <Button label="Add Criterion" icon="pi pi-plus" size="small" :disabled="!rubricForm.id" @click="openNewCriterion" />
                </div>
                <Message v-if="!rubricForm.id" severity="info" :closable="false">Save the rubric before adding criteria.</Message>
                <div v-else class="space-y-4">
                    <div v-if="childLoading" class="py-8 text-center text-slate-500">Loading rubric criteria...</div>
                    <div v-else-if="!criteria.length" class="rounded-xl border border-dashed border-slate-300 py-8 text-center text-slate-500">No criteria added.</div>
                    <article v-for="criterion in criteria" :key="criterion.id" class="rounded-xl border border-slate-200 bg-white p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div><div class="flex items-center gap-2"><h4 class="font-bold text-slate-800">{{ criterion.criterion_title }}</h4><PrimeTag :value="`${formatPoints(criterion.maximum_points)} max`" severity="success" rounded /></div><p class="mt-1 text-sm text-slate-600">{{ criterion.criterion_desc || 'No description.' }}</p></div>
                            <div class="flex gap-2"><Button label="Add Level" icon="pi pi-plus" size="small" @click="openNewLevel(criterion)" /><Button icon="pi pi-pencil" severity="warn" rounded size="small" @click="openEditCriterion(criterion)" /><Button icon="pi pi-trash" severity="danger" rounded size="small" @click="selectedCriterion = criterion; criterionDeleteVisible = true" /></div>
                        </div>
                        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <div v-for="level in criterion.levels" :key="level.id" class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <div class="flex items-start justify-between gap-2"><div><p class="font-bold text-slate-700">{{ level.item_title }}</p><PrimeTag :value="`${formatPoints(level.item_point)} pts`" severity="info" class="mt-1" /></div><div class="flex"><Button icon="pi pi-pencil" severity="warn" text rounded size="small" @click="openEditLevel(criterion, level)" /><Button icon="pi pi-trash" severity="danger" text rounded size="small" @click="selectedCriterion = criterion; selectedLevel = level; levelDeleteVisible = true" /></div></div>
                                <p class="mt-3 text-sm text-slate-600">{{ level.item_desc || 'No description.' }}</p>
                            </div>
                            <p v-if="!criterion.levels.length" class="text-sm italic text-slate-500">No performance levels.</p>
                        </div>
                    </article>
                </div>
            </div>
            <template #footer><Button label="Close" severity="secondary" outlined @click="rubricDialogVisible = false" /><Button label="Save" icon="pi pi-save" severity="success" :loading="saving" @click="saveRubric" /></template>
        </Dialog>

        <Dialog v-model:visible="criterionDialogVisible" modal :header="criterionDialogTitle" class="w-[min(92vw,650px)]"><div class="space-y-4"><div><label class="mb-2 block text-sm font-semibold">Criterion Title</label><InputText v-model="criterionForm.criterion_title" class="w-full" /><small v-if="criterionErrors.criterion_title" class="text-red-500">{{ criterionErrors.criterion_title }}</small></div><div><label class="mb-2 block text-sm font-semibold">Description</label><Textarea v-model="criterionForm.criterion_desc" rows="4" class="w-full" auto-resize /></div></div><template #footer><Button label="Cancel" severity="secondary" outlined @click="criterionDialogVisible = false" /><Button label="Save" icon="pi pi-save" severity="success" :loading="childLoading" @click="saveCriterion" /></template></Dialog>

        <Dialog v-model:visible="levelDialogVisible" modal :header="levelDialogTitle" class="w-[min(92vw,680px)]"><div class="grid gap-4 sm:grid-cols-2"><div><label class="mb-2 block text-sm font-semibold">Points</label><InputNumber v-model="levelForm.item_point" :min="0" :max-fraction-digits="2" :use-grouping="false" class="w-full" /><small v-if="levelErrors.item_point" class="text-red-500">{{ levelErrors.item_point }}</small></div><div><label class="mb-2 block text-sm font-semibold">Level Title</label><InputText v-model="levelForm.item_title" class="w-full" /><small v-if="levelErrors.item_title" class="text-red-500">{{ levelErrors.item_title }}</small></div><div class="sm:col-span-2"><label class="mb-2 block text-sm font-semibold">Description</label><Textarea v-model="levelForm.item_desc" rows="4" class="w-full" auto-resize /></div></div><template #footer><Button label="Cancel" severity="secondary" outlined @click="levelDialogVisible = false" /><Button label="Save" icon="pi pi-save" severity="success" :loading="childLoading" @click="saveLevel" /></template></Dialog>

        <Dialog v-model:visible="rubricDeleteVisible" modal header="Delete Rubric" class="w-[min(92vw,520px)]"><Message severity="warn" :closable="false">Delete <strong>{{ selectedRubric?.rubrics_name || 'this rubric' }}</strong>? Its criteria and performance levels will also be deleted.</Message><template #footer><Button label="Cancel" severity="secondary" outlined @click="rubricDeleteVisible = false" /><Button label="Delete" icon="pi pi-trash" severity="danger" :loading="saving" @click="deleteRubric" /></template></Dialog>
        <Dialog v-model:visible="criterionDeleteVisible" modal header="Delete Criterion" class="w-[min(92vw,520px)]"><Message severity="warn" :closable="false">Delete <strong>{{ selectedCriterion?.criterion_title || 'this criterion' }}</strong>? All its performance levels will also be deleted.</Message><template #footer><Button label="Cancel" severity="secondary" outlined @click="criterionDeleteVisible = false" /><Button label="Delete" icon="pi pi-trash" severity="danger" :loading="childLoading" @click="deleteCriterion" /></template></Dialog>
        <Dialog v-model:visible="levelDeleteVisible" modal header="Delete Performance Level" class="w-[min(92vw,520px)]"><Message severity="warn" :closable="false">Delete <strong>{{ selectedLevel?.item_title || 'this performance level' }}</strong>?</Message><template #footer><Button label="Cancel" severity="secondary" outlined @click="levelDeleteVisible = false" /><Button label="Delete" icon="pi pi-trash" severity="danger" :loading="childLoading" @click="deleteLevel" /></template></Dialog>
    </div>
</template>
