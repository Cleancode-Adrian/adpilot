import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    BarChart3,
    Building2,
    FileSignature,
    ListChecks,
    Palette,
    PlusCircle,
    ScanSearch,
    ShieldCheck,
    UsersRound,
    Wallet,
} from 'lucide-react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const { admin, care_due, wp_alerts, sites_down } = usePage<SharedData>().props;

    // 1) Klienci – dane, logowanie, notatki, zadania  2) Reklamy – wyniki i zadania reklamowe
    const clientItems: NavItem[] = [
        { title: 'Wszyscy klienci', url: '/clients', icon: Building2, badge: (wp_alerts ?? 0) + (sites_down ?? 0) },
        { title: 'Zadania i opieka', url: '/care', icon: ShieldCheck, badge: care_due },
        { title: 'Audyt dowolnej strony', url: '/audits', icon: ScanSearch },
        ...(admin
            ? [
                  { title: 'Oferty', url: '/quotes', icon: FileSignature },
                  { title: 'Rozliczenia', url: '/billing', icon: Wallet },
              ]
            : []),
        { title: 'Nowy klient', url: '/clients/create', icon: PlusCircle },
    ];
    const adsItems: NavItem[] = [
        { title: 'Wyniki reklam', url: '/dashboard', icon: BarChart3 },
        { title: 'Zadania reklamowe', url: '/tasks', icon: ListChecks },
    ];

    const adminItems: NavItem[] = admin
        ? [
              { title: 'Status systemu', url: '/admin/status', icon: Activity, badge: admin.problems },
              { title: 'Ustawienia agencji', url: '/admin/settings', icon: Palette },
              { title: 'Zespół', url: '/admin/team', icon: UsersRound },
          ]
        : [];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/clients" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={clientItems} label="Klienci" />
                <NavMain items={adsItems} label="Reklamy" />
                {admin && <NavMain items={adminItems} label="Administracja" />}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
