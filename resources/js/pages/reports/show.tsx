import { RecommendationCard } from '@/components/adpilot/recommendation-card';
import { KpiTile, Section } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import ClientLayout from '@/layouts/client-layout';
import { date, money, num, roas } from '@/lib/format';
import { type ClientProps, type Kpis, type Recommendation } from '@/types/adpilot';
import { router, useForm } from '@inertiajs/react';
import { Copy, Download, ExternalLink, Mail, Trash2 } from 'lucide-react';

interface Report {
    id: number;
    type: 'client' | 'internal';
    period_start: string;
    period_end: string;
    ai_model: string | null;
    sent_at: string | null;
    note: string | null;
    data: { kpis: Kpis; open: Recommendation[]; done: Recommendation[] };
}

export default function ReportShow({
    client,
    report,
    summary_html,
    public_url,
    pdf_url,
}: {
    client: ClientProps;
    report: Report;
    summary_html: string | null;
    public_url: string;
    pdf_url: string;
}) {
    const k = report.data.kpis;
    const cur = client.currency;
    const isClient = report.type === 'client';
    const noteForm = useForm({ note: report.note ?? '' });

    return (
        <ClientLayout client={client} title="Raport">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 className="text-lg font-semibold">{isClient ? 'Raport dla klienta' : 'Plan działań (wewnętrzny)'}</h2>
                    <p className="text-muted-foreground text-sm">
                        {date(report.period_start)} – {date(report.period_end)} · komentarz: {report.ai_model ?? '–'}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {isClient && (
                        <>
                            <Button variant="outline" size="sm" onClick={() => navigator.clipboard.writeText(public_url)}>
                                <Copy className="size-4" /> Kopiuj link dla klienta
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <a href={public_url} target="_blank" rel="noreferrer">
                                    <ExternalLink className="size-4" /> Podgląd
                                </a>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <a href={pdf_url}>
                                    <Download className="size-4" /> PDF
                                </a>
                            </Button>
                            <Button size="sm" disabled={!client.contact_email} onClick={() => router.post(`/reports/${report.id}/send`)} title={client.contact_email ?? 'Brak e-maila klienta'}>
                                <Mail className="size-4" /> {report.sent_at ? 'Wyślij ponownie' : 'Wyślij klientowi'}
                            </Button>
                        </>
                    )}
                    <Button variant="ghost" size="sm" onClick={() => confirm('Usunąć raport?') && router.delete(`/reports/${report.id}`)}>
                        <Trash2 className="size-4" />
                    </Button>
                </div>
            </div>

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <KpiTile label="Wydatki" value={money(k.ads.cost.value, cur)} delta={k.ads.cost.delta} inverse />
                {k.goal === 'leads' ? (
                    <>
                        <KpiTile label="Leady" value={num(k.ads.conversions.value)} delta={k.ads.conversions.delta} />
                        <KpiTile label="Koszt leada" value={money(k.ads.cpa.value, cur, 2)} delta={k.ads.cpa.delta} inverse />
                        <KpiTile label="Wejścia na stronę" value={num(k.analytics.sessions.value)} delta={k.analytics.sessions.delta} />
                    </>
                ) : (
                    <>
                        <KpiTile label="Przychód z reklam" value={money(k.ads.conversion_value.value, cur)} delta={k.ads.conversion_value.delta} />
                        <KpiTile label="ROAS" value={roas(k.ads.roas.value)} delta={k.ads.roas.delta} />
                        <KpiTile label="Konwersje" value={num(k.ads.conversions.value, 1)} delta={k.ads.conversions.delta} />
                    </>
                )}
            </div>

            {isClient && (
                <Section title="Słowo od specjalisty" description="Twój komentarz na początku raportu – np. co zrobiliśmy, na co zwrócić uwagę. Widoczny dla klienta.">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            noteForm.patch(`/reports/${report.id}/note`, { preserveScroll: true });
                        }}
                        className="grid gap-2"
                    >
                        <textarea
                            rows={4}
                            value={noteForm.data.note}
                            onChange={(e) => noteForm.setData('note', e.target.value)}
                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                            placeholder="Np. W tym miesiącu uruchomiliśmy nową kampanię na usługę X i wyłączyliśmy słabe słowa kluczowe…"
                        />
                        <div>
                            <Button type="submit" size="sm" variant="outline" disabled={noteForm.processing}>
                                Zapisz notatkę
                            </Button>
                        </div>
                    </form>
                </Section>
            )}

            {summary_html && (
                <Section title={isClient ? 'Komentarz dla klienta' : 'Plan działań'}>
                    <div
                        className="prose-sm max-w-none [&_h2]:mt-4 [&_h2]:mb-2 [&_h2]:text-base [&_h2]:font-semibold [&_li]:my-1 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:my-2 [&_ul]:list-disc [&_ul]:pl-5"
                        dangerouslySetInnerHTML={{ __html: summary_html }}
                    />
                </Section>
            )}

            {!isClient && report.data.open.length > 0 && (
                <Section title="Rekomendacje w chwili tworzenia planu">
                    <div className="grid gap-3">
                        {report.data.open.map((r) => (
                            <RecommendationCard key={r.id} rec={{ ...r, status: 'open' } as Recommendation} />
                        ))}
                    </div>
                </Section>
            )}
        </ClientLayout>
    );
}
