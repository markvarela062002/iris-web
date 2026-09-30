<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import axios from 'axios';

import Button from 'primevue/button';
import Message from 'primevue/message';
import ProgressBar from 'primevue/progressbar';
import RadioButton from 'primevue/radiobutton';
import Tag from 'primevue/tag';

import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
} from 'vue';

const props = defineProps<{
    assessmentId: string;
}>();

defineOptions({
    inheritAttrs: false,

    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: '/student-dashboard',
            },
            {
                title: 'Theoretical Assessments',
                href:
                    '/assessments/theoretical-internal/student/datatable',
            },
            {
                title: 'Exam',
                href: '#',
            },
        ],
    },
});

type ExamState =
    | 'start'
    | 'resume'
    | 'time_expired'
    | 'not_available'
    | 'expired'
    | 'completed';

type ExamMetadata = {
    id: string;
    name_course: string;
    session_code: string;
    exam_type: string;
    proctor_name: string;
    duration: number;
    started_at: string | null;
    expires_at: string | null;
    access_start: string | null;
    access_end: string | null;
    server_time: string;
    done: boolean;
    remarks: 'PASSED' | 'FAILED' | null;
    final_result: FinalResult | null;
    exam_action_state: ExamState;
    exam_action_label: string;
    can_open_exam: boolean;
};

type ExamChoice = {
    key: string;
    text: string;
    image_url: string | null;
};

type ExamQuestion = {
    id: string;
    index: number;
    question: string;
    answer: string;
    choices: ExamChoice[];
};

type ExamTopic = {
    id: string;
    bs_topic_id: string;
    description: string;
    order_no: number;
    total_competences: number;
    quest_cnt: number;
    started_at: string | null;
};

type TopicResult = {
    topic: string;
    order_no: number;
    completed: boolean;
};

type NextCompetence = {
    id: string;
    bs_topic_id: string;
    order_no: number;
    description: string;
};

type FinalResult = {
    remarks: 'PASSED' | 'FAILED';
    score: number;
    total_items: number;
    percentage: number;
    exam_package: string;
};

const loading = ref(true);
const starting = ref(false);
const submitting = ref(false);
const timeoutSubmitting = ref(false);
const pageError = ref('');
const saveError = ref('');

const exam = ref<ExamMetadata | null>(null);
const topic = ref<ExamTopic | null>(null);
const questions = ref<ExamQuestion[]>([]);
const savingQuestionIds = ref<string[]>([]);
const topicResult = ref<TopicResult | null>(null);
const nextCompetence = ref<NextCompetence | null>(null);
const examCompleted = ref(false);
const finalResult = ref<FinalResult | null>(null);
const submitModalVisible = ref(false);

const serverOffsetMs = ref(0);
const remainingSeconds = ref(0);
let timerId: number | null = null;

const answeredCount = computed(() =>
    questions.value.filter(
        (question) =>
            question.answer.trim() !== '',
    ).length,
);

const totalQuestions = computed(
    () => questions.value.length,
);

const progressValue = computed(() => {
    if (totalQuestions.value <= 0) {
        return 0;
    }

    return Math.round(
        (answeredCount.value /
            totalQuestions.value) *
            100,
    );
});

const timerText = computed(() => {
    const total = Math.max(
        0,
        remainingSeconds.value,
    );
    const hours = Math.floor(
        total / 3600,
    );
    const minutes = Math.floor(
        (total % 3600) / 60,
    );
    const seconds = total % 60;

    return [hours, minutes, seconds]
        .map((value) =>
            String(value).padStart(2, '0'),
        )
        .join(':');
});

const timerPercentage = computed(() => {
    const durationSeconds =
        Math.max(
            0,
            Number(
                exam.value?.duration ?? 0,
            ),
        ) * 60;

    if (durationSeconds <= 0) {
        return 0;
    }

    return Math.max(
        0,
        Math.min(
            100,
            Math.round(
                (remainingSeconds.value /
                    durationSeconds) *
                    100,
            ),
        ),
    );
});

