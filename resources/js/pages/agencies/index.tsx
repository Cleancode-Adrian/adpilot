import { EmptyState } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { AlarmClock, Archive, EyeOff, Globe, Handshake, Mail, Phone, PlusCircle, Search, ShieldAlert } from 'lucide-react';
import { useMemo, useState } from 'react';

interface AgencyRow {
    id: number;
    name: string;
    website_url: string | null;
    contact_name: string | null;
    contact_email: string | null;
    contact_phone: string | null;
    white_label: boolean;
    archived_at: string | null;
    notes_excerpt: string | null;
    sites: { id: number; name: string; website_url: string }[];
    sites_down: number;
    overdue: number;
    wp_alerts: number;
}

const host = (url: string) => url.replace(/^https?:\/\//, '').replace(/\/$/, '');

function AgencyCard({ a }: { a: AgencyRow }) {
    const shown = a.sites.slice(0, 5);
    return (
        <div className="bg-card flex flex-col rounded-xl border">
            <Link href={`/agencies/${a.id}`} className="hover:bg-muted/40 block flex-1 rounded-t-xl px-4 pt-4 pb-3">
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                        <div className="truncate font-semibold">{a.name}</div>
                        {a.contact_name && <div className="text-muted-foreground truncate text-xs">{a.contact_name}</div>}
                    </div>
                    <div className="flex shrink-0 gap-1">
                        {a.white_label && (
                            <span
                                title="White label – klient końcowy nie wie, że to Ty wykonujesz pracę"
                                className="bg-muted inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px]"
                            >
                                <EyeOff className="size-3" /> white label
                            </span>
                        )}
                        <span className="bg-muted inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px]">
                            <Globe className="size-3" /> {a.sites.length}{' '}
                            {a.sites.length === 1 ? 'strona' : a.sites.length < 5 && a.sites.length > 1 ? 'strony' : 'stron'}
                        </span>
                    </div>
                </div>

                <div className="mt-3 grid gap-1.5 text-xs">
                    {a.sites_down > 0 && (
                        <div className="inline-flex items-center gap-1 font-semibold text-[#b42f2f] dark:text-[#f08080]">
                            <ShieldAlert className="size-3.5" /> {a.sites_down} {a.sites_down === 1 ? 'strona nie działa' : 'strony nie działają'}
                        </div>
                    )}
                    {a.overdue > 0 && (
                        <div className="inline-flex items-center gap-1 font-medium text-[#b42f2f] dark:text-[#f08080]">
                            <AlarmClock className="size-3.5" /> {a.overdue} zadań po terminie
                        </div>
                    )}
                    {a.wp_alerts > 0 && (
                        <div className="inline-flex items-center gap-1 font-medium text-[#b42f2f] dark:text-[#f08080]">
                            <ShieldAlert className="size-3.5" /> WordPress: {a.wp_alerts} do sprawdzenia
                        </div>
                    )}
                    {shown.length > 0 ? (
                        <ul className="text-muted-foreground mt-1 space-y-0.5">
                            {shown.map((s) => (
                                <li key={s.id} className="truncate">
                                    • {s.name} <span className="opacity-70">({host(s.website_url)})</span>
                                </li>
                            ))}
                            {a.sites.length > shown.length && <li>… i {a.sites.length - shown.length} więcej</li>}
                        </ul>
                    ) : (
                        <div className="text-muted-foreground">Brak stron – dodaj pierwszą w karcie agencji</div>
                    )}
                    {a.notes_excerpt && <p className="text-muted-foreground mt-1 line-clamp-2 whitespace-pre-line">{a.notes_excerpt}</p>}
                </div>
            </Link>

            {(a.contact_email || a.contact_phone) && (
                <div className="flex flex-wrap items-center gap-1 border-t px-2 py-1.5">
                    {a.contact_email && (
                        <Button variant="ghost" size="sm" asChild>
                            <a href={`mailto:${a.contact_email}`}>
                                <Mail className="size-4" /> E-mail
                            </a>
                        </Button>
                    )}
                    {a.contact_phone && (
                        <Button variant="ghost" size="sm" asChild>
                            <a href={`tel:${a.contact_phone.replace(/\s/g, '')}`}>
                                <Phone className="size-4" /> {a.contact_phone}
                            </a>
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}

export default function AgenciesIndex({
    agencies,
    showArchived,
    archivedCount,
    unassignedCount,
}: {
    agencies: AgencyRow[];
    showArchived: boolean;
    archivedCount: number;
    unassignedCount: number;
}) {
    const [q, setQ] = useState('');
    const list = useMemo(() => {
        const s = q.trim().toLowerCase();
        return agencies.filter(
            (a) =>
                !s ||
                [a.name, a.contact_name, a.contact_email, a.website_url].some((v) => v?.toLowerCase().includes(s)) ||
                a.sites.some((x) => x.name.toLowerCase().includes(s) || x.website_url.toLowerCase().includes(s)),
        );
    }, [agencies, q]);
    const sites = agencies.reduce((n, a) => n + a.sites.length, 0);

    return (
        <AppLayout breadcrumbs={[{ title: 'Agencje', href: '/agencies' }]}>
            <Head title="Agencje" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">{showArchived ? 'Agencje w archiwum' : 'Agencje'}</h1>
                        <p className="text-muted-foreground text-sm">
                            Agencje, dla których obsługujesz strony ich klientów · {agencies.length} {agencies.length === 1 ? 'agencja' : 'agencji'},{' '}
                            {sites} stron
                            {!showArchived && unassignedCount > 0 && ` · ${unassignedCount} Twoich klientów bez agencji`}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {(archivedCount > 0 || showArchived) && (
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={showArchived ? '/agencies' : '/agencies?archived=1'}>
                                    <Archive className="size-4" /> {showArchived ? 'Aktywne' : `Archiwum (${archivedCount})`}
                                </Link>
                            </Button>
                        )}
                        <Button size="sm" asChild>
                            <Link href="/agencies/create">
                                <PlusCircle className="size-4" /> Nowa agencja
                            </Link>
                        </Button>
                    </div>
                </div>

                {agencies.length > 0 && (
                    <div className="relative min-w-56 sm:max-w-sm">
                        <Search className="text-muted-foreground absolute top-2.5 left-2.5 size-4" />
                        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Szukaj: agencja, osoba, strona…" className="pl-8" />
                    </div>
                )}

                {agencies.length === 0 ? (
                    <EmptyState title={showArchived ? 'Archiwum jest puste' : 'Nie masz jeszcze agencji'}>
                        {!showArchived && (
                            <div className="flex flex-col items-center gap-2">
                                <Handshake className="text-muted-foreground size-6" />
                                <span>Dodaj agencję, dla której pracujesz, i przypisz do niej obsługiwane strony.</span>
                                <Link href="/agencies/create" className="underline">
                                    Dodaj pierwszą agencję
                                </Link>
                            </div>
                        )}
                    </EmptyState>
                ) : list.length === 0 ? (
                    <EmptyState title="Brak agencji dla tego wyszukiwania" />
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {list.map((a) => (
                            <AgencyCard key={a.id} a={a} />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
