import { CareProgress, CareTaskRow, localDate } from '@/components/adpilot/care-task-row';
import { Section } from '@/components/adpilot/ui';
import { CopyButton, SecretValue } from '@/components/adpilot/vault';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import ClientLayout from '@/layouts/client-layout';
import { money, num } from '@/lib/format';
import { type SharedData } from '@/types';
import { type CareTask, type ClientProps } from '@/types/adpilot';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Briefcase, Check, ExternalLink, KeyRound, LogIn, Mail, Megaphone, Pencil, Phone, Plus, ShieldCheck, User } from 'lucide-react';
import { useState } from 'react';

type Kpi = { value: number | null };
interface AdsKpis {
    ads: { cost: Kpi; clicks: Kpi; conversions: Kpi; cpa: Kpi };
}

/** Notatki z autozapisem po wyjściu z pola. */
function Notes({ client }: { client: ClientProps }) {
    const [value, setValue] = useState(client.notes ?? '');
    const [state, setState] = useState<'idle' | 'saving' | 'saved'>('idle');
    const save = () => {
        if (value === (client.notes ?? '')) return;
        setState('saving');
        router.patch(
            `/clients/${client.id}/notes`,
            { notes: value || null },
            { preserveScroll: true, preserveState: true, onSuccess: () => setState('saved'), onError: () => setState('idle') },
        );
    };
    return (
        <Section
            title="Notatki"
            description="Ustalenia, zakres usług, cena, hosting, na co uważać. Hasła trzymaj w zakładce Dostępy."
            actions={
                <span className="text-muted-foreground text-xs" aria-live="polite">
                    {state === 'saving' ? 'Zapisywanie…' : state === 'saved' ? '✓ Zapisano' : ''}
                </span>
            }
        >
            <textarea
                value={value}
                onChange={(e) => {
                    setValue(e.target.value);
                    setState('idle');
                }}
                onBlur={save}
                rows={Math.min(24, Math.max(8, value.split('\n').length + 2))}
                className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm leading-relaxed"
                placeholder={
                    'np.\n• Abonament: 300 zł/mies., faktura do 5.\n• Hosting: cyber_Folks, pakiet…\n• Klient prosi o kontakt mailowy, nie telefoniczny.'
                }
            />
        </Section>
    );
}