const timerContainerClass = computed(() => {
    const percentage =
        timerPercentage.value;

    if (percentage >= 50) {
        return 'border-emerald-300 bg-emerald-50/95 text-emerald-800 shadow-emerald-100/70';
    }

    if (percentage >= 30) {
        return 'border-yellow-300 bg-yellow-50/95 text-yellow-800 shadow-yellow-100/70';
    }

    if (percentage >= 10) {
        return 'border-orange-300 bg-orange-50/95 text-orange-800 shadow-orange-100/70';
    }

    return 'border-red-300 bg-red-50/95 text-red-700 shadow-red-100/70';
});

const timerProgressClass = computed(() => {
    const percentage =
        timerPercentage.value;

    if (percentage >= 50) {
        return 'bg-emerald-500';
    }

    if (percentage >= 30) {
        return 'bg-yellow-500';
    }

    if (percentage >= 10) {
        return 'bg-orange-500';
    }

    return 'bg-red-500';
});

function isQuestionSaving(
    questionId: string,
): boolean {
    return savingQuestionIds.value.includes(
        questionId,
    );
}

function formatDateTime(
    value: string | null,
): string {
    if (!value) {
        return 'Not available';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat(
        'en-PH',
        {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        },
    ).format(date);
}

function formatResultNumber(
    value: number,
): string {
    const numeric = Number(value);

    if (!Number.isFinite(numeric)) {
        return '0';
    }

    return Number.isInteger(numeric)
        ? String(numeric)
        : numeric.toFixed(1);
}

function syncServerClock(
    metadata: ExamMetadata,
): void {
    const serverTime = Date.parse(
        metadata.server_time,
    );

    serverOffsetMs.value =
        Number.isNaN(serverTime)
            ? 0
            : serverTime - Date.now();

    updateRemainingTime();
}

function updateRemainingTime(): void {
    if (!exam.value?.expires_at) {
        remainingSeconds.value = 0;
        return;
    }

    const expiry = Date.parse(
        exam.value.expires_at,
    );

    if (Number.isNaN(expiry)) {
        remainingSeconds.value = 0;
        return;
    }

    const serverNow =
        Date.now() +
        serverOffsetMs.value;

    remainingSeconds.value = Math.max(
        0,
        Math.ceil(
            (expiry - serverNow) / 1000,
        ),
    );

    if (
        remainingSeconds.value === 0 &&
        exam.value.started_at &&
        !examCompleted.value &&
        !timeoutSubmitting.value
    ) {
        void finalizeTimeout();
    }
}

function startLocalTimer(): void {
    stopLocalTimer();
    updateRemainingTime();

    timerId = window.setInterval(
        updateRemainingTime,
        1000,
    );
}

function stopLocalTimer(): void {
    if (timerId !== null) {
        window.clearInterval(timerId);
        timerId = null;
    }
}

function applyActivePayload(
    data: Record<string, any>,
): void {
    exam.value = data.exam;
    topic.value = data.topic;
    questions.value =
        data.questions ?? [];
    topicResult.value = null;
    nextCompetence.value = null;
    examCompleted.value = false;
    finalResult.value = null;
    pageError.value = '';
    saveError.value = '';

    if (exam.value) {
        syncServerClock(exam.value);
        startLocalTimer();
    }
}

async function loadState(): Promise<void> {
    loading.value = true;
    pageError.value = '';

    try {
        const response = await axios.get(
            `/api/v1/student/theoretical-assessments/${props.assessmentId}/exam`,
            {
                withCredentials: true,
            },
        );

        exam.value = response.data.exam;
        examCompleted.value =
            response.data.exam?.done === true;

        finalResult.value =
            response.data.exam?.final_result ??
            null;

        if (exam.value) {
            syncServerClock(exam.value);
        }

        if (
            exam.value?.exam_action_state ===
            'resume'
        ) {
            await startOrResumeExam();
        }

        /*
         * Do not automatically finalize a timed-out assessment just because
         * the page was opened. The explicit timed-out state below lets the
         * student finalize it from the page.
         */
    } catch (error: unknown) {
        pageError.value = getErrorMessage(
            error,
            'Unable to load the examination.',
        );
    } finally {
        loading.value = false;
    }
}

async function startOrResumeExam(): Promise<void> {
    if (starting.value) {
        return;
    }

    starting.value = true;
    pageError.value = '';

    try {
        const response = await axios.post(
            `/api/v1/student/theoretical-assessments/${props.assessmentId}/exam/start`,
            {},
            {
                withCredentials: true,
            },
        );

        if (response.data.completed) {
            examCompleted.value = true;
            topic.value = null;
            questions.value = [];
            stopLocalTimer();
            return;
        }

        applyActivePayload(response.data);
    } catch (error: unknown) {
        if (
            axios.isAxiosError(error) &&
            error.response?.data
                ?.time_expired === true
        ) {
            await loadState();
            return;
        }

        pageError.value = getErrorMessage(
            error,
            'Unable to start or resume the examination.',
        );
    } finally {
        starting.value = false;
    }
}

async function saveAnswer(
    question: ExamQuestion,
    answer: string,
): Promise<void> {
    if (
        examCompleted.value ||
        submitting.value ||
        timeoutSubmitting.value
    ) {
        return;
    }

    const previous = question.answer;
    question.answer = answer;
    saveError.value = '';

    if (
        !savingQuestionIds.value.includes(
            question.id,
        )
    ) {
        savingQuestionIds.value.push(
            question.id,
        );
    }

    try {
        await axios.patch(
            `/api/v1/student/theoretical-assessments/${props.assessmentId}/exam/questions/${question.id}`,
            {
                answer,
            },
            {
                withCredentials: true,
            },
        );
    } catch (error: unknown) {
        question.answer = previous;

        if (
            axios.isAxiosError(error) &&
            error.response?.data
                ?.time_expired === true
        ) {
            await finalizeTimeout();
            return;
        }

        saveError.value = getErrorMessage(
            error,
            `Unable to save answer ${question.index}.`,
        );
    } finally {
        savingQuestionIds.value =
            savingQuestionIds.value.filter(
                (id) =>
                    id !== question.id,
            );
    }
}

function requestSubmitTopic(): void {
    if (
        !topic.value ||
        submitting.value ||
        timeoutSubmitting.value
    ) {
        return;
    }

    submitModalVisible.value = true;
}

function closeSubmitModal(): void {
    if (submitting.value) {
        return;
    }

    submitModalVisible.value = false;
}

function confirmSubmitTopic(): void {
    if (
        submitting.value ||
        timeoutSubmitting.value
    ) {
        return;
    }

    submitModalVisible.value = false;
    void submitTopic();
}

async function submitTopic(): Promise<void> {
    if (!topic.value) {
        return;
    }

    submitting.value = true;
    pageError.value = '';
    saveError.value = '';

    try {
        const response = await axios.post(
            `/api/v1/student/theoretical-assessments/${props.assessmentId}/exam/topics/${topic.value.id}/submit`,
            {},
            {
                withCredentials: true,
            },
        );

        topicResult.value =
            response.data.topic_result;

        nextCompetence.value =
            response.data.done !== 'Y'
                ? response.data.next_topic
                : null;

        if (response.data.done === 'Y') {
            finalResult.value =
                response.data.final_result ??
                null;
            examCompleted.value = true;
            stopLocalTimer();
        }
    } catch (error: unknown) {
        if (
            axios.isAxiosError(error) &&
            error.response?.data
                ?.time_expired === true
        ) {
            await finalizeTimeout();
            return;
        }

        const unanswered =
            axios.isAxiosError(error)
                ? error.response?.data
                      ?.unanswered_numbers
                : null;

        if (
            Array.isArray(unanswered) &&
            unanswered.length > 0
        ) {
            pageError.value =
                `Please answer question${unanswered.length > 1 ? 's' : ''} ${unanswered.join(', ')} before submitting.`;
        } else {
            pageError.value = getErrorMessage(
                error,
                'Unable to submit this competence.',
            );
        }
    } finally {
        submitting.value = false;
    }
}

async function continueNextTopic(): Promise<void> {
    topicResult.value = null;
    nextCompetence.value = null;
    topic.value = null;
    questions.value = [];

    await startOrResumeExam();
}

async function finalizeTimeout(): Promise<void> {
    if (
        timeoutSubmitting.value ||
        examCompleted.value
    ) {
        return;
    }

    timeoutSubmitting.value = true;
    stopLocalTimer();

    try {
        const response =
            await axios.post(
                `/api/v1/student/theoretical-assessments/${props.assessmentId}/exam/timeout`,
                {},
                {
                    withCredentials: true,
                },
            );

        examCompleted.value = true;
        finalResult.value =
            response.data.final_result ??
            null;
        topicResult.value = null;
        nextCompetence.value = null;
        pageError.value = '';
    } catch (error: unknown) {
        pageError.value = getErrorMessage(
            error,
            'Unable to finalize the timed-out examination.',
        );
    } finally {
        timeoutSubmitting.value = false;
    }
}

function getErrorMessage(
    error: unknown,
    fallback: string,
): string {
    if (axios.isAxiosError(error)) {
        return (
            error.response?.data?.message ??
            fallback
        );
    }

    return fallback;
}

function backToAssessments(): void {
    window.location.href =
        '/assessments/theoretical-internal/student/datatable';
}

onMounted(() => {
    void loadState();
});

onBeforeUnmount(() => {
    stopLocalTimer();
});
</script>

<template>
    <Head title="Theoretical Assessment Exam" />

    <div
        class="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-[#F8FAFC] p-4 lg:p-5"
    >
        <div
            v-if="loading"
            class="flex min-h-[360px] items-center justify-center"
            role="status"
            aria-label="Loading examination"
        >
            <i
                class="pi pi-spin pi-spinner text-3xl text-[#377EC0]"
                aria-hidden="true"
            ></i>
        </div>

        <template v-else>
            <Message
                v-if="pageError"
                severity="error"
                :closable="false"
            >
                {{ pageError }}
            </Message>

            <Message
                v-if="saveError"
                severity="warn"
                :closable="true"
                @close="saveError = ''"
            >
                {{ saveError }}
            </Message>

            <!-- FIXED EXAM TIMER -->
            <div
                v-if="
                    exam?.started_at &&
                    !examCompleted
                "
                class="pointer-events-none fixed top-[84px] right-3 z-[80] flex justify-end sm:right-4 lg:right-6"
            >
                <div
                    class="w-fit min-w-[190px] max-w-[calc(100vw-1.5rem)] rounded-2xl border-2 px-3 py-2.5 shadow-lg backdrop-blur transition-colors duration-300 sm:max-w-none"
                    :class="timerContainerClass"
                    aria-live="polite"
                >
                    <div
                        class="flex items-center justify-between gap-3"
                    >
                        <div
                            class="flex items-center gap-2"
                        >
                            <i
                                class="pi pi-clock text-sm"
                            ></i>
                            <span
                                class="text-[11px] font-bold tracking-wide uppercase"
                            >
                                Time Remaining
                            </span>
                        </div>

                        <span
                            class="font-mono text-sm font-bold tabular-nums"
                        >
                            {{ timerText }}
                        </span>
                    </div>

                    <div
                        class="mt-2 flex items-center gap-2"
                    >
                        <div
                            class="h-1.5 flex-1 overflow-hidden rounded-full bg-black/10"
                        >
                            <div
                                class="h-full rounded-full transition-all duration-500"
                                :class="timerProgressClass"
                                :style="{
                                    width: `${timerPercentage}%`,
                                }"
                            ></div>
                        </div>

                        <span
                            class="min-w-9 text-right text-[10px] font-bold"
                        >
                            {{ timerPercentage }}%
                        </span>
                    </div>
                </div>
            </div>

            <section
                v-if="exam"
                class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:p-5"
            >
                <div
                    class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"
                >
                    <div class="flex min-w-0 items-center gap-4">
                        <div
                            class="relative flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-gradient-to-br from-[#123A63] to-[#377EC0] text-white shadow-lg shadow-[#377EC0]/20"
                        >
                            <div
                                class="pointer-events-none absolute -top-3 -right-3 size-8 rounded-full bg-white/15"
                            ></div>
                            <i
                                class="pi pi-clipboard relative z-10 !text-[1.65rem] !leading-none !text-white"
                            ></i>
                        </div>

                        <div class="min-w-0">
                            <h1
                                class="text-xl font-bold text-slate-900"
                            >
                                Theoretical Assessment
                            </h1>
                            <p
                                class="mt-1 break-words text-sm font-medium text-slate-600"
                            >
                                {{ exam.name_course }}
                            </p>

                            <div
                                class="mt-2 flex flex-wrap gap-2"
                            >
                                <Tag
                                    v-if="exam.session_code"
                                    :value="exam.session_code"
                                    severity="secondary"
                                    rounded
                                />
                                <Tag
                                    v-if="exam.exam_type"
                                    :value="exam.exam_type"
                                    severity="info"
                                    rounded
                                />
                            </div>
                        </div>
                    </div>

                </div>
            </section>

            <section
                v-if="
                    exam &&
                    exam.exam_action_state ===
                        'start' &&
                    !topic &&
                    !examCompleted
                "
                class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
            >
                <div
                    class="mx-auto max-w-2xl space-y-4 text-center"
                >
                    <div
                        class="mx-auto flex size-14 items-center justify-center rounded-full bg-blue-50 text-[#377EC0]"
                    >
                        <i
                            class="pi pi-play text-xl"
                        ></i>
                    </div>

                    <div>
                        <h2
                            class="text-lg font-bold text-slate-900"
                        >
                            Ready to begin?
                        </h2>
                        <p
                            class="mt-2 text-sm leading-6 text-slate-600"
                        >
                            Your timer starts when you press Start Exam. Closing or refreshing the page will not reset the timer.
                        </p>
                    </div>

                    <div
                        class="grid gap-3 text-left sm:grid-cols-2"
                    >
                        <div
                            class="rounded-xl border border-slate-200 bg-slate-50 p-3"
                        >
                            <p
                                class="text-xs font-bold tracking-wide text-slate-500 uppercase"
                            >
                                Duration
                            </p>
                            <p
                                class="mt-1 font-semibold text-slate-800"
                            >
                                {{ exam.duration }} minutes
                            </p>
                        </div>
                        <div
                            class="rounded-xl border border-slate-200 bg-slate-50 p-3"
                        >
                            <p
                                class="text-xs font-bold tracking-wide text-slate-500 uppercase"
                            >
                                Access Until
                            </p>
                            <p
                                class="mt-1 font-semibold text-slate-800"
                            >
                                {{
                                    formatDateTime(
                                        exam.access_end,
                                    )
                                }}
                            </p>
                        </div>
                    </div>

                    <Button
                        type="button"
                        label="Start Exam"
                        icon="pi pi-play"
                        severity="success"
                        :loading="starting"
                        @click="startOrResumeExam"
                    />
                </div>
            </section>

            <section
                v-else-if="
                    exam &&
                    exam.exam_action_state ===
                        'time_expired' &&
                    !topic &&
                    !examCompleted
                "
                class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
            >
                <div
                    class="mx-auto max-w-2xl text-center"
                >
                    <div
                        class="mx-auto flex size-14 items-center justify-center rounded-full bg-amber-50 text-amber-600"
                    >
                        <i
                            class="pi pi-clock text-xl"
                        ></i>
                    </div>

                    <h2
                        class="mt-4 text-lg font-bold text-slate-900"
                    >
                        Assessment Time Expired
                    </h2>

                    <p
                        class="mt-2 text-sm leading-6 text-slate-600"
                    >
                        The allotted assessment time has ended. Your answers already saved in the system will be used when the assessment is finalized.
                    </p>

                    <div
                        class="mt-5 flex flex-col justify-center gap-3 sm:flex-row"
                    >
                        <Button
                            type="button"
                            label="Finalize Assessment"
                            icon="pi pi-check-circle"
                            severity="warn"
                            :loading="timeoutSubmitting"
                            @click="finalizeTimeout"
                        />

                        <Button
                            type="button"
                            label="Back to Assessments"
                            icon="pi pi-arrow-left"
                            severity="secondary"
                            outlined
                            @click="backToAssessments"
                        />
                    </div>
                </div>
            </section>

            <section
                v-else-if="
                    exam &&
                    (exam.exam_action_state ===
                        'not_available' ||
                        exam.exam_action_state ===
                            'expired') &&
                    !topic &&
                    !examCompleted
                "
                class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
            >
                <Message
                    :severity="
                        exam.exam_action_state ===
                        'expired'
                            ? 'warn'
                            : 'info'
                    "
                    :closable="false"
                >
                    {{ exam.exam_action_label }}.
                    <template
                        v-if="
                            exam.exam_action_state ===
                            'not_available'
                        "
                    >
                        Access begins
                        {{
                            formatDateTime(
                                exam.access_start,
                            )
                        }}.
                    </template>
                </Message>

                <div class="mt-4">
                    <Button
                        type="button"
                        label="Back to Assessments"
                        icon="pi pi-arrow-left"
                        severity="secondary"
                        outlined
                        @click="backToAssessments"
                    />
                </div>
            </section>

            <template
                v-if="
                    topic &&
                    !topicResult &&
                    !examCompleted
                "
            >
                <section
                    class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:p-5"
                >
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <p
                                class="text-xs font-bold tracking-wide text-[#377EC0] uppercase"
                            >
                                Competence {{ topic.order_no }} of
                                {{ topic.total_competences }}
                            </p>
                            <h2
                                class="mt-1 text-lg font-bold text-slate-900"
                            >
                                {{
                                    topic.description ||
                                    'Theoretical Assessment Competence'
                                }}
                            </h2>
                        </div>

                        <Tag
                            :value="`${answeredCount} / ${totalQuestions} answered`"
                            :severity="
                                answeredCount ===
                                totalQuestions
                                    ? 'success'
                                    : 'info'
                            "
                            rounded
                        />
                    </div>

                    <ProgressBar
                        :value="progressValue"
                        :show-value="false"
                        class="mt-4 !h-2"
                    />
                </section>

                <div class="space-y-4">
                    <section
                        v-for="question in questions"
                        :key="question.id"
                        class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:p-5"
                    >
                        <div
                            class="flex items-start gap-3"
                        >
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-full bg-[#377EC0]/10 text-sm font-bold text-[#377EC0]"
                            >
                                {{ question.index }}
                            </div>

                            <div
                                class="min-w-0 flex-1"
                            >
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <p
                                        class="whitespace-pre-line text-base leading-7 font-semibold text-slate-800"
                                    >
                                        {{ question.question }}
                                    </p>

                                    <i
                                        v-if="isQuestionSaving(question.id)"
                                        class="pi pi-spin pi-spinner mt-1 shrink-0 text-sm text-[#377EC0]"
                                        aria-label="Saving answer"
                                    ></i>
                                </div>

                                <div
                                    class="mt-4 space-y-2.5"
                                >
                                    <label
                                        v-for="choice in question.choices"
                                        :key="choice.key"
                                        class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition"
                                        :class="
                                            question.answer ===
                                            choice.key
                                                ? 'border-[#377EC0] bg-blue-50/70 ring-1 ring-[#377EC0]/20'
                                                : 'border-slate-200 bg-white hover:border-[#377EC0]/35 hover:bg-slate-50'
                                        "
                                    >
                                        <RadioButton
                                            :model-value="question.answer"
                                            :input-id="`${question.id}-${choice.key}`"
                                            :name="question.id"
                                            :value="choice.key"
                                            :disabled="
                                                submitting ||
                                                timeoutSubmitting ||
                                                remainingSeconds <=
                                                    0
                                            "
                                            @update:model-value="
                                                saveAnswer(
                                                    question,
                                                    String(
                                                        $event,
                                                    ),
                                                )
                                            "
                                        />

                                        <div
                                            class="min-w-0 flex-1"
                                        >
                                            <div
                                                class="flex items-start gap-2"
                                            >
                                                <span
                                                    class="font-bold text-slate-700"
                                                >
                                                    {{
                                                        choice.key
                                                    }}.
                                                </span>
                                                <span
                                                    v-if="choice.text"
                                                    class="whitespace-pre-line text-sm leading-6 text-slate-700"
                                                >
                                                    {{
                                                        choice.text
                                                    }}
                                                </span>
                                            </div>

                                            <img
                                                v-if="choice.image_url"
                                                :src="choice.image_url"
                                                alt="Answer choice image"
                                                class="mt-3 max-h-72 max-w-full rounded-xl border border-slate-200 object-contain"
                                            />
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <section
                    class="sticky bottom-0 z-10 rounded-2xl border border-slate-200 bg-white/95 p-4 shadow-lg backdrop-blur"
                >
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <p
                                class="text-sm font-semibold text-slate-700"
                            >
                                {{ answeredCount }} of
                                {{ totalQuestions }} questions answered
                            </p>
                            <p
                                v-if="answeredCount < totalQuestions"
                                class="mt-0.5 text-xs text-slate-500"
                            >
                                All questions must be answered before manual submission.
                            </p>
                        </div>

                        <Button
                            type="button"
                            label="Submit Competence"
                            icon="pi pi-check"
                            severity="success"
                            :loading="submitting"
                            :disabled="
                                timeoutSubmitting ||
                                remainingSeconds <= 0
                            "
                            @click="requestSubmitTopic"
                        />
                    </div>
                </section>
            </template>

            <section
                v-if="
                    topicResult &&
                    !examCompleted
                "
                class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
            >
                <div
                    class="mx-auto max-w-2xl text-center"
                >
                    <div
                        class="mx-auto flex size-16 items-center justify-center rounded-full bg-emerald-50 text-emerald-600"
                    >
                        <i
                            class="pi pi-check-circle text-3xl"
                        ></i>
                    </div>

                    <Tag
                        value="Completed"
                        severity="success"
                        rounded
                        class="mt-4"
                    />

                    <h2
                        class="mt-3 text-xl font-bold text-slate-900"
                    >
                        Competence
                        {{ topicResult.order_no }}
                        Completed
                    </h2>

                    <p
                        class="mt-2 text-sm leading-6 text-slate-600"
                    >
                        You have completed
                        <span class="font-semibold text-slate-800">
                            {{
                                topicResult.topic ||
                                'this competence'
                            }}
                        </span>.
                    </p>

                    <div
                        v-if="nextCompetence"
                        class="mt-5 rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-left"
                    >
                        <p
                            class="text-xs font-bold tracking-wide text-[#377EC0] uppercase"
                        >
                            Next Competence
                        </p>

                        <p
                            class="mt-1 text-sm font-semibold leading-6 text-slate-800"
                        >
                            {{
                                nextCompetence.description ||
                                `Competence ${nextCompetence.order_no}`
                            }}
                        </p>

                        <p
                            class="mt-1 text-xs leading-5 text-slate-600"
                        >
                            Proceed when you are ready. Your examination timer continues to run.
                        </p>
                    </div>

                    <Button
                        v-if="nextCompetence"
                        type="button"
                        label="Proceed to Next Competence"
                        icon="pi pi-arrow-right"
                        icon-pos="right"
                        severity="info"
                        class="mt-5"
                        :loading="starting"
                        @click="continueNextTopic"
                    />
                </div>
            </section>

            <section
                v-if="examCompleted"
                class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
            >
                <div
                    class="mx-auto max-w-xl text-center"
                >
                    <div
                        class="mx-auto flex size-16 items-center justify-center rounded-full bg-blue-50 text-[#377EC0]"
                    >
                        <i
                            class="pi pi-flag-fill text-2xl"
                        ></i>
                    </div>

                    <h2
                        class="mt-4 text-xl font-bold text-slate-900"
                    >
                        Examination Complete
                    </h2>

                    <p
                        class="mt-2 text-sm leading-6 text-slate-600"
                    >
                        You have completed all assigned competences for this theoretical assessment.
                    </p>

                    <div
                        v-if="finalResult"
                        class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50"
                    >
                        <div
                            class="border-b border-slate-200 bg-white px-4 py-3 text-left sm:px-5"
                        >
                            <p
                                class="text-xs font-bold tracking-wide text-slate-500 uppercase"
                            >
                                Exam Package
                            </p>
                            <p
                                class="mt-1 text-sm font-bold leading-6 text-slate-900"
                            >
                                {{
                                    finalResult.exam_package ||
                                    exam?.name_course ||
                                    'Not available'
                                }}
                            </p>
                        </div>

                        <div
                            class="grid gap-px bg-slate-200 sm:grid-cols-3"
                        >
                            <div
                                class="bg-slate-50 px-4 py-4 text-center"
                            >
                                <p
                                    class="text-xs font-bold tracking-wide text-slate-500 uppercase"
                                >
                                    Score
                                </p>
                                <p
                                    class="mt-2 text-xl font-bold text-slate-900"
                                >
                                    {{
                                        formatResultNumber(
                                            finalResult.score,
                                        )
                                    }}
                                    /
                                    {{
                                        formatResultNumber(
                                            finalResult.total_items,
                                        )
                                    }}
                                </p>
                            </div>

                            <div
                                class="bg-slate-50 px-4 py-4 text-center"
                            >
                                <p
                                    class="text-xs font-bold tracking-wide text-slate-500 uppercase"
                                >
                                    Percentage
                                </p>
                                <p
                                    class="mt-2 text-xl font-bold text-slate-900"
                                >
                                    {{
                                        formatResultNumber(
                                            finalResult.percentage,
                                        )
                                    }}%
                                </p>
                            </div>

                            <div
                                class="bg-slate-50 px-4 py-4 text-center"
                            >
                                <p
                                    class="text-xs font-bold tracking-wide text-slate-500 uppercase"
                                >
                                    Remarks
                                </p>
                                <Tag
                                    :value="finalResult.remarks"
                                    :severity="
                                        finalResult.remarks ===
                                        'PASSED'
                                            ? 'success'
                                            : 'danger'
                                    "
                                    rounded
                                    class="mt-2 !px-4 !py-2 !text-sm !font-bold"
                                />
                            </div>
                        </div>
                    </div>

                    <p
                        class="mt-4 text-xs leading-5 text-slate-500"
                    >
                        Your final result is also available from the Theoretical Assessments page.
                    </p>

                    <Button
                        type="button"
                        label="Back to Assessments"
                        icon="pi pi-arrow-left"
                        severity="info"
                        class="mt-5"
                        @click="backToAssessments"
                    />
                </div>
            </section>

            <!-- CUSTOM SUBMIT CONFIRMATION MODAL -->
            <Teleport to="body">
                <div
                    v-if="submitModalVisible"
                    class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/45 p-4 backdrop-blur-[2px]"
                    @click.self="closeSubmitModal"
                >
                    <div
                        class="w-full max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl"
                    >
                        <div
                            class="border-b border-slate-100 px-5 py-4"
                        >
                            <div
                                class="flex items-start gap-3"
                            >
                                <div
                                    class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-600"
                                >
                                    <i
                                        class="pi pi-exclamation-triangle text-lg"
                                    ></i>
                                </div>

                                <div
                                    class="min-w-0"
                                >
                                    <h3
                                        class="text-base font-bold text-slate-900"
                                    >
                                        Submit Competence?
                                    </h3>

                                    <p
                                        class="mt-1 text-sm leading-6 text-slate-600"
                                    >
                                        You will not be able to change your answers after submitting this competence.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div
                            class="flex flex-col-reverse gap-2 px-5 py-4 sm:flex-row sm:justify-end"
                        >
                            <Button
                                type="button"
                                label="Cancel"
                                icon="pi pi-times"
                                severity="secondary"
                                outlined
                                :disabled="submitting"
                                @click="closeSubmitModal"
                            />

                            <Button
                                type="button"
                                label="Submit Competence"
                                icon="pi pi-check"
                                severity="success"
                                :loading="submitting"
                                @click="confirmSubmitTopic"
                            />
                        </div>
                    </div>
                </div>
            </Teleport>
        </template>
    </div>
</template>
