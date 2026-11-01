import { Section } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Circle, Mail, RefreshCw, Sparkles, XCircle } from 'lucide-react';

type Status = 'ok' | 'warn' | 'fail' | 'none';
interface Check {
    group: string;
    label: string;
    status: Status;
    details: string;
    fix: string | null;
}
interface ClientRow {
    id: number;
    name: string;
    is_demo: boolean;
    providers: Record<string, { label: string; status: Status; details: string; accounts?: string }>;
    audit: { score: number; date: string } | null;
}

const ICON: Record<Status, { icon: typeof Circle; cls: string; label: string }> = {
    ok: { icon: CheckCircle2, cls: 'text-[#0a7d0a] dark:text-[#4ccc4c]', label: 'Działa' },
    warn: { icon: AlertTriangle, cls: 'text-[#a4471f] dark:text-[#ec835a]', label: 'Uwaga' },
    fail: { icon: XCircle, cls: 'text-[#b42f2f] dark:text-[#f08080]', label: 'Nie działa' },
    none: { icon: Circle, cls: 'text-muted-foreground', label: 'Nie połączono' },
};

function StatusIcon({ s }: { s: Status }) {
    const i = ICON[s];
    return <i.icon className={`size-5 shrink-0 ${i.cls}`} aria-label={i.label} />;
}

export default function SystemStatus({ checks, integrations }: { checks: Check[]; integrations: ClientRow[] }) {
    const groups = Object.entries(checks.reduce<Record<string, Check[]>>((a, c) => ((a[c.group] ??= []).push(c), a), {}));
    const fails = checks.filter((c) => c.status === 'fail').length;
    const warns = checks.filter((c) => c.status === 'warn').length;

    return (
        <AppLayout breadcrumbs={[{ title: 'Status systemu', href: '/admin/status' }]}>
            <Head title="Status systemu" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Status systemu</h1>
                        <p className="text-muted-foreground text-sm">
                            Co jest połączone i skonfigurowane, a co wymaga uwagi.{' '}
                            {fails + warns === 0 ? 'Wszystko działa.' : `Błędy: ${fails}, ostrzeżenia: ${warns}.`}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" onClick={() => router.post('/admin/status/test-mail', {}, { preserveScroll: true })}>
                            <Mail className="size-4" /> Wyślij testowy e-mail
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => router.post('/admin/status/test-ai', {}, { preserveScroll: true })}>
                            <Sparkles className="size-4" /> Testuj AI
                        </Button>
                        <Button variant="ghost" size="sm" onClick={() => router.reload()}>
                            <RefreshCw className="size-4" /> Odśwież
                        </Button>
                    </div>
                </div>

                <Section title="Połączenia klientów" description="Google Ads / GA4 i Meta Ads dla każdego aktywnego klienta">
                    {integrations.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Brak klientów.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-left text-xs">
                                        <th className="py-2 pr-4 font-medium">Klient</th>
                                        <th className="py-2 pr-4 font-medium">Google Ads / GA4</th>
                                        <th className="py-2 pr-4 font-medium">Meta Ads</th>
                                        <th className="py-2 font-medium">Audyt pomiaru</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {integrations.map((c) => (
                                        <tr key={c.id} className="border-b align-top last:border-0">
                                            <td className="py-3 pr-4">
                                                <Link href={`/clients/${c.id}/integrations`} className="font-medium hover:underline">
                                                    {c.name}
                                                </Link>
                                                {c.is_demo && <div className="text-muted-foreground text-xs">dane demo</div>}
                                            </td>
                                            {(['google', 'meta'] as const).map((p) => {
                                                const v = c.providers[p];
                                                return (
                                                    <td key={p} className="py-3 pr-4">
                                                        <div className="flex gap-2">
                                                            <StatusIcon s={v.status} />
                                                            <div className="min-w-0">
                                                                <div className="font-medium">{ICON[v.status].label}</div>
                                                                <div className="text-muted-foreground line-clamp-2 text-xs" title={v.details}>
                                                                    {v.details}
                                                                </div>
                                                                {v.accounts && <div className="text-muted-foreground text-xs">{v.accounts}</div>}
                                                            </div>
                                                        </div>
                                                    </td>
                                                );
                                            })}
                                            <td className="py-3">
                                                {c.audit ? (
                                                    <span className="tabular-nums">{c.audit.score}/100</span>
                                                ) : (
                                                    <span className="text-muted-foreground">brak</span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Section>

                <div className="grid gap-4 lg:grid-cols-2">
                    {groups.map(([group, items]) => (
                        <Section key={group} title={group}>
                            <ul className="divide-y">
                                {items.map((c) => (
                                    <li key={c.label} className="flex gap-3 py-3 first:pt-0 last:pb-0">
                                        <StatusIcon s={c.status} />
                                        <div className="min-w-0 flex-1">
                                            <div className="font-medium">{c.label}</div>
                                            <div className="text-muted-foreground text-sm break-words">{c.details}</div>
                                            {c.status !== 'ok' && c.fix && <div className="bg-muted/60 mt-2 rounded-md p-2 text-sm">{c.fix}</div>}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </Section>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
