import { RecommendationCard } from '@/components/adpilot/recommendation-card';
import { EmptyState, Section } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { money, num, roas } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type KpiValue, type Recommendation } from '@/types/adpilot';
import { Head, Link } from '@inertiajs/react';
import { AlertOctagon, AlertTriangle, Archive, FlaskConical, PlusCircle } from 'lucide-react';

interface ClientRow {
    id: number;
    name: string;
    website_url: string;
    currency: string;
    is_demo: boolean;
    target_roas: number | null;
    target_cpa: number | null;
    goal: 'sales' | 'leads' | 'mixed';
    archived_at: string | null;
    cpa: KpiValue;
    sessions: KpiValue;
    leads: KpiValue;
    cost: KpiValue;
    roas: KpiValue;
    revenue: KpiValue;
    conversions: KpiValue;
    open_count: number;
    critical_count: number;
    audit_score: number | null;
    sync_error: boolean;
    last_synced_at: string | null;
}

function Delta({ d, inverse = false }: { d: number | null; inverse?: boolean }) {
    if (d === null) return null;
    const good = inverse ? d < 0 : d > 0;
    return (
        <span className={cn('ml-1 text-xs tabular-nums', good ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400')}>
            {d > 0 ? '▲' : '▼'} {Math.abs(d).toLocaleString('pl-PL')}%
        </span>
    );
}

export default function Dashboard({
    clients,
    tasks,
    period,
    showArchived,
    archivedCount,
}: {
    clients: ClientRow[];
    tasks: Recommendation[];
    period: { from: string; to: string };
    showArchived: boolean;
    archivedCount: number;
}) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Pulpit', href: '/dashboard' }]}>
            <Head title="Pulpit" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 className="text-xl font-semibold">{showArchived ? 'Klienci w archiwum' : 'Klienci'}</h1>
                        <p className="text-muted-foreground text-sm">
                            Ostatnie 30 dni ({new Date(period.from).toLocaleDateString('pl-PL')} – {new Date(period.to).toLocaleDateString('pl-PL')}) vs poprzednie 30 dni
                        </p>
                    </div>
                    <div className="flex gap-2">
                    {(archivedCount > 0 || showArchived) && (
                        <Button asChild variant="outline">
                            <Link href={showArchived ? '/dashboard' : '/dashboard?archived=1'}>
                                <Archive className="size-4" /> {showArchived ? 'Aktywni klienci' : `Archiwum (${archivedCount})`}
                            </Link>
                        </Button>
                    )}
                    <Button asChild>
                        <Link href="/clients/create">
                            <PlusCircle className="size-4" /> Dodaj klienta
                        </Link>
                    </Button>
                    </div>
                </div>

                {clients.length === 0 ? (
                    <EmptyState title="Nie masz jeszcze klientów">
                        Dodaj pierwszego klienta – podaj adres sklepu, a system od razu zrobi audyt pomiaru. Chcesz najpierw zobaczyć, jak to działa? Uruchom{' '}
                        <code className="bg-muted rounded px-1">php artisan adpilot:demo</code>.
                    </EmptyState>
                ) : (
                    <div className="bg-card overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-left text-xs">
                                    <th className="px-4 py-2 font-medium">Klient</th>
                                    <th className="px-4 py-2 text-right font-medium">Wydatki</th>
                                    <th className="px-4 py-2 text-right font-medium">Wynik</th>
                                    <th className="px-4 py-2 text-right font-medium">ROAS / koszt leada</th>
                                    <th className="px-4 py-2 text-right font-medium">Pomiar</th>
                                    <th className="px-4 py-2 font-medium">Do zrobienia</th>
                                </tr>
                            </thead>
                            <tbody>
                                {clients.map((c) => (
                                    <tr key={c.id} className="hover:bg-muted/40 border-b last:border-0">
                                        <td className="px-4 py-3">
                                            <Link href={`/clients/${c.id}`} className="font-medium hover:underline">
                                                {c.name}
                                            </Link>
                                            {c.is_demo && <FlaskConical className="text-muted-foreground ml-1 inline size-3.5" aria-label="demo" />}
                                            <div className="text-muted-foreground text-xs">{c.website_url.replace(/^https?:\/\//, '')}</div>
                                            {c.sync_error && <div className="text-xs text-red-700 dark:text-red-400">Błąd synchronizacji – sprawdź integracje</div>}
                                        </td>
                                        <td className="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                                            {money(c.cost.value, c.currency)}
                                            <Delta d={c.cost.delta} />
                                        </td>
                                        <td className="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                                            {c.goal === 'leads' ? (
                                                <>
                                                    {num(c.conversions.value)} leadów
                                                    <Delta d={c.conversions.delta} />
                                                </>
                                            ) : (
                                                <>
                                                    {money(c.revenue.value, c.currency)}
                                                    <Delta d={c.revenue.delta} />
                                                </>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                                            {c.goal === 'leads' ? (
                                                <>
                                                    <span className="font-medium">{money(c.cpa.value, c.currency, 2)}</span>
                                                    <Delta d={c.cpa.delta} inverse />
                                                    {c.target_cpa && <div className="text-muted-foreground text-xs">cel {money(c.target_cpa, c.currency)}</div>}
                                                </>
                                            ) : (
                                                <>
                                                    <span className="font-medium">{roas(c.roas.value)}</span>
                                                    <Delta d={c.roas.delta} />
                                                    {c.target_roas && <div className="text-muted-foreground text-xs">cel {roas(c.target_roas)}</div>}
                                                </>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">{c.audit_score !== null ? `${c.audit_score}/100` : '–'}</td>
                                        <td className="px-4 py-3">
                                            <Link href={`/clients/${c.id}/recommendations`} className="inline-flex items-center gap-2 hover:underline">
                                                {c.critical_count > 0 && (
                                                    <span className="inline-flex items-center gap-1 text-xs font-medium text-[#b42f2f] dark:text-[#f08080]">
                                                        <AlertOctagon className="size-3.5" /> {c.critical_count} krytyczne
                                                    </span>
                                                )}
                                                <span className="text-muted-foreground inline-flex items-center gap-1 text-xs">
                                                    <AlertTriangle className="size-3.5" /> {c.open_count} otwartych
                                                </span>
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <Section
                    title="Najpilniejsze zadania"
                    description="Rekomendacje silnika reguł ze wszystkich kont – od najważniejszych"
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <Link href="/tasks">Wszystkie zadania</Link>
                        </Button>
                    }
                >
                    {tasks.length === 0 ? (
                        <EmptyState title="Brak otwartych zadań">Konta działają zgodnie z celami.</EmptyState>
                    ) : (
                        <div className="grid gap-3">
                            {tasks.map((t) => (
                                <RecommendationCard key={t.id} rec={t} showClient />
                            ))}
                        </div>
                    )}
                </Section>
            </div>
        </AppLayout>
    );
}
