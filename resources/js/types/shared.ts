import type { Auth } from './auth';

export interface SchoolIdentity {
    code: string;
    name: string;
    logo: string;
}

export interface SharedData {
    name: string;
    auth: Auth;
    school: SchoolIdentity;
    sidebarOpen: boolean;
    [key: string]: unknown;
}