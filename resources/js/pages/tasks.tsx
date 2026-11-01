import { RecommendationCard } from '@/components/adpilot/recommendation-card';
import { EmptyState, NativeSelect } from '@/components/adpilot/ui';
import AppLayout from '@/layouts/app-layout';
import { type Recommendation } from '@/types/adpilot';
import { Head, Link, router } from '@inertiajs/react';

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
}

export default function Tasks({
    tasks,
    clients,
    filters,
}: {
    tasks: Paginated<Recommendation>;
    clients: { id: number; name: string }[];
    filters: { status: string; severity?: string; client?: string };
}) {
    const apply = (patch: Record<string, string>) => router.get('/tasks', { ...filters, ...patch }, { preserveState: true, replace: true });

    return (
        <AppLayout breadcrumbs={[{ title: 'Zadania', href: '/tasks' }]}>
            <Head title="Zadania" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Zadania ze wszystkich kont</h1>
                        <p className="text-muted-foreground text-sm">{tasks.total} pozycji</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
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
                ) : (
                    <div className="grid gap-3">
                        {tasks.data.map((t) => (
                            <RecommendationCard key={t.id} rec={t} showClient />
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
