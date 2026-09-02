import type { UrlMethodPair } from '@inertiajs/core';
import type { Component } from 'vue';

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string | UrlMethodPair;
    icon?: Component;
    isActive?: boolean;
    items?: NavItem[];
}