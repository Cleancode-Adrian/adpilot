import { RecommendationCard } from '@/components/adpilot/recommendation-card';
import { EmptyState, Section } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import ClientLayout from '@/layouts/client-layout';
import { cn } from '@/lib/utils';
import { type ClientProps, type Recommendation } from '@/types/adpilot';
import { Link, router } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';

interface Plan {
    id: number;
    created_at: string;
    ai_model: string | null;
}

export default function Recommendations({
    client,
    status,
    recommendations,
    counts,
    plan,
}: {
    client: ClientProps;
    status: string;
    recommendations: Recommendation[];
    counts: Record<string, number>;
    plan: Plan | null;
}) {
    const tabs = [
        { key: 'open', label: 'Otwarte' },
        { key: 'done', label: 'Wykonane' },
        { key: 'resolved', label: 'Rozwiązane automatycznie' },
        { key: 'dismissed', label: 'Odrzucone' },
    ];
    const problems = recommendations.filter((r) => r.kind === 'problem');
    const opportunities = recommendations.filter((r) => r.kind === 'opportunity');

    const generatePlan = () => {
        const to = new Date(Date.now() - 864e5);
        const from = new Date(to.getTime() - 13 * 864e5);
        router.post(
            `/clients/${client.id}/reports`,
            { type: 'internal', from: from.toISOString().slice(0, 10), to: to.toISOString().slice(0, 10) },
            { preserveScroll: true },
        );
    };

    return (
        <ClientLayout client={client} title="Do zrobienia">
            <Section
                title="Plan działań dla specjalisty"
                description="Silnik reguł analizuje dane po każdej synchronizacji. AI układa z rekomendacji priorytetowy plan na 2 tygodnie."
                actions={
                    <div className="flex gap-2">
                        {plan && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/reports/${plan.id}`}>Ostatni plan ({new Date(plan.created_at).toLocaleDateString('pl-PL')})</Link>
                            </Button>
                        )}
                        <Button size="sm" onClick={generatePlan}>
                            <Sparkles className="size-4" /> Wygeneruj plan
                        </Button>
                    </div>
                }
            >
                <div className="flex flex-wrap gap-1">
                    {tabs.map((t) => (
                        <Link
                            key={t.key}
                            href={`/clients/${client.id}/recommendations?status=${t.key}`}
                            preserveScroll
                            className={cn(
                                'rounded-full border px-3 py-1 text-sm',
                                status === t.key ? 'bg-foreground text-background' : 'hover:bg-muted',
                            )}
                        >
                            {t.label} ({counts[t.key] ?? 0})
                        </Link>
                    ))}
                </div>
            </Section>

            {recommendations.length === 0 ? (
                <EmptyState title="Brak pozycji">
                    {status === 'open' ? 'Wszystko w normie – kolejne rekomendacje pojawią się po synchronizacji.' : 'Nic tu jeszcze nie ma.'}
                </EmptyState>
            ) : (
                <div className="grid gap-6">
                    {problems.length > 0 && (
                        <div className="grid gap-3">
                            <h2 className="font-semibold">Problemy ({problems.length})</h2>
                            {problems.map((r) => (
                                <RecommendationCard key={r.id} rec={r} />
                            ))}
                        </div>
                    )}
                    {opportunities.length > 0 && (
                        <div className="grid gap-3">
                            <h2 className="font-semibold">Szanse na wzrost ({opportunities.length})</h2>
                            {opportunities.map((r) => (
                                <RecommendationCard key={r.id} rec={r} />
                            ))}
                        </div>
                    )}
                </div>
            )}
        </ClientLayout>
    );
}
