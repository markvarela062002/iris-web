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
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import PrimeTag from 'primevue/tag';
import Select from 'primevue/select';
import Textarea from 'primevue/textarea';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
} from 'vue';

import { dashboard } from '@/routes';

const props = defineProps<{
    studentId: string;
}>();

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
            {
                title: 'Student Profile',
                href: '#',
            },
        ],
    },
});

type LookupOption = {
    value: string;
    label: string;
};

type StudentRecord = {
    id: string;
    code_person: string | null;
    school_id_no: string | null;
    fname: string | null;
    mname: string | null;
    lname: string | null;
    gender: string | null;
    civ_status: string | null;
    birth_date: string | null;
    birth_place: string | null;
    batch_no: string | null;
    st_address: string | null;
    city_id: string | number | null;
    province_id: string | number | null;
    mobile: string | null;
    phone: string | null;
    email: string | null;
    facebook: string | null;
    st_address_province: string | null;
    phone_province: string | null;
    mother_name: string | null;
    mother_nos: string | null;
    father_name: string | null;
    father_nos: string | null;
    spouse_name: string | null;
    spouse_nos: string | null;
    date_reg: string | null;
    dept: string | null;
    etrb_type: string | null;
    notes: string | null;
    ins_company: string | null;
    ins_amt: string | number | null;
    ins_hospital: string | number | null;
    ins_disability: string | number | null;
    ins_death: string | number | null;
    stipend: string | number | null;
    login_name: string | null;
    active: string | null;
    photo_file: string | null;
    photo_url: string | null;
};

type StudentResponse = {
    data: StudentRecord;
};

type StudentOptionsResponse = {
    data: {
        cities: LookupOption[];
        provinces: LookupOption[];
    };
};

type PhotoResponse = {
    message: string;
    data: {
        photo_file: string;
        photo_url: string | null;
    };
};

type ResetCredentials = {
    loginName: string;
    password: string;
};

type StudentForm = {
    code_person: string;
    school_id_no: string;
    fname: string;
    mname: string;
    lname: string;
    gender: string | null;
    civ_status: string | null;
    birth_date: Date | null;
    birth_place: string;
    batch_no: string | null;
    st_address: string;
    city_id: string | null;
    province_id: string | null;
    mobile: string;
    phone: string;
    email: string;
    facebook: string;
    st_address_province: string;
    phone_province: string;
    mother_name: string;
    mother_nos: string;
    father_name: string;
    father_nos: string;
    spouse_name: string;
    spouse_nos: string;
    date_reg: Date | null;
    dept: string | null;
    etrb_type: string | null;
    notes: string;
    ins_company: string;
    ins_amt: string;
    ins_hospital: string;
    ins_disability: string;
    ins_death: string;
    stipend: string;
    login_name: string;
    new_password: string;
    new_password_confirmation: string;
    active: 'Y' | 'N';
    photo_file: string;
    photo_url: string | null;
};

const toast = useToast();

const loading = ref(false);
const saving = ref(false);
const photoUploading = ref(false);
const pageError = ref('');

const cityOptions = ref<LookupOption[]>([]);
const provinceOptions = ref<LookupOption[]>([]);

const selectedPhoto = ref<File | null>(null);
const photoInput = ref<HTMLInputElement | null>(null);
const localPhotoPreview = ref<string | null>(null);
const profilePhotoFailed = ref(false);
const defaultAvatarFailed = ref(false);

/*
|--------------------------------------------------------------------------
| Profile photo crop
|--------------------------------------------------------------------------
|
| The selected image is cropped entirely in the browser. Nothing is sent
| to the server until the administrator confirms the crop and presses
| Upload Photo.
|
*/

const cropDialogVisible = ref(false);
const croppingPhoto = ref(false);
const cropImageLoaded = ref(false);
const cropSourceFile = ref<File | null>(null);
const cropSourceUrl = ref<string | null>(null);
const cropImageElement = ref<HTMLImageElement | null>(null);
const cropViewportElement = ref<HTMLDivElement | null>(null);

const cropNaturalWidth = ref(0);
const cropNaturalHeight = ref(0);
const cropViewportSize = ref(0);
const cropBaseScale = ref(1);
const cropZoom = ref(1);
const cropOffsetX = ref(0);
const cropOffsetY = ref(0);

const cropDragging = ref(false);
const cropPointerId = ref<number | null>(null);
const cropDragStartX = ref(0);
const cropDragStartY = ref(0);
const cropDragOriginX = ref(0);
const cropDragOriginY = ref(0);

const resetCredentials =
    ref<ResetCredentials | null>(null);

let requestController: AbortController | null = null;

function emptyForm(): StudentForm {
    return {
        code_person: '',
        school_id_no: '',
        fname: '',
        mname: '',
        lname: '',
        gender: null,
        civ_status: null,
        birth_date: null,
        birth_place: '',
        batch_no: null,
        st_address: '',
        city_id: null,
        province_id: null,
        mobile: '',
        phone: '',
        email: '',
        facebook: '',
        st_address_province: '',
        phone_province: '',
        mother_name: '',
        mother_nos: '',
        father_name: '',
        father_nos: '',
        spouse_name: '',
        spouse_nos: '',
        date_reg: null,
        dept: null,
        etrb_type: null,
        notes: '',
        ins_company: '',
        ins_amt: '0',
        ins_hospital: '0',
        ins_disability: '0',
        ins_death: '0',
        stipend: '0',
        login_name: '',
        new_password: '',
        new_password_confirmation: '',
        active: 'Y',
        photo_file: '',
        photo_url: null,
    };
}

const form = ref<StudentForm>(
    emptyForm(),
);

const genderOptions: LookupOption[] = [
    {
        value: 'MALE',
        label: 'MALE',
    },
    {
        value: 'FEMALE',
        label: 'FEMALE',
    },
];

const civilStatusOptions: LookupOption[] = [
    {
        value: 'SINGLE',
        label: 'SINGLE',
    },
    {
        value: 'MARRIED',
        label: 'MARRIED',
    },
    {
        value: 'SEPARATED',
        label: 'SEPARATED',
    },
    {
        value: 'WIDOW/ER',
        label: 'WIDOW/ER',
    },
];

const departmentOptions: LookupOption[] = [
    {
        value: 'DECK',
        label: 'DECK',
    },
    {
        value: 'ENGINE',
        label: 'ENGINE',
    },
    {
        value: 'NON-MARITIME',
        label: 'NON-MARITIME',
    },
];

