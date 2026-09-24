<script setup lang="ts">
import { computed } from 'vue';
import Button from 'primevue/button';
import ProgressSpinner from 'primevue/progressspinner';
import type { CalendarEvent, CalendarDay } from '@/types/calendar';

const props = withDefaults(
    defineProps<{
        month: string;
        events: CalendarEvent[];
        title?: string;
        description?: string;
        headerIcon?: string;
        loading?: boolean;
    }>(),
    {
        title: '',
        description: '',
        headerIcon: 'pi pi-calendar',
        loading: false,
    },
);

const emit = defineEmits<{
    'month-change': [month: string];
    event: [event: CalendarEvent];
}>();

const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const currentMonthDate = computed(() => {
    const [year, month] = props.month.split('-').map(Number);
    return new Date(year, month - 1, 1);
});

const monthLabel = computed(() =>
    new Intl.DateTimeFormat('en-US', {
        month: 'long',
        year: 'numeric',
    }).format(currentMonthDate.value),
);

const todayKey = computed(() => toDateKey(new Date()));

const eventMap = computed(() => {
    const map = new Map<string, CalendarEvent[]>();

    for (const event of props.events) {
        const existing = map.get(event.date) ?? [];
        existing.push(event);
        map.set(event.date, existing);
    }

    return map;
});

const days = computed<CalendarDay[]>(() => {
    const monthDate = currentMonthDate.value;
    const year = monthDate.getFullYear();
    const month = monthDate.getMonth();
    const firstDay = new Date(year, month, 1);
    const gridStart = new Date(year, month, 1 - firstDay.getDay());

    const output: CalendarDay[] = [];

    for (let index = 0; index < 42; index++) {
        const date = new Date(gridStart);
        date.setDate(gridStart.getDate() + index);

        const key = toDateKey(date);

        output.push({
            date: key,
            day: date.getDate(),
            currentMonth: date.getMonth() === month,
            today: key === todayKey.value,
            events: eventMap.value.get(key) ?? [],
        });
    }

    return output;
});

function previousMonth(): void {
    emit('month-change', shiftMonth(-1));
}

function nextMonth(): void {
    emit('month-change', shiftMonth(1));
}

function goToday(): void {
    emit('month-change', toMonthKey(new Date()));
}

function shiftMonth(amount: number): string {
    const date = currentMonthDate.value;

    return toMonthKey(
        new Date(
            date.getFullYear(),
            date.getMonth() + amount,
            1,
        ),
    );
}

function handleEvent(event: CalendarEvent): void {
    emit('event', event);
}

function toMonthKey(date: Date): string {
    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
    ].join('-');
}

function toDateKey(date: Date): string {
    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');
}
</script>

<template>
    <section
        class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-xl shadow-slate-200/40"
    >
        <div
            class="relative overflow-hidden border-b border-slate-200/80 bg-gradient-to-r from-slate-50 via-white to-blue-50/50 px-5 py-5 lg:px-6"
        >
            <div
                class="pointer-events-none absolute -top-16 -right-12 size-40 rounded-full bg-blue-400/[0.07] blur-2xl"
            ></div>

            <div
                class="relative z-10 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"
            >
                <div class="flex min-w-0 items-center gap-4">
                    <div
                        v-if="headerIcon"
                        class="relative flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-gradient-to-br from-[#123A63] to-[#377EC0] text-white shadow-lg shadow-[#377EC0]/20"
                    >
                        <div
                            class="pointer-events-none absolute -top-3 -right-3 size-8 rounded-full bg-white/15"
                        ></div>

                        <i
                            :class="[
                                headerIcon,
                                'relative z-10 !text-[1.65rem] !leading-none !text-white',
                            ]"
                        ></i>
                    </div>

                    <div class="min-w-0">
                        <div class="mb-1 flex items-center gap-2">
                            <span class="size-1.5 shrink-0 rounded-full bg-emerald-500"></span>
                            <span
                                class="text-[10px] font-bold tracking-[0.14em] text-[#377EC0] uppercase"
                            >
                                Records Management
                            </span>
                        </div>

                        <h2
                            v-if="title"
                            class="text-xl leading-tight font-bold tracking-tight text-[#21365A]"
                        >
                            {{ title }}
                        </h2>

                        <p
                            v-if="description"
                            class="mt-1 max-w-3xl text-sm leading-relaxed text-slate-500"
                        >
                            {{ description }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        label="Today"
                        icon="pi pi-calendar"
                        severity="secondary"
                        outlined
                        @click="goToday"
                    />

                    <Button
                        type="button"
                        icon="pi pi-chevron-left"
                        severity="secondary"
                        outlined
                        aria-label="Previous month"
                        title="Previous month"
                        @click="previousMonth"
                    />

                    <Button
                        type="button"
                        icon="pi pi-chevron-right"
                        severity="secondary"
                        outlined
                        aria-label="Next month"
                        title="Next month"
                        @click="nextMonth"
                    />
                </div>
            </div>
        </div>

        <div class="border-b border-slate-200 bg-white px-5 py-4 lg:px-6">
            <div class="text-2xl font-bold tracking-tight text-[#21365A]">
                {{ monthLabel }}
            </div>
        </div>

        <div class="grid grid-cols-7 border-b border-slate-200 bg-slate-50/80">
            <div
                v-for="weekday in weekdays"
                :key="weekday"
                class="border-r border-slate-200 px-2 py-3 text-center text-xs font-bold tracking-wide text-slate-600 uppercase last:border-r-0"
            >
                {{ weekday }}
            </div>
        </div>

        <div class="relative min-h-[640px] flex-1 bg-white">
            <div class="grid h-full min-h-[640px] grid-cols-7 grid-rows-6">
                <div
                    v-for="day in days"
                    :key="day.date"
                    :class="[
                        'min-h-28 border-r border-b border-slate-200 p-2 last:border-r-0',
                        day.currentMonth ? 'bg-white' : 'bg-slate-50/60',
                        day.today ? '!bg-amber-50/70' : '',
                    ]"
                >
                    <div class="mb-2 flex items-center justify-end">
                        <span
                            :class="[
                                'flex size-7 items-center justify-center rounded-full text-sm font-semibold',
                                day.today
                                    ? 'bg-[#377EC0] text-white'
                                    : day.currentMonth
                                      ? 'text-slate-600'
                                      : 'text-slate-300',
                            ]"
                        >
                            {{ day.day }}
                        </span>
                    </div>

                    <div class="grid gap-1.5">
                        <slot
                            v-for="event in day.events"
                            :key="event.id"
                            name="event"
                            :event="event"
                        >
                            <Button
                                type="button"
                                :label="event.label"
                                :icon="event.icon || undefined"
                                :severity="event.severity || 'secondary'"
                                size="small"
                                class="!w-full !justify-start !rounded-lg !px-2.5 !py-1.5 !text-left !text-xs !font-semibold"
                                @click="handleEvent(event)"
                            />
                        </slot>
                    </div>
                </div>
            </div>

            <div
                v-if="loading"
                class="absolute inset-0 z-20 flex items-center justify-center bg-white/75 backdrop-blur-[1px]"
            >
                <div
                    class="flex flex-col items-center gap-3 rounded-2xl border border-slate-200 bg-white px-6 py-5 shadow-lg"
                >
                    <ProgressSpinner
                        stroke-width="5"
                        class="!size-8"
                    />

                    <div class="text-sm font-semibold text-slate-600">
                        Loading calendar
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
