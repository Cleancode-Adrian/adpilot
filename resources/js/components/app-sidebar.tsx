import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Activity, LayoutGrid, ListChecks, Palette, PlusCircle, ShieldCheck, UsersRound } from 'lucide-react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const { admin, care_due } = usePage<SharedData>().props;

    const mainNavItems: NavItem[] = [
        { title: 'Pulpit', url: '/dashboard', icon: LayoutGrid },
        { title: 'Zadania reklamowe', url: '/tasks', icon: ListChecks },
        { title: 'Opieka nad stronami', url: '/care', icon: ShieldCheck, badge: care_due },
        { title: 'Nowy klient', url: '/clients/create', icon: PlusCircle },
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
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} label="Praca" />
                {admin && <NavMain items={adminItems} label="Administracja" />}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