function QuickTask({ clientId }: { clientId: number }) {
    const form = useForm({ title: '', due_date: localDate() });
    return (
        <form
            className="mt-3 flex flex-wrap gap-2 border-t pt-3"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/clients/${clientId}/care/tasks`, { preserveScroll: true, onSuccess: () => form.reset('title') });
            }}
        >
            <Input
                required
                value={form.data.title}
                onChange={(e) => form.setData('title', e.target.value)}
                placeholder="Nowe zadanie, np. wymienić baner"
                className="min-w-48 flex-1"
            />
            <Input type="date" required value={form.data.due_date} onChange={(e) => form.setData('due_date', e.target.value)} className="w-40" />
            <Button type="submit" size="sm" className="h-9" disabled={form.processing}>
                <Plus className="size-4" /> Dodaj
            </Button>
        </form>
    );
}

export default function ClientCard({ client, upcoming, adsKpis }: { client: ClientProps; upcoming: CareTask[]; adsKpis: AdsKpis | null }) {
    const { admin } = usePage<SharedData>().props;
    const base = `/clients/${client.id}`;
    const contact = [client.contact_name, client.contact_email, client.contact_phone].some(Boolean);

    return (
        <ClientLayout client={client} title="Karta klienta">
            <div className="grid gap-4 lg:grid-cols-[1fr_380px]">
                <div className="grid content-start gap-4">
                    <Section
                        title="Zadania"
                        description={
                            client.care?.total
                                ? undefined
                                : 'Jednorazowe zadania dodasz tutaj. Stały plan co miesiąc ustawisz w zakładce „Zadania i opieka”.'
                        }
                        actions={
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={`${base}/care`}>Wszystkie i plan →</Link>
                            </Button>
                        }
                    >
                        {client.care && client.care.total > 0 && (
                            <div className="mb-3">
                                <CareProgress done={client.care.done} total={client.care.total} />
                            </div>
                        )}
                        {upcoming.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Nic do zrobienia na teraz. ✓</p>
                        ) : (
                            <ul className="grid gap-2">
                                {upcoming.map((t) => (
                                    <CareTaskRow key={t.id} task={t} showPeriod={t.period !== client.care?.period} />
                                ))}
                            </ul>
                        )}
                        <QuickTask clientId={client.id} />
                    </Section>

                    <Notes key={client.id} client={client} />
                </div>

                <div className="grid content-start gap-4">
                    <Section title="Logowanie do strony">
                        <div className="grid gap-3">
                            {client.admin_url ? (
                                <Button asChild>
                                    <a href={client.admin_url} target="_blank" rel="noreferrer noopener">
                                        <LogIn className="size-4" /> Otwórz panel strony
                                    </a>
                                </Button>
                            ) : (
                                <Button variant="outline" asChild>
                                    <Link href={`${base}/edit`}>
                                        <Pencil className="size-4" /> Dodaj adres panelu (np. /wp-admin)
                                    </Link>
                                </Button>
                            )}
                            {client.admin_url && <div className="text-muted-foreground -mt-1 truncate text-xs">{client.admin_url}</div>}

                            {admin &&
                                (client.panel ? (
                                    <dl className="grid grid-cols-[auto_1fr] items-center gap-x-3 gap-y-1 rounded-lg border px-3 py-2 text-sm">
                                        <dt className="text-muted-foreground text-xs">Login</dt>
                                        <dd className="flex min-w-0 items-center gap-1">
                                            {client.panel.username ? (
                                                <>
                                                    <span className="truncate font-mono">{client.panel.username}</span>
                                                    <CopyButton value={client.panel.username} label="Kopiuj login" />
                                                </>
                                            ) : (
                                                <span className="text-muted-foreground text-xs">brak</span>
                                            )}
                                        </dd>
                                        <dt className="text-muted-foreground text-xs">Hasło</dt>
                                        <dd>
                                            {client.panel.has_secret ? (
                                                <SecretValue id={client.panel.id} />
                                            ) : (
                                                <span className="text-muted-foreground text-xs">brak</span>
                                            )}
                                        </dd>
                                    </dl>
                                ) : (
                                    <Link href={`${base}/edit`} className="text-muted-foreground text-xs underline-offset-2 hover:underline">
                                        + Zapisz login i hasło do panelu
                                    </Link>
                                ))}

                            {admin && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={`${base}/credentials`}>
                                        <KeyRound className="size-4" /> FTP, hosting, baza i pozostałe
                                        {client.credentials_count ? ` (${client.credentials_count})` : ''}
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </Section>

                    <Section
                        title="Kontakt"
                        actions={
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={`${base}/edit`} aria-label="Edytuj dane">
                                    <Pencil className="size-4" />
                                </Link>
                            </Button>
                        }
                    >
                        <ul className="grid gap-2 text-sm">
                            {client.contact_name && (
                                <li className="flex items-center gap-2">
                                    <User className="text-muted-foreground size-4" /> {client.contact_name}
                                </li>
                            )}
                            {client.contact_email && (
                                <li className="flex items-center gap-2">
                                    <Mail className="text-muted-foreground size-4" />
                                    <a href={`mailto:${client.contact_email}`} className="hover:underline">
                                        {client.contact_email}
                                    </a>
                                </li>
                            )}
                            {client.contact_phone && (
                                <li className="flex items-center gap-2">
                                    <Phone className="text-muted-foreground size-4" />
                                    <a href={`tel:${client.contact_phone.replace(/\s/g, '')}`} className="hover:underline">
                                        {client.contact_phone}
                                    </a>
                                </li>
                            )}
                            <li className="flex items-center gap-2">
                                <ExternalLink className="text-muted-foreground size-4" />
                                <a href={client.website_url} target="_blank" rel="noreferrer" className="truncate hover:underline">
                                    {client.website_url.replace(/^https?:\/\//, '')}
                                </a>
                            </li>
                            {!contact && (
                                <li className="text-muted-foreground text-xs">
                                    Brak danych kontaktowych –{' '}
                                    <Link href={`${base}/edit`} className="underline">
                                        uzupełnij
                                    </Link>
                                    .
                                </li>
                            )}
                            {client.industry && <li className="text-muted-foreground text-xs">Branża: {client.industry}</li>}
                        </ul>
                    </Section>

                    <Section
                        title="Strona"
                        actions={
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={`${base}/site`}>Szczegóły →</Link>
                            </Button>
                        }
                    >
                        <ul className="grid gap-1.5 text-sm">
                            <li className="flex justify-between gap-2">
                                <span className="text-muted-foreground">Dostępność</span>
                                <span className="font-medium">
                                    {client.site.status === 'up'
                                        ? '✓ działa'
                                        : client.site.status === 'down'
                                          ? '✗ nie działa'
                                          : client.site.status === 'unknown'
                                            ? 'pierwszy pomiar…'
                                            : 'monitoring wyłączony'}
                                    {client.site.last_response_ms ? ` · ${client.site.last_response_ms} ms` : ''}
                                </span>
                            </li>
                            <li className="flex justify-between gap-2">
                                <span className="text-muted-foreground">Certyfikat SSL</span>
                                <span className="font-medium">
                                    {client.site.ssl_days === null ? '–' : client.site.ssl_days < 0 ? 'wygasł' : `${client.site.ssl_days} dni`}
                                </span>
                            </li>
                            <li className="flex justify-between gap-2">
                                <span className="text-muted-foreground">Audyt SEO</span>
                                <span className="font-medium">
                                    {client.site.audit_score === null ? 'nie było' : `${client.site.audit_score}/100`}
                                </span>
                            </li>
                        </ul>
                    </Section>

                    <Section title="Usługi">
                        <div className="grid gap-3 text-sm">
                            <div className="flex items-center justify-between gap-2">
                                <span className="flex items-center gap-2">
                                    <Briefcase className="size-4" /> Obsługa ogólna
                                </span>
                                {client.has_general ? (
                                    <Link href={`${base}/care`} className="hover:underline">
                                        zadania →
                                    </Link>
                                ) : (
                                    <span className="text-muted-foreground text-xs">nie</span>
                                )}
                            </div>
                            <div className="flex items-center justify-between gap-2">
                                <span className="flex items-center gap-2">
                                    <ShieldCheck className="size-4" /> Opieka nad stroną
                                </span>
                                {client.has_care ? (
                                    <Link href={`${base}/care`} className="hover:underline">
                                        {client.care?.total ? `${client.care.done}/${client.care.total} w tym mies.` : 'ustaw plan →'}
                                    </Link>
                                ) : (
                                    <span className="text-muted-foreground text-xs">nie</span>
                                )}
                            </div>
                            <div className="flex items-center justify-between gap-2">
                                <span className="flex items-center gap-2">
                                    <Megaphone className="size-4" /> Reklamy Google / Meta
                                </span>
                                {client.has_ads ? (
                                    <Link href={`${base}/ads`} className="hover:underline">
                                        wyniki →
                                    </Link>
                                ) : (
                                    <span className="text-muted-foreground text-xs">nie</span>
                                )}
                            </div>
                            {adsKpis && (
                                <div className="bg-muted/50 grid grid-cols-2 gap-2 rounded-lg p-3 text-xs">
                                    <div>
                                        <div className="text-muted-foreground">Wydatki 30 dni</div>
                                        <div className="font-semibold tabular-nums">{money(adsKpis.ads.cost.value, client.currency)}</div>
                                    </div>
                                    <div>
                                        <div className="text-muted-foreground">Kliknięcia</div>
                                        <div className="font-semibold tabular-nums">{num(adsKpis.ads.clicks.value)}</div>
                                    </div>
                                    <div>
                                        <div className="text-muted-foreground">{client.goal === 'sales' ? 'Konwersje' : 'Wyniki'}</div>
                                        <div className="font-semibold tabular-nums">{num(adsKpis.ads.conversions.value, 1)}</div>
                                    </div>
                                    <div>
                                        <div className="text-muted-foreground">Zadania reklamowe</div>
                                        <Link href={`${base}/recommendations`} className="font-semibold tabular-nums hover:underline">
                                            {client.open_count}
                                        </Link>
                                    </div>
                                </div>
                            )}
                            {(!client.has_general || !client.has_care || !client.has_ads) && (
                                <Link href={`${base}/edit`} className="text-muted-foreground text-xs underline-offset-2 hover:underline">
                                    Zmień usługi w edycji klienta
                                </Link>
                            )}
                        </div>
                    </Section>
                    <p className="text-muted-foreground flex items-center gap-1 text-xs">
                        <Check className="size-3" /> Notatki zapisują się same po kliknięciu poza polem.
                    </p>
                </div>
            </div>
        </ClientLayout>
    );
}
