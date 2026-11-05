import { ClientCard, type ClientRow } from '@/components/adpilot/client-card';
import { EmptyState, NativeSelect } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Archive, PlusCircle, Search } from 'lucide-react';
import { useMemo, useState } from 'react';

type Filter = 'all' | 'general' | 'care' | 'ads';

export default function ClientsIndex({
    clients,
    showArchived,
    archivedCount,
    agencies,
}: {
    clients: ClientRow[];
    showArchived: boolean;
    archivedCount: number;
    agencies: { id: number; name: string }[];
}) {
    const { admin } = usePage<SharedData>().props;
    const [q, setQ] = useState('');
    const [filter, setFilter] = useState<Filter>('all');
    // '' = wszystkie, 'own' = bez agencji (Twoi klienci), liczba = id agencji
    const [agency, setAgency] = useState<string>(() =>
        typeof window === 'undefined' ? '' : (new URLSearchParams(window.location.search).get('agency') ?? ''),
    );

    const list = useMemo(() => {
        const s = q.trim().toLowerCase();
        return clients.filter(
            (c) =>
                (filter === 'all' || (filter === 'general' ? c.has_general : filter === 'care' ? c.has_care : c.has_ads)) &&
                (agency === '' || (agency === 'own' ? c.agency_id === null : String(c.agency_id) === agency)) &&
                (!s || [c.name, c.website_url, c.contact_name, c.contact_email, c.industry, c.agency_name].some((v) => v?.toLowerCase().includes(s))),
        );
    }, [clients, q, filter, agency]);

    const overdue = clients.reduce((n, c) => n + (c.care?.overdue ?? 0), 0);

    return (
        <AppLayout breadcrumbs={[{ title: 'Klienci', href: '/clients' }]}>
            <Head title="Klienci" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">{showArchived ? 'Klienci w archiwum' : 'Klienci'}</h1>
                        <p className="text-muted-foreground text-sm">
                            {clients.length} {clients.length === 1 ? 'klient' : 'klientów'}
                            {overdue > 0 && ` · ${overdue} zadań po terminie`}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {(archivedCount > 0 || showArchived) && (
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={showArchived ? '/clients' : '/clients?archived=1'}>
                                    <Archive className="size-4" /> {showArchived ? 'Aktywni' : `Archiwum (${archivedCount})`}
                                </Link>
                            </Button>
                        )}
                        <Button size="sm" asChild>
                            <Link href="/clients/create">
                                <PlusCircle className="size-4" /> Nowy klient
                            </Link>
                        </Button>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative min-w-56 flex-1 sm:max-w-sm">
                        <Search className="text-muted-foreground absolute top-2.5 left-2.5 size-4" />
                        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Szukaj: nazwa, strona, osoba…" className="pl-8" />
                    </div>
                    <div className="flex rounded-md border p-0.5">
                        {(
                            [
                                ['all', 'Wszyscy'],
                                ['general', 'Obsługa'],
                                ['care', 'Opieka'],
                                ['ads', 'Reklamy'],
                            ] as const
                        ).map(([k, label]) => (
                            <Button
                                key={k}
                                variant={filter === k ? 'secondary' : 'ghost'}
                                size="sm"
                                className={cn('h-8')}
                                onClick={() => setFilter(k)}
                            >
                                {label}
                            </Button>
                        ))}
                    </div>
                    {agencies.length > 0 && (
                        <NativeSelect value={agency} onChange={(e) => setAgency(e.target.value)} className="h-9 w-auto" aria-label="Agencja">
                            <option value="">Wszystkie zlecenia</option>
                            <option value="own">Moi klienci (bez agencji)</option>
                            {agencies.map((a) => (
                                <option key={a.id} value={a.id}>
                                    Agencja: {a.name}
                                </option>
                            ))}
                        </NativeSelect>
                    )}
                </div>

                {clients.length === 0 ? (
                    <EmptyState title={showArchived ? 'Archiwum jest puste' : 'Nie masz jeszcze klientów'}>
                        {!showArchived && (
                            <Link href="/clients/create" className="underline">
                                Dodaj pierwszego klienta
                            </Link>
                        )}
                    </EmptyState>
                ) : list.length === 0 ? (
                    <EmptyState title="Brak klientów dla tego wyszukiwania" />
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {list.map((c) => (
                            <ClientCard key={c.id} c={c} isAdmin={!!admin} />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
