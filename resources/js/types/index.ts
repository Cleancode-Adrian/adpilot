import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    badge?: number | null;
}

export interface SharedData {
    name: string;
    quote?: { message: string; author: string };
    flash: { success?: string | null; error?: string | null; invite_link?: string | null };
    brand: Brand;
    admin: { problems: number } | null;
    care_due: number;
    wp_alerts: number;
    sites_down: number;
    vault?: { unlocked_until: number | null; seconds: number };
    auth: Auth;
    [key: string]: unknown;
}

export interface Brand {
    name: string;
    email: string | null;
    phone: string | null;
    website: string | null;
    logo_url: string | null;
    color: string;
    report_footer: string | null;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    is_admin?: boolean;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}
