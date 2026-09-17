<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import Avatar from 'primevue/avatar';
import Message from 'primevue/message';
import PrimeTag from 'primevue/tag';
import {
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
                title: 'Students',
                href: '/databases/students',
            },
        ],
    },
});

type StudentActivity = {
    id: string;
    activity_id: string | null;
    description: string | null;
    start_date: string | null;
    end_date: string | null;
    verified: string | null;
};

type StudentUploadedFile = {
    id: string;
    requirement_id: string | null;
    requirement: string | null;
    description: string | null;
    date_uploaded: string | null;
    verified: string | null;
};

type StudentApiResponse = {
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

type DepartmentSeverity =
    | 'success'
    | 'info'
    | 'secondary';

/*
|--------------------------------------------------------------------------
| Table state
|--------------------------------------------------------------------------
*/

const students = ref<DataTableRow[]>([]);

const loading = ref(false);

const totalRecords = ref(0);
const first = ref(0);
const perPage = ref(10);
const search = ref('');

const sortField = ref('lname');

const sortDirection = ref<'asc' | 'desc'>(
    'asc',
);

const errorMessage = ref('');

let requestController:
    | AbortController
    | null = null;

/*
|--------------------------------------------------------------------------
| DataTable columns
|--------------------------------------------------------------------------
*/

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
        field: 'activities',
        header: 'Latest Activities',
        sortable: false,
        searchable: false,
        class:
            'min-w-[320px] whitespace-normal',
    },
    {
        field: 'uploaded_files',
        header: 'Latest Uploaded Documents',
        sortable: false,
        searchable: false,
        class:
            'min-w-[320px] whitespace-normal',
    },
];

/*
|--------------------------------------------------------------------------
| DataTable actions
|--------------------------------------------------------------------------
*/

const actions: DataTableAction[] = [
    {
        key: 'edit',
        label: 'Edit Student',
        icon: 'pi pi-pencil',
        severity: 'info',
    },
];

/*
|--------------------------------------------------------------------------
| Load students
|--------------------------------------------------------------------------
*/

