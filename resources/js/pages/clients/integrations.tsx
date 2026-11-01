import { NativeSelect, Section } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ClientLayout from '@/layouts/client-layout';
import { dateTime } from '@/lib/format';
import { type ClientProps, type Integration } from '@/types/adpilot';
import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Link2, Unplug } from 'lucide-react';

type Account = { id: string; name: string };

function Status({ integration }: { integration?: Integration }) {
    if (!integration) return <span className="text-muted-foreground text-sm">Nie połączono</span>;
    if (integration.last_error)
        return (
            <span className="inline-flex items-center gap-1 text-sm text-red-700 dark:text-red-400">
                <AlertTriangle className="size-4" /> Błąd
            </span>
        );
    if (integration.status === 'connected')
        return (
            <span className="inline-flex items-center gap-1 text-sm text-green-700 dark:text-green-400">
                <CheckCircle2 className="size-4" /> Połączono · sync {dateTime(integration.last_synced_at)}
            </span>
        );
    return <span className="text-sm text-amber-700 dark:text-amber-400">Wybierz konto</span>;
}

function AccountPicker({ label, accounts, value, onChange, placeholder }: { label: string; accounts: Account[]; value: string; onChange: (v: string) => void; placeholder: string }) {
    return (
        <div className="grid gap-1.5">
            <Label>{label}</Label>
            {accounts.length ? (
                <NativeSelect value={value} onChange={(e) => onChange(e.target.value)}>
                    <option value="">— wybierz —</option>
                    {accounts.map((a) => (
                        <option key={a.id} value={a.id}>
                            {a.name} ({a.id})
                        </option>
                    ))}
                </NativeSelect>
            ) : (
                <Input value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} />
            )}
        </div>
    );
}

