import { Button } from '@/components/ui/button';
import { num } from '@/lib/format';
import { cn } from '@/lib/utils';
import { ExternalLink, MousePointerClick, Video } from 'lucide-react';
import { useState } from 'react';

interface Row {
    item: string;
    sessions: number;
    engaged_rate: number;
    avg_seconds: number;
    views_per_session: number;
    key_events: number;
}
interface Group {
    totals: Omit<Row, 'item'>;
    landing: Row[];
    ads: Row[];
    events: Record<string, number>;
}
export type Behavior = Partial<Record<'meta' | 'google_ads', Group>>;

const secs = (s: number) => (s >= 60 ? `${Math.floor(s / 60)} min ${s % 60} s` : `${s} s`);

/** Kroki „jak daleko zaszli” – z dostępnych zdarzeń GA4 (pomiar rozszerzony + zdarzenia leadowe). */
function steps(g: Group) {
    const e = g.events;
    const sent = (e.generate_lead ?? 0) || (e.form_submit ?? 0);
    return [
        { label: 'Weszli na stronę', value: g.totals.sessions, hint: null },
        { label: 'Zaangażowani (≥10 s, 2+ odsłony lub zdarzenie)', value: Math.round((g.totals.sessions * g.totals.engaged_rate) / 100), hint: null },
        { label: 'Przewinęli 90% strony', value: e.scroll ?? 0, hint: e.scroll ? null : 'brak zdarzenia scroll – włącz pomiar rozszerzony w GA4' },
        {
            label: 'Zaczęli wypełniać formularz',
            value: e.form_start ?? 0,
            hint: e.form_start ? null : 'brak form_start – pomiar rozszerzony nie widzi formularza',
        },
        { label: 'Wysłali formularz', value: sent, hint: sent ? null : 'brak generate_lead / form_submit' },
        { label: 'Kliknęli telefon', value: (e.phone_click ?? 0) + (e.click_to_call ?? 0), hint: null },
    ];
}

