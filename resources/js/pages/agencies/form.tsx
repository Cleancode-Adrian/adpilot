import { NativeSelect, useVat } from '@/components/adpilot/ui';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export interface AgencyProps {
    id: number;
    name: string;
    website_url: string | null;
    tax_id: string | null;
    billing_name?: string | null;
    billing_street?: string | null;
    billing_post_code?: string | null;
    billing_city?: string | null;
    billing_email?: string | null;
    request_url?: string;
    contact_name: string | null;
    contact_email: string | null;
    contact_phone: string | null;
    white_label: boolean;
    logo_url?: string | null;
    brand_color: string | null;
    report_footer: string | null;
    hourly_rate: number | null;
    currency: string;
    terms: string | null;
    notes: string | null;
    archived_at: string | null;
}

export default function AgencyForm({ agency }: { agency: AgencyProps | null }) {
    const { admin } = usePage<SharedData>().props;
    const { net } = useVat();
    const { data, setData, post, processing, errors, transform } = useForm({
        name: agency?.name ?? '',
        website_url: agency?.website_url ?? '',
        tax_id: agency?.tax_id ?? '',
        billing_name: agency?.billing_name ?? '',
        billing_street: agency?.billing_street ?? '',
        billing_post_code: agency?.billing_post_code ?? '',
        billing_city: agency?.billing_city ?? '',
        billing_email: agency?.billing_email ?? '',
        contact_name: agency?.contact_name ?? '',
        contact_email: agency?.contact_email ?? '',
        contact_phone: agency?.contact_phone ?? '',
        white_label: agency?.white_label ?? false,
        brand_color: agency?.brand_color ?? '#2a78d6',
        report_footer: agency?.report_footer ?? '',
        hourly_rate: agency?.hourly_rate?.toString() ?? '',
        currency: agency?.currency ?? 'PLN',
        logo: null as File | null,
        remove_logo: false as boolean,
        terms: agency?.terms ?? '',
        notes: agency?.notes ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        // plik logo wymaga multipart – przy edycji PUT przez _method
        transform((d) => ({ ...d, ...(agency ? { _method: 'put' } : {}) }));
        post(agency ? `/agencies/${agency.id}` : '/agencies', { forceFormData: true });
    };

    type TextKey = Exclude<keyof typeof data, 'white_label' | 'logo' | 'remove_logo'>;
    const field = (name: TextKey, label: string, props: React.InputHTMLAttributes<HTMLInputElement> = {}, hint?: string) => (
        <div className="grid gap-1.5">
            <Label htmlFor={name}>{label}</Label>
            <Input id={name} value={data[name]} onChange={(e) => setData(name, e.target.value)} {...props} />
            {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
            <InputError message={errors[name]} />
        </div>
    );
    const area = (name: 'terms' | 'notes', label: string, placeholder: string, hint?: string) => (
        <div className="grid gap-1.5">
            <Label htmlFor={name}>{label}</Label>
            <textarea
                id={name}
                rows={5}
                value={data[name]}
                onChange={(e) => setData(name, e.target.value)}
                className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                placeholder={placeholder}
            />
            {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
            <InputError message={errors[name]} />
        </div>
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Agencje', href: '/agencies' },
                ...(agency ? [{ title: agency.name, href: `/agencies/${agency.id}` }] : []),
                { title: agency ? 'Edycja' : 'Nowa agencja', href: '#' },
            ]}
        >
            <Head title={agency ? `Edycja – ${agency.name}` : 'Nowa agencja'} />
            <form onSubmit={submit} className="max-w-3xl space-y-6 p-4">
                <h1 className="text-xl font-semibold">{agency ? 'Edycja agencji' : 'Nowa agencja'}</h1>

                <div className="rounded-xl border p-4">
                    <h2 className="mb-4 font-medium">Dane agencji</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {field('name', 'Nazwa agencji', { required: true, placeholder: 'np. Studio Kreatywne XYZ' })}
                        {field('website_url', 'Strona agencji', { placeholder: 'studio-xyz.pl' })}
                        {field('tax_id', 'NIP', { placeholder: '0000000000' })}
                    </div>
                </div>

                <details className="rounded-xl border p-4" open={!!(agency?.billing_name || agency?.billing_street)}>
                    <summary className="cursor-pointer font-medium">Dane do faktury</summary>
                    <p className="text-muted-foreground mt-1 mb-4 text-sm">
                        Pod ręką przy wystawianiu faktury: pełna nazwa, NIP, adres i e-mail do faktur.
                    </p>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {field('billing_name', 'Pełna nazwa firmy', { placeholder: 'np. Zielony Ogród Anna Nowak' })}
                        {field('billing_street', 'Ulica i numer', { placeholder: 'ul. Kwiatowa 5' })}
                        <div className="grid grid-cols-[8rem_1fr] gap-2">
                            {field('billing_post_code', 'Kod', { placeholder: '00-001' })}
                            {field('billing_city', 'Miasto', { placeholder: 'Warszawa' })}
                        </div>
                        {field('billing_email', 'E-mail do faktur', { type: 'email', placeholder: 'faktury@firma.pl' })}
                    </div>
                </details>

                <div className="rounded-xl border p-4">
                    <h2 className="mb-4 font-medium">Osoba kontaktowa</h2>
                    <div className="grid gap-4 sm:grid-cols-3">
                        {field('contact_name', 'Imię i nazwisko', { placeholder: 'Anna Nowak' })}
                        {field('contact_email', 'E-mail', { type: 'email', placeholder: 'anna@studio-xyz.pl' })}
                        {field('contact_phone', 'Telefon', { type: 'tel', placeholder: '600 000 000' })}
                    </div>
                </div>

                <div className="rounded-xl border p-4">
                    <h2 className="mb-4 font-medium">Współpraca</h2>
                    <label
                        className={`mb-4 flex cursor-pointer gap-3 rounded-lg border p-3 text-sm ${data.white_label ? 'border-foreground ring-foreground ring-1' : ''}`}
                    >
                        <input
                            type="checkbox"
                            checked={data.white_label}
                            onChange={(e) => setData('white_label', e.target.checked)}
                            className="mt-0.5 size-4"
                        />
                        <div>
                            <div className="font-medium">White label</div>
                            <div className="text-muted-foreground mt-1 text-xs">
                                Klienci końcowi nie wiedzą, że to Ty wykonujesz pracę. Raporty (reklamy, prace, audyt strony) i e-maile z raportami
                                dla stron tej agencji pójdą pod marką agencji – bez Twojej nazwy i logo.
                            </div>
                        </div>
                    </label>
                    {data.white_label && (
                        <div className="mb-4 grid gap-4 rounded-lg border border-dashed p-3">
                            <p className="text-muted-foreground text-sm">
                                Marka w raportach i e-mailach dla klientów tej agencji (zamiast Twojej): logo, kolor, stopka i kontakt agencji z
                                formularza powyżej. Odpowiedzi na e-maile z raportami trafią do agencji.
                            </p>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="logo">Logo agencji (PNG/JPG/WebP, maks. 2 MB)</Label>
                                    <div className="flex items-center gap-3">
                                        {agency?.logo_url && !data.remove_logo && !data.logo && (
                                            <img src={agency.logo_url} alt="Logo" className="h-10 max-w-32 rounded border object-contain p-1" />
                                        )}
                                        <Input
                                            id="logo"
                                            type="file"
                                            accept="image/png,image/jpeg,image/webp"
                                            onChange={(e) => setData('logo', e.target.files?.[0] ?? null)}
                                        />
                                    </div>
                                    {agency?.logo_url && (
                                        <label className="flex items-center gap-2 text-xs">
                                            <input
                                                type="checkbox"
                                                checked={data.remove_logo}
                                                onChange={(e) => setData('remove_logo', e.target.checked)}
                                            />
                                            usuń logo
                                        </label>
                                    )}
                                    <InputError message={errors.logo} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="brand_color">Kolor marki</Label>
                                    <div className="flex items-center gap-2">
                                        <input
                                            id="brand_color"
                                            type="color"
                                            value={data.brand_color}
                                            onChange={(e) => setData('brand_color', e.target.value)}
                                            className="h-9 w-12 rounded border"
                                        />
                                        <span className="text-muted-foreground font-mono text-xs">{data.brand_color}</span>
                                    </div>
                                    <InputError message={errors.brand_color} />
                                </div>
                            </div>
                            {field('report_footer', 'Stopka raportu', {
                                maxLength: 500,
                                placeholder: 'np. Studio XYZ · Twoja strona w dobrych rękach',
                            })}
                        </div>
                    )}
                    {admin && (
                        <div className="mb-4 grid gap-4 sm:grid-cols-3">
                            {field(
                                'hourly_rate',
                                `Stawka godzinowa${net} (${data.currency})`,
                                { type: 'number', step: '0.01', min: 0, placeholder: '120' },
                                'Do rozliczenia prac bez wpisanej kwoty: czas × stawka.',
                            )}
                            <div className="grid gap-1.5">
                                <Label htmlFor="currency">Waluta</Label>
                                <NativeSelect id="currency" value={data.currency} onChange={(e) => setData('currency', e.target.value)}>
                                    {['PLN', 'EUR', 'USD', 'GBP', 'CZK'].map((c) => (
                                        <option key={c}>{c}</option>
                                    ))}
                                </NativeSelect>
                            </div>
                        </div>
                    )}
                    <div className="grid gap-4">
                        {area(
                            'terms',
                            'Warunki współpracy',
                            'Stawka godzinowa / ryczałt za stronę, termin płatności, zakres (co robisz, a czego nie), czas reakcji, kanał zgłoszeń…',
                        )}
                        {area('notes', 'Notatki', 'Ustalenia, osoby po stronie agencji, na co uważać… (bez haseł – te w Dostępach klienta)')}
                    </div>
                </div>

                <div className="flex items-center justify-between">
                    <Button type="submit" disabled={processing}>
                        {agency ? 'Zapisz zmiany' : 'Dodaj agencję'}
                    </Button>
                    {agency && (
                        <div className="flex gap-2">
                            <Button type="button" variant="outline" onClick={() => router.post(`/agencies/${agency.id}/archive`)}>
                                {agency.archived_at ? 'Przywróć z archiwum' : 'Archiwizuj'}
                            </Button>
                            <Button
                                type="button"
                                variant="destructive"
                                onClick={() =>
                                    confirm(
                                        `Usunąć agencję „${agency.name}”? Jej strony NIE zostaną usunięte – zostaną na liście klientów bez przypisanej agencji.`,
                                    ) && router.delete(`/agencies/${agency.id}`)
                                }
                            >
                                Usuń agencję
                            </Button>
                        </div>
                    )}
                </div>
            </form>
        </AppLayout>
    );
}