export default function Integrations({
    client,
    accounts,
    redirects,
    ads_token_configured,
}: {
    client: ClientProps;
    accounts: { google?: { google_ads?: Account[]; ga4?: Account[] }; meta?: { meta_ads?: Account[] } };
    redirects: { google: string; meta: string };
    ads_token_configured: boolean;
}) {
    const google = client.integrations.find((i) => i.provider === 'google');
    const meta = client.integrations.find((i) => i.provider === 'meta');

    const gForm = useForm({
        customer_id: String(google?.settings?.customer_id ?? ''),
        login_customer_id: String(google?.settings?.login_customer_id ?? ''),
        ga4_property_id: String(google?.settings?.ga4_property_id ?? ''),
    });
    const mForm = useForm({ ad_account_id: String(meta?.settings?.ad_account_id ?? '') });

    const disconnect = (i?: Integration) =>
        i && confirm('Odłączyć integrację? Pobrane dane zostaną.') && router.delete(`/clients/${client.id}/integrations/${i.id}`, { preserveScroll: true });

    return (
        <ClientLayout client={client} title="Integracje">
            {client.is_demo && (
                <div className="rounded-lg border border-dashed p-3 text-sm">To klient demo – dane są generowane. Dla prawdziwego klienta połącz konta poniżej.</div>
            )}

            <Section
                title="Google (Google Ads + Analytics 4)"
                description="Jedno logowanie Google daje dostęp do kont Ads i usług GA4, do których ma dostęp zalogowany użytkownik."
                actions={<Status integration={google} />}
            >
                {!client.google_configured ? (
                    <SetupHint
                        lines={[
                            'Utwórz projekt w Google Cloud Console, włącz Google Ads API, Google Analytics Data API i Admin API.',
                            'Utwórz dane logowania OAuth (aplikacja internetowa) i dodaj URI przekierowania:',
                            redirects.google,
                            'Wpisz GOOGLE_CLIENT_ID i GOOGLE_CLIENT_SECRET do pliku .env.',
                        ]}
                    />
                ) : (
                    <div className="space-y-4">
                        {!ads_token_configured && (
                            <p className="text-sm text-amber-700 dark:text-amber-400">
                                Brak GOOGLE_ADS_DEVELOPER_TOKEN w .env – GA4 zadziała, ale Google Ads wymaga developer tokena z konta MCC.
                            </p>
                        )}
                        <div className="flex gap-2">
                            <Button asChild>
                                <a href={`/oauth/google/${client.id}`}>
                                    <Link2 className="size-4" /> {google ? 'Połącz ponownie' : 'Połącz z Google'}
                                </a>
                            </Button>
                            {google && (
                                <Button variant="ghost" onClick={() => disconnect(google)}>
                                    <Unplug className="size-4" /> Odłącz
                                </Button>
                            )}
                        </div>
                        {google?.last_error && <p className="text-sm text-red-700 dark:text-red-400">{google.last_error}</p>}
                        {google && (
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    gForm.put(`/clients/${client.id}/integrations/google`, { preserveScroll: true });
                                }}
                                className="grid gap-4 sm:grid-cols-3"
                            >
                                <AccountPicker
                                    label="Konto Google Ads"
                                    accounts={accounts.google?.google_ads ?? []}
                                    value={gForm.data.customer_id}
                                    onChange={(v) => gForm.setData('customer_id', v)}
                                    placeholder="123-456-7890"
                                />
                                <div className="grid gap-1.5">
                                    <Label>Konto MCC (login-customer-id)</Label>
                                    <Input value={gForm.data.login_customer_id} onChange={(e) => gForm.setData('login_customer_id', e.target.value)} placeholder="opcjonalnie" />
                                </div>
                                <AccountPicker
                                    label="Usługa GA4"
                                    accounts={accounts.google?.ga4 ?? []}
                                    value={gForm.data.ga4_property_id}
                                    onChange={(v) => gForm.setData('ga4_property_id', v)}
                                    placeholder="ID usługi, np. 312345678"
                                />
                                <div className="sm:col-span-3">
                                    <Button type="submit" disabled={gForm.processing}>
                                        Zapisz i synchronizuj
                                    </Button>
                                </div>
                            </form>
                        )}
                    </div>
                )}
            </Section>

            <Section title="Meta (Facebook / Instagram Ads)" description="Wydatki, wyniki kampanii i reklam, częstotliwość, zakupy z Pixela/CAPI." actions={<Status integration={meta} />}>
                {!client.meta_configured ? (
                    <SetupHint
                        lines={[
                            'Utwórz aplikację typu „Firma” w Meta for Developers i dodaj produkt „Marketing API”.',
                            'Dodaj „Facebook Login for Business” z adresem przekierowania:',
                            redirects.meta,
                            'Wpisz META_APP_ID i META_APP_SECRET do .env. Do pracy z kontami klientów potrzebne jest App Review (ads_read) i weryfikacja firmy.',
                        ]}
                    />
                ) : (
                    <div className="space-y-4">
                        <div className="flex gap-2">
                            <Button asChild>
                                <a href={`/oauth/meta/${client.id}`}>
                                    <Link2 className="size-4" /> {meta ? 'Połącz ponownie' : 'Połącz z Meta'}
                                </a>
                            </Button>
                            {meta && (
                                <Button variant="ghost" onClick={() => disconnect(meta)}>
                                    <Unplug className="size-4" /> Odłącz
                                </Button>
                            )}
                        </div>
                        {meta?.last_error && <p className="text-sm text-red-700 dark:text-red-400">{meta.last_error}</p>}
                        {meta && (
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    mForm.put(`/clients/${client.id}/integrations/meta`, { preserveScroll: true });
                                }}
                                className="grid gap-4 sm:grid-cols-3"
                            >
                                <AccountPicker
                                    label="Konto reklamowe"
                                    accounts={accounts.meta?.meta_ads ?? []}
                                    value={mForm.data.ad_account_id}
                                    onChange={(v) => mForm.setData('ad_account_id', v)}
                                    placeholder="act_1234567890"
                                />
                                <div className="flex items-end">
                                    <Button type="submit" disabled={mForm.processing}>
                                        Zapisz i synchronizuj
                                    </Button>
                                </div>
                            </form>
                        )}
                    </div>
                )}
            </Section>

            <Section title="GTM, Pixel, Consent Mode" description="Nie wymagają logowania – sprawdzamy je automatycznie w zakładce Audyt trackingu.">
                <p className="text-muted-foreground text-sm">
                    Audyt wykrywa kontenery GTM i analizuje ich zawartość (tagi GA4, konwersje Ads, Pixel, ustawienia zgód), bez dostępu do konta GTM klienta.
                </p>
            </Section>
        </ClientLayout>
    );
}

function SetupHint({ lines }: { lines: string[] }) {
    return (
        <div className="space-y-1 text-sm">
            <p className="font-medium">Konfiguracja jednorazowa (dla całej agencji):</p>
            <ol className="text-muted-foreground list-decimal space-y-1 pl-5">
                {lines.map((l, i) =>
                    l.startsWith('http') ? (
                        <li key={i} className="list-none">
                            <code className="bg-muted text-foreground rounded px-1.5 py-0.5 text-xs">{l}</code>
                        </li>
                    ) : (
                        <li key={i}>{l}</li>
                    ),
                )}
            </ol>
        </div>
    );
}
