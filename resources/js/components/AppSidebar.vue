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
    Globe,
    History,
    LayoutGrid,
    List,
    MapPin,
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
                title: 'Theoretical Assessments - Internal (Enrolled)',
                href: '/assessments/theoretical/internal',
                icon: BookOpen,
            },
            {
                title: 'Theoretical Assessments - External (Non-Enrolled)',
                href: '/assessments/theoretical/external',
                icon: BookOpen,
            },
            {
                title: 'Practical Assessments - Internal (Enrolled)',
                href: '/assessments/practical/internal',
                icon: ClipboardCheck,
            },
            {
                title: 'Practical Assessments - External (Non-Enrolled)',
                href: '/assessments/practical/external',
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
                href: '/monitoring/activity-updates',
                icon: Activity,
            },
            {
                title: 'Uploaded Documents',
                href: '/monitoring/uploaded-documents',
                icon: Upload,
            },
            {
                title: 'Training Record Book (OTG)',
                href: '/monitoring/training-record-book',
                icon: BookOpen,
            },
            {
                title: 'Daily Journal',
                href: '/monitoring/daily-journal',
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
                title: 'Student',
                href: '/databases/students',
                icon: User,
            },
            {
                title: 'Batch Upload',
                href: '/databases/batch-upload',
                icon: Upload,
            },
            {
                title: 'Student List Report',
                href: '/databases/student-list-report',
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
                title: 'Activity Types Setup',
                href: '/setup/activity-types',
                icon: Activity,
            },
            {
                title: 'Requirement Types Setup',
                href: '/setup/requirement-types',
                icon: List,
            },
            {
                title: 'TRB - OTG Content Setup',
                href: '/setup/trb-otg-content',
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
                href: '/assessment-setup/exam-sessions',
                icon: Calendar,
            },
            {
                title: 'Exam Packages',
                href: '/assessment-setup/exam-packages',
                icon: Package,
            },
            {
                title: 'Question Bank',
                href: '/assessment-setup/question-bank',
                icon: CircleHelp,
            },
            {
                title: 'Question Upload',
                href: '/assessment-setup/question-upload',
                icon: Upload,
            },
            {
                title: 'Question Activation',
                href: '/assessment-setup/question-activation',
                icon: CheckCircle,
            },
            {
                title: 'Subject Batch Update',
                href: '/assessment-setup/subject-batch-update',
                icon: RefreshCw,
            },
            {
                title: 'Rubric Setup',
                href: '/assessment-setup/rubric',
                icon: List,
            },
            {
                title: 'Practical Assessment Setup',
                href: '/assessment-setup/practical',
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
                title: 'Exam Packages Summary',
                href: '/assessment-reports/exam-packages-summary',
                icon: Package,
            },
            {
                title: 'Exam Results Summary',
                href: '/assessment-reports/exam-results-summary',
                icon: BarChart3,
            },
            {
                title: 'Frequency of Correct Answers',
                href: '/assessment-reports/correct-answer-frequency',
                icon: CheckCircle,
            },
            {
                title: 'Difficulty Level',
                href: '/assessment-reports/difficulty-level',
                icon: BarChart3,
            },
            {
                title: 'Discriminability Index',
                href: '/assessment-reports/discriminability-index',
                icon: BarChart3,
            },
            {
                title: 'Item Analysis',
                href: '/assessment-reports/item-analysis',
                icon: ClipboardCheck,
            },
            {
                title: 'Question List',
                href: '/assessment-reports/question-list',
                icon: List,
            },
            {
                title: 'Item Analysis History',
                href: '/assessment-reports/item-analysis-history',
                icon: History,
            },
        ],
    },
];
</script>

<template>
    <Sidebar
        collapsible="icon"
        variant="inset"
    >
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        size="lg"
                        as-child
                        class="h-28 justify-center p-2"
                    >
                        <Link
                            :href="dashboard()"
                            class="flex items-center justify-center"
                        >
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>

    <slot />
</template>