async function loadStudents(
    pageNumber = 1,
): Promise<void> {
    requestController?.abort();

    const controller =
        new AbortController();

    requestController = controller;

    loading.value = true;
    errorMessage.value = '';

    try {
        const response =
            await axios.get<StudentApiResponse>(
                '/api/v1/databases/datatable/students',
                {
                    signal:
                        controller.signal,

                    params: {
                        page:
                            pageNumber,

                        per_page:
                            perPage.value,

                        search:
                            search.value,

                        sort_field:
                            sortField.value,

                        sort_direction:
                            sortDirection.value,
                    },

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

        students.value =
            response.data.data;

        totalRecords.value =
            response.data.meta.total;

        perPage.value =
            response.data.meta.perPage;

        first.value =
            (
                response.data.meta
                    .currentPage - 1
            ) *
            response.data.meta.perPage;
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

        students.value = [];
        totalRecords.value = 0;

        errorMessage.value =
            getErrorMessage(
                error,
                'Unable to load students.',
            );

        console.error(
            'Unable to load students:',
            error,
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

/*
|--------------------------------------------------------------------------
| DataTable events
|--------------------------------------------------------------------------
*/

function handlePage(
    event: DataTablePageEvent,
): void {
    perPage.value = event.rows;
    first.value = event.first;

    void loadStudents(
        event.page + 1,
    );
}

function handleSort(
    event: DataTableSortEvent,
): void {
    sortField.value =
        event.sortField ||
        'lname';

    sortDirection.value =
        event.sortOrder === -1
            ? 'desc'
            : 'asc';

    first.value = 0;

    void loadStudents(1);
}

function handleSearch(
    value: string,
): void {
    search.value = value;
    first.value = 0;

    void loadStudents(1);
}

function handleAction(
    action: string,
    student: DataTableRow,
): void {
    if (action !== 'edit') {
        return;
    }

    const studentId = String(
        student.id ?? '',
    ).trim();

    if (!studentId) {
        errorMessage.value =
            'The selected student ID is missing.';

        return;
    }

    router.visit(
        `/databases/students/profile/${encodeURIComponent(
            studentId,
        )}`,
    );
}

/*
|--------------------------------------------------------------------------
| Student helpers
|--------------------------------------------------------------------------
*/

function getStudentFullName(
    student: DataTableRow,
): string {
    const lastName = String(
        student.lname ?? '',
    ).trim();

    const otherNames = [
        student.fname,
        student.mname,
    ]
        .filter((name) => {
            return (
                typeof name ===
                    'string' &&
                name.trim() !== ''
            );
        })
        .map((name) => {
            return String(
                name,
            ).trim();
        })
        .join(' ');

    if (
        lastName &&
        otherNames
    ) {
        return `${lastName}, ${otherNames}`.toUpperCase();
    }

    return (
        lastName ||
        otherNames
    ).toUpperCase();
}

function getStudentInitials(
    student: DataTableRow,
): string {
    const firstName = String(
        student.fname ?? '',
    ).trim();

    const lastName = String(
        student.lname ?? '',
    ).trim();

    const initials =
        `${firstName.charAt(
            0,
        )}${lastName.charAt(
            0,
        )}`;

    return (
        initials.toUpperCase() ||
        'ST'
    );
}

function getStudentAvatar(
    gender: unknown,
): string | undefined {
    const value = String(
        gender ?? '',
    )
        .trim()
        .toUpperCase();

    if (
        value === 'M' ||
        value === 'MALE'
    ) {
        return '/images/male-cadet.png';
    }

    if (
        value === 'F' ||
        value === 'FEMALE'
    ) {
        return '/images/female-cadet.png';
    }

    return undefined;
}

function getSchoolId(
    student: DataTableRow,
): string {
    const value = String(
        student.school_id_no ?? '',
    ).trim();

    return (
        value ||
        'No School ID'
    );
}

function getDepartmentSeverity(
    department: unknown,
): DepartmentSeverity {
    const value = String(
        department ?? '',
    )
        .trim()
        .toUpperCase();

    if (value === 'DECK') {
        return 'success';
    }

    if (value === 'ENGINE') {
        return 'info';
    }

    return 'secondary';
}

function getDepartmentIcon(
    department: unknown,
): string {
    const value = String(
        department ?? '',
    )
        .trim()
        .toUpperCase();

    if (value === 'DECK') {
        return 'pi pi-compass';
    }

    if (value === 'ENGINE') {
        return 'pi pi-cog';
    }

    return 'pi pi-building';
}

/*
|--------------------------------------------------------------------------
| Activity helpers
|--------------------------------------------------------------------------
*/

function getActivities(
    student: DataTableRow,
): StudentActivity[] {
    if (
        !Array.isArray(
            student.activities,
        )
    ) {
        return [];
    }

    return student.activities.flatMap(
        (
            candidate,
        ): StudentActivity[] => {
            if (
                candidate === null ||
                typeof candidate !==
                    'object'
            ) {
                return [];
            }

            const activity =
                candidate as Record<
                    string,
                    unknown
                >;

            return [
                {
                    id:
                        String(
                            activity.id ??
                                '',
                        ),

                    activity_id:
                        activity.activity_id ==
                        null
                            ? null
                            : String(
                                  activity.activity_id,
                              ),

                    description:
                        activity.description ==
                        null
                            ? null
                            : String(
                                  activity.description,
                              ),

                    start_date:
                        activity.start_date ==
                        null
                            ? null
                            : String(
                                  activity.start_date,
                              ),

                    end_date:
                        activity.end_date ==
                        null
                            ? null
                            : String(
                                  activity.end_date,
                              ),

                    verified:
                        activity.verified ==
                        null
                            ? null
                            : String(
                                  activity.verified,
                              ),
                },
            ];
        },
    );
}

/*
|--------------------------------------------------------------------------
| Uploaded file helpers
|--------------------------------------------------------------------------
*/

function getUploadedFiles(
    student: DataTableRow,
): StudentUploadedFile[] {
    if (
        !Array.isArray(
            student.uploaded_files,
        )
    ) {
        return [];
    }

    return student.uploaded_files.flatMap(
        (
            candidate,
        ): StudentUploadedFile[] => {
            if (
                candidate === null ||
                typeof candidate !==
                    'object'
            ) {
                return [];
            }

            const file =
                candidate as Record<
                    string,
                    unknown
                >;

            return [
                {
                    id:
                        String(
                            file.id ??
                                '',
                        ),

                    requirement_id:
                        file.requirement_id ==
                        null
                            ? null
                            : String(
                                  file.requirement_id,
                              ),

                    requirement:
                        file.requirement ==
                        null
                            ? null
                            : String(
                                  file.requirement,
                              ),

                    description:
                        file.description ==
                        null
                            ? null
                            : String(
                                  file.description,
                              ),

                    date_uploaded:
                        file.date_uploaded ==
                        null
                            ? null
                            : String(
                                  file.date_uploaded,
                              ),

                    verified:
                        file.verified ==
                        null
                            ? null
                            : String(
                                  file.verified,
                              ),
                },
            ];
        },
    );
}

/*
|--------------------------------------------------------------------------
| Date helper
|--------------------------------------------------------------------------
*/

function formatDate(
    value: unknown,
): string {
    if (
        !value ||
        value ===
            '1970-01-01' ||
        value ===
            '1970-01-01 00:00:00'
    ) {
        return '—';
    }

    const rawValue =
        String(value);

    const normalizedValue =
        rawValue.includes('T')
            ? rawValue
            : rawValue.replace(
                  ' ',
                  'T',
              );

    const date =
        new Date(
            normalizedValue,
        );

    if (
        Number.isNaN(
            date.getTime(),
        )
    ) {
        return rawValue;
    }

    return new Intl.DateTimeFormat(
        'en-PH',
        {
            timeZone:
                'Asia/Manila',

            month:
                'short',

            day:
                'numeric',

            year:
                'numeric',
        },
    ).format(date);
}

/*
|--------------------------------------------------------------------------
| Response helper
|--------------------------------------------------------------------------
*/

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

    const responseData =
        error.response?.data as
            | {
                  message?: string;
              }
            | undefined;

    return (
        responseData?.message ||
        fallback
    );
}

/*
|--------------------------------------------------------------------------
| Lifecycle
|--------------------------------------------------------------------------
*/

onMounted(() => {
    void loadStudents(1);
});

onBeforeUnmount(() => {
    requestController?.abort();
});
</script>

<template>
    <Head title="Students" />

    <div
        class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5"
    >
        <!-- ERROR MESSAGE -->

        <Message
            v-if="errorMessage"
            severity="error"
            closable
            @close="
                errorMessage = ''
            "
        >
            {{ errorMessage }}
        </Message>

        <!-- DATATABLE -->

        <Datatable
            title="Students"
            description="View students registered in the selected school database."
            header-icon="pi pi-users"
            search-placeholder="Search students..."
            empty-title="No students found"
            empty-description="No student records match your search."
            empty-icon="pi pi-users"
            table-min-width="1300px"
            data-key="id"
            lazy
            :loading="loading"
            :data="students"
            :columns="columns"
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
            actions-header="Actions"
            actions-width="90px"
            @page="
                handlePage
            "
            @sort="
                handleSort
            "
            @search="
                handleSearch
            "
            @action="
                handleAction
            "
        >
            <!-- STUDENT INFORMATION -->

            <template
                #cell-fname="{ data }"
            >
                <div
                    class="flex items-center gap-3"
                >
                    <Avatar
                        v-if="
                            getStudentAvatar(
                                data.gender,
                            )
                        "
                        :image="
                            getStudentAvatar(
                                data.gender,
                            )
                        "
                        :aria-label="
                            getStudentFullName(
                                data,
                            )
                        "
                        shape="circle"
                        size="large"
                        class="shrink-0"
                    />

                    <Avatar
                        v-else
                        :label="
                            getStudentInitials(
                                data,
                            )
                        "
                        shape="circle"
                        size="large"
                        class="shrink-0 !bg-[#377EC0]/10 !text-xs !font-bold !text-[#377EC0]"
                    />

                    <div
                        class="min-w-0"
                    >
                        <p
                            class="truncate font-semibold text-slate-700"
                        >
                            {{
                                getStudentFullName(
                                    data,
                                ) ||
                                '—'
                            }}
                        </p>

                        <!--
                            Same format used by
                            Daily Journals:

                            School ID + Department
                        -->

                        <div
                            class="mt-1 flex flex-wrap items-center gap-1.5"
                        >
                            <PrimeTag
                                :value="
                                    getSchoolId(
                                        data,
                                    )
                                "
                                icon="pi pi-id-card"
                                rounded
                                severity="info"
                                class="!px-2 !py-0.5 !text-xs !font-semibold"
                            />

                            <PrimeTag
                                :value="
                                    String(
                                        data.dept ||
                                            '—',
                                    )
                                "
                                :severity="
                                    getDepartmentSeverity(
                                        data.dept,
                                    )
                                "
                                :icon="
                                    getDepartmentIcon(
                                        data.dept,
                                    )
                                "
                                rounded
                                class="!px-2 !py-0.5 !text-xs !font-semibold"
                            />
                            <PrimeTag
                                :value="
                                    String(
                                        data.batch_no ||
                                            'No CCI Year',
                                    )
                                "
                                severity="secondary"
                                icon="pi pi-calendar"
                                rounded
                                class="!px-2 !py-0.5 !text-sm !font-semibold"
                            />
                        </div>
                    </div>
                </div>
            </template>

            <!-- LATEST ACTIVITIES -->

            <template
                #cell-activities="{
                    data,
                }"
            >
                <div
                    v-if="
                        getActivities(
                            data,
                        ).length >
                        0
                    "
                    class="space-y-2"
                >
                    <div
                        v-for="activity in getActivities(
                            data,
                        )"
                        :key="
                            activity.id
                        "
                        class="flex min-w-0 items-start gap-2"
                    >
                    <i
                        class="pi pi-calendar text-blue-500"
                    ></i>

                            {{
                                formatDate(
                                    activity.start_date,
                                )
                            }}
                        <i
                            class="pi pi-list-check mt-0.5 shrink-0 text-emerald-500"
                        ></i>

                        <div
                            class="min-w-0"
                        >
                            <p
                                class="font-medium break-words whitespace-normal text-slate-700"
                            >
                                {{
                                    activity.description ||
                                    '—'
                                }}
                            </p>

                            <p
                                class="mt-1 flex items-center gap-1.5 text-xs text-slate-500"
                            >
                            </p>
                        </div>
                    </div>
                </div>

                <span
                    v-else
                    class="text-slate-400"
                >
                    —
                </span>
            </template>

            <!-- UPLOADED FILES -->

            <template
                #cell-uploaded_files="{
                    data,
                }"
            >
                <div
                    v-if="
                        getUploadedFiles(
                            data,
                        ).length >
                        0
                    "
                    class="space-y-2"
                >
                    <div
                        v-for="file in getUploadedFiles(
                            data,
                        )"
                        :key="
                            file.id
                        "
                        class="flex min-w-0 items-start gap-2"
                    >
                    <i
                        class="pi pi-calendar text-blue-500"
                    ></i>

                        {{
                            formatDate(
                            file.date_uploaded,
                            )
                        }}
                        <i
                            class="pi pi-file mt-0.5 shrink-0 text-[#377EC0]"
                        ></i>

                        <div
                            class="min-w-0"
                        >
                            <p
                                class="font-medium break-words whitespace-normal text-slate-700"
                            >
                                {{
                                    file.requirement ||
                                    file.description ||
                                    'Uploaded File'
                                }}
                            </p>

                            <p
                                class="mt-1 flex items-center gap-1.5 text-xs text-slate-500"
                            >
                            </p>
                        </div>
                    </div>
                </div>

                <span
                    v-else
                    class="text-slate-400"
                >
                    —
                </span>
            </template>
        </Datatable>
    </div>
</template>