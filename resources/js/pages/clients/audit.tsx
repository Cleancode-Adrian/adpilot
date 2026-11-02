import { EmptyState, Section } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import ClientLayout from '@/layouts/client-layout';
import { dateTime } from '@/lib/format';
import { type Audit, type AuditCheck, type ClientProps } from '@/types/adpilot';
import { router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Info, Loader2, ScanSearch, XCircle } from 'lucide-react';
import { useEffect } from 'react';

const STATUS: Record<AuditCheck['status'], { icon: typeof Info; label: string; cls: string }> = {
    ok: { icon: CheckCircle2, label: 'OK', cls: 'text-[#0a7d0a] dark:text-[#4ccc4c]' },
    warn: { icon: AlertTriangle, label: 'Uwaga', cls: 'text-[#a4471f] dark:text-[#ec835a]' },
    fail: { icon: XCircle, label: 'Błąd', cls: 'text-[#b42f2f] dark:text-[#f08080]' },
    info: { icon: Info, label: 'Info', cls: 'text-muted-foreground' },
};

function Score({ score }: { score: number }) {
    const label = score >= 80 ? 'Dobry' : score >= 55 ? 'Do poprawy' : 'Słaby';
    return (
        <div className="flex items-center gap-4">
            <div className="text-4xl font-semibold tabular-nums">
                {score}
                <span className="text-muted-foreground text-lg">/100</span>
            </div>
            <div>
                <div className="font-medium">{label} pomiar</div>
                <div className="bg-muted mt-1 h-2 w-48 overflow-hidden rounded-full">
                    <div className="bg-foreground h-full rounded-full" style={{ width: `${score}%` }} />
                </div>
            </div>
        </div>
    );
}

export default function AuditPage({
    client,
    audit,
    history,
}: {
    client: ClientProps;
    audit: Audit | null;
    history: Pick<Audit, 'id' | 'status' | 'score' | 'created_at'>[];
}) {
    const running = audit && (audit.status === 'pending' || audit.status === 'running');

    // odświeżaj, dopóki audyt trwa
    useEffect(() => {
        if (!running) return;
        const t = setInterval(() => router.reload({ only: ['audit', 'history'] }), 4000);
        return () => clearInterval(t);
    }, [running]);

    const r = audit?.results;
    const groups = r ? Object.entries(r.checks.reduce<Record<string, AuditCheck[]>>((acc, c) => ((acc[c.group] ??= []).push(c), acc), {})) : [];

    return (
        <ClientLayout client={client} title="Audyt trackingu">
            <Section
                title="Audyt pomiaru i strony"
                description="Skanuje stronę i publiczne kontenery GTM: GA4, Google Ads, Meta Pixel, Consent Mode v2, technika i szybkość."
                actions={
                    <Button size="sm" disabled={!!running} onClick={() => router.post(`/clients/${client.id}/audit`, {}, { preserveScroll: true })}>
                        {running ? <Loader2 className="size-4 animate-spin" /> : <ScanSearch className="size-4" />}{' '}
                        {running ? 'Trwa audyt…' : 'Uruchom audyt'}
                    </Button>
                }
            >
                {!audit ? (
                    <EmptyState title="Brak audytu">Uruchom pierwszy audyt – zajmie ok. minuty (PageSpeed bywa wolny).</EmptyState>
                ) : audit.status === 'failed' ? (
                    <div className="text-sm text-red-700 dark:text-red-400">Audyt nie powiódł się: {audit.error}</div>
                ) : running ? (
                    <div className="text-muted-foreground flex items-center gap-2 text-sm">
                        <Loader2 className="size-4 animate-spin" /> Skanuję {audit.url}… (wymaga działającego workera kolejki)
                    </div>
                ) : (
                    r && (
                        <div className="flex flex-wrap items-center justify-between gap-6">
                            <Score score={audit.score ?? 0} />
                            <dl className="grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-3">
                                <dt className="text-muted-foreground">Platforma</dt>
                                <dd className="sm:col-span-2">{r.platform ?? 'nie rozpoznano'}</dd>
                                <dt className="text-muted-foreground">GTM</dt>
                                <dd className="sm:col-span-2">{r.detected.gtm.join(', ') || '–'}</dd>
                                <dt className="text-muted-foreground">Inne narzędzia</dt>
                                <dd className="sm:col-span-2">{r.detected.others.join(', ') || '–'}</dd>
                                <dt className="text-muted-foreground">Data</dt>
                                <dd className="sm:col-span-2">{dateTime(audit.created_at)}</dd>
                            </dl>
                        </div>
                    )
                )}
            </Section>

            {r &&
                !running &&
                groups.map(([group, checks]) => (
                    <Section key={group} title={group}>
                        <ul className="divide-y">
                            {checks.map((c) => {
                                const s = STATUS[c.status];
                                return (
                                    <li key={c.key} className="flex gap-3 py-3 first:pt-0 last:pb-0">
                                        <s.icon className={`mt-0.5 size-5 shrink-0 ${s.cls}`} aria-label={s.label} />
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-baseline gap-2">
                                                <span className="font-medium">{c.label}</span>
                                                <span className={`text-xs font-medium ${s.cls}`}>{s.label}</span>
                                            </div>
                                            <div className="text-muted-foreground text-sm">{c.details}</div>
                                            {c.status !== 'ok' && c.fix && <div className="bg-muted/60 mt-2 rounded-md p-2 text-sm">{c.fix}</div>}
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    </Section>
                ))}

            {history.length > 1 && (
                <Section title="Historia audytów">
                    <ul className="text-sm">
                        {history.map((h) => (
                            <li key={h.id} className="flex justify-between border-b py-1.5 last:border-0">
                                <span>{dateTime(h.created_at)}</span>
                                <span className="tabular-nums">{h.status === 'done' ? `${h.score}/100` : h.status}</span>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}
        </ClientLayout>
    );
}
