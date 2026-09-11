<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import {
    Activity,
    BarChart3,
    Bell,
    BookOpen,
    Calendar,
    CheckCircle,
    CircleHelp,
    ClipboardCheck,
    Database,
    FileText,
    History,
    LayoutGrid,
    List,
    Megaphone,
    MessageSquare,
    Monitor,
    Notebook,
    Package,
    RefreshCw,
    Settings,
    Upload,
    User,
    Users,
} from '@lucide/vue';

import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';

import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

import { dashboard } from '@/routes';

import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Alerts',
        href: '#',
        icon: Bell,
        items: [
            {
                title: 'Statistics',
                href: '/alerts/statistics',
                icon: BarChart3,
            },
            {
                title: 'Calendar',
                href: '/alerts/calendar',
                icon: Calendar,
            },
            {
                title: 'Announcements',
                href: '/alerts/announcements',
                icon: Megaphone,
            },
            {
                title: 'Messages',
                href: '/alerts/messages',
                icon: MessageSquare,
            },
        ],
    },
    {
        title: 'Assessments',
        href: '#',
        icon: ClipboardCheck,
        items: [
            {
                title:
                    'Theoretical - Internal',
                href:
                    '/dashboard/theoretical-internal',
                icon: BookOpen,
            },
            {
                title:
                    'Theoretical - External',
                href:
                    '/dashboard/theoretical-external',
                icon: CircleHelp,
            },
            {
                title:
                    'Practical - Internal',
                href:
                    '/dashboard/practical-internal',
                icon: ClipboardCheck,
            },
            {
                title:
                    'Practical - External',
                href:
                    '/dashboard/practical-external',
                icon: ClipboardCheck,
            },
        ],
    },
    {
        title: 'Monitoring',
        href: '#',
        icon: Monitor,
        items: [
            {
                title: 'Activity Updates',
                href:
                    '/monitoring/activity-updates',
                icon: Activity,
            },
            {
                title: 'Uploaded Documents',
                href:
                    '/monitoring/uploaded-documents',
                icon: Upload,
            },
            {
                title:
                    'Training Record Book (OTG)',
                href:
                    '/dashboard/otg-updates',
                icon: BookOpen,
            },
            {
                title: 'Daily Journal',
                href:
                    '/dashboard/daily-journals',
                icon: Notebook,
            },
            {
                title: 'Reports',
                href: '/monitoring/reports',
                icon: FileText,
            },
        ],
    },
    {
        title: 'Databases',
        href: '#',
        icon: Database,
        items: [
            {
                title: 'Students',
                href: '/databases/students',
                icon: User,
            },
            {
                title: 'Batch Upload',
                href:
                    '/databases/batch-upload',
                icon: Upload,
            },
            {
                title:
                    'Student List Report',
                href:
                    '/databases/student-list-report',
                icon: Users,
            },
        ],
    },
    {
        title: 'Setup',
        href: '#',
        icon: Settings,
        items: [
            {
                title:
                    'Activity Types Setup',
                href:
                    '/setup/activity-types',
                icon: Activity,
            },
            {
                title:
                    'Requirement Types Setup',
                href:
                    '/setup/requirement-types',
                icon: List,
            },
            {
                title:
                    'TRB - OTG Content Setup',
                href:
                    '/setup/trb-otg-content',
                icon: BookOpen,
            },
        ],
    },
    {
        title: 'Assessment Setup',
        href: '#',
        icon: Settings,
        items: [
            {
                title: 'Exam Sessions',
                href:
                    '/assessment-setup/exam-sessions',
                icon: Calendar,
            },
            {
                title: 'Exam Packages',
                href:
                    '/assessment-setup/exam-packages',
                icon: Package,
            },
            {
                title: 'Question Bank',
                href:
                    '/assessment-setup/question-bank',
                icon: CircleHelp,
            },
            {
                title: 'Question Upload',
                href:
                    '/assessment-setup/question-upload',
                icon: Upload,
            },
            {
                title:
                    'Question Activation',
                href:
                    '/assessment-setup/question-activation',
                icon: CheckCircle,
            },
            {
                title:
                    'Subject Batch Update',
                href:
                    '/assessment-setup/subject-batch-update',
                icon: RefreshCw,
            },
            {
                title: 'Rubric Setup',
                href:
                    '/assessment-setup/rubric',
                icon: List,
            },
            {
                title:
                    'Practical Assessment Setup',
                href:
                    '/assessment-setup/practical',
                icon: ClipboardCheck,
            },
        ],
    },
    {
        title: 'Assessment Reports',
        href: '#',
        icon: FileText,
        items: [
            {
                title:
                    'Exam Packages Summary',
                href:
                    '/assessment-reports/exam-packages-summary',
                icon: Package,
            },
            {
                title:
                    'Exam Results Summary',
                href:
                    '/assessment-reports/exam-results-summary',
                icon: BarChart3,
            },
            {
                title:
                    'Correct Answer Frequency',
                href:
                    '/assessment-reports/correct-answer-frequency',
                icon: CheckCircle,
            },
            {
                title: 'Difficulty Level',
                href:
                    '/assessment-reports/difficulty-level',
                icon: BarChart3,
            },
            {
                title:
                    'Discriminability Index',
                href:
                    '/assessment-reports/discriminability-index',
                icon: BarChart3,
            },
            {
                title: 'Item Analysis',
                href:
                    '/assessment-reports/item-analysis',
                icon: ClipboardCheck,
            },
            {
                title: 'Question List',
                href:
                    '/assessment-reports/question-list',
                icon: List,
            },
            {
                title:
                    'Item Analysis History',
                href:
                    '/assessment-reports/item-analysis-history',
                icon: History,
            },
        ],
    },
];
</script>

