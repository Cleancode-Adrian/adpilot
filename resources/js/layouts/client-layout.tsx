import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type ClientProps } from '@/types/adpilot';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ExternalLink, FlaskConical, Pencil, RefreshCw } from 'lucide-react';

export default function ClientLayout({ client, title, children }: { client: ClientProps; title: string; children: React.ReactNode }) {
    const { url } = usePage();
    const base = `/clients/${client.id}`;
    const tabs = [
        { href: base, label: 'Przegląd' },
        { href: `${base}/recommendations`, label: `Do zrobienia${client.open_count ? ` (${client.open_count})` : ''}` },
        { href: `${base}/audit`, label: 'Audyt trackingu' },
        { href: `${base}/integrations`, label: 'Integracje' },
        { href: `${base}/reports`, label: 'Raporty' },
    ];
    const path = url.split('?')[0];
    const lastSync = client.integrations.map((i) => i.last_synced_at).filter(Boolean).sort().pop();

    return (
        <AppLayout breadcrumbs={[{ title: 'Klienci', href: '/dashboard' }, { title: client.name, href: base }]}>
            <Head title={`${title} – ${client.name}`} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 text-xl font-semibold">
                            {client.name}
                            {client.is_demo && (
                                <span className="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium">
                                    <FlaskConical className="size-3" /> dane demo
                                </span>
                            )}
                        </h1>
                        <a href={client.website_url} target="_blank" rel="noreferrer" className="text-muted-foreground inline-flex items-center gap-1 text-sm hover:underline">
                            {client.website_url} <ExternalLink className="size-3" />
                        </a>
                        <div className="text-muted-foreground mt-0.5 text-xs">
                            {client.goal === 'leads' ? 'Kontakty (leady)' : client.goal === 'mixed' ? 'Sprzedaż i kontakty' : 'Sprzedaż'} ·{' '}
                            {client.goal !== 'leads' && <>Cel ROAS: {client.target_roas ? `${client.target_roas * 100}%` : '–'} · </>}
                            {client.goal === 'leads' ? 'Cel kosztu leada' : 'Cel CPA'}: {client.target_cpa ?? '–'} {client.currency} · Budżet: {client.daily_budget ? `${client.daily_budget} ${client.currency}/dzień` : `${client.monthly_budget ?? '–'} ${client.currency}/mies.`} · Ostatnia synchronizacja:{' '}
                            {lastSync ? new Date(lastSync).toLocaleString('pl-PL', { dateStyle: 'short', timeStyle: 'short' }) : 'nigdy'}
                        </div>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`${base}/edit`}>
                                <Pencil className="size-4" /> Edytuj
                            </Link>
                        </Button>
                        <Button size="sm" onClick={() => router.post(`${base}/sync`, {}, { preserveScroll: true })}>
                            <RefreshCw className="size-4" /> Synchronizuj
                        </Button>
                    </div>
                </div>

                <nav className="flex gap-1 overflow-x-auto border-b">
                    {tabs.map((t) => (
                        <Link
                            key={t.href}
                            href={t.href}
                            className={cn(
                                '-mb-px border-b-2 px-3 py-2 text-sm whitespace-nowrap transition',
                                path === t.href ? 'border-foreground font-medium' : 'text-muted-foreground hover:text-foreground border-transparent',
                            )}
                        >
                            {t.label}
                        </Link>
                    ))}
                </nav>

                {children}
            </div>
        </AppLayout>
    );
}
