<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronRight, Circle } from '@lucide/vue';

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

function getHref(item: NavItem): string {
    if (typeof item.href === 'string') {
        return item.href;
    }

    return item.href.url;
}

function isItemActive(item: NavItem): boolean {
    const href = getHref(item);

    if (!href || href === '#') {
        return false;
    }

    return page.url === href || page.url.startsWith(`${href}/`);
}

function hasActiveChild(item: NavItem): boolean {
    return item.items?.some((child) => isItemActive(child)) ?? false;
}
</script>

<template>
    <SidebarGroup>
        <SidebarGroupLabel>MODULES</SidebarGroupLabel>

        <SidebarMenu>
            <template
                v-for="item in items"
                :key="item.title"
            >
                <!-- DROPDOWN ITEM -->
                <Collapsible
                    v-if="item.items?.length"
                    as-child
                    :default-open="false"
                    class="group/collapsible"
                >
                    <SidebarMenuItem>
                        <CollapsibleTrigger as-child>
                            <SidebarMenuButton
                                :tooltip="item.title"
                                :is-active="hasActiveChild(item)"
                                class="
                                    nav-wrap-item
                                    data-[active=true]:!bg-[#377EC0]
                                    data-[active=true]:!text-white
                                    hover:!bg-[#377EC0]/10
                                    hover:!text-foreground
                                "
                            >
                                <!-- Parent Icon -->
                                <component
                                    :is="item.icon"
                                    v-if="item.icon"
                                    class="size-4 shrink-0"
                                />

                                <!-- Parent Title -->
                                <span class="min-w-0 flex-1 text-left">
                                    {{ item.title }}
                                </span>

                                <!-- Dropdown Arrow -->
                                <ChevronRight
                                    class="
                                        ml-auto
                                        size-4
                                        shrink-0
                                        transition-transform
                                        duration-200
                                        group-data-[state=open]/collapsible:rotate-90
                                    "
                                />
                            </SidebarMenuButton>
                        </CollapsibleTrigger>

                        <CollapsibleContent>
                            <SidebarMenuSub
                                class="group-data-[collapsible=icon]:hidden"
                            >
                                <SidebarMenuSubItem
                                    v-for="child in item.items"
                                    :key="child.title"
                                    class="nav-wrap-sub-item"
                                >
                                    <SidebarMenuSubButton
                                        as-child
                                        :is-active="isItemActive(child)"
                                        class="
                                            nav-wrap-item
                                            data-[active=true]:!bg-[#377EC0]
                                            data-[active=true]:!text-white
                                            hover:!bg-[#377EC0]/10
                                            hover:!text-foreground
                                        "
                                    >
                                        <Link
                                            :href="child.href"
                                            class="flex w-full min-w-0 items-start gap-2"
                                        >
                                            <!--
                                                Use the custom child icon when
                                                available. Otherwise, use a
                                                circle as the default icon.
                                            -->
                                            <component
                                                :is="child.icon ?? Circle"
                                                class="
                                                    mt-1
                                                    size-3
                                                    shrink-0
                                                    text-[#377EC0]
                                                "
                                            />

                                            <!-- Child Title -->
                                            <span
                                                class="min-w-0 flex-1 text-left"
                                            >
                                                {{ child.title }}
                                            </span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            </SidebarMenuSub>
                        </CollapsibleContent>
                    </SidebarMenuItem>
                </Collapsible>

                <!-- NORMAL ITEM -->
                <SidebarMenuItem v-else>
                    <SidebarMenuButton
                        as-child
                        :tooltip="item.title"
                        :is-active="isItemActive(item)"
                        class="
                            nav-wrap-item
                            data-[active=true]:!bg-[#377EC0]
                            data-[active=true]:!text-white
                            hover:!bg-[#377EC0]/10
                            hover:!text-foreground
                        "
                    >
                        <Link
                            :href="item.href"
                            class="flex w-full min-w-0 items-start gap-2"
                        >
                            <!-- Normal Item Icon -->
                            <component
                                :is="item.icon"
                                v-if="item.icon"
                                class="size-4 shrink-0"
                            />

                            <!-- Normal Item Title -->
                            <span class="min-w-0 flex-1 text-left">
                                {{ item.title }}
                            </span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </template>
        </SidebarMenu>
    </SidebarGroup>
</template>