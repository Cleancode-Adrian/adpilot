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
    Handshake,
    Inbox,
    ListChecks,
    Palette,
    PiggyBank,
    ScanSearch,
    ShieldCheck,
    Sun,
    UsersRound,
    Wallet,
} from 'lucide-react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const { admin, care_due, wp_alerts, sites_down, requests_new } = usePage<SharedData>().props;

    // Menu w grupach wg rodzaju pracy: start → praca dla klientów → sprzedaż → finanse → reklamy → administracja
    const start: NavItem[] = [{ title: 'Dziś', url: '/today', icon: Sun, badge: (sites_down ?? 0) + (requests_new ?? 0) }];
    const work: NavItem[] = [
        { title: 'Klienci', url: '/clients', icon: Building2, badge: wp_alerts },
        { title: 'Agencje', url: '/agencies', icon: Handshake },
        { title: 'Zadania i opieka', url: '/care', icon: ShieldCheck, badge: care_due },
        { title: 'Zgłoszenia', url: '/requests', icon: Inbox, badge: requests_new },
    ];
    const sales: NavItem[] = [
        { title: 'Audyt strony', url: '/audits', icon: ScanSearch },
        ...(admin ? [{ title: 'Oferty', url: '/quotes', icon: FileSignature }] : []),
    ];
    const finance: NavItem[] = admin
        ? [
              { title: 'Rozliczenia', url: '/billing', icon: Wallet },
              { title: 'Rentowność', url: '/profitability', icon: PiggyBank },
          ]
        : [];
    const ads: NavItem[] = [
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
                            <Link href="/today" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={start} label="" />
                <NavMain items={work} label="Praca" />
                <NavMain items={sales} label="Sprzedaż" />
                {admin && <NavMain items={finance} label="Finanse" />}
                <NavMain items={ads} label="Reklamy" />
                {admin && <NavMain items={adminItems} label="Administracja" />}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
