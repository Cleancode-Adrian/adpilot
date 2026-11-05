import { CareProgress } from '@/components/adpilot/care-task-row';
import { Button } from '@/components/ui/button';
import { type CareSummary } from '@/types/adpilot';
import { Link } from '@inertiajs/react';
import {
    AlarmClock,
    Briefcase,
    FlaskConical,
    Handshake,
    KeyRound,
    ListChecks,
    LogIn,
    Megaphone,
    Phone,
    ShieldAlert,
    ShieldCheck,
} from 'lucide-react';

export interface ClientRow {
    id: number;
    agency_id: number | null;
    agency_name: string | null;
    name: string;
    website_url: string;
    admin_url: string | null;
    industry: string | null;
    has_ads: boolean;
    has_care: boolean;
    has_general: boolean;
    contact_name: string | null;
    contact_email: string | null;
    contact_phone: string | null;
    is_demo: boolean;
    archived_at: string | null;
    notes_excerpt: string | null;
    credentials_count: number;
    open_count: number;
    care: CareSummary | null;
    wp_alerts: number;
    wp_connected: boolean;
    site_down: boolean;
    ssl_days: number | null;
}

export function ClientCard({ c, isAdmin, showAgency = true }: { c: ClientRow; isAdmin: boolean; showAgency?: boolean }) {
    const left = c.care ? c.care.total - c.care.done : 0;
    return (
        <div className="bg-card flex flex-col rounded-xl border">
            <Link href={`/clients/${c.id}`} className="hover:bg-muted/40 block rounded-t-xl px-4 pt-4 pb-3">
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2 font-semibold">
                            <span className="truncate">{c.name}</span>
                            {c.is_demo && <FlaskConical className="text-muted-foreground size-3.5 shrink-0" aria-label="dane demo" />}
                        </div>
                        <div className="text-muted-foreground truncate text-xs">{c.website_url.replace(/^https?:\/\//, '')}</div>
                        {showAgency && c.agency_name && (
                            <div
                                className="text-muted-foreground mt-0.5 inline-flex items-center gap-1 text-xs"
                                title="Strona obsługiwana na zlecenie agencji"
                            >
                                <Handshake className="size-3" /> {c.agency_name}
                            </div>
                        )}
                    </div>
                    <div className="flex shrink-0 gap-1">
                        {c.has_general && (
                            <span title="Obsługa ogólna" className="bg-muted inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px]">
                                <Briefcase className="size-3" /> obsługa
                            </span>
                        )}
                        {c.has_care && (
                            <span title="Opieka nad stroną" className="bg-muted inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px]">
                                <ShieldCheck className="size-3" /> opieka
                            </span>
                        )}
                        {c.has_ads && (
                            <span title="Reklamy" className="bg-muted inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px]">
                                <Megaphone className="size-3" /> reklamy
                            </span>
                        )}
                    </div>
                </div>

                <div className="mt-3 grid gap-1.5 text-xs">
                    {c.care && c.care.total > 0 && (
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-muted-foreground">Zadania w tym miesiącu</span>
                            <CareProgress done={c.care.done} total={c.care.total} />
                        </div>
                    )}
                    {c.care && c.care.overdue > 0 && (
                        <div className="inline-flex items-center gap-1 font-medium text-[#b42f2f] dark:text-[#f08080]">
                            <AlarmClock className="size-3.5" /> {c.care.overdue} po terminie
                        </div>
                    )}
                    {c.care && c.care.total > 0 && left === 0 && c.care.overdue === 0 && (
                        <div className="text-muted-foreground">Miesiąc zamknięty ✓</div>
                    )}
                    {c.has_care && (!c.care || c.care.total === 0) && (
                        <div className="text-muted-foreground">Brak planu opieki – ustaw w karcie klienta</div>
                    )}
                    {c.site_down && (
                        <div className="inline-flex items-center gap-1 font-semibold text-[#b42f2f] dark:text-[#f08080]">
                            <ShieldAlert className="size-3.5" /> Strona nie działa
                        </div>
                    )}
                    {c.ssl_days !== null && c.ssl_days <= 14 && (
                        <div className="inline-flex items-center gap-1 font-medium text-[#a4471f] dark:text-[#ec835a]">
                            <AlarmClock className="size-3.5" /> {c.ssl_days < 0 ? 'Certyfikat SSL wygasł' : `SSL wygasa za ${c.ssl_days} dni`}
                        </div>
                    )}
                    {c.wp_alerts > 0 && (
                        <div className="inline-flex items-center gap-1 font-medium text-[#b42f2f] dark:text-[#f08080]">
                            <ShieldAlert className="size-3.5" /> WordPress: {c.wp_alerts} do sprawdzenia
                        </div>
                    )}
                    {c.has_ads && c.open_count > 0 && (
                        <div className="inline-flex items-center gap-1">
                            <ListChecks className="size-3.5" /> {c.open_count} zadań reklamowych
                        </div>
                    )}
                    {c.notes_excerpt && <p className="text-muted-foreground mt-1 line-clamp-2 whitespace-pre-line">{c.notes_excerpt}</p>}
                </div>
            </Link>

            <div className="mt-auto flex flex-wrap items-center gap-1 border-t px-2 py-1.5">
                {c.admin_url && (
                    <Button variant="ghost" size="sm" asChild>
                        <a href={c.admin_url} target="_blank" rel="noreferrer noopener" title={c.admin_url}>
                            <LogIn className="size-4" /> Panel strony
                        </a>
                    </Button>
                )}
                {isAdmin && (
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={`/clients/${c.id}/credentials`}>
                            <KeyRound className="size-4" /> Dostępy{c.credentials_count ? ` (${c.credentials_count})` : ''}
                        </Link>
                    </Button>
                )}
                {c.contact_phone && (
                    <Button variant="ghost" size="sm" asChild>
                        <a href={`tel:${c.contact_phone.replace(/\s/g, '')}`} title={c.contact_name ?? undefined}>
                            <Phone className="size-4" /> {c.contact_phone}
                        </a>
                    </Button>
                )}
            </div>
        </div>
    );
}
