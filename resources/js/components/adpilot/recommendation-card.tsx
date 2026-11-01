import { Button } from '@/components/ui/button';
import { date, platformName } from '@/lib/format';
import { type Recommendation } from '@/types/adpilot';
import { Link, router } from '@inertiajs/react';
import { Check, ChevronDown, RotateCcw, X } from 'lucide-react';
import { useState } from 'react';
import { SeverityBadge } from './ui';

export function RecommendationCard({ rec, showClient = false }: { rec: Recommendation; showClient?: boolean }) {
    const [open, setOpen] = useState(false);
    const setStatus = (status: string) => router.patch(`/recommendations/${rec.id}`, { status }, { preserveScroll: true });

    return (
        <div className="bg-card rounded-lg border">
            <div className="flex items-start gap-3 p-4">
                <div className="min-w-0 flex-1">
                    <div className="mb-1.5 flex flex-wrap items-center gap-2">
                        <SeverityBadge severity={rec.severity} kind={rec.kind} />
                        <span className="text-muted-foreground text-xs">{platformName[rec.platform] ?? rec.platform}</span>
                        {showClient && rec.client && (
                            <Link href={`/clients/${rec.client.id}/recommendations`} className="text-xs font-medium hover:underline">
                                {rec.client.name}
                            </Link>
                        )}
                        <span className="text-muted-foreground text-xs">· wykryto {date(rec.detected_at)}</span>
                    </div>
                    <button onClick={() => setOpen(!open)} className="flex w-full items-start gap-1 text-left font-medium">
                        <span className="flex-1">{rec.title}</span>
                        <ChevronDown className={`mt-1 size-4 shrink-0 transition ${open ? 'rotate-180' : ''}`} />
                    </button>
                    <p className="text-muted-foreground mt-1 text-sm">{rec.description}</p>
                    {open && (
                        <div className="mt-3 space-y-2 text-sm">
                            <div className="bg-muted/60 rounded-md p-3">
                                <div className="mb-1 text-xs font-semibold tracking-wide uppercase">Co zrobić</div>
                                {rec.action}
                            </div>
                            {rec.impact && (
                                <div>
                                    <span className="font-medium">Wpływ: </span>
                                    {rec.impact}
                                </div>
                            )}
                            {rec.data?.terms && (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="text-muted-foreground text-left text-xs">
                                                <th className="py-1 pr-2 font-medium">Hasło</th>
                                                <th className="py-1 pr-2 font-medium">Kampania</th>
                                                <th className="py-1 pr-2 text-right font-medium">Kliknięcia</th>
                                                <th className="py-1 text-right font-medium">Koszt</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {rec.data.terms.map((t) => (
                                                <tr key={t.term + t.campaign} className="border-t">
                                                    <td className="py-1 pr-2 font-mono text-xs">{t.term}</td>
                                                    <td className="text-muted-foreground py-1 pr-2 text-xs">{t.campaign}</td>
                                                    <td className="py-1 pr-2 text-right tabular-nums">{t.clicks}</td>
                                                    <td className="py-1 text-right tabular-nums">{t.cost.toLocaleString('pl-PL')}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    )}
                </div>
                <div className="flex shrink-0 flex-col gap-1.5">
                    {rec.status === 'open' ? (
                        <>
                            <Button size="sm" onClick={() => setStatus('done')}>
                                <Check className="size-4" /> Zrobione
                            </Button>
                            <Button size="sm" variant="ghost" onClick={() => setStatus('dismissed')} title="Odrzuć – nie dotyczy">
                                <X className="size-4" /> Odrzuć
                            </Button>
                        </>
                    ) : (
                        <Button size="sm" variant="outline" onClick={() => setStatus('open')}>
                            <RotateCcw className="size-4" /> Przywróć
                        </Button>
                    )}
                </div>
            </div>
        </div>
    );
}
