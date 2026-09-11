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

const school = computed(
    () => page.props.school,
);
</script>

<template>
    <header
        class="
            group/header
            relative
            isolate
            flex
            h-[72px]
            w-full
            shrink-0
            items-center
            gap-4
            overflow-hidden
            rounded-t-2xl
            border-b
            border-white/10
            bg-gradient-to-r
            from-[#07182D]
            via-[#123A63]
            to-[#377EC0]
            px-4
            text-white
            shadow-lg
            shadow-[#123A63]/10
            transition-[width,height]
            ease-linear
            group-has-data-[collapsible=icon]/sidebar-wrapper:h-14
            md:px-6
            [&_button]:!text-white
            [&_svg]:!text-white
            [&_[data-slot=breadcrumb-link]]:!text-white/70
            [&_[data-slot=breadcrumb-link]]:transition-colors
            [&_[data-slot=breadcrumb-link]]:hover:!text-white
            [&_[data-slot=breadcrumb-page]]:!font-semibold
            [&_[data-slot=breadcrumb-page]]:!text-white
            [&_[data-slot=breadcrumb-separator]]:!text-white/35
        "
    >
        <!-- Decorative Background -->

        <div
            class="pointer-events-none absolute -top-16 right-[20%] size-40 rounded-full bg-cyan-300/10 blur-3xl"
        ></div>

        <div
            class="pointer-events-none absolute -right-12 -bottom-20 size-44 rounded-full bg-white/[0.07] blur-2xl"
        ></div>

        <div
            class="pointer-events-none absolute inset-0 bg-gradient-to-b from-white/[0.04] to-transparent"
        ></div>

        <!-- Left Side -->

        <div
            class="relative z-10 flex min-w-0 flex-1 items-center gap-3"
        >
            <SidebarTrigger
                class="
                    -ml-1
                    size-10
                    shrink-0
                    rounded-xl
                    border
                    border-white/10
                    bg-white/10
                    text-white
                    backdrop-blur-sm
                    transition-all
                    hover:scale-105
                    hover:border-white/20
                    hover:bg-white/20
                    hover:text-white
                "
            />

            <div
                class="h-6 w-px shrink-0 bg-white/15"
            ></div>

            <div class="min-w-0">
                <Breadcrumbs
                    v-if="
                        breadcrumbs.length > 0
                    "
                    :breadcrumbs="
                        breadcrumbs
                    "
                />
            </div>
        </div>

        <!-- Right Side: School Identity -->

        <div
            class="relative z-10 ml-auto flex shrink-0 items-center gap-3"
        >
            <!-- School Name -->

            <div
                class="hidden shrink-0 text-right sm:block"
            >
                <p
                    class="
                        whitespace-nowrap
                        text-sm
                        leading-tight
                        font-bold
                        tracking-tight
                        text-white
                        md:text-base
                        lg:text-lg
                    "
                >
                    {{ school.name }}
                </p>

            </div>

            <!-- Divider -->

            <div
                class="hidden h-8 w-px bg-white/15 sm:block"
            ></div>

            <!-- School Logo -->

            <div
                class="
                    flex
                    size-12
                    shrink-0
                    items-center
                    justify-center
                    transition-transform
                    duration-300
                    group-hover/header:scale-105
                    group-has-data-[collapsible=icon]/sidebar-wrapper:size-10
                "
            >
                <img
                    :src="school.logo"
                    :alt="school.name"
                    class="h-full w-full object-contain drop-shadow-lg"
                />
            </div>
        </div>
    </header>
</template>