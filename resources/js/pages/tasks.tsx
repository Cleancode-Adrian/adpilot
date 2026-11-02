import { RecommendationCard } from '@/components/adpilot/recommendation-card';
import { EmptyState, NativeSelect } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type Recommendation } from '@/types/adpilot';
import { Head, Link, router } from '@inertiajs/react';
import { AlertOctagon, AlertTriangle, ChevronDown, ExternalLink, Layers, List } from 'lucide-react';
import { useMemo, useState } from 'react';

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
}

const SEVERITY_RANK: Record<string, number> = { critical: 0, high: 1, medium: 2, low: 3 };

function ClientGroup({ name, id, items, defaultOpen }: { name: string; id: number; items: Recommendation[]; defaultOpen: boolean }) {
    const [open, setOpen] = useState(defaultOpen);
    const critical = items.filter((r) => r.severity === 'critical').length;
    const high = items.filter((r) => r.severity === 'high').length;

    return (
        <section className="bg-card rounded-xl border">
            <div className="flex flex-wrap items-center gap-3 px-4 py-3">
                <button onClick={() => setOpen(!open)} className="flex min-w-0 flex-1 items-center gap-2 text-left" aria-expanded={open}>
                    <ChevronDown className={`size-4 shrink-0 transition ${open ? '' : '-rotate-90'}`} />
                    <span className="truncate font-semibold">{name}</span>
                    <span className="text-muted-foreground text-sm">({items.length})</span>
                </button>
                <div className="flex items-center gap-3 text-xs">
                    {critical > 0 && (
                        <span className="inline-flex items-center gap-1 font-medium text-[#b42f2f] dark:text-[#f08080]">
                            <AlertOctagon className="size-3.5" /> {critical} krytyczne
                        </span>
                    )}
                    {high > 0 && (
                        <span className="inline-flex items-center gap-1 font-medium text-[#a4471f] dark:text-[#ec835a]">
                            <AlertTriangle className="size-3.5" /> {high} wysokie
                        </span>
                    )}
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={`/clients/${id}/recommendations`}>
                            Otwórz klienta <ExternalLink className="size-3.5" />
                        </Link>
                    </Button>
                </div>
            </div>
            {open && (
                <div className="grid gap-3 border-t p-4">
                    {items.map((t) => (
                        <RecommendationCard key={t.id} rec={t} />
                    ))}
                </div>
            )}
        </section>
    );
}

export default function Tasks({
    tasks,
    clients,
    filters,
}: {
    tasks: Paginated<Recommendation>;
    clients: { id: number; name: string }[];
    filters: { status: string; severity?: string; client?: string; view?: string };
}) {
    const grouped = (filters.view ?? 'clients') === 'clients' && !filters.client;
    const apply = (patch: Record<string, string>) => router.get('/tasks', { ...filters, ...patch }, { preserveState: true, replace: true });

    // Grupy: klienci z zadaniami krytycznymi najwyżej, potem wg liczby zadań
    const groups = useMemo(() => {
        const map = new Map<number, { id: number; name: string; items: Recommendation[] }>();
        for (const t of tasks.data) {
            const id = t.client?.id ?? t.client_id;
            if (!map.has(id)) map.set(id, { id, name: t.client?.name ?? `Klient #${id}`, items: [] });
            map.get(id)!.items.push(t);
        }
        const worst = (g: { items: Recommendation[] }) => Math.min(...g.items.map((r) => SEVERITY_RANK[r.severity] ?? 9));
        return [...map.values()].sort((a, b) => worst(a) - worst(b) || b.items.length - a.items.length || a.name.localeCompare(b.name, 'pl'));
    }, [tasks.data]);

    return (
        <AppLayout breadcrumbs={[{ title: 'Zadania', href: '/tasks' }]}>
            <Head title="Zadania" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Zadania ze wszystkich kont</h1>
                        <p className="text-muted-foreground text-sm">
                            {tasks.total} pozycji{grouped && groups.length > 0 ? ` u ${groups.length} ${groups.length === 1 ? 'klienta' : 'klientów'}` : ''}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {!filters.client && (
                            <div className="flex rounded-md border p-0.5">
                                <Button variant={grouped ? 'secondary' : 'ghost'} size="sm" className="h-8" onClick={() => apply({ view: 'clients' })}>
                                    <Layers className="size-4" /> Wg klienta
                                </Button>
                                <Button variant={!grouped ? 'secondary' : 'ghost'} size="sm" className="h-8" onClick={() => apply({ view: 'list' })}>
                                    <List className="size-4" /> Jedna lista
                                </Button>
                            </div>
                        )}
                        <NativeSelect value={filters.status} onChange={(e) => apply({ status: e.target.value })} aria-label="Status">
                            <option value="open">Otwarte</option>
                            <option value="done">Wykonane</option>
                            <option value="dismissed">Odrzucone</option>
                            <option value="resolved">Rozwiązane automatycznie</option>
                        </NativeSelect>
                        <NativeSelect value={filters.severity ?? ''} onChange={(e) => apply({ severity: e.target.value })} aria-label="Priorytet">
                            <option value="">Każdy priorytet</option>
                            <option value="critical">Krytyczne</option>
                            <option value="high">Wysokie</option>
                            <option value="medium">Średnie</option>
                            <option value="low">Niskie</option>
                        </NativeSelect>
                        <NativeSelect value={filters.client ?? ''} onChange={(e) => apply({ client: e.target.value })} aria-label="Klient">
                            <option value="">Wszyscy klienci</option>
                            {clients.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                </div>

                {tasks.data.length === 0 ? (
                    <EmptyState title="Brak zadań dla wybranych filtrów" />
                ) : grouped ? (
                    <div className="grid gap-4">
                        {groups.map((g, i) => (
                            <ClientGroup key={g.id} id={g.id} name={g.name} items={g.items} defaultOpen={i < 3} />
                        ))}
                    </div>
                ) : (
                    <div className="grid gap-3">
                        {tasks.data.map((t) => (
                            <RecommendationCard key={t.id} rec={t} showClient={!filters.client} />
                        ))}
                    </div>
                )}

                {tasks.links.length > 3 && (
                    <div className="flex flex-wrap gap-1">
                        {tasks.links.map((l, i) =>
                            l.url ? (
                                <Link
                                    key={i}
                                    href={l.url}
                                    className={`rounded border px-3 py-1 text-sm ${l.active ? 'bg-foreground text-background' : ''}`}
                                    dangerouslySetInnerHTML={{ __html: l.label }}
                                />
                            ) : (
                                <span key={i} className="text-muted-foreground px-3 py-1 text-sm" dangerouslySetInnerHTML={{ __html: l.label }} />
                            ),
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