const etrbTypeOptions: LookupOption[] = [
    {
        value: 'GMET',
        label: 'GMET',
    },
    {
        value: 'ISF',
        label: 'ISF',
    },
    {
        value: 'GMET and ISF',
        label: 'GMET and ISF',
    },
    {
        value: 'TRMF',
        label: 'TRMF',
    },
    {
        value: 'SCHOOL',
        label: 'SCHOOL',
    },
];

const cciYearOptions = computed<LookupOption[]>(
    () => {
        const startYear = 2015;
        const endYear =
            new Date().getFullYear() + 4;

        const options: LookupOption[] = [];

        for (
            let year = startYear;
            year <= endYear;
            year += 1
        ) {
            const value = String(year);

            options.push({
                value,
                label: value,
            });
        }

        return options;
    },
);

const studentName = computed(() => {
    const lastName = form.value.lname.trim();

    const otherNames = [
        form.value.fname,
        form.value.mname,
    ]
        .map((value) => value.trim())
        .filter(Boolean)
        .join(' ');

    if (lastName && otherNames) {
        return `${lastName}, ${otherNames}`.toUpperCase();
    }

    return (
        lastName ||
        otherNames ||
        'STUDENT PROFILE'
    ).toUpperCase();
});

const studentInitials = computed(() => {
    const first =
        form.value.fname.trim().charAt(0);

    const last =
        form.value.lname.trim().charAt(0);

    return (
        `${first}${last}`.toUpperCase() ||
        'ST'
    );
});

function hasActualStudentPhoto(): boolean {
    const rawFile = String(
        form.value.photo_file ?? '',
    )
        .trim()
        .replace(/\\/g, '/');

    const filename =
        rawFile
            .split('/')
            .pop()
            ?.trim()
            .toLowerCase() ?? '';

    return (
        filename !== '' &&
        filename !== 'profile.jpg'
    );
}

const photoPreviewUrl = computed<string | null>(() => {
    /*
     * A newly cropped local image is always a real image chosen
     * by the administrator, so preview it first.
     */
    if (localPhotoPreview.value) {
        return localPhotoPreview.value;
    }

    /*
     * Do not treat the legacy photos/profile.jpg silhouette as a
     * student photo. When there is no real photo, fall through to
     * the MALE/FEMALE cadet avatar.
     */
    if (
        profilePhotoFailed.value ||
        !hasActualStudentPhoto()
    ) {
        return null;
    }

    return form.value.photo_url || null;
});

