import { ClientCard, type ClientRow } from '@/components/adpilot/client-card';
import { CopyLink } from '@/components/adpilot/copy-link';
import { EmptyState, NativeSelect, Section, useVat } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlarmClock, Archive, ExternalLink, EyeOff, Link2, Mail, Pencil, Phone, PlusCircle, ShieldAlert, Unlink, User, Wallet } from 'lucide-react';
import { useState } from 'react';
import { type AgencyProps } from './form';

type SiteRef = { id: number; name: string; website_url: string };

export default function AgencyShow({
    agency,
    clients,
    archivedClients,
    unassigned,
}: {
    agency: AgencyProps;
    clients: ClientRow[];
    archivedClients: SiteRef[];
    unassigned: SiteRef[];
}) {
    const { admin } = usePage<SharedData>().props;
    const { net } = useVat();
    const [attachId, setAttachId] = useState('');
    const down = clients.filter((c) => c.site_down).length;
    const overdue = clients.reduce((n, c) => n + (c.care?.overdue ?? 0), 0);
    const wp = clients.reduce((n, c) => n + c.wp_alerts, 0);
    const contact = agency.contact_name || agency.contact_email || agency.contact_phone;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Agencje', href: '/agencies' },
                { title: agency.name, href: `/agencies/${agency.id}` },
            ]}
        >
            <Head title={`Agencja – ${agency.name}`} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 text-xl font-semibold">
                            {agency.logo_url && <img src={agency.logo_url} alt="" className="h-8 max-w-28 rounded object-contain" />}
                            {agency.name}
                            {agency.archived_at && (
                                <span className="bg-muted inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-normal">
                                    <Archive className="size-3" /> archiwum
                                </span>
                            )}
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            {clients.length} {clients.length === 1 ? 'obsługiwana strona' : 'obsługiwanych stron'}
                            {down > 0 && ` · ${down} nie działa`}
                            {overdue > 0 && ` · ${overdue} zadań po terminie`}
                            {wp > 0 && ` · WordPress: ${wp} do sprawdzenia`}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {admin && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/agencies/${agency.id}/settlement`}>
                                    <Wallet className="size-4" /> Rozliczenie
                                </Link>
                            </Button>
                        )}
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/agencies/${agency.id}/edit`}>
                                <Pencil className="size-4" /> Edytuj agencję
                            </Link>
                        </Button>
                        <Button size="sm" asChild>
                            <Link href={`/clients/create?agency=${agency.id}`}>
                                <PlusCircle className="size-4" /> Dodaj stronę
                            </Link>
                        </Button>
                    </div>
                </div>

                {agency.white_label && (
                    <div className="flex items-start gap-2 rounded-lg border border-[#a4471f]/40 bg-[#a4471f]/5 px-3 py-2 text-sm">
                        <EyeOff className="mt-0.5 size-4 shrink-0" />
                        <span>
                            <strong>White label.</strong> Raporty i e-maile z raportami dla stron tej agencji idą pod marką agencji (logo, kolor,
                            kontakt). Alerty o awariach i przypomnienia dostajesz tylko Ty. Nie pisz do klientów końcowych bezpośrednio.
                        </span>
                    </div>
                )}

                <div className="grid gap-4 lg:grid-cols-3">
                    <Section title="Kontakt">
                        <ul className="space-y-2 text-sm">
                            {agency.contact_name && (
                                <li className="flex items-center gap-2">
                                    <User className="text-muted-foreground size-4" /> {agency.contact_name}
                                </li>
                            )}
                            {agency.contact_email && (
                                <li className="flex items-center gap-2">
                                    <Mail className="text-muted-foreground size-4" />
                                    <a href={`mailto:${agency.contact_email}`} className="hover:underline">
                                        {agency.contact_email}
                                    </a>
                                </li>
                            )}
                            {agency.contact_phone && (
                                <li className="flex items-center gap-2">
                                    <Phone className="text-muted-foreground size-4" />
                                    <a href={`tel:${agency.contact_phone.replace(/\s/g, '')}`} className="hover:underline">
                                        {agency.contact_phone}
                                    </a>
                                </li>
                            )}
                            {agency.website_url && (
                                <li className="flex items-center gap-2">
                                    <ExternalLink className="text-muted-foreground size-4" />
                                    <a href={agency.website_url} target="_blank" rel="noreferrer noopener" className="truncate hover:underline">
                                        {agency.website_url.replace(/^https?:\/\//, '')}
                                    </a>
                                </li>
                            )}
                            {agency.tax_id && <li className="text-muted-foreground text-xs">NIP: {agency.tax_id}</li>}
                            {agency.hourly_rate !== null && (
                                <li className="text-muted-foreground text-xs">
                                    Stawka: {agency.hourly_rate.toLocaleString('pl-PL')} {agency.currency}/h{net}
                                </li>
                            )}
                            {!contact && (
                                <li className="text-muted-foreground text-xs">
                                    Brak danych kontaktowych –{' '}
                                    <Link href={`/agencies/${agency.id}/edit`} className="underline">
                                        uzupełnij
                                    </Link>
                                    .
                                </li>
                            )}
                        </ul>
                    </Section>
                    <Section title="Warunki współpracy">
                        <p className="text-sm whitespace-pre-line">
                            {agency.terms || <span className="text-muted-foreground">Nie wpisano – stawki, płatności, zakres prac.</span>}
                        </p>
                    </Section>
                    <Section title="Notatki">
                        <p className="text-sm whitespace-pre-line">{agency.notes || <span className="text-muted-foreground">Brak notatek.</span>}</p>
                    </Section>
                    {agency.request_url && (
                        <Section
                            title="Formularz zgłoszeń"
                            description="Wyślij agencji – wybiera stronę ze swojej listy, a zgłoszenie trafia do Ciebie jako zadanie."
                        >
                            <CopyLink url={agency.request_url} />
                        </Section>
                    )}
                </div>

                <div className="flex flex-wrap items-end justify-between gap-3 pt-2">
                    <h2 className="text-lg font-semibold">Obsługiwane strony</h2>
                    {unassigned.length > 0 && (
                        <form
                            className="flex items-center gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (attachId)
                                    router.post(`/agencies/${agency.id}/attach`, { client_id: attachId }, { onSuccess: () => setAttachId('') });
                            }}
                        >
                            <NativeSelect value={attachId} onChange={(e) => setAttachId(e.target.value)} aria-label="Strona do przypisania">
                                <option value="">Przypisz istniejącego klienta…</option>
                                {unassigned.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name} ({c.website_url.replace(/^https?:\/\//, '')})
                                    </option>
                                ))}
                            </NativeSelect>
                            <Button type="submit" variant="outline" size="sm" className="h-9" disabled={!attachId}>
                                <Link2 className="size-4" /> Przypisz
                            </Button>
                        </form>
                    )}
                </div>

                {(down > 0 || overdue > 0) && (
                    <div className="flex flex-wrap gap-3 text-sm">
                        {down > 0 && (
                            <span className="inline-flex items-center gap-1 font-semibold text-[#b42f2f] dark:text-[#f08080]">
                                <ShieldAlert className="size-4" /> {down} {down === 1 ? 'strona nie działa' : 'strony nie działają'}
                            </span>
                        )}
                        {overdue > 0 && (
                            <span className="inline-flex items-center gap-1 font-medium text-[#b42f2f] dark:text-[#f08080]">
                                <AlarmClock className="size-4" /> {overdue} zadań po terminie
                            </span>
                        )}
                    </div>
                )}

                {clients.length === 0 ? (
                    <EmptyState title="Ta agencja nie ma jeszcze stron">
                        <Link href={`/clients/create?agency=${agency.id}`} className="underline">
                            Dodaj pierwszą stronę
                        </Link>
                        {unassigned.length > 0 && ' albo przypisz istniejącego klienta powyżej.'}
                    </EmptyState>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {clients.map((c) => (
                            <div key={c.id} className="group relative">
                                <ClientCard c={c} isAdmin={!!admin} showAgency={false} />
                                <button
                                    type="button"
                                    title="Odepnij od agencji (strona zostaje na liście klientów)"
                                    className="bg-card text-muted-foreground hover:text-foreground absolute right-2 bottom-2 rounded-md border p-1 opacity-0 transition group-hover:opacity-100 focus:opacity-100"
                                    onClick={() =>
                                        confirm(`Odpiąć „${c.name}” od agencji ${agency.name}? Strona zostanie na liście klientów.`) &&
                                        router.delete(`/agencies/${agency.id}/clients/${c.id}`, { preserveScroll: true })
                                    }
                                >
                                    <Unlink className="size-4" />
                                </button>
                            </div>
                        ))}
                    </div>
                )}

                {archivedClients.length > 0 && (
                    <p className="text-muted-foreground text-sm">
                        W archiwum:{' '}
                        {archivedClients.map((c, i) => (
                            <span key={c.id}>
                                {i > 0 && ', '}
                                <Link href={`/clients/${c.id}`} className="hover:underline">
                                    {c.name}
                                </Link>
                            </span>
                        ))}
                    </p>
                )}
            </div>
        </AppLayout>
    );
}