function Table({ rows, first }: { rows: Row[]; first: string }) {
    if (!rows.length) return <p className="text-muted-foreground text-sm">Brak danych.</p>;
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="text-muted-foreground border-b text-left text-xs">
                        <th className="py-1.5 font-medium">{first}</th>
                        <th className="py-1.5 text-right font-medium">Sesje</th>
                        <th className="py-1.5 text-right font-medium">Zaangażowane</th>
                        <th className="py-1.5 text-right font-medium">Śr. czas aktywności</th>
                        <th className="py-1.5 text-right font-medium">Odsłony / sesja</th>
                        <th className="py-1.5 text-right font-medium">Zdarzenia kluczowe</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((r) => (
                        <tr key={r.item} className="border-b last:border-0">
                            <td className="max-w-96 truncate py-1.5 font-mono text-xs" title={r.item}>
                                {r.item}
                            </td>
                            <td className="py-1.5 text-right tabular-nums">{num(r.sessions)}</td>
                            <td
                                className={cn(
                                    'py-1.5 text-right tabular-nums',
                                    r.sessions >= 5 && r.engaged_rate < 40 && 'font-medium text-[#a4471f] dark:text-[#ec835a]',
                                )}
                            >
                                {r.engaged_rate}%
                            </td>
                            <td className="py-1.5 text-right tabular-nums">{secs(r.avg_seconds)}</td>
                            <td className="py-1.5 text-right tabular-nums">{num(r.views_per_session, 1)}</td>
                            <td className="py-1.5 text-right tabular-nums">{num(r.key_events, 1)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export function BehaviorSection({ behavior, clarityDetected }: { behavior: Behavior | null; clarityDetected: boolean }) {
    const groups = (['meta', 'google_ads'] as const).filter((k) => behavior?.[k]);
    const [tab, setTab] = useState<'meta' | 'google_ads'>(groups[0] ?? 'meta');
    const g = behavior?.[tab];

    return (
        <div className="grid gap-4">
            {groups.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    Brak danych GA4 o ruchu z reklam w tym okresie. Dane z GA4 pojawiają się z opóźnieniem kilku godzin i tylko dla osób, które
                    zaakceptowały cookies analityczne.
                </p>
            ) : (
                <>
                    {groups.length > 1 && (
                        <div className="flex w-fit rounded-md border p-0.5">
                            {groups.map((k) => (
                                <Button key={k} variant={tab === k ? 'secondary' : 'ghost'} size="sm" className="h-8" onClick={() => setTab(k)}>
                                    {k === 'meta' ? 'Meta (Facebook / Instagram)' : 'Google Ads'}
                                </Button>
                            ))}
                        </div>
                    )}
                    {g && (
                        <>
                            <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                                {[
                                    ['Sesje z reklam', num(g.totals.sessions)],
                                    ['Zaangażowane sesje', `${g.totals.engaged_rate}%`],
                                    ['Śr. czas aktywności', secs(g.totals.avg_seconds)],
                                    ['Odsłony na sesję', num(g.totals.views_per_session, 1)],
                                    ['Zdarzenia kluczowe', num(g.totals.key_events, 1)],
                                ].map(([l, v]) => (
                                    <div key={l} className="bg-card rounded-xl border p-3">
                                        <div className="text-muted-foreground text-xs">{l}</div>
                                        <div className="mt-0.5 text-lg font-semibold tabular-nums">{v}</div>
                                    </div>
                                ))}
                            </div>

                            <div>
                                <div className="mb-2 text-sm font-medium">Jak daleko zaszli</div>
                                <ol className="grid gap-1 text-sm">
                                    {steps(g).map((s) => {
                                        const pct = g.totals.sessions ? Math.min(100, Math.round((s.value / g.totals.sessions) * 100)) : 0;
                                        return (
                                            <li key={s.label} className="grid grid-cols-[minmax(0,14rem)_1fr_auto] items-center gap-3">
                                                <span className="truncate">{s.label}</span>
                                                <span className="bg-muted h-2 overflow-hidden rounded-full" aria-hidden>
                                                    <span
                                                        className="block h-full rounded-full bg-[#2a78d6] dark:bg-[#3987e5]"
                                                        style={{ width: `${pct}%` }}
                                                    />
                                                </span>
                                                <span className="w-40 text-right text-xs tabular-nums">
                                                    {num(s.value)} {g.totals.sessions ? `(${pct}%)` : ''}
                                                    {s.hint && <span className="text-muted-foreground block">{s.hint}</span>}
                                                </span>
                                            </li>
                                        );
                                    })}
                                </ol>
                            </div>

                            <div>
                                <div className="mb-2 text-sm font-medium">Strony wejścia</div>
                                <Table rows={g.landing} first="Strona" />
                            </div>
                            <div>
                                <div className="mb-2 text-sm font-medium">Wg reklamy (utm_content)</div>
                                {g.ads.length ? (
                                    <Table rows={g.ads} first="Reklama" />
                                ) : (
                                    <p className="text-muted-foreground text-sm">
                                        Brak parametru utm_content w linkach reklam. Dodaj go (np. utm_content={'{{ad.name}}'} w Meta), żeby widzieć
                                        zachowanie osób z każdej reklamy osobno.
                                    </p>
                                )}
                            </div>
                        </>
                    )}
                    <p className="text-muted-foreground text-xs">
                        GA4 liczy tylko osoby, które zgodziły się na cookies analityczne. Dane z ostatnich godzin dochodzą z opóźnieniem.
                    </p>
                </>
            )}

            <div className="flex items-start gap-3 rounded-lg border px-4 py-3 text-sm">
                <Video className="mt-0.5 size-5 shrink-0" />
                <div className="flex-1">
                    {clarityDetected ? (
                        <>
                            <strong>Nagrania sesji: Microsoft Clarity jest na stronie.</strong> W Clarity → Nagrania ustaw filtr <em>Źródło ruchu</em>{' '}
                            (np. facebook), żeby obejrzeć, gdzie osoby z reklam klikały, jak przewijały i w którym miejscu wyszły.
                        </>
                    ) : (
                        <>
                            <strong>Chcesz zobaczyć, gdzie dokładnie się zatrzymali?</strong> Zainstaluj darmowe <strong>Microsoft Clarity</strong>:
                            nagrania sesji, mapy kliknięć i przewijania, „wściekłe kliknięcia”. Instalacja: clarity.microsoft.com → nowy projekt →
                            Instalacja → Google Tag Manager. Zgoda: Clarity respektuje baner cookies (Consent Mode). Po instalacji uruchom ponownie
                            Audyt pomiaru – panel to wykryje.
                        </>
                    )}
                </div>
                <Button variant="outline" size="sm" asChild>
                    <a href="https://clarity.microsoft.com/" target="_blank" rel="noreferrer noopener">
                        <MousePointerClick className="size-4" /> Clarity <ExternalLink className="size-3" />
                    </a>
                </Button>
            </div>
        </div>
    );
}
