import { NativeSelect } from '@/components/adpilot/ui';
import { generatePassword } from '@/components/adpilot/vault';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { type ClientProps } from '@/types/adpilot';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Briefcase, Dices, Eye, EyeOff, Lock, Megaphone, ShieldCheck } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

export default function ClientForm({
    client,
    panel,
    agencies = [],
    agencyId = null,
}: {
    client: ClientProps | null;
    panel?: { username: string | null; has_secret: boolean } | null;
    agencies?: { id: number; name: string }[];
    agencyId?: number | null;
}) {
    const { admin } = usePage<SharedData>().props;
    const [showPassword, setShowPassword] = useState(false);
    const { data, setData, post, put, processing, errors } = useForm({
        agency_id: (client ? client.agency_id : agencyId)?.toString() ?? '',
        name: client?.name ?? '',
        website_url: client?.website_url ?? '',
        admin_url: client?.admin_url ?? '',
        panel_login: panel?.username ?? '',
        panel_password: '',
        industry: client?.industry ?? '',
        contact_name: client?.contact_name ?? '',
        contact_email: client?.contact_email ?? '',
        contact_phone: client?.contact_phone ?? '',
        has_general: client?.has_general ?? true,
        has_care: client?.has_care ?? false,
        has_ads: client?.has_ads ?? false,
        goal: client?.goal ?? 'sales',
        lead_events: client?.lead_events?.join(', ') ?? '',
        currency: client?.currency ?? 'PLN',
        target_roas: client?.target_roas?.toString() ?? '',
        target_cpa: client?.target_cpa?.toString() ?? '',
        monthly_budget: client?.monthly_budget?.toString() ?? '',
        daily_budget: client?.daily_budget?.toString() ?? '',
        auto_send_reports: client?.auto_send_reports ?? false,
        notes: client?.notes ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (client) put(`/clients/${client.id}`);
        else post('/clients');
    };

    const field = (name: keyof typeof data, label: string, props: React.InputHTMLAttributes<HTMLInputElement> = {}, hint?: string) => (
        <div className="grid gap-1.5">
            <Label htmlFor={name}>{label}</Label>
            <Input id={name} value={data[name] as string} onChange={(e) => setData(name, e.target.value)} {...props} />
            {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
            <InputError message={errors[name]} />
        </div>
    );

    const service = (key: 'has_general' | 'has_care' | 'has_ads', Icon: typeof ShieldCheck, label: string, hint: string) => (
        <label className={`flex cursor-pointer gap-3 rounded-lg border p-3 text-sm ${data[key] ? 'border-foreground ring-foreground ring-1' : ''}`}>
            <input type="checkbox" checked={data[key]} onChange={(e) => setData(key, e.target.checked)} className="mt-0.5 size-4" />
            <div>
                <div className="flex items-center gap-1.5 font-medium">
                    <Icon className="size-4" /> {label}
                </div>
                <div className="text-muted-foreground mt-1 text-xs">{hint}</div>
            </div>
        </label>
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Klienci', href: '/clients' },
                { title: client ? 'Edycja' : 'Nowy klient', href: '#' },
            ]}
        >
            <Head title={client ? `Edycja – ${client.name}` : 'Nowy klient'} />
            <form onSubmit={submit} className="max-w-3xl space-y-6 p-4">
                <h1 className="text-xl font-semibold">{client ? 'Edycja klienta' : 'Nowy klient'}</h1>

                <div className="rounded-xl border p-4">
                    <h2 className="mb-4 font-medium">Dane klienta</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {field('name', 'Nazwa klienta', { required: true, placeholder: 'np. Pracownia Zielony Ogród' })}
                        {field('website_url', 'Adres strony', { required: true, placeholder: 'zielony-ogrod.pl' })}
                        {field('industry', 'Branża', { placeholder: 'np. usługi ogrodnicze' })}
                        <div className="grid gap-1.5">
                            <Label htmlFor="agency_id">Zlecenie od agencji</Label>
                            <NativeSelect id="agency_id" value={data.agency_id} onChange={(e) => setData('agency_id', e.target.value)}>
                                <option value="">— mój klient (bez agencji) —</option>
                                {agencies.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.name}
                                    </option>
                                ))}
                            </NativeSelect>
                            <p className="text-muted-foreground text-xs">
                                {agencies.length === 0 ? (
                                    <>
                                        Obsługujesz strony dla agencji?{' '}
                                        <a href="/agencies/create" className="underline">
                                            Dodaj agencję
                                        </a>
                                        .
                                    </>
                                ) : (
                                    'Strona obsługiwana na zlecenie agencji – pojawi się w zakładce Agencje.'
                                )}
                            </p>
                            <InputError message={errors.agency_id} />
                        </div>
                    </div>
                </div>

                <div className="rounded-xl border p-4">
                    <h2 className="mb-1 font-medium">Logowanie do panelu strony</h2>
                    <p className="text-muted-foreground mb-4 flex items-center gap-1.5 text-sm">
                        <Lock className="size-3.5" /> Hasło zapisuje się zaszyfrowane. FTP, hosting, bazę i resztę dodasz w karcie klienta → Dane
                        dostępowe.
                    </p>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">{field('admin_url', 'Adres logowania', { placeholder: 'zielony-ogrod.pl/wp-admin' })}</div>
                        {admin ? (
                            <>
                                {field('panel_login', 'Login', { autoComplete: 'off', placeholder: 'admin' })}
                                <div className="grid gap-1.5">
                                    <Label htmlFor="panel_password">Hasło</Label>
                                    <div className="flex gap-1">
                                        <Input
                                            id="panel_password"
                                            type={showPassword ? 'text' : 'password'}
                                            autoComplete="new-password"
                                            className="font-mono"
                                            value={data.panel_password}
                                            placeholder={panel?.has_secret ? 'zapisane – zostaw puste, by nie zmieniać' : ''}
                                            onChange={(e) => setData('panel_password', e.target.value)}
                                        />
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="h-9"
                                            title={showPassword ? 'Ukryj' : 'Pokaż'}
                                            onClick={() => setShowPassword(!showPassword)}
                                        >
                                            {showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="h-9"
                                            title="Wygeneruj silne hasło"
                                            onClick={() => {
                                                setData('panel_password', generatePassword());
                                                setShowPassword(true);
                                            }}
                                        >
                                            <Dices className="size-4" />
                                        </Button>
                                    </div>
                                    <InputError message={errors.panel_password} />
                                </div>
                            </>
                        ) : (
                            <p className="text-muted-foreground text-xs sm:col-span-2">Login i hasło może zapisać administrator.</p>
                        )}
                    </div>
                </div>

                <div className="rounded-xl border p-4">
                    <h2 className="mb-4 font-medium">Osoba kontaktowa</h2>
                    <div className="grid gap-4 sm:grid-cols-3">
                        {field('contact_name', 'Imię i nazwisko', { placeholder: 'Anna Nowak' })}
                        {field(
                            'contact_email',
                            'E-mail',
                            { type: 'email', placeholder: 'kontakt@zielony-ogrod.pl' },
                            data.has_ads ? 'Na ten adres idą też raporty' : undefined,
                        )}
                        {field('contact_phone', 'Telefon', { type: 'tel', placeholder: '600 000 000' })}
                    </div>
                </div>

                <div className="rounded-xl border p-4">
                    <h2 className="mb-1 font-medium">Usługi</h2>
                    <p className="text-muted-foreground mb-4 text-sm">Zaznacz, czym się zajmujesz u tego klienta. Możesz to zmienić później.</p>
                    <div className="grid gap-3 sm:grid-cols-3">
                        {service(
                            'has_general',
                            Briefcase,
                            'Obsługa ogólna',
                            'Zlecenia, poprawki, wsparcie – zadania doraźne z terminami, notatki, dostępy.',
                        )}
                        {service(
                            'has_care',
                            ShieldCheck,
                            'Opieka nad stroną',
                            'Bezpieczeństwo, aktualizacje, kopie – zadania co miesiąc z przypomnieniami.',
                        )}
                        {service(
                            'has_ads',
                            Megaphone,
                            'Reklamy Google / Meta',
                            'Wyniki kampanii, audyt pomiaru, zadania reklamowe, raporty dla klienta.',
                        )}
                    </div>
                </div>

                {data.has_ads && (
                    <>
                        <div className="rounded-xl border p-4">
                            <h2 className="mb-1 font-medium">Cel reklam</h2>
                            <p className="text-muted-foreground mb-4 text-sm">
                                Od tego zależy, co system liczy jako wynik: zamówienia i przychód (ROAS) czy kontakty – formularze, rejestracje,
                                telefony (koszt leada).
                            </p>
                            <div className="grid gap-3 sm:grid-cols-3">
                                {(
                                    [
                                        ['sales', 'Sprzedaż', 'Sklep internetowy – zamówienia, przychód, ROAS'],
                                        ['leads', 'Kontakty (leady)', 'Usługi, B2B – formularze, rejestracje, telefony, koszt leada'],
                                        ['mixed', 'Sprzedaż i kontakty', 'Sklep + zapytania ofertowe / rejestracje'],
                                    ] as const
                                ).map(([value, label, hint]) => (
                                    <label
                                        key={value}
                                        className={`cursor-pointer rounded-lg border p-3 text-sm ${data.goal === value ? 'border-foreground ring-foreground ring-1' : ''}`}
                                    >
                                        <input
                                            type="radio"
                                            name="goal"
                                            checked={data.goal === value}
                                            onChange={() => setData('goal', value)}
                                            className="mr-2"
                                        />
                                        <span className="font-medium">{label}</span>
                                        <div className="text-muted-foreground mt-1 text-xs">{hint}</div>
                                    </label>
                                ))}
                            </div>
                            {data.goal !== 'sales' && (
                                <div className="mt-4">
                                    {field(
                                        'lead_events',
                                        'Zdarzenia GA4 liczone jako kontakt',
                                        { placeholder: 'generate_lead, form_submit, sign_up, phone_click' },
                                        'Puste = domyślne: generate_lead, form_submit, sign_up, contact, phone_click, click_to_call, submit_lead_form. Wpisz własne nazwy, jeśli strona używa innych.',
                                    )}
                                </div>
                            )}
                        </div>

                        <div className="rounded-xl border p-4">
                            <h2 className="mb-1 font-medium">Cele i budżet kampanii</h2>
                            <p className="text-muted-foreground mb-4 text-sm">
                                Na ich podstawie silnik reguł ocenia kampanie i reklamy. Bez celów system porówna wyniki ze średnią konta.
                            </p>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="currency">Waluta</Label>
                                    <NativeSelect id="currency" value={data.currency} onChange={(e) => setData('currency', e.target.value)}>
                                        {['PLN', 'EUR', 'USD', 'GBP', 'CZK'].map((c) => (
                                            <option key={c}>{c}</option>
                                        ))}
                                    </NativeSelect>
                                </div>
                                {data.goal !== 'leads' &&
                                    field(
                                        'target_roas',
                                        'Docelowy ROAS (×)',
                                        { type: 'number', step: '0.1', min: 0, placeholder: '5' },
                                        'np. 5 = 500% (5 zł przychodu z 1 zł)',
                                    )}
                                {field(
                                    'target_cpa',
                                    data.goal === 'leads' ? `Docelowy koszt leada (${data.currency})` : `Docelowy CPA (${data.currency})`,
                                    { type: 'number', step: '0.01', min: 0, placeholder: data.goal === 'leads' ? '80' : '120' },
                                    data.goal === 'leads' ? 'ile maks. możesz zapłacić za jedno zapytanie' : 'maks. koszt pozyskania zamówienia',
                                )}
                                <div className="grid gap-1.5">
                                    <Label htmlFor="daily_budget">Budżet dzienny ({data.currency})</Label>
                                    <Input
                                        id="daily_budget"
                                        type="number"
                                        step="0.01"
                                        min={0}
                                        placeholder="10"
                                        value={data.daily_budget}
                                        onChange={(e) => {
                                            const v = e.target.value;
                                            setData((d) => ({
                                                ...d,
                                                daily_budget: v,
                                                monthly_budget: v ? String(Math.round(Number(v) * 30.4)) : d.monthly_budget,
                                            }));
                                        }}
                                    />
                                    <p className="text-muted-foreground text-xs">łącznie Google + Meta; miesięczny przeliczy się sam</p>
                                    <InputError message={errors.daily_budget} />
                                </div>
                                {field(
                                    'monthly_budget',
                                    `Budżet miesięczny (${data.currency})`,
                                    { type: 'number', step: '1', min: 0 },
                                    'łącznie Google + Meta',
                                )}
                            </div>
                            <label className="mt-4 flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={data.auto_send_reports}
                                    onChange={(e) => setData('auto_send_reports', e.target.checked)}
                                    className="size-4"
                                />
                                Wysyłaj klientowi automatycznie raport miesięczny z reklam (2. dnia miesiąca, na e-mail kontaktowy)
                            </label>
                            {data.auto_send_reports && data.agency_id && (
                                <p className="mt-2 text-xs text-[#a4471f] dark:text-[#ec835a]">
                                    Ta strona jest obsługiwana na zlecenie agencji – upewnij się, że raport może iść do klienta końcowego (przy white
                                    label wpisz e-mail agencji albo wyłącz wysyłkę).
                                </p>
                            )}
                        </div>
                    </>
                )}

                <div className="grid gap-1.5">
                    <Label htmlFor="notes">Notatki</Label>
                    <textarea
                        id="notes"
                        rows={5}
                        value={data.notes}
                        onChange={(e) => setData('notes', e.target.value)}
                        className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                        placeholder="Ustalenia z klientem, zakres usług, cena abonamentu, hosting, na co uważać… (bez haseł – te w zakładce Dostępy)"
                    />
                </div>

                <div className="flex items-center justify-between">
                    <Button type="submit" disabled={processing}>
                        {client ? 'Zapisz zmiany' : data.has_ads ? 'Dodaj klienta i uruchom audyt' : 'Dodaj klienta'}
                    </Button>
                    {client && (
                        <div className="flex gap-2">
                            <Button type="button" variant="outline" onClick={() => router.post(`/clients/${client.id}/archive`)}>
                                {client.archived_at ? 'Przywróć z archiwum' : 'Archiwizuj'}
                            </Button>
                            <Button
                                type="button"
                                variant="destructive"
                                onClick={() =>
                                    confirm(
                                        `Usunąć klienta „${client.name}” wraz ze wszystkimi danymi, zadaniami, dostępami i raportami? Tego nie da się cofnąć.`,
                                    ) && router.delete(`/clients/${client.id}`)
                                }
                            >
                                Usuń klienta
                            </Button>
                        </div>
                    )}
                </div>
            </form>
        </AppLayout>
    );
}