<template>
    <Sidebar
    collapsible="icon"
    variant="sidebar"
    class="
            overflow-hidden
        rounded-r-2xl
        border-r-0


        [&_[data-sidebar=sidebar]]:!border-0
        [&_[data-sidebar=sidebar]]:!bg-[#0B223D]
        [&_[data-sidebar=sidebar]]:!text-white

        [&_[data-sidebar=group-label]]:!text-slate-400
        [&_[data-sidebar=group-label]]:!text-[10px]
        [&_[data-sidebar=group-label]]:!font-bold
        [&_[data-sidebar=group-label]]:!tracking-[0.15em]
        [&_[data-sidebar=group-label]]:uppercase

        [&_[data-sidebar=menu-button]]:!rounded-xl
        [&_[data-sidebar=menu-button]]:!text-slate-200
        [&_[data-sidebar=menu-button]]:transition-all
        [&_[data-sidebar=menu-button]]:duration-200

        [&_[data-sidebar=menu-button]:hover]:!bg-white/10
        [&_[data-sidebar=menu-button]:hover]:!text-white

        [&_[data-sidebar=menu-button][data-active=true]]:!bg-[#377EC0]
        [&_[data-sidebar=menu-button][data-active=true]]:!font-semibold
        [&_[data-sidebar=menu-button][data-active=true]]:!text-white

        [&_[data-sidebar=menu-sub]]:!border-white/15

        [&_[data-sidebar=menu-sub-button]]:!rounded-lg
        [&_[data-sidebar=menu-sub-button]]:!text-slate-300
        [&_[data-sidebar=menu-sub-button]]:transition-all
        [&_[data-sidebar=menu-sub-button]]:duration-200

        [&_[data-sidebar=menu-sub-button]:hover]:!bg-white/10
        [&_[data-sidebar=menu-sub-button]:hover]:!text-white

        [&_[data-sidebar=menu-sub-button][data-active=true]]:!bg-[#377EC0]/25
        [&_[data-sidebar=menu-sub-button][data-active=true]]:!font-semibold
        [&_[data-sidebar=menu-sub-button][data-active=true]]:!text-blue-200

        [&_[data-sidebar=menu-sub-button]_svg]:!text-current
        [&_[data-sidebar=menu-button]_svg]:!text-current

        [&_[data-sidebar=separator]]:!bg-white/10
    "
>
        <!-- Compact Logo Header -->

       <!-- Larger Logo Header -->
<SidebarHeader class="border-b border-white/10 p-3">
    <SidebarMenu>
        <SidebarMenuItem>
            <SidebarMenuButton
                size="lg"
                as-child
                tooltip="Student Activity Monitoring"
                class="
                    h-28
                    justify-center
                    rounded-xl
                    bg-transparent
                    p-2
                    hover:bg-white/5
                    group-data-[collapsible=icon]:h-14
                    group-data-[collapsible=icon]:p-1
                "
            >
                <Link
                    :href="dashboard()"
                    class="
                        flex
                        h-full
                        w-full
                        flex-col
                        items-center
                        justify-center
                        gap-2
                    "
                >
                    <div
                        class="
                            flex
                            size-20
                            shrink-0
                            items-center
                            justify-center
                            group-data-[collapsible=icon]:size-11
                        "
                    >
                        <AppLogo />
                    </div>

                    <p
                        class="
                            text-center
                            text-[10px]
                            font-semibold
                            tracking-[0.16em]
                            whitespace-nowrap
                            text-blue-100/70
                            uppercase
                            group-data-[collapsible=icon]:hidden
                        "
                    >
                        Student Activity Monitoring
                    </p>
                </Link>
            </SidebarMenuButton>
        </SidebarMenuItem>
    </SidebarMenu>
</SidebarHeader>

        <!-- Navigation -->

        <SidebarContent
            class="
                px-2
                py-3
                [scrollbar-color:rgba(255,255,255,0.12)_transparent]
                [scrollbar-width:thin]
            "
        >
            <NavMain
                :items="mainNavItems"
            />
        </SidebarContent>

        <!-- User Footer -->

        <SidebarFooter
            class="border-t border-white/10 bg-white/[0.025] p-2"
        >
            <NavUser />
        </SidebarFooter>
    </Sidebar>

    <slot />
</template>