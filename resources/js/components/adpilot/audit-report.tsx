import { Section } from '@/components/adpilot/ui';
import { cn } from '@/lib/utils';

export type Sev = 'pilne' | 'srednie' | 'drobne';

export interface Finding {
    id: string;
    page: string;
    section: 'page' | 'code' | 'mobile' | 'perf' | 'legal';
    group: string;
    sev: Sev;
    title: string;
    text: string;
    fix: string | null;
    evidence: string | null;
    urls: string[];
    verify: boolean;
    source: 'auto' | 'ai' | 'area';
}

export interface Report {
    version: number;
    ai_model: string | null;
    company: string | null;
    industry: string | null;
    summary: string;
    strengths: string[];
    pages: {
        role: string;
        label: string;
        url: string;
        title: string | null;
        ok: boolean;
        status: number;
        outline?: { level: number; text: string; zone: string; problem: string | null }[];
    }[];
    performance?: {
        mobile: PsSummary | null;
        desktop: PsSummary | null;
        images: {
            url: string;
            kb: number;
            width: number | null;
            height: number | null;
            format: string;
            pages: string[];
            saving_kb: number;
            issues: string[];
        }[];
        totals: { images_checked: number; images_kb: number; saving_kb: number } | null;
    };
    legal?: Legal | null;
    inspection_error: string | null;
    blocked?: string | null;
    sections: {
        key: string;
        num: number;
        title: string;
        subtitle: string | null;
        url: string | null;
        counts: Record<Sev, number>;
        groups: { title: string; findings: Finding[] }[];
    }[];
    plan: Record<'now' | 'month' | 'later', { id: string; title: string; where: string; who: string; verify: boolean }[]>;
    proposals: {
        title: string;
        what: string;
        steps: string[];
        benefit: string;
        needs_from_client: string;
        implementation: string;
        effort: 'S' | 'M' | 'L';
    }[];
    checklist: string[];
    counts: Record<Sev, number>;
    verify_count: number;
}

interface PsSummary {
    performance: number;
    accessibility: number | null;
    best_practices: number | null;
    seo: number | null;
    fcp_s: number | null;
    lcp_s: number;
    tbt_ms: number;
    cls: number;
    ttfb_ms: number | null;
    total_kb: number | null;
}

interface Legal {
    shop: boolean;
    platform: string | null;
    banner: 'cmp' | 'own' | 'info' | null;
    cmp: string[];
    cmp_auto: boolean;
    consent_mode: boolean;
    tools: { name: string; ids: string[]; source: string; load: string; ok: boolean | null }[];
    documents: { key: string; label: string; url: string | null; status: string; http: number | null; required: boolean }[];
    promo: { checked: number; found: boolean; lowest: boolean | null; url: string | null } | null;
}

const okCls = (ok: boolean | null) =>
    ok === true ? 'text-[#0a7d0a] dark:text-[#4ccc4c]' : ok === false ? 'text-[#c0392b] dark:text-[#f08080]' : 'text-[#a86a00] dark:text-[#fab219]';

const DOC_STATUS: Record<string, [string, boolean | null]> = {
    ok: ['jest', true],
    link: ['jest link', true],
    in_text: ['opisane w regulaminie / polityce', true],
    broken: ['link nie działa', false],
    missing: ['nie znaleziono', false],
    unknown: ['nie udało się sprawdzić', null],
};

function LegalSummary({ legal }: { legal: Legal }) {
    const banner =
        legal.banner === 'cmp'
            ? `baner zgód: ${legal.cmp.join(', ')}${legal.cmp_auto ? ' (automatyczne blokowanie skryptów)' : ''}`
            : legal.banner === 'own'
              ? 'własny baner cookies (nie rozpoznano platformy)'
              : legal.banner === 'info'
                ? 'tylko komunikat „strona używa cookies” – bez możliwości odmowy'
                : 'nie znaleziono banera cookies';
    return (
        <div className="grid gap-3 text-sm md:grid-cols-2">
            <div>
                <div className="text-muted-foreground text-xs">Narzędzia śledzące i zgody · {banner}</div>
                {legal.tools.length === 0 ? (
                    <p className="text-muted-foreground mt-1">Nie wykryto Google Analytics, Tag Managera ani pikseli.</p>
                ) : (
                    <ul className="mt-1 grid gap-0.5">
                        {legal.tools.map((t) => (
                            <li key={t.name} className="flex justify-between gap-2 border-t py-0.5">
                                <span className="truncate" title={`${t.ids.join(', ')} · ${t.source}`}>
                                    {t.name}
                                    {t.ids.length > 0 && <span className="text-muted-foreground"> · {t.ids.slice(0, 2).join(', ')}</span>}
                                </span>
                                <span className={cn('shrink-0 text-right font-medium', okCls(t.ok))}>{t.load}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            <div>
                <div className="text-muted-foreground text-xs">
                    Dokumenty{legal.shop ? ` · sklep${legal.platform ? ` (${legal.platform})` : ''}` : ''}
                </div>
                <ul className="mt-1 grid gap-0.5">
                    {legal.documents.map((d) => {
                        const [label, ok] = DOC_STATUS[d.status] ?? [d.status, null];
                        return (
                            <li key={d.key} className="flex justify-between gap-2 border-t py-0.5">
                                {d.url ? (
                                    <a href={d.url} target="_blank" rel="noreferrer noopener" className="truncate hover:underline">
                                        {d.label}
                                    </a>
                                ) : (
                                    <span className="truncate">{d.label}</span>
                                )}
                                <span className={cn('shrink-0 font-medium', okCls(!d.required && d.status === 'missing' ? null : ok))}>{label}</span>
                            </li>
                        );
                    })}
                </ul>
                {legal.promo && (
                    <p className="text-muted-foreground mt-1 text-xs">
                        Omnibus: sprawdzono {legal.promo.checked} str.{' '}
                        {legal.promo.found
                            ? legal.promo.lowest
                                ? '– przy promocjach jest najniższa cena z 30 dni.'
                                : '– przy promocjach brakuje najniższej ceny z 30 dni.'
                            : '– bez obniżonych cen.'}
                    </p>
                )}
            </div>
        </div>
    );
}

const kb = (v: number) => (v >= 1024 ? `${(v / 1024).toFixed(1).replace('.', ',')} MB` : `${v} KB`);

function PerfSummary({ perf }: { perf: NonNullable<Report['performance']> }) {
    const rows: [string, (p: PsSummary) => string][] = [
        ['Wydajność', (p) => `${p.performance}/100`],
        ['Dostępność', (p) => (p.accessibility === null ? '–' : `${p.accessibility}/100`)],
        ['Dobre praktyki', (p) => (p.best_practices === null ? '–' : `${p.best_practices}/100`)],
        ['LCP (główna treść)', (p) => `${String(p.lcp_s).replace('.', ',')} s`],
        ['TBT (blokowanie)', (p) => `${p.tbt_ms} ms`],
        ['CLS (przesunięcia)', (p) => String(p.cls).replace('.', ',')],
        ['Waga strony', (p) => (p.total_kb === null ? '–' : kb(p.total_kb))],
    ];
    return (
        <div className="grid gap-3 text-sm md:grid-cols-2">
            {(perf.mobile || perf.desktop) && (
                <table className="w-full">
                    <thead>
                        <tr className="text-muted-foreground text-xs">
                            <th className="text-left font-normal">Google PageSpeed</th>
                            <th className="text-right font-normal">Telefon</th>
                            <th className="text-right font-normal">Komputer</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map(([label, f]) => (
                            <tr key={label} className="border-t">
                                <td className="py-0.5">{label}</td>
                                <td className="text-right tabular-nums">{perf.mobile ? f(perf.mobile) : '–'}</td>
                                <td className="text-right tabular-nums">{perf.desktop ? f(perf.desktop) : '–'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
            {perf.images.length > 0 && (
                <div>
                    <div className="text-muted-foreground text-xs">
                        Najcięższe obrazy{perf.totals ? ` · razem ${kb(perf.totals.images_kb)}, oszczędność ~${kb(perf.totals.saving_kb)}` : ''}
                    </div>
                    <ul className="mt-1 grid gap-0.5">
                        {perf.images.slice(0, 6).map((i) => (
                            <li key={i.url} className="flex justify-between gap-2 border-t py-0.5">
                                <span className="truncate" title={i.issues.join('; ')}>
                                    {decodeURIComponent(i.url.split('/').pop() ?? i.url)}
                                </span>
                                <span className="shrink-0 tabular-nums">
                                    {kb(i.kb)} {i.width ? `· ${i.width}×${i.height}` : ''} · {i.format}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

export const SEV: Record<Sev, { label: string; bg: string; text: string }> = {
    pilne: { label: 'Pilne', bg: 'bg-[#c0392b]', text: 'text-[#c0392b] dark:text-[#f08080]' },
    srednie: { label: 'Średnie', bg: 'bg-[#d4880f]', text: 'text-[#a86a00] dark:text-[#fab219]' },
    drobne: { label: 'Drobne', bg: 'bg-[#3a6ea5]', text: 'text-[#3a6ea5] dark:text-[#8db8e8]' },
};

const EFFORT = { S: 'S – do 2 dni', M: 'M – 3–5 dni', L: 'L – ponad tydzień' };

function Counts({ counts }: { counts: Record<Sev, number> }) {
    return (
        <span className="inline-flex gap-2 text-xs font-medium">
            {(Object.keys(SEV) as Sev[]).map((s) =>
                counts[s] ? (
                    <span key={s} className={SEV[s].text}>
                        {counts[s]} {SEV[s].label.toLowerCase()}
                    </span>
                ) : null,
            )}
            {!counts.pilne && !counts.srednie && !counts.drobne && <span className="text-muted-foreground">bez uwag</span>}
        </span>
    );
}

function FindingRow({ f }: { f: Finding }) {
    return (
        <li className="flex overflow-hidden rounded-md border">
            <div className={cn('w-16 shrink-0 px-1 pt-2 text-center text-[10px] font-bold text-white uppercase', SEV[f.sev].bg)}>
                {SEV[f.sev].label}
            </div>
            <div className="min-w-0 flex-1 p-2 text-sm">
                <span className="text-muted-foreground mr-1 tabular-nums">{f.id}</span>
                <span className="font-medium">{f.title}</span>
                {f.verify && (
                    <span className="ml-2 rounded border border-amber-300 bg-amber-50 px-1 text-[10px] font-semibold text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                        do potwierdzenia
                    </span>
                )}
                {f.source === 'ai' && <span className="text-muted-foreground ml-2 text-[10px]">AI</span>}
                <div className="text-muted-foreground">{f.text}</div>
                {f.evidence && <pre className="bg-muted mt-1 rounded px-2 py-1 text-xs break-all whitespace-pre-wrap">{f.evidence}</pre>}
                {f.fix && (
                    <div className="mt-1 text-xs">
                        <span className="font-medium">Co zrobić:</span> {f.fix}
                    </div>
                )}
            </div>
        </li>
    );
}

export function AuditReport({ report }: { report: Report }) {
    return (
        <>
            <Section
                title="Szczegółowa analiza"
                description={
                    report.ai_model
                        ? `Treść podstron przejrzał model AI (${report.ai_model}).`
                        : 'Bez przeglądu treści przez AI – dodaj ANTHROPIC_API_KEY, aby raport zawierał też literówki, sprzeczności i błędy w ofercie.'
                }
                actions={<Counts counts={report.counts} />}
            >
                <p className="text-sm">{report.summary}</p>
                {report.strengths.length > 0 && (
                    <p className="mt-2 text-sm">
                        <span className="font-medium text-[#2e7d32]">Działa dobrze:</span> {report.strengths.join(' · ')}
                    </p>
                )}
                {report.pages.length > 0 && (
                    <div className="text-muted-foreground mt-2 text-xs">
                        Podstrony:{' '}
                        {report.pages.map((p, i) => (
                            <span key={p.role}>
                                {i > 0 && ' · '}
                                <a href={p.url} target="_blank" rel="noreferrer noopener" className="underline">
                                    {p.label}
                                </a>
                                {!p.ok && <span className="text-[#c0392b]"> (błąd {p.status || 'połączenia'})</span>}
                            </span>
                        ))}
                    </div>
                )}
                {report.blocked && (
                    <p className="mt-2 rounded-md border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                        Serwer strony pokazał skanerowi ekran weryfikacji ({report.blocked}) zamiast treści – to zabezpieczenie hostingu, nie błąd
                        strony. Zablokowanych elementów nie oceniono. Spróbuj „Ponów” za kilkanaście minut albo poproś o dodanie IP serwera AdPilot do
                        wyjątków zabezpieczenia.
                    </p>
                )}
                {!report.blocked && report.inspection_error && (
                    <p className="mt-2 text-xs text-[#b42f2f]">Analiza podstron nie powiodła się: {report.inspection_error}</p>
                )}
            </Section>

            {report.sections.map((s) => (
                <details key={s.key} className="bg-card group rounded-xl border" open={s.counts.pilne > 0}>
                    <summary className="flex cursor-pointer list-none flex-wrap items-center justify-between gap-2 px-4 py-3">
                        <span>
                            <span className="font-semibold">
                                {s.num}. {s.title}
                            </span>
                            {s.url && <span className="text-muted-foreground ml-2 text-xs break-all">{s.url}</span>}
                        </span>
                        <Counts counts={s.counts} />
                    </summary>
                    <div className="grid gap-3 border-t p-4">
                        {s.key === 'perf' && report.performance && <PerfSummary perf={report.performance} />}
                        {s.key === 'legal' && report.legal && <LegalSummary legal={report.legal} />}
                        {s.groups.length === 0 && <p className="text-muted-foreground text-sm">Bez uwag.</p>}
                        {s.groups.map((g) => (
                            <div key={g.title}>
                                <h3 className="mb-1 text-sm font-semibold">{g.title}</h3>
                                <ul className="grid gap-1.5">
                                    {g.findings.map((f) => (
                                        <FindingRow key={f.id} f={f} />
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                </details>
            ))}

            {report.proposals.length > 0 && (
                <Section title="Propozycje rozbudowy" description="Wchodzą do raportu dla klienta – dobry punkt wyjścia do oferty.">
                    <div className="grid gap-3 md:grid-cols-2">
                        {report.proposals.map((p) => (
                            <div key={p.title} className="rounded-lg border p-3 text-sm">
                                <div className="flex justify-between gap-2">
                                    <span className="font-medium">{p.title}</span>
                                    <span className="text-muted-foreground shrink-0 text-xs">{EFFORT[p.effort] ?? p.effort}</span>
                                </div>
                                <p className="text-muted-foreground mt-1">{p.what}</p>
                                <p className="mt-1 text-xs">
                                    <span className="font-medium">Zysk:</span> {p.benefit}
                                </p>
                                <p className="text-xs">
                                    <span className="font-medium">Wykonanie:</span> {p.implementation}
                                </p>
                            </div>
                        ))}
                    </div>
                </Section>
            )}

            {report.checklist.length > 0 && (
                <Section
                    title="Checklista do sprawdzenia ręcznie"
                    description={`Punktów „do potwierdzenia”: ${report.verify_count}. Sprawdź je przed wysłaniem raportu klientowi.`}
                >
                    <ul className="grid list-inside list-disc gap-1 text-sm">
                        {report.checklist.map((c) => (
                            <li key={c}>{c}</li>
                        ))}
                    </ul>
                </Section>
            )}
        </>
    );
}