const defaultAvatarUrl = computed<string | null>(() => {
    if (defaultAvatarFailed.value) {
        return null;
    }

    const gender = String(
        form.value.gender ?? '',
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


const cropImageStyle = computed(() => {
    const width =
        cropNaturalWidth.value *
        cropBaseScale.value;

    const height =
        cropNaturalHeight.value *
        cropBaseScale.value;

    return {
        width: `${width}px`,
        height: `${height}px`,
        transform:
            `translate(-50%, -50%) ` +
            `translate(${cropOffsetX.value}px, ${cropOffsetY.value}px) ` +
            `scale(${cropZoom.value})`,
    };
});

function departmentSeverity():
    | 'success'
    | 'info'
    | 'secondary' {
    if (form.value.dept === 'DECK') {
        return 'success';
    }

    if (form.value.dept === 'ENGINE') {
        return 'info';
    }

    return 'secondary';
}

function departmentIcon(): string {
    if (form.value.dept === 'DECK') {
        return 'pi pi-compass';
    }

    if (form.value.dept === 'ENGINE') {
        return 'pi pi-cog';
    }

    return 'pi pi-building';
}

function parseDate(
    value: unknown,
): Date | null {
    const rawValue = String(
        value ?? '',
    ).trim();

    if (
        rawValue === '' ||
        rawValue === '1970-01-01' ||
        rawValue ===
            '1970-01-01 00:00:00'
    ) {
        return null;
    }

    const date = new Date(
        `${rawValue.substring(0, 10)}T00:00:00`,
    );

    return Number.isNaN(
        date.getTime(),
    )
        ? null
        : date;
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

function stringValue(
    value: unknown,
): string {
    return String(
        value ?? '',
    ).trim();
}

function loadStudentIntoForm(
    student: StudentRecord,
): void {
    resetCredentials.value = null;
    profilePhotoFailed.value = false;
    defaultAvatarFailed.value = false;

    form.value = {
        code_person:
            stringValue(student.code_person),
        school_id_no:
            stringValue(student.school_id_no),
        fname:
            stringValue(student.fname),
        mname:
            stringValue(student.mname),
        lname:
            stringValue(student.lname),
        gender:
            stringValue(student.gender) ||
            null,
        civ_status:
            stringValue(student.civ_status) ||
            null,
        birth_date:
            parseDate(student.birth_date),
        birth_place:
            stringValue(student.birth_place),
        batch_no:
            stringValue(student.batch_no) ||
            null,
        st_address:
            stringValue(student.st_address),
        city_id:
            student.city_id == null
                ? null
                : String(student.city_id),
        province_id:
            student.province_id == null
                ? null
                : String(student.province_id),
        mobile:
            stringValue(student.mobile),
        phone:
            stringValue(student.phone),
        email:
            stringValue(student.email),
        facebook:
            stringValue(student.facebook),
        st_address_province:
            stringValue(
                student.st_address_province,
            ),
        phone_province:
            stringValue(
                student.phone_province,
            ),
        mother_name:
            stringValue(student.mother_name),
        mother_nos:
            stringValue(student.mother_nos),
        father_name:
            stringValue(student.father_name),
        father_nos:
            stringValue(student.father_nos),
        spouse_name:
            stringValue(student.spouse_name),
        spouse_nos:
            stringValue(student.spouse_nos),
        date_reg:
            parseDate(student.date_reg),
        dept:
            stringValue(student.dept) ||
            null,
        etrb_type:
            stringValue(student.etrb_type) ||
            null,
        notes:
            stringValue(student.notes),
        ins_company:
            stringValue(student.ins_company),
        ins_amt:
            stringValue(student.ins_amt || 0),
        ins_hospital:
            stringValue(
                student.ins_hospital || 0,
            ),
        ins_disability:
            stringValue(
                student.ins_disability || 0,
            ),
        ins_death:
            stringValue(student.ins_death || 0),
        stipend:
            stringValue(student.stipend || 0),
        login_name:
            stringValue(student.login_name),
        new_password: '',
        new_password_confirmation: '',
        active:
            student.active === 'N'
                ? 'N'
                : 'Y',
        photo_file:
            stringValue(student.photo_file),
        photo_url:
            student.photo_url || null,
    };
}

async function loadProfile(): Promise<void> {
    requestController?.abort();

    const controller =
        new AbortController();

    requestController = controller;
    loading.value = true;
    pageError.value = '';

    try {
        const [
            studentResponse,
            optionsResponse,
        ] = await Promise.all([
            axios.get<StudentResponse>(
                `/api/v1/databases/students/${encodeURIComponent(
                    props.studentId,
                )}`,
                {
                    signal: controller.signal,
                    headers: {
                        Accept:
                            'application/json',
                        'X-Requested-With':
                            'XMLHttpRequest',
                    },
                    withCredentials: true,
                },
            ),
            axios.get<StudentOptionsResponse>(
                '/api/v1/databases/students/options',
                {
                    signal: controller.signal,
                    headers: {
                        Accept:
                            'application/json',
                        'X-Requested-With':
                            'XMLHttpRequest',
                    },
                    withCredentials: true,
                },
            ),
        ]);

        loadStudentIntoForm(
            studentResponse.data.data,
        );

        cityOptions.value =
            optionsResponse.data.data.cities;

        provinceOptions.value =
            optionsResponse.data.data.provinces;
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
                'Unable to load the student profile.',
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

async function saveStudent(): Promise<void> {
    const submittedPassword =
        form.value.new_password.trim();

    saving.value = true;
    pageError.value = '';
    resetCredentials.value = null;

    try {
        const response =
            await axios.put<{
                message: string;
            }>(
                `/api/v1/databases/students/${encodeURIComponent(
                    props.studentId,
                )}`,
                {
                    school_id_no:
                        form.value.school_id_no.trim(),
                    fname:
                        form.value.fname.trim(),
                    mname:
                        form.value.mname.trim(),
                    lname:
                        form.value.lname.trim(),
                    gender:
                        form.value.gender,
                    civ_status:
                        form.value.civ_status,
                    birth_date:
                        formatDateParameter(
                            form.value.birth_date,
                        ),
                    birth_place:
                        form.value.birth_place.trim(),
                    batch_no:
                        form.value.batch_no,
                    st_address:
                        form.value.st_address.trim(),
                    city_id:
                        form.value.city_id,
                    province_id:
                        form.value.province_id,
                    mobile:
                        form.value.mobile.trim(),
                    phone:
                        form.value.phone.trim(),
                    email:
                        form.value.email.trim(),
                    facebook:
                        form.value.facebook.trim(),
                    st_address_province:
                        form.value.st_address_province.trim(),
                    phone_province:
                        form.value.phone_province.trim(),
                    mother_name:
                        form.value.mother_name.trim(),
                    mother_nos:
                        form.value.mother_nos.trim(),
                    father_name:
                        form.value.father_name.trim(),
                    father_nos:
                        form.value.father_nos.trim(),
                    spouse_name:
                        form.value.spouse_name.trim(),
                    spouse_nos:
                        form.value.spouse_nos.trim(),
                    date_reg:
                        formatDateParameter(
                            form.value.date_reg,
                        ),
                    dept:
                        form.value.dept,
                    etrb_type:
                        form.value.etrb_type,
                    notes:
                        form.value.notes.trim(),
                    ins_company:
                        form.value.ins_company.trim(),
                    ins_amt:
                        form.value.ins_amt || '0',
                    ins_hospital:
                        form.value.ins_hospital || '0',
                    ins_disability:
                        form.value.ins_disability || '0',
                    ins_death:
                        form.value.ins_death || '0',
                    stipend:
                        form.value.stipend || '0',
                    login_name:
                        form.value.login_name.trim(),
                    new_password:
                        submittedPassword ||
                        null,
                    new_password_confirmation:
                        form.value
                            .new_password_confirmation
                            .trim() ||
                        null,
                    active:
                        form.value.active,
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

        if (submittedPassword !== '') {
            resetCredentials.value = {
                loginName:
                    form.value.login_name.trim(),
                password:
                    submittedPassword,
            };

            form.value.new_password = '';
            form.value.new_password_confirmation = '';
        }

        toast.add({
            severity: 'success',
            summary: 'Student Saved',
            detail:
                response.data.message ||
                'Student profile saved successfully.',
            life: 4000,
        });
    } catch (error: unknown) {
        pageError.value =
            getErrorMessage(
                error,
                'Unable to save the student profile.',
            );
    } finally {
        saving.value = false;
    }
}

function clearResetCredentials(): void {
    resetCredentials.value = null;
}

function generatePassword(): void {
    const characters =
        'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    const values =
        new Uint32Array(8);

    window.crypto.getRandomValues(
        values,
    );

    const password =
        Array.from(
            values,
            (value) => {
                return characters[
                    value %
                        characters.length
                ];
            },
        ).join('');

    form.value.new_password =
        password;

    form.value.new_password_confirmation =
        password;

    resetCredentials.value = null;
}

async function copyCredentials(): Promise<void> {
    if (!resetCredentials.value) {
        return;
    }

    const credentials =
        `Login Name: ${resetCredentials.value.loginName}\n` +
        `Password: ${resetCredentials.value.password}`;

    try {
        await navigator.clipboard.writeText(
            credentials,
        );

        toast.add({
            severity: 'success',
            summary: 'Credentials Copied',
            detail:
                'The new student login credentials were copied to the clipboard.',
            life: 3000,
        });
    } catch {
        pageError.value =
            'Unable to copy the credentials automatically. Please copy them manually.';
    }
}

function handleProfilePhotoError(): void {
    profilePhotoFailed.value = true;
}

function handleDefaultAvatarError(): void {
    defaultAvatarFailed.value = true;
}

function sendCredentialsEmail(): void {
    toast.add({
        severity: 'info',
        summary: 'Send Email',
        detail:
            'Credential email sending will be connected to the existing emailer later.',
        life: 3500,
    });
}

function openPhotoPicker(): void {
    photoInput.value?.click();
}

function cleanupCropSource(): void {
    if (cropSourceUrl.value) {
        URL.revokeObjectURL(
            cropSourceUrl.value,
        );

        cropSourceUrl.value = null;
    }

    cropSourceFile.value = null;
    cropImageLoaded.value = false;
    cropNaturalWidth.value = 0;
    cropNaturalHeight.value = 0;
    cropViewportSize.value = 0;
    cropBaseScale.value = 1;
    cropZoom.value = 1;
    cropOffsetX.value = 0;
    cropOffsetY.value = 0;
    cropDragging.value = false;
    cropPointerId.value = null;
}

function cancelPhotoCrop(): void {
    cropDialogVisible.value = false;
    cleanupCropSource();
}

function clampCropOffsets(): void {
    if (
        cropViewportSize.value <= 0 ||
        cropNaturalWidth.value <= 0 ||
        cropNaturalHeight.value <= 0
    ) {
        return;
    }

    const displayedWidth =
        cropNaturalWidth.value *
        cropBaseScale.value *
        cropZoom.value;

    const displayedHeight =
        cropNaturalHeight.value *
        cropBaseScale.value *
        cropZoom.value;

    const maximumX = Math.max(
        0,
        (
            displayedWidth -
            cropViewportSize.value
        ) / 2,
    );

    const maximumY = Math.max(
        0,
        (
            displayedHeight -
            cropViewportSize.value
        ) / 2,
    );

    cropOffsetX.value = Math.min(
        maximumX,
        Math.max(
            -maximumX,
            cropOffsetX.value,
        ),
    );

    cropOffsetY.value = Math.min(
        maximumY,
        Math.max(
            -maximumY,
            cropOffsetY.value,
        ),
    );
}

function resetCropPosition(): void {
    cropZoom.value = 1;
    cropOffsetX.value = 0;
    cropOffsetY.value = 0;

    clampCropOffsets();
}

function handleCropZoomInput(
    event: Event,
): void {
    const input =
        event.target as HTMLInputElement;

    const zoom = Number(
        input.value,
    );

    cropZoom.value =
        Number.isFinite(zoom)
            ? Math.min(
                  3,
                  Math.max(
                      1,
                      zoom,
                  ),
              )
            : 1;

    clampCropOffsets();
}

function handleCropImageLoaded(
    event: Event,
): void {
    const image =
        event.target as HTMLImageElement;

    cropImageElement.value = image;

    const viewport =
        cropViewportElement.value;

    if (!viewport) {
        return;
    }

    const viewportSize =
        Math.min(
            viewport.clientWidth,
            viewport.clientHeight,
        );

    cropViewportSize.value =
        viewportSize;

    cropNaturalWidth.value =
        image.naturalWidth;

    cropNaturalHeight.value =
        image.naturalHeight;

    if (
        image.naturalWidth <= 0 ||
        image.naturalHeight <= 0 ||
        viewportSize <= 0
    ) {
        pageError.value =
            'Unable to read the selected image.';

        return;
    }

    /*
     * Minimum scale that completely covers the square crop viewport.
     */
    cropBaseScale.value =
        Math.max(
            viewportSize /
                image.naturalWidth,
            viewportSize /
                image.naturalHeight,
        );

    cropImageLoaded.value = true;

    resetCropPosition();
}

function beginCropDrag(
    event: PointerEvent,
): void {
    if (!cropImageLoaded.value) {
        return;
    }

    cropDragging.value = true;
    cropPointerId.value =
        event.pointerId;

    cropDragStartX.value =
        event.clientX;

    cropDragStartY.value =
        event.clientY;

    cropDragOriginX.value =
        cropOffsetX.value;

    cropDragOriginY.value =
        cropOffsetY.value;

    const target =
        event.currentTarget as HTMLElement;

    target.setPointerCapture(
        event.pointerId,
    );
}

function moveCropDrag(
    event: PointerEvent,
): void {
    if (
        !cropDragging.value ||
        cropPointerId.value !==
            event.pointerId
    ) {
        return;
    }

    cropOffsetX.value =
        cropDragOriginX.value +
        (
            event.clientX -
            cropDragStartX.value
        );

    cropOffsetY.value =
        cropDragOriginY.value +
        (
            event.clientY -
            cropDragStartY.value
        );

    clampCropOffsets();
}

function endCropDrag(
    event: PointerEvent,
): void {
    if (
        cropPointerId.value !==
        event.pointerId
    ) {
        return;
    }

    cropDragging.value = false;
    cropPointerId.value = null;

    const target =
        event.currentTarget as HTMLElement;

    if (
        target.hasPointerCapture(
            event.pointerId,
        )
    ) {
        target.releasePointerCapture(
            event.pointerId,
        );
    }
}

function handlePhotoSelected(
    event: Event,
): void {
    const input =
        event.target as HTMLInputElement;

    const file =
        input.files?.[0] ?? null;

    /*
     * Clear the native input so selecting the same file again
     * still triggers the change event.
     */
    input.value = '';

    if (!file) {
        return;
    }

    if (
        ![
            'image/jpeg',
            'image/png',
            'image/webp',
        ].includes(file.type)
    ) {
        pageError.value =
            'Select a JPG, PNG, or WEBP image.';

        return;
    }

    cleanupCropSource();

    cropSourceFile.value = file;
    cropSourceUrl.value =
        URL.createObjectURL(file);

    cropDialogVisible.value = true;
    pageError.value = '';
}

async function confirmPhotoCrop(): Promise<void> {
    const image =
        cropImageElement.value;

    const sourceFile =
        cropSourceFile.value;

    if (
        !image ||
        !sourceFile ||
        !cropImageLoaded.value ||
        cropViewportSize.value <= 0
    ) {
        pageError.value =
            'The image is not ready to crop.';

        return;
    }

    croppingPhoto.value = true;
    pageError.value = '';

    try {
        const effectiveScale =
            cropBaseScale.value *
            cropZoom.value;

        const displayedWidth =
            cropNaturalWidth.value *
            effectiveScale;

        const displayedHeight =
            cropNaturalHeight.value *
            effectiveScale;

        /*
         * Position of the scaled image inside the square viewport.
         */
        const imageLeft =
            (
                cropViewportSize.value -
                displayedWidth
            ) /
                2 +
            cropOffsetX.value;

        const imageTop =
            (
                cropViewportSize.value -
                displayedHeight
            ) /
                2 +
            cropOffsetY.value;

        /*
         * Convert the visible viewport back into source-image pixels.
         */
        const sourceX =
            Math.max(
                0,
                -imageLeft /
                    effectiveScale,
            );

        const sourceY =
            Math.max(
                0,
                -imageTop /
                    effectiveScale,
            );

        const sourceSize =
            Math.min(
                cropViewportSize.value /
                    effectiveScale,
                cropNaturalWidth.value -
                    sourceX,
                cropNaturalHeight.value -
                    sourceY,
            );

        const outputSize = 1000;

        const canvas =
            document.createElement(
                'canvas',
            );

        canvas.width = outputSize;
        canvas.height = outputSize;

        const context =
            canvas.getContext('2d');

        if (!context) {
            throw new Error(
                'Canvas is unavailable.',
            );
        }

        context.imageSmoothingEnabled =
            true;

        context.imageSmoothingQuality =
            'high';

        context.drawImage(
            image,
            sourceX,
            sourceY,
            sourceSize,
            sourceSize,
            0,
            0,
            outputSize,
            outputSize,
        );

        const outputType =
            sourceFile.type ===
            'image/png'
                ? 'image/png'
                : sourceFile.type ===
                    'image/webp'
                  ? 'image/webp'
                  : 'image/jpeg';

        const blob =
            await new Promise<Blob>(
                (
                    resolve,
                    reject,
                ) => {
                    canvas.toBlob(
                        (result) => {
                            if (!result) {
                                reject(
                                    new Error(
                                        'Unable to create the cropped image.',
                                    ),
                                );

                                return;
                            }

                            resolve(
                                result,
                            );
                        },
                        outputType,
                        0.92,
                    );
                },
            );

        const originalName =
            sourceFile.name;

        const lastDot =
            originalName.lastIndexOf(
                '.',
            );

        const baseName =
            (
                lastDot > 0
                    ? originalName.slice(
                          0,
                          lastDot,
                      )
                    : originalName
            )
                .trim()
                .replace(
                    /[^A-Za-z0-9_-]+/g,
                    '_',
                ) ||
            'student_photo';

        const extension =
            outputType === 'image/png'
                ? 'png'
                : outputType ===
                    'image/webp'
                  ? 'webp'
                  : 'jpg';

        const croppedFile =
            new File(
                [
                    blob,
                ],
                `${baseName}_cropped.${extension}`,
                {
                    type: outputType,
                    lastModified:
                        Date.now(),
                },
            );

        selectedPhoto.value =
            croppedFile;

        profilePhotoFailed.value =
            false;

        if (
            localPhotoPreview.value
        ) {
            URL.revokeObjectURL(
                localPhotoPreview.value,
            );
        }

        localPhotoPreview.value =
            URL.createObjectURL(
                croppedFile,
            );

        cropDialogVisible.value =
            false;

        cleanupCropSource();

        toast.add({
            severity: 'success',
            summary: 'Photo Cropped',
            detail:
                'Review the cropped photo, then press Upload Photo to save it.',
            life: 3000,
        });
    } catch {
        pageError.value =
            'Unable to crop the selected image.';
    } finally {
        croppingPhoto.value = false;
    }
}

async function uploadPhoto(): Promise<void> {
    if (!selectedPhoto.value) {
        pageError.value =
            'Select a profile photo first.';

        return;
    }

    photoUploading.value = true;
    pageError.value = '';

    const formData = new FormData();

    formData.append(
        'photo',
        selectedPhoto.value,
    );

    try {
        const response =
            await axios.post<PhotoResponse>(
                `/api/v1/databases/students/${encodeURIComponent(
                    props.studentId,
                )}/photo`,
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

        form.value.photo_file =
            response.data.data.photo_file;

        form.value.photo_url =
            response.data.data.photo_url;

        profilePhotoFailed.value = false;

        selectedPhoto.value = null;

        if (localPhotoPreview.value) {
            URL.revokeObjectURL(
                localPhotoPreview.value,
            );

            localPhotoPreview.value = null;
        }

        if (photoInput.value) {
            photoInput.value.value = '';
        }

        toast.add({
            severity: 'success',
            summary: 'Photo Updated',
            detail:
                response.data.message ||
                'Student profile photo updated successfully.',
            life: 4000,
        });
    } catch (error: unknown) {
        pageError.value =
            getErrorMessage(
                error,
                'Unable to upload the student profile photo.',
            );
    } finally {
        photoUploading.value = false;
    }
}

function handleActiveChange(
    event: Event,
): void {
    const target =
        event.target as HTMLInputElement;

    form.value.active =
        target.checked
            ? 'Y'
            : 'N';
}

function goBack(): void {
    router.visit(
        '/databases/students',
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
    void loadProfile();
});

onBeforeUnmount(() => {
    requestController?.abort();

    if (localPhotoPreview.value) {
        URL.revokeObjectURL(
            localPhotoPreview.value,
        );
    }

    cleanupCropSource();
});
</script>

<template>
    <Head title="Student Profile" />

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
                        class="flex flex-col gap-4 sm:flex-row sm:items-center"
                    >
                        <div
                            class="relative h-32 w-32 shrink-0 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
                        >
                            <!--
                                Student Profile Edit avatar order:
                                1. Existing/uploaded student photo
                                2. Gender default avatar
                                3. Student initials
                            -->

                            <img
                                v-if="photoPreviewUrl"
                                :src="photoPreviewUrl"
                                :alt="studentName"
                                class="h-full w-full object-cover"
                                @error="
                                    handleProfilePhotoError
                                "
                            />

                            <img
                                v-else-if="
                                    defaultAvatarUrl
                                "
                                :src="defaultAvatarUrl"
                                :alt="studentName"
                                class="h-full w-full object-cover"
                                @error="
                                    handleDefaultAvatarError
                                "
                            />

                            <Avatar
                                v-else
                                :label="studentInitials"
                                shape="circle"
                                class="!h-full !w-full !bg-blue-50 !text-3xl !font-bold !text-blue-600"
                            />
                        </div>

                        <div class="min-w-0">
                            <p
                                class="text-xs font-semibold tracking-wide text-slate-400 uppercase"
                            >
                                Student Profile
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
                                    :value="
                                        form.school_id_no ||
                                        'No School ID'
                                    "
                                    icon="pi pi-id-card"
                                    severity="info"
                                    rounded
                                />

                                <PrimeTag
                                    :value="
                                        form.dept ||
                                        'No Department'
                                    "
                                    :severity="
                                        departmentSeverity()
                                    "
                                    :icon="departmentIcon()"
                                    rounded
                                />

                                <PrimeTag
                                    :value="
                                        form.batch_no ||
                                        'No CCI Year'
                                    "
                                    icon="pi pi-calendar"
                                    severity="secondary"
                                    rounded
                                />
                            </div>

                            <div
                                class="mt-3 flex flex-wrap gap-2"
                            >
                                <input
                                    ref="photoInput"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    class="hidden"
                                    @change="handlePhotoSelected"
                                />

                                <Button
                                    type="button"
                                    label="Choose Photo"
                                    icon="pi pi-image"
                                    severity="secondary"
                                    variant="outlined"
                                    size="small"
                                    :disabled="
                                        loading ||
                                        photoUploading
                                    "
                                    @click="openPhotoPicker"
                                />

                                <Button
                                    v-if="selectedPhoto"
                                    type="button"
                                    label="Upload Photo"
                                    icon="pi pi-upload"
                                    severity="info"
                                    size="small"
                                    :loading="photoUploading"
                                    :disabled="photoUploading"
                                    @click="uploadPhoto"
                                />
                            </div>
                        </div>
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-2"
                    >
                        <Button
                            type="button"
                            label="Back to Students"
                            icon="pi pi-arrow-left"
                            severity="secondary"
                            variant="outlined"
                            :disabled="saving"
                            @click="goBack"
                        />

                        <Button
                            type="button"
                            label="Save Changes"
                            icon="pi pi-save"
                            severity="success"
                            :loading="saving"
                            :disabled="
                                loading || saving
                            "
                            @click="saveStudent"
                        />
                    </div>
                </div>
            </template>
        </Card>

        <div
            v-if="loading"
            class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm font-medium text-slate-500 shadow-sm"
        >
            <i
                class="pi pi-spin pi-spinner mr-2"
            ></i>
            Loading student profile...
        </div>

        <template v-else>
            <Card
                class="!rounded-2xl !border !border-slate-200 !shadow-sm [&_.p-card-body]:!p-5 [&_.p-card-content]:!p-0"
            >
                <template #title>
                    <div
                        class="flex items-center gap-2 text-slate-800"
                    >
                        <i
                            class="pi pi-user text-[#377EC0]"
                        ></i>
                        Personal Information
                    </div>
                </template>

                <template #content>
                    <div
                        class="grid gap-4 md:grid-cols-2 xl:grid-cols-2"
                    >
                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-school-id"
                                class="text-sm font-semibold text-slate-700"
                            >
                                School ID No.
                                <span
                                    class="text-red-500"
                                >
                                    *
                                </span>
                            </label>

                            <InputText
                                id="student-school-id"
                                v-model="form.school_id_no"
                                class="w-full"
                            />
                        </div>
                                                <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-cci-year"
                                class="text-sm font-semibold text-slate-700"
                            >
                                CCI Year
                                <span
                                    class="text-red-500"
                                >
                                    *
                                </span>
                            </label>

                            <Select
                                id="student-cci-year"
                                v-model="form.batch_no"
                                :options="cciYearOptions"
                                option-label="label"
                                option-value="value"
                                placeholder="Select CCI year"
                                class="w-full"
                            />
                        </div>
                    </div>
                        <div
                            class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3"
                        >

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-first-name"
                                class="text-sm font-semibold text-slate-700"
                            >
                                First Name
                                <span
                                    class="text-red-500"
                                >
                                    *
                                </span>
                            </label>

                            <InputText
                                id="student-first-name"
                                v-model="form.fname"
                                class="w-full"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-middle-name"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Middle Name
                            </label>

                            <InputText
                                id="student-middle-name"
                                v-model="form.mname"
                                class="w-full"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-last-name"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Last Name
                                <span
                                    class="text-red-500"
                                >
                                    *
                                </span>
                            </label>

                            <InputText
                                id="student-last-name"
                                v-model="form.lname"
                                class="w-full"
                            />
                        </div>
                    </div>  
                        <div
                        class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4"
                    >
                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-civil-status"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Civil Status
                            </label>

                            <Select
                                id="student-civil-status"
                                v-model="form.civ_status"
                                :options="civilStatusOptions"
                                option-label="label"
                                option-value="value"
                                placeholder="Select civil status"
                                show-clear
                                class="w-full"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-birth-date"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Date of Birth
                            </label>

                            <DatePicker
                                id="student-birth-date"
                                v-model="form.birth_date"
                                date-format="M d, yy"
                                show-icon
                                icon-display="input"
                                :manual-input="false"
                                class="w-full"
                                input-class="w-full"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-birth-place"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Place of Birth
                            </label>

                            <InputText
                                id="student-birth-place"
                                v-model="form.birth_place"
                                class="w-full"
                            />
                        </div>
                                                <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-gender"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Gender
                            </label>

                            <Select
                                id="student-gender"
                                v-model="form.gender"
                                :options="genderOptions"
                                option-label="label"
                                option-value="value"
                                placeholder="Select gender"
                                show-clear
                                class="w-full"
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
                        <i
                            class="pi pi-address-book text-[#377EC0]"
                        ></i>
                        Contact Information
                    </div>
                </template>

                <template #content>
                    <div
                        class="grid gap-4 md:grid-cols-2 xl:grid-cols-4"
                    >
                        <div
                            class="flex flex-col gap-2 md:col-span-2"
                        >
                            <label
                                for="student-address"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Street Address
                            </label>

                            <InputText
                                id="student-address"
                                v-model="form.st_address"
                                class="w-full"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-city"
                                class="text-sm font-semibold text-slate-700"
                            >
                                City
                            </label>

                            <Select
                                id="student-city"
                                v-model="form.city_id"
                                :options="cityOptions"
                                option-label="label"
                                option-value="value"
                                filter
                                show-clear
                                placeholder="Select city"
                                class="w-full"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-province"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Province
                            </label>

                            <Select
                                id="student-province"
                                v-model="form.province_id"
                                :options="provinceOptions"
                                option-label="label"
                                option-value="value"
                                filter
                                show-clear
                                placeholder="Select province"
                                class="w-full"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-mobile"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Mobile
                            </label>

                            <InputText
                                id="student-mobile"
                                v-model="form.mobile"
                                class="w-full"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-phone"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Phone
                            </label>

                            <InputText
                                id="student-phone"
                                v-model="form.phone"
                                class="w-full"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-email"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Email
                            </label>

                            <InputText
                                id="student-email"
                                v-model="form.email"
                                type="email"
                                class="w-full"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-facebook"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Facebook Name
                            </label>

                            <InputText
                                id="student-facebook"
                                v-model="form.facebook"
                                class="w-full"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2 md:col-span-2 xl:col-span-3"
                        >
                            <label
                                for="student-province-address"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Province Address
                            </label>

                            <InputText
                                id="student-province-address"
                                v-model="form.st_address_province"
                                class="w-full"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-province-contact"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Province Contact No.
                            </label>

                            <InputText
                                id="student-province-contact"
                                v-model="form.phone_province"
                                class="w-full"
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
                        <i
                            class="pi pi-users text-[#377EC0]"
                        ></i>
                        Family Information
                    </div>
                </template>

                <template #content>
                    <div
                        class="grid gap-4 md:grid-cols-2 xl:grid-cols-2"
                    >
                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-mother-name"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Mother's Name
                            </label>

                            <InputText
                                id="student-mother-name"
                                v-model="form.mother_name"
                                class="w-full"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-mother-contact"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Mother's Contact No.
                            </label>

                            <InputText
                                id="student-mother-contact"
                                v-model="form.mother_nos"
                                class="w-full"
                            />
                        </div>

                        <div
                            class="hidden xl:block"
                        ></div>
                    </div>
                    <div
                        class="grid gap-4 md:grid-cols-2 xl:grid-cols-2"
                    >

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-father-name"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Father's Name
                            </label>

                            <InputText
                                id="student-father-name"
                                v-model="form.father_name"
                                class="w-full"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-father-contact"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Father's Contact No.
                            </label>

                            <InputText
                                id="student-father-contact"
                                v-model="form.father_nos"
                                class="w-full"
                            />
                        </div>
                    </div>
                        <div
                        class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-2"
                        >

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-spouse-name"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Spouse's Name
                            </label>

                            <InputText
                                id="student-spouse-name"
                                v-model="form.spouse_name"
                                class="w-full"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-spouse-contact"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Spouse's Contact No.
                            </label>

                            <InputText
                                id="student-spouse-contact"
                                v-model="form.spouse_nos"
                                class="w-full"
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
                        <i
                            class="pi pi-book text-[#377EC0]"
                        ></i>
                        School Records
                    </div>
                </template>

                <template #content>
                    <div
                        class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
                    >
                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-date-registered"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Date Registered
                            </label>

                            <DatePicker
                                id="student-date-registered"
                                v-model="form.date_reg"
                                date-format="M d, yy"
                                show-icon
                                icon-display="input"
                                :manual-input="false"
                                class="w-full"
                                input-class="w-full"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-department"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Department
                                <span
                                    class="text-red-500"
                                >
                                    *
                                </span>
                            </label>

                            <Select
                                id="student-department"
                                v-model="form.dept"
                                :options="departmentOptions"
                                option-label="label"
                                option-value="value"
                                placeholder="Select department"
                                class="w-full"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-etrb-type"
                                class="text-sm font-semibold text-slate-700"
                            >
                                e-TRB Type
                                <span
                                    class="text-red-500"
                                >
                                    *
                                </span>
                            </label>

                            <Select
                                id="student-etrb-type"
                                v-model="form.etrb_type"
                                :options="etrbTypeOptions"
                                option-label="label"
                                option-value="value"
                                placeholder="Select e-TRB type"
                                class="w-full"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2 md:col-span-2 xl:col-span-3"
                        >
                            <label
                                for="student-notes"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Notes
                            </label>

                            <Textarea
                                id="student-notes"
                                v-model="form.notes"
                                rows="4"
                                auto-resize
                                fluid
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
                        <i
                            class="pi pi-shield text-[#377EC0]"
                        ></i>
                        Insurance
                    </div>
                </template>

                <template #content>
                    <div
                        class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
                    >
                        <div
                            class="flex flex-col gap-2 md:col-span-2 xl:col-span-3"
                        >
                            <label
                                for="student-insurance-company"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Insurance Company
                            </label>

                            <InputText
                                id="student-insurance-company"
                                v-model="form.ins_company"
                                class="w-full"
                            />
                        </div>
                    </div>
                        <div
                        class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-5"
                        >

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-insurance-amount"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Insurance Amount
                            </label>

                            <InputText
                                id="student-insurance-amount"
                                v-model="form.ins_amt"
                                type="number"
                                min="0"
                                class="w-full"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-hospitalization"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Hospitalization Coverage
                            </label>

                            <InputText
                                id="student-hospitalization"
                                v-model="form.ins_hospital"
                                type="number"
                                min="0"
                                class="w-full"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-disability"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Disability Benefits
                            </label>

                            <InputText
                                id="student-disability"
                                v-model="form.ins_disability"
                                type="number"
                                min="0"
                                class="w-full"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-death-benefit"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Death Benefits
                            </label>

                            <InputText
                                id="student-death-benefit"
                                v-model="form.ins_death"
                                type="number"
                                min="0"
                                class="w-full"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-stipend"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Stipend
                            </label>

                            <InputText
                                id="student-stipend"
                                v-model="form.stipend"
                                type="number"
                                min="0"
                                class="w-full"
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
                        <i
                            class="pi pi-lock text-[#377EC0]"
                        ></i>

                        Access
                    </div>
                </template>

                <template #content>
                    <div
                        class="grid gap-4 md:grid-cols-2"
                    >
                        <!-- LOGIN NAME -->

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-login-name"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Login Name
                            </label>

                            <InputText
                                id="student-login-name"
                                v-model="form.login_name"
                                class="w-full"
                                autocomplete="off"
                                placeholder="Enter login name"
                                :disabled="saving"
                                @input="
                                    clearResetCredentials
                                "
                            />

                            <small
                                class="text-slate-500"
                            >
                                Used by the student to sign in.
                            </small>
                        </div>

                        <!-- ACCOUNT STATUS -->

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                class="text-sm font-semibold text-slate-700"
                            >
                                Account Status
                            </label>

                            <div
                                class="flex min-h-[42px] items-center rounded-xl border border-slate-200 bg-slate-50 px-4"
                            >
                                <label
                                    class="flex cursor-pointer items-center gap-3 text-sm font-semibold text-slate-700"
                                >
                                    <input
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-slate-300"
                                        :checked="
                                            form.active === 'Y'
                                        "
                                        :disabled="saving"
                                        @change="
                                            handleActiveChange
                                        "
                                    />

                                    Active Student Account
                                </label>
                            </div>
                        </div>

                        <!-- NEW PASSWORD -->

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-new-password"
                                class="text-sm font-semibold text-slate-700"
                            >
                                New Password
                            </label>

                            <div
                                class="flex gap-2"
                            >
                                <InputText
                                    id="student-new-password"
                                    v-model="
                                        form.new_password
                                    "
                                    type="text"
                                    class="min-w-0 flex-1"
                                    autocomplete="new-password"
                                    placeholder="Enter or generate password"
                                    :disabled="saving"
                                    @input="
                                        clearResetCredentials
                                    "
                                />

                                <Button
                                    type="button"
                                    label="Generate"
                                    icon="pi pi-key"
                                    severity="secondary"
                                    variant="outlined"
                                    :disabled="saving"
                                    @click="
                                        generatePassword
                                    "
                                />
                            </div>

                            <small
                                class="text-slate-500"
                            >
                                Leave blank to keep the current password.
                            </small>
                        </div>

                        <!-- CONFIRM PASSWORD -->

                        <div
                            class="flex flex-col gap-2"
                        >
                            <label
                                for="student-new-password-confirmation"
                                class="text-sm font-semibold text-slate-700"
                            >
                                Confirm Password
                            </label>

                            <InputText
                                id="student-new-password-confirmation"
                                v-model="
                                    form.new_password_confirmation
                                "
                                type="text"
                                class="w-full"
                                autocomplete="new-password"
                                placeholder="Confirm new password"
                                :disabled="saving"
                                @input="
                                    clearResetCredentials
                                "
                            />
                        </div>

                        <Message
                            severity="info"
                            :closable="false"
                            class="md:col-span-2"
                        >
                            The existing password is never displayed.
                            Enter or generate a new password only when
                            the student's login credentials need to be
                            reset.
                        </Message>

                        <div
                            class="md:col-span-2 flex justify-end"
                        >
                            <Button
                                type="button"
                                label="Send Email"
                                icon="pi pi-envelope"
                                severity="secondary"
                                variant="outlined"
                                :disabled="saving"
                                @click="
                                    sendCredentialsEmail
                                "
                            />
                        </div>

                        <!-- NEWLY RESET CREDENTIALS -->

                        <div
                            v-if="resetCredentials"
                            class="md:col-span-2 rounded-xl border border-emerald-200 bg-emerald-50 p-4"
                        >
                            <div
                                class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"
                            >
                                <div>
                                    <p
                                        class="font-semibold text-emerald-800"
                                    >
                                        Student credentials updated
                                    </p>

                                    <div
                                        class="mt-2 space-y-1 text-sm text-slate-700"
                                    >
                                        <p>
                                            Login Name:
                                            <strong>
                                                {{
                                                    resetCredentials.loginName
                                                }}
                                            </strong>
                                        </p>

                                        <p>
                                            Password:
                                            <strong>
                                                {{
                                                    resetCredentials.password
                                                }}
                                            </strong>
                                        </p>
                                    </div>

                                    <p
                                        class="mt-2 text-xs text-slate-500"
                                    >
                                        Copy these credentials now and
                                        send them to the rightful student.
                                        They disappear when this page is
                                        refreshed or closed.
                                    </p>
                                </div>

                                <div
                                    class="flex flex-wrap gap-2"
                                >
                                    <Button
                                        type="button"
                                        label="Copy Credentials"
                                        icon="pi pi-copy"
                                        severity="info"
                                        :disabled="saving"
                                        @click="
                                            copyCredentials
                                        "
                                    />

                                    <Button
                                        type="button"
                                        label="Send Email"
                                        icon="pi pi-envelope"
                                        severity="secondary"
                                        variant="outlined"
                                        :disabled="saving"
                                        @click="
                                            sendCredentialsEmail
                                        "
                                    />
                                </div>
                            </div>
                        </div>
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
                    :disabled="saving"
                    @click="goBack"
                />

                <Button
                    type="button"
                    label="Save Changes"
                    icon="pi pi-save"
                    severity="success"
                    :loading="saving"
                    :disabled="saving"
                    @click="saveStudent"
                />
            </div>
        </template>
    </div>
    <!--
    |--------------------------------------------------------------------------
    | Crop Profile Photo
    |--------------------------------------------------------------------------
    |
    | The image is cropped locally. Confirming this dialog only prepares the
    | cropped file; Upload Photo still performs the actual server upload.
    |
    -->

    <Dialog
        v-model:visible="cropDialogVisible"
        modal
        header="Crop Profile Photo"
        :closable="!croppingPhoto"
        :dismissable-mask="false"
        :draggable="false"
        class="w-[min(94vw,640px)]"
        @hide="
            !croppingPhoto &&
            cleanupCropSource()
        "
    >
        <div class="space-y-4">
            <Message
                severity="info"
                :closable="false"
            >
                Drag the image to choose the visible area.
                Use the zoom control when needed.
            </Message>

            <div
                ref="cropViewportElement"
                class="relative mx-auto aspect-square w-full max-w-[460px] touch-none select-none overflow-hidden rounded-2xl bg-slate-950 shadow-inner"
                :class="{
                    'cursor-grabbing':
                        cropDragging,
                    'cursor-grab':
                        !cropDragging,
                }"
                @pointerdown="
                    beginCropDrag
                "
                @pointermove="
                    moveCropDrag
                "
                @pointerup="
                    endCropDrag
                "
                @pointercancel="
                    endCropDrag
                "
            >
                <img
                    v-if="cropSourceUrl"
                    ref="cropImageElement"
                    :src="cropSourceUrl"
                    alt="Crop selected profile photo"
                    draggable="false"
                    class="pointer-events-none absolute top-1/2 left-1/2 max-w-none origin-center select-none"
                    :style="
                        cropImageStyle
                    "
                    @load="
                        handleCropImageLoaded
                    "
                />

                <div
                    class="pointer-events-none absolute inset-0 border-[3px] border-white/90"
                ></div>

                <div
                    class="pointer-events-none absolute inset-0 grid grid-cols-3 grid-rows-3"
                >
                    <div
                        v-for="index in 9"
                        :key="index"
                        class="border border-white/20"
                    ></div>
                </div>

                <div
                    v-if="
                        !cropImageLoaded
                    "
                    class="absolute inset-0 flex items-center justify-center bg-slate-950/70 text-sm font-semibold text-white"
                >
                    Loading image…
                </div>
            </div>

            <div
                class="rounded-xl border border-slate-200 bg-slate-50 p-4"
            >
                <div
                    class="flex items-center justify-between gap-3"
                >
                    <label
                        for="profile-photo-zoom"
                        class="text-sm font-semibold text-slate-700"
                    >
                        Zoom
                    </label>

                    <span
                        class="text-xs font-semibold text-slate-500"
                    >
                        {{
                            cropZoom.toFixed(
                                2,
                            )
                        }}×
                    </span>
                </div>

                <input
                    id="profile-photo-zoom"
                    type="range"
                    min="1"
                    max="3"
                    step="0.01"
                    :value="cropZoom"
                    class="mt-3 w-full accent-[#377EC0]"
                    :disabled="
                        !cropImageLoaded ||
                        croppingPhoto
                    "
                    @input="
                        handleCropZoomInput
                    "
                />

                <div
                    class="mt-3 flex justify-end"
                >
                    <Button
                        type="button"
                        label="Reset Position"
                        icon="pi pi-refresh"
                        severity="secondary"
                        variant="outlined"
                        size="small"
                        :disabled="
                            !cropImageLoaded ||
                            croppingPhoto
                        "
                        @click="
                            resetCropPosition
                        "
                    />
                </div>
            </div>
        </div>

        <template #footer>
            <div
                class="flex w-full justify-end gap-2"
            >
                <Button
                    type="button"
                    label="Cancel"
                    icon="pi pi-times"
                    severity="secondary"
                    variant="outlined"
                    :disabled="
                        croppingPhoto
                    "
                    @click="
                        cancelPhotoCrop
                    "
                />

                <Button
                    type="button"
                    label="Use Photo"
                    icon="pi pi-check"
                    severity="info"
                    :loading="
                        croppingPhoto
                    "
                    :disabled="
                        !cropImageLoaded ||
                        croppingPhoto
                    "
                    @click="
                        confirmPhotoCrop
                    "
                />
            </div>
        </template>
    </Dialog>

</template>
