import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { type ClientProps } from '@/types/adpilot';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlarmClock, ExternalLink, FlaskConical, LogIn, Pencil, RefreshCw, ShieldAlert } from 'lucide-react';

interface Tab {
    href: string;
    label: string;
}

export default function ClientLayout({ client, title, children }: { client: ClientProps; title: string; children: React.ReactNode }) {
    const { url, props } = usePage<SharedData>();
    const base = `/clients/${client.id}`;

    // Zakładki w grupach: klient → opieka/zadania → reklamy (tylko gdy klient ma tę usługę)
    const groups: { label: string; tabs: Tab[] }[] = [
        {
            label: 'Klient',
            tabs: [
                { href: base, label: 'Karta' },
                { href: `${base}/care`, label: `Zadania${client.care?.total ? ` (${client.care.done}/${client.care.total})` : ''}` },
                ...(props.admin
                    ? [{ href: `${base}/credentials`, label: `Dane dostępowe${client.credentials_count ? ` (${client.credentials_count})` : ''}` }]
                    : []),
                { href: `${base}/site`, label: `Strona${client.site?.status === 'down' ? ' ⚠' : ''}` },
                { href: `${base}/wordpress`, label: `WordPress${client.wp?.alerts ? ` (${client.wp.alerts})` : ''}` },
                { href: `${base}/reports`, label: 'Raporty' },
                ...(props.admin ? [{ href: `${base}/billing`, label: 'Oferty i rozliczenia' }] : []),
            ],
        },
        ...(client.has_ads
            ? [
                  {
                      label: 'Reklamy',
                      tabs: [
                          { href: `${base}/ads`, label: 'Wyniki' },
                          { href: `${base}/recommendations`, label: `Zadania reklamowe${client.open_count ? ` (${client.open_count})` : ''}` },
                          { href: `${base}/audit`, label: 'Audyt pomiaru' },
                          { href: `${base}/integrations`, label: 'Integracje' },
                      ],
                  },
              ]
            : []),
    ];

    const care = client.care;
    const careLeft = care ? care.total - care.done : 0;
    const path = url.split('?')[0];
    const showCareBanner = care && (care.overdue > 0 || care.due_today > 0) && path !== `${base}/care`;
    const lastSync = client.integrations
        .map((i) => i.last_synced_at)
        .filter(Boolean)
        .sort()
        .pop();

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Klienci', href: '/clients' },
                { title: client.name, href: base },
            ]}
        >
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
                        <a
                            href={client.website_url}
                            target="_blank"
                            rel="noreferrer"
                            className="text-muted-foreground inline-flex items-center gap-1 text-sm hover:underline"
                        >
                            {client.website_url} <ExternalLink className="size-3" />
                        </a>
                        {client.has_ads && path !== base && (
                            <div className="text-muted-foreground mt-0.5 text-xs">
                                {client.goal === 'leads' ? 'Kontakty (leady)' : client.goal === 'mixed' ? 'Sprzedaż i kontakty' : 'Sprzedaż'} ·{' '}
                                {client.goal !== 'leads' && <>Cel ROAS: {client.target_roas ? `${client.target_roas * 100}%` : '–'} · </>}
                                {client.goal === 'leads' ? 'Cel kosztu leada' : 'Cel CPA'}: {client.target_cpa ?? '–'} {client.currency} · Budżet:{' '}
                                {client.daily_budget
                                    ? `${client.daily_budget} ${client.currency}/dzień`
                                    : `${client.monthly_budget ?? '–'} ${client.currency}/mies.`}{' '}
                                · Ostatnia synchronizacja:{' '}
                                {lastSync ? new Date(lastSync).toLocaleString('pl-PL', { dateStyle: 'short', timeStyle: 'short' }) : 'nigdy'}
                            </div>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {client.admin_url && (
                            <Button variant="outline" size="sm" asChild>
                                <a href={client.admin_url} target="_blank" rel="noreferrer noopener">
                                    <LogIn className="size-4" /> Panel strony
                                </a>
                            </Button>
                        )}
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`${base}/edit`}>
                                <Pencil className="size-4" /> Edytuj
                            </Link>
                        </Button>
                        {client.has_ads && (
                            <Button size="sm" onClick={() => router.post(`${base}/sync`, {}, { preserveScroll: true })}>
                                <RefreshCw className="size-4" /> Synchronizuj
                            </Button>
                        )}
                    </div>
                </div>

                <nav className="flex items-end gap-1 overflow-x-auto border-b">
                    {groups.map((g, gi) => (
                        <div key={g.label} className={cn('flex items-end gap-1', gi > 0 && 'ml-3 border-l pl-3')}>
                            {groups.length > 1 && (
                                <span className="text-muted-foreground pr-1 pb-2.5 text-[11px] font-medium tracking-wide uppercase">{g.label}</span>
                            )}
                            {g.tabs.map((t) => (
                                <Link
                                    key={t.href}
                                    href={t.href}
                                    className={cn(
                                        '-mb-px border-b-2 px-3 py-2 text-sm whitespace-nowrap transition',
                                        path === t.href
                                            ? 'border-foreground font-medium'
                                            : 'text-muted-foreground hover:text-foreground border-transparent',
                                    )}
                                >
                                    {t.label}
                                </Link>
                            ))}
                        </div>
                    ))}
                </nav>

                {client.site?.status === 'down' && path !== `${base}/site` && (
                    <Link
                        href={`${base}/site`}
                        className="flex items-center gap-2 rounded-lg border border-[#d03b3b]/50 bg-[#d03b3b]/10 px-4 py-2.5 text-sm hover:bg-[#d03b3b]/15"
                    >
                        <ShieldAlert className="size-4 shrink-0 text-[#b42f2f] dark:text-[#f08080]" />
                        <span>
                            <strong>Strona nie działa</strong> – monitoring nie dostaje odpowiedzi.
                        </span>
                        <span className="ml-auto font-medium underline-offset-2 hover:underline">Szczegóły →</span>
                    </Link>
                )}

                {client.wp && client.wp.alerts > 0 && path !== `${base}/wordpress` && (
                    <Link
                        href={`${base}/wordpress`}
                        className={cn(
                            'flex items-center gap-2 rounded-lg border px-4 py-2.5 text-sm',
                            client.wp.critical > 0
                                ? 'border-[#d03b3b]/50 bg-[#d03b3b]/10 hover:bg-[#d03b3b]/15'
                                : 'border-amber-500/40 bg-amber-500/10 hover:bg-amber-500/15',
                        )}
                    >
                        <ShieldAlert
                            className={cn(
                                'size-4 shrink-0',
                                client.wp.critical > 0 ? 'text-[#b42f2f] dark:text-[#f08080]' : 'text-amber-700 dark:text-amber-400',
                            )}
                        />
                        <span>
                            <strong>WordPress:</strong> {client.wp.alerts} {client.wp.alerts === 1 ? 'zdarzenie' : 'zdarzeń'} do sprawdzenia
                            {client.wp.critical > 0 && <> (w tym krytyczne: {client.wp.critical})</>}
                        </span>
                        <span className="ml-auto font-medium underline-offset-2 hover:underline">Sprawdź →</span>
                    </Link>
                )}

                {showCareBanner && (
                    <Link
                        href={`${base}/care`}
                        className="flex items-center gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-2.5 text-sm hover:bg-amber-500/15"
                    >
                        <AlarmClock className="size-4 shrink-0 text-amber-700 dark:text-amber-400" />
                        <span>
                            <strong>Zadania:</strong> {care.overdue > 0 && <>{care.overdue} po terminie</>}
                            {care.overdue > 0 && care.due_today > 0 && ', '}
                            {care.due_today > 0 && <>{care.due_today} na dziś</>}
                            {careLeft > 0 && (
                                <>
                                    {' '}
                                    · w tym miesiącu zostało {careLeft} z {care.total}
                                </>
                            )}
                        </span>
                        <span className="ml-auto font-medium underline-offset-2 hover:underline">Przejdź →</span>
                    </Link>
                )}

                {children}
            </div>
        </AppLayout>
    );
}
