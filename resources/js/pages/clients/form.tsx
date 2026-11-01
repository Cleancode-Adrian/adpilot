import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type ClientProps } from '@/types/adpilot';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function ClientForm({ client }: { client: ClientProps | null }) {
    const { data, setData, post, put, processing, errors } = useForm({
        name: client?.name ?? '',
        website_url: client?.website_url ?? '',
        industry: client?.industry ?? '',
        goal: client?.goal ?? 'sales',
        lead_events: client?.lead_events?.join(', ') ?? '',
        currency: client?.currency ?? 'PLN',
        target_roas: client?.target_roas?.toString() ?? '',
        target_cpa: client?.target_cpa?.toString() ?? '',
        monthly_budget: client?.monthly_budget?.toString() ?? '',
        daily_budget: client?.daily_budget?.toString() ?? '',
        contact_email: client?.contact_email ?? '',
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

    return (
        <AppLayout breadcrumbs={[{ title: 'Klienci', href: '/dashboard' }, { title: client ? 'Edycja' : 'Nowy klient', href: '#' }]}>
            <Head title={client ? `Edycja – ${client.name}` : 'Nowy klient'} />
            <form onSubmit={submit} className="max-w-3xl space-y-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">{client ? 'Edycja klienta' : 'Nowy klient'}</h1>
                    <p className="text-muted-foreground text-sm">
                        Po zapisaniu system od razu przeskanuje stronę (GTM, GA4, Pixel, Consent Mode). Konta reklamowe podłączysz w kolejnym kroku.
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    {field('name', 'Nazwa klienta / sklepu', { required: true, placeholder: 'np. Sklep Ogrodowy Raj' })}
                    {field('website_url', 'Adres strony', { required: true, placeholder: 'https://sklep.pl' })}
                    {field('industry', 'Branża', { placeholder: 'np. meble ogrodowe' })}
                    <div className="grid gap-1.5">
                        <Label htmlFor="currency">Waluta</Label>
                        <NativeSelect id="currency" value={data.currency} onChange={(e) => setData('currency', e.target.value)}>
                            {['PLN', 'EUR', 'USD', 'GBP', 'CZK'].map((c) => (
                                <option key={c}>{c}</option>
                            ))}
                        </NativeSelect>
                    </div>
                </div>

                <div className="rounded-xl border p-4">
                    <h2 className="mb-1 font-medium">Cel reklam</h2>
                    <p className="text-muted-foreground mb-4 text-sm">
                        Od tego zależy, co system liczy jako wynik: zamówienia i przychód (ROAS) czy kontakty – formularze, rejestracje, telefony (koszt leada).
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
                                <input type="radio" name="goal" checked={data.goal === value} onChange={() => setData('goal', value)} className="mr-2" />
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
                    <h2 className="mb-1 font-medium">Cele kampanii</h2>
                    <p className="text-muted-foreground mb-4 text-sm">Na ich podstawie silnik reguł ocenia kampanie i reklamy. Bez celów system porówna wyniki ze średnią konta.</p>
                    <div className="grid gap-4 sm:grid-cols-3">
                        {data.goal !== 'leads' &&
                            field('target_roas', 'Docelowy ROAS (×)', { type: 'number', step: '0.1', min: 0, placeholder: '5' }, 'np. 5 = 500% (5 zł przychodu z 1 zł)')}
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
                                    setData((d) => ({ ...d, daily_budget: v, monthly_budget: v ? String(Math.round(Number(v) * 30.4)) : d.monthly_budget }));
                                }}
                            />
                            <p className="text-muted-foreground text-xs">łącznie Google + Meta; miesięczny przeliczy się sam</p>
                            <InputError message={errors.daily_budget} />
                        </div>
                        {field('monthly_budget', `Budżet miesięczny (${data.currency})`, { type: 'number', step: '1', min: 0 }, 'łącznie Google + Meta')}
                    </div>
                </div>

                <div className="rounded-xl border p-4">
                    <h2 className="mb-4 font-medium">Raporty dla klienta</h2>
                    <div className="grid gap-4">
                        {field('contact_email', 'E-mail do raportów', { type: 'email', placeholder: 'wlasciciel@sklep.pl' })}
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={data.auto_send_reports} onChange={(e) => setData('auto_send_reports', e.target.checked)} className="size-4" />
                            Wysyłaj automatycznie raport miesięczny (2. dnia miesiąca)
                        </label>
                    </div>
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="notes">Notatki</Label>
                    <textarea
                        id="notes"
                        rows={4}
                        value={data.notes}
                        onChange={(e) => setData('notes', e.target.value)}
                        className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                        placeholder="Marże, sezonowość, ustalenia z klientem…"
                    />
                </div>

                <div className="flex items-center justify-between">
                    <Button type="submit" disabled={processing}>
                        {client ? 'Zapisz zmiany' : 'Dodaj klienta i uruchom audyt'}
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
                                    confirm(`Usunąć klienta „${client.name}” wraz ze wszystkimi danymi i raportami? Tego nie da się cofnąć.`) &&
                                    router.delete(`/clients/${client.id}`)
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
