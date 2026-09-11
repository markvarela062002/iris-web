<script setup lang="ts">
import {
    Link,
    usePage,
} from '@inertiajs/vue3';

import {
    ChevronRight,
    Circle,
} from '@lucide/vue';

import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';

import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';

import type { NavItem } from '@/types';

defineProps<{
    items: NavItem[];
}>();

const page = usePage();

function getHref(
    item: NavItem,
): string {
    if (
        typeof item.href === 'string'
    ) {
        return item.href;
    }

    return item.href.url;
}

function isItemActive(
    item: NavItem,
): boolean {
    const href = getHref(item);

    if (!href || href === '#') {
        return false;
    }

    return (
        page.url === href ||
        page.url.startsWith(
            `${href}/`,
        )
    );
}

function hasActiveChild(
    item: NavItem,
): boolean {
    return (
        item.items?.some((child) =>
            isItemActive(child),
        ) ?? false
    );
}
</script>

<template>
    <SidebarGroup class="px-1">
        <SidebarGroupLabel
            class="
                px-3
                text-[10px]
                font-bold
                tracking-[0.16em]
                !text-slate-400
                uppercase
            "
        >
            Modules
        </SidebarGroupLabel>

        <SidebarMenu class="gap-1">
            <template
                v-for="item in items"
                :key="item.title"
            >
                <!-- Dropdown Item -->

                <Collapsible
                    v-if="
                        item.items?.length
                    "
                    as-child
                    :default-open="
                        hasActiveChild(
                            item,
                        )
                    "
                    class="group/collapsible"
                >
                    <SidebarMenuItem>
                        <CollapsibleTrigger
                            as-child
                        >
                            <SidebarMenuButton
                                :tooltip="
                                    item.title
                                "
                                :is-active="
                                    hasActiveChild(
                                        item,
                                    )
                                "
                                class="
                                    nav-wrap-item
                                    !min-h-10
                                    !rounded-xl
                                    !px-3
                                    !text-slate-200
                                    transition-all
                                    duration-200
                                    hover:!bg-white/10
                                    hover:!text-white
                                    data-[active=true]:!bg-[#377EC0]
                                    data-[active=true]:!font-semibold
                                    data-[active=true]:!text-white
                                "
                            >
                                <!-- Parent Icon -->

                                <component
                                    :is="
                                        item.icon
                                    "
                                    v-if="
                                        item.icon
                                    "
                                    class="
                                        size-4
                                        shrink-0
                                        !text-current
                                    "
                                />

                                <!-- Parent Title -->

                                <span
                                    class="min-w-0 flex-1 text-left"
                                >
                                    {{
                                        item.title
                                    }}
                                </span>

                                <!-- Dropdown Arrow -->

                                <ChevronRight
                                    class="
                                        ml-auto
                                        size-4
                                        shrink-0
                                        !text-current
                                        transition-transform
                                        duration-200
                                        group-data-[state=open]/collapsible:rotate-90
                                    "
                                />
                            </SidebarMenuButton>
                        </CollapsibleTrigger>

                        <CollapsibleContent>
                            <SidebarMenuSub
                                class="
                                    ml-4
                                    border-white/15
                                    pl-2
                                    group-data-[collapsible=icon]:hidden
                                "
                            >
                                <SidebarMenuSubItem
                                    v-for="child in item.items"
                                    :key="
                                        child.title
                                    "
                                    class="nav-wrap-sub-item"
                                >
                                    <SidebarMenuSubButton
                                        as-child
                                        :is-active="
                                            isItemActive(
                                                child,
                                            )
                                        "
                                        class="
                                            nav-wrap-item
                                            !min-h-9
                                            !rounded-lg
                                            !px-2.5
                                            !text-slate-300
                                            transition-all
                                            duration-200
                                            hover:!bg-white/10
                                            hover:!text-white
                                            data-[active=true]:!bg-[#377EC0]/30
                                            data-[active=true]:!font-semibold
                                            data-[active=true]:!text-blue-100
                                        "
                                    >
                                        <Link
                                            :href="
                                                child.href
                                            "
                                            class="flex w-full min-w-0 items-center gap-2.5"
                                        >
                                            <!-- Child Icon -->

                                            <component
                                                :is="
                                                    child.icon ??
                                                    Circle
                                                "
                                                class="
                                                    size-3.5
                                                    shrink-0
                                                    !text-current
                                                    opacity-80
                                                "
                                            />

                                            <!-- Child Title -->

                                            <span
                                                class="min-w-0 flex-1 text-left leading-5"
                                            >
                                                {{
                                                    child.title
                                                }}
                                            </span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            </SidebarMenuSub>
                        </CollapsibleContent>
                    </SidebarMenuItem>
                </Collapsible>

                <!-- Normal Item -->

                <SidebarMenuItem v-else>
                    <SidebarMenuButton
                        as-child
                        :tooltip="
                            item.title
                        "
                        :is-active="
                            isItemActive(
                                item,
                            )
                        "
                        class="
                            nav-wrap-item
                            !min-h-10
                            !rounded-xl
                            !px-3
                            !text-slate-200
                            transition-all
                            duration-200
                            hover:!bg-white/10
                            hover:!text-white
                            data-[active=true]:!bg-[#377EC0]
                            data-[active=true]:!font-semibold
                            data-[active=true]:!text-white
                        "
                    >
                        <Link
                            :href="item.href"
                            class="flex w-full min-w-0 items-center gap-2.5"
                        >
                            <!-- Item Icon -->

                            <component
                                :is="
                                    item.icon
                                "
                                v-if="
                                    item.icon
                                "
                                class="
                                    size-4
                                    shrink-0
                                    !text-current
                                "
                            />

                            <!-- Item Title -->

                            <span
                                class="min-w-0 flex-1 text-left leading-5"
                            >
                                {{
                                    item.title
                                }}
                            </span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </template>
        </SidebarMenu>
    </SidebarGroup>
</template>