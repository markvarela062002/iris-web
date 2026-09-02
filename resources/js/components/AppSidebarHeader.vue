<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';

import type {
    BreadcrumbItem,
    SharedData,
} from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage<SharedData>();

const school = computed(() => page.props.school);
</script>

<template>
    <header
        class="
            flex
            h-16
            w-full
            shrink-0
            items-center
            justify-between
            gap-4
            rounded-t-xl
            border-b
            border-[#377EC0]
            bg-[#377EC0]
            px-4
            text-white
            shadow-sm
            transition-[width,height]
            ease-linear
            group-has-data-[collapsible=icon]/sidebar-wrapper:h-12
            md:px-6
            [&_button]:!text-white
            [&_svg]:!text-white
            [&_[data-slot=breadcrumb-link]]:!text-white/80
            [&_[data-slot=breadcrumb-page]]:!text-white
            [&_[data-slot=breadcrumb-separator]]:!text-white/60
        "
    >
        <!-- LEFT SIDE -->
        <div class="flex min-w-0 items-center gap-2">
            <SidebarTrigger
                class="
                    -ml-1
                    shrink-0
                    rounded-lg
                    text-white
                    hover:bg-white/15
                    hover:text-white
                "
            />

            <template v-if="breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>

        <!-- RIGHT SIDE: SCHOOL IDENTITY -->
        <div class="ml-auto flex shrink-0 items-center gap-3">
            <!-- School Name -->
            <div class="hidden text-right sm:block">
                <p
                    class="max-w-[320px] truncate text-base font-bold leading-tight text-white md:text-lg lg:max-w-[480px] lg:text-xl"
                >
                    {{ school.name }}
                </p>
            </div>

            <!-- School Logo -->
            <div
                class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-white p-1.5 shadow-sm group-has-data-[collapsible=icon]/sidebar-wrapper:size-9"
            >
                <img
                    :src="school.logo"
                    :alt="school.name"
                    class="h-full w-full object-contain"
                />
            </div>
        </div>
    </header>
</template>