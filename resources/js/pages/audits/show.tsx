import { Section } from '@/components/adpilot/ui';
import { CopyButton } from '@/components/adpilot/vault';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { dateTime, num } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import { AlertOctagon, AlertTriangle, CheckCircle2, Download, ExternalLink, Info, RefreshCw, Trash2, UserPlus, XCircle } from 'lucide-react';
import { useEffect } from 'react';
import { scoreTone } from './index';

interface Issue {
    code: string;
    severity: 'critical' | 'high' | 'medium' | 'low';
    title: string;
    description: string;
    action: string;
    count: number;
    urls: string[];
}
interface Check {
    key: string;
    label: string;
    status: 'ok' | 'warn' | 'fail' | 'info';
    details: string;
    fix: string | null;
}
interface Audit {
    id: number;
    url: string;
    name: string | null;
    email: string | null;
    status: 'pending' | 'running' | 'done' | 'failed';
    score: number | null;
    error: string | null;
    created_at: string;
    finished_at: string | null;
    client: { id: number; name: string } | null;
    areas: {
        seo?: { score: number | null; error?: string; issues?: Issue[]; pages?: number; summary?: { avg_ms: number | null } };
        tracking?: { score: number | null; error?: string; checks?: Check[]; platform?: string | null };
        speed?: {
            score: number | null;
            pagespeed: { performance: number; lcp_s: number; cls: number; tbt_ms: number } | null;
            avg_ms: number | null;
            source: string | null;
        };
        security?: { score: number | null; error?: string; checks?: Check[]; wordpress?: boolean };
    } | null;
}

const SEV = {
    critical: { label: 'Krytyczne', icon: AlertOctagon, cls: 'text-[#b42f2f] dark:text-[#f08080]' },
    high: { label: 'Wysokie', icon: AlertTriangle, cls: 'text-[#a4471f] dark:text-[#ec835a]' },
    medium: { label: 'Średnie', icon: Info, cls: 'text-[#7a5600] dark:text-[#fab219]' },
    low: { label: 'Niskie', icon: Info, cls: 'text-muted-foreground' },
};
const CHECK = {
    ok: { icon: CheckCircle2, cls: 'text-[#0a7d0a] dark:text-[#4ccc4c]' },
    warn: { icon: AlertTriangle, cls: 'text-[#a4471f] dark:text-[#ec835a]' },
    fail: { icon: XCircle, cls: 'text-[#b42f2f] dark:text-[#f08080]' },
    info: { icon: Info, cls: 'text-muted-foreground' },
};

function Checks({ checks }: { checks: Check[] }) {
    const sorted = [...checks].sort((a, b) => ({ fail: 0, warn: 1, info: 2, ok: 3 })[a.status] - { fail: 0, warn: 1, info: 2, ok: 3 }[b.status]);
    return (
        <ul className="grid gap-1.5">
            {sorted.map((c) => {
                const s = CHECK[c.status] ?? CHECK.info;
                return (
                    <li key={c.key} className="flex items-start gap-2 text-sm">
                        <s.icon className={cn('mt-0.5 size-4 shrink-0', s.cls)} />
                        <div>
                            <span className="font-medium">{c.label}</span> <span className="text-muted-foreground">– {c.details}</span>
                            {c.status !== 'ok' && c.fix && <div className="text-xs">Jak naprawić: {c.fix}</div>}
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}

export default function AuditShow({
    audit,
    areas,
    public_url,
    pdf_url,
}: {
    audit: Audit;
    areas: Record<string, string>;
    public_url: string;
    pdf_url: string;
}) {
    const running = audit.status === 'pending' || audit.status === 'running';
    useEffect(() => {
        if (!running) return;
        const t = setInterval(() => router.reload({ only: ['audit'] }), 4000);
        return () => clearInterval(t);
    }, [running]);
    const a = audit.areas ?? {};
    const host = audit.url.replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, '');
    const total = scoreTone(audit.score);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Audyt dowolnej strony', href: '/audits' },
                { title: host, href: `/audits/${audit.id}` },
            ]}
        >
            <Head title={`Audyt – ${host}`} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">{audit.name || host}</h1>
                        <a
                            href={audit.url}
                            target="_blank"
                            rel="noreferrer noopener"
                            className="text-muted-foreground inline-flex items-center gap-1 text-sm hover:underline"
                        >
                            {audit.url} <ExternalLink className="size-3" />
                        </a>
                        <div className="text-muted-foreground text-xs">
                            {dateTime(audit.created_at)}
                            {audit.email && ` · ${audit.email}`}
                            {audit.client && (
                                <>
                                    {' '}
                                    · klient:{' '}
                                    <Link href={`/clients/${audit.client.id}`} className="underline">
                                        {audit.client.name}
                                    </Link>
                                </>
                            )}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {audit.status === 'done' && (
                            <>
                                <Button variant="outline" asChild>
                                    <a href={public_url} target="_blank" rel="noreferrer">
                                        <ExternalLink className="size-4" /> Raport dla klienta
                                    </a>
                                </Button>
                                <CopyButton value={public_url} label="Kopiuj link do raportu" />
                                <Button variant="outline" asChild>
                                    <a href={pdf_url}>
                                        <Download className="size-4" /> PDF
                                    </a>
                                </Button>
                            </>
                        )}
                        {!audit.client && audit.status === 'done' && (
                            <Button onClick={() => router.post(`/audits/${audit.id}/convert`)}>
                                <UserPlus className="size-4" /> Utwórz klienta
                            </Button>
                        )}
                        {!running && (
                            <Button variant="outline" onClick={() => router.post(`/audits/${audit.id}/rerun`)}>
                                <RefreshCw className="size-4" /> Ponów
                            </Button>
                        )}
                        <Button variant="ghost" title="Usuń" onClick={() => confirm('Usunąć audyt?') && router.delete(`/audits/${audit.id}`)}>
                            <Trash2 className="size-4" />
                        </Button>
                    </div>
                </div>

                {running ? (
                    <Section title="Audyt w toku">
                        <div className="text-muted-foreground flex items-center gap-2 text-sm">
                            <RefreshCw className="size-4 animate-spin" /> Sprawdzam stronę: SEO (do 30 podstron), pomiar, szybkość, bezpieczeństwo –
                            zwykle 1–2 minuty.
                        </div>
                    </Section>
                ) : audit.status === 'failed' ? (
                    <Section title="Audyt nie powiódł się">
                        <p className="text-sm text-[#b42f2f]">{audit.error}</p>
                    </Section>
                ) : (
                    <>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                            <div className="bg-card rounded-xl border p-4">
                                <div className="text-muted-foreground text-sm">Ocena ogólna</div>
                                <div className={cn('text-4xl font-bold tabular-nums', total.cls)}>
                                    {audit.score}
                                    <span className="text-muted-foreground text-base font-normal">/100</span>
                                </div>
                                <div className={cn('text-sm font-medium', total.cls)}>{total.label}</div>
                            </div>
                            {Object.entries(areas).map(([k, label]) => {
                                const s = (a as Record<string, { score: number | null } | undefined>)[k]?.score ?? null;
                                const t = scoreTone(s);
                                return (
                                    <a key={k} href={`#${k}`} className="bg-card hover:bg-muted/40 rounded-xl border p-4">
                                        <div className="text-muted-foreground text-sm">{label}</div>
                                        <div className={cn('text-2xl font-semibold tabular-nums', t.cls)}>{s ?? '–'}</div>
                                        <div className={cn('text-xs', t.cls)}>{s === null ? 'nie sprawdzono' : t.label}</div>
                                    </a>
                                );
                            })}
                        </div>

                        <Section
                            title="SEO i technika"
                            description={a.seo?.pages ? `Sprawdzono ${a.seo.pages} podstron.` : undefined}
                            className="scroll-mt-4"
                        >
                            <div id="seo" />
                            {a.seo?.error ? (
                                <p className="text-sm text-[#b42f2f]">{a.seo.error}</p>
                            ) : !a.seo?.issues?.length ? (
                                <p className="text-sm">Bez problemów.</p>
                            ) : (
                                <ul className="grid gap-2">
                                    {a.seo.issues.map((i) => {
                                        const s = SEV[i.severity];
                                        return (
                                            <li key={i.code} className="flex items-start gap-2 text-sm">
                                                <s.icon className={cn('mt-0.5 size-4 shrink-0', s.cls)} />
                                                <div>
                                                    <span className="font-medium">{i.title}</span>
                                                    <span className="text-muted-foreground">
                                                        {' '}
                                                        · {s.label.toLowerCase()} · {i.count} {i.count === 1 ? 'adres' : 'adresów'}
                                                    </span>
                                                    <div className="text-muted-foreground text-xs">{i.action}</div>
                                                    {i.urls[0] && (
                                                        <div className="text-muted-foreground font-mono text-xs break-all">np. {i.urls[0]}</div>
                                                    )}
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </Section>

                        <Section title="Szybkość">
                            <div id="speed" />
                            {a.speed?.pagespeed ? (
                                <div className="grid gap-3 text-sm sm:grid-cols-4">
                                    <div>
                                        <div className="text-muted-foreground text-xs">Google PageSpeed (telefon)</div>
                                        <div className="text-xl font-semibold tabular-nums">{a.speed.pagespeed.performance}/100</div>
                                    </div>
                                    <div>
                                        <div className="text-muted-foreground text-xs">Największy element (LCP)</div>
                                        <div className="text-xl font-semibold tabular-nums">{num(a.speed.pagespeed.lcp_s, 1)} s</div>
                                        <div className="text-muted-foreground text-xs">dobrze: do 2,5 s</div>
                                    </div>
                                    <div>
                                        <div className="text-muted-foreground text-xs">Przesunięcia układu (CLS)</div>
                                        <div className="text-xl font-semibold tabular-nums">{a.speed.pagespeed.cls}</div>
                                        <div className="text-muted-foreground text-xs">dobrze: do 0,1</div>
                                    </div>
                                    <div>
                                        <div className="text-muted-foreground text-xs">Blokowanie (TBT)</div>
                                        <div className="text-xl font-semibold tabular-nums">{num(a.speed.pagespeed.tbt_ms)} ms</div>
                                        <div className="text-muted-foreground text-xs">dobrze: do 200 ms</div>
                                    </div>
                                </div>
                            ) : (
                                <p className="text-sm">
                                    Średni czas odpowiedzi serwera: <strong>{a.speed?.avg_ms ? `${num(a.speed.avg_ms)} ms` : '–'}</strong>
                                    <span className="text-muted-foreground">
                                        {' '}
                                        (Google PageSpeed niedostępny – dodaj klucz PAGESPEED_API_KEY dla pełnego pomiaru).
                                    </span>
                                </p>
                            )}
                        </Section>

                        <Section title="Pomiar i marketing" description={a.tracking?.platform ? `Platforma: ${a.tracking.platform}` : undefined}>
                            <div id="tracking" />
                            {a.tracking?.error ? (
                                <p className="text-sm text-[#b42f2f]">{a.tracking.error}</p>
                            ) : (
                                <Checks checks={a.tracking?.checks ?? []} />
                            )}
                        </Section>

                        <Section title="Bezpieczeństwo" description="Tylko publiczne, nieinwazyjne sprawdzenia – to, co widzi każdy odwiedzający.">
                            <div id="security" />
                            {a.security?.error ? (
                                <p className="text-sm text-[#b42f2f]">{a.security.error}</p>
                            ) : (
                                <Checks checks={a.security?.checks ?? []} />
                            )}
                        </Section>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
