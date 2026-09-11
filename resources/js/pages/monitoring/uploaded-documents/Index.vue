<script setup lang="ts">
import {Head, router} from '@inertiajs/vue3';
import axios from 'axios';

import Avatar from 'primevue/avatar';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Message from 'primevue/message';
import PrimeTag from 'primevue/tag';

import {
    computed,
    onBeforeUnmount,
    onMounted,
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
                title: 'Uploaded Documents',
                href: '/monitoring/uploaded-documents',
            },
        ],
    },
});

type UploadedFile = {
    name: string;
    label: string;
    url: string;
};

type DocumentApiResponse = {
    data: DataTableRow[];

    meta: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
        from: number | null;
        to: number | null;
    };

    links: {
        first: string | null;
        last: string | null;
        previous: string | null;
        next: string | null;
    };
};

type DataTablePageEvent = {
    page: number;
    rows: number;
    first: number;
};

type DataTableSortEvent = {
    sortField: string;
    sortOrder: number;
};

const documents = ref<DataTableRow[]>([]);

const loading = ref(false);

const totalRecords = ref(0);
const first = ref(0);
const perPage = ref(10);

const search = ref('');

const sortField = ref('date_uploaded');

const sortDirection = ref<'asc' | 'desc'>(
    'desc',
);

const errorMessage = ref('');

let requestController: AbortController | null =
    null;

const columns: DataTableColumn[] = [
    {
        field: 'fname',
        header: 'Student Information',
        sortable: false,
        searchable: true,
        frozen: true,
        alignFrozen: 'left',
        class: 'min-w-[320px]',
    },
    {
        field: 'desc_requirement',
        header: 'Requirement Type',
        sortable: false,
        searchable: false,
        class: 'min-w-[260px] whitespace-normal',
    },
    {
        field: 'date_uploaded',
        header: 'Uploaded Date',
        sortable: false,
        searchable: false,
        class: 'min-w-[180px]',
    },
    {
        field: 'sto_validated',
        header: 'Verified',
        sortable: true,
        searchable: false,
        class: 'min-w-[150px]',
    },
    {
        field: 'revise_remarks',
        header: 'Remarks',
        sortable: false,
        searchable: false,
        class: 'min-w-[240px] whitespace-normal',
    },
  
];

const selectedDocument =
    ref<DataTableRow | null>(null);

const filesDialogVisible = ref(false);

const selectedFiles = computed<
    UploadedFile[]
>(() => {
    if (!selectedDocument.value) {
        return [];
    }

    return getUploadedFiles(
        selectedDocument.value,
    );
});

</script>

<template>
    <Head title="Uploaded Documents" />

</template>