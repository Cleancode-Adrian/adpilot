import { NativeSelect, Section } from '@/components/adpilot/ui';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type Brand } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { PlugZap } from 'lucide-react';

export default function AgencySettings({
    settings,
    brand,
}: {
    settings: Record<string, string | null> & { fakturownia_token_set?: boolean };
    brand: Brand;
}) {
    const form = useForm({
        agency_name: brand.name ?? '',
        agency_email: settings.agency_email ?? brand.email ?? '',
        agency_phone: settings.agency_phone ?? '',
        agency_website: settings.agency_website ?? '',
        brand_color: brand.color ?? '#2a78d6',
        report_footer: settings.report_footer ?? '',
        vat_rate: settings.vat_rate ?? 'zw',
        target_hourly_rate: settings.target_hourly_rate ?? '',
        fakturownia_domain: settings.fakturownia_domain ?? '',
        fakturownia_token: '',
        remove_fakturownia: false as boolean,
        invoice_kind: settings.invoice_kind ?? 'vat',
        invoice_exempt_basis: settings.invoice_exempt_basis ?? '',
        invoice_payment_days: settings.invoice_payment_days ?? '7',
        logo: null as File | null,
        remove_logo: false as boolean,
    });
    const tokenSet = !!settings.fakturownia_token_set;

    const field = (name: 'agency_name' | 'agency_email' | 'agency_phone' | 'agency_website', label: string, placeholder = '') => (
        <div className="grid gap-1.5">
            <Label htmlFor={name}>{label}</Label>
            <Input id={name} value={form.data[name]} placeholder={placeholder} onChange={(e) => form.setData(name, e.target.value)} />
            <InputError message={form.errors[name]} />
        </div>
    );

    return (
        <AppLayout breadcrumbs={[{ title: 'Ustawienia agencji', href: '/admin/settings' }]}>
            <Head title="Ustawienia agencji" />
            <form
                className="flex max-w-3xl flex-col gap-4 p-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/admin/settings', { forceFormData: true, preserveScroll: true });
                }}
            >
                <div>
                    <h1 className="text-xl font-semibold">Ustawienia agencji</h1>
                    <p className="text-muted-foreground text-sm">
                        Nazwa, logo i dane kontaktowe – widoczne w panelu, raportach dla klientów i e-mailach.
                    </p>
                </div>

                <Section title="Dane agencji">
                    <div className="grid gap-4 sm:grid-cols-2">
                        {field('agency_name', 'Nazwa agencji', 'np. CleanCode AS')}
                        {field('agency_email', 'E-mail kontaktowy', 'biuro@twojadomena.pl')}
                        {field('agency_phone', 'Telefon')}
                        {field('agency_website', 'Strona www', 'https://…')}
                    </div>
                </Section>

                <Section title="Wygląd raportów">
                    <div className="grid gap-4">
                        <div className="flex flex-wrap items-center gap-4">
                            {brand.logo_url && !form.data.remove_logo ? (
                                <img src={brand.logo_url} alt="Logo" className="h-12 max-w-48 rounded border object-contain p-1" />
                            ) : (
                                <div className="text-muted-foreground flex h-12 w-32 items-center justify-center rounded border border-dashed text-xs">
                                    brak logo
                                </div>
                            )}
                            <div className="grid gap-1.5">
                                <Label htmlFor="logo">Logo (PNG/JPG/WebP, maks. 2 MB)</Label>
                                <Input
                                    id="logo"
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp"
                                    onChange={(e) => form.setData('logo', e.target.files?.[0] ?? null)}
                                />
                                <InputError message={form.errors.logo} />
                            </div>
                            {brand.logo_url && (
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={form.data.remove_logo}
                                        onChange={(e) => form.setData('remove_logo', e.target.checked)}
                                    />{' '}
                                    Usuń logo
                                </label>
                            )}
                        </div>
                        <div className="flex items-end gap-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="brand_color">Kolor marki</Label>
                                <input
                                    id="brand_color"
                                    type="color"
                                    value={form.data.brand_color}
                                    onChange={(e) => form.setData('brand_color', e.target.value)}
                                    className="h-9 w-16 rounded border"
                                />
                            </div>
                            <span className="text-muted-foreground pb-2 text-sm">przyciski i akcenty w raporcie dla klienta</span>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="report_footer">Stopka raportu</Label>
                            <textarea
                                id="report_footer"
                                rows={2}
                                value={form.data.report_footer}
                                onChange={(e) => form.setData('report_footer', e.target.value)}
                                className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                placeholder="np. W razie pytań jesteśmy do dyspozycji – zadzwoń lub odpisz na maila."
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="vat_rate">VAT</Label>
                            <NativeSelect id="vat_rate" value={form.data.vat_rate} onChange={(e) => form.setData('vat_rate', e.target.value)}>
                                <option value="zw">Nie jestem płatnikiem VAT – jedna kwota</option>
                                <option value="23">23%</option>
                                <option value="8">8%</option>
                                <option value="5">5%</option>
                                <option value="0">0%</option>
                            </NativeSelect>
                            <p className="text-muted-foreground text-xs">
                                Bez VAT panel, oferty i rozliczenia pokazują jedną kwotę (bez netto/brutto). Ze stawką VAT – kwoty netto z brutto
                                obok, oferty doliczają VAT.
                            </p>
                            <InputError message={form.errors.vat_rate} />
                        </div>
                    </div>
                </Section>

                <Section
                    title="Fakturownia – faktury jednym kliknięciem"
                    description="Przycisk „Wystaw fakturę” w Rozliczeniach, u klienta i w agencji tworzy fakturę w Twoim koncie Fakturowni."
                >
                    <div id="fakturownia" className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="fakturownia_domain">Adres konta</Label>
                            <div className="flex items-center gap-1">
                                <Input
                                    id="fakturownia_domain"
                                    value={form.data.fakturownia_domain}
                                    placeholder="mojafirma"
                                    onChange={(e) => form.setData('fakturownia_domain', e.target.value)}
                                />
                                <span className="text-muted-foreground text-sm whitespace-nowrap">.fakturownia.pl</span>
                            </div>
                            <InputError message={form.errors.fakturownia_domain} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="fakturownia_token">Kod autoryzacyjny API</Label>
                            <Input
                                id="fakturownia_token"
                                type="password"
                                autoComplete="off"
                                value={form.data.fakturownia_token}
                                placeholder={tokenSet ? 'zapisany – zostaw puste, by nie zmieniać' : 'Ustawienia → Ustawienia konta → Integracja'}
                                onChange={(e) => form.setData('fakturownia_token', e.target.value)}
                            />
                            <InputError message={form.errors.fakturownia_token} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="invoice_kind">Rodzaj dokumentu</Label>
                            <NativeSelect
                                id="invoice_kind"
                                value={form.data.invoice_kind}
                                onChange={(e) => form.setData('invoice_kind', e.target.value)}
                            >
                                <option value="vat">Faktura</option>
                                <option value="bill">Rachunek</option>
                            </NativeSelect>
                            <p className="text-muted-foreground text-xs">
                                Bez VAT pozycje idą ze stawką „zw” – rodzaj ustaw tak, jak wystawiasz w Fakturowni.
                            </p>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="invoice_payment_days">Termin płatności (dni)</Label>
                            <Input
                                id="invoice_payment_days"
                                type="number"
                                min={0}
                                max={120}
                                value={form.data.invoice_payment_days}
                                onChange={(e) => form.setData('invoice_payment_days', e.target.value)}
                            />
                        </div>
                        {form.data.vat_rate === 'zw' && (
                            <div className="grid gap-1.5 sm:col-span-2">
                                <Label htmlFor="invoice_exempt_basis">Podstawa zwolnienia z VAT (opcjonalnie)</Label>
                                <Input
                                    id="invoice_exempt_basis"
                                    value={form.data.invoice_exempt_basis}
                                    placeholder="zostaw puste, jeśli podstawę masz ustawioną w Fakturowni"
                                    onChange={(e) => form.setData('invoice_exempt_basis', e.target.value)}
                                />
                                <p className="text-muted-foreground text-xs">
                                    Przekazywana do pola zwolnienia w Fakturowni – wpisz dokładnie tak, jak w Twoim koncie Fakturowni.
                                </p>
                            </div>
                        )}
                        {tokenSet && (
                            <div className="flex flex-wrap items-center gap-3 sm:col-span-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => router.post('/invoices/test', {}, { preserveScroll: true })}
                                >
                                    <PlugZap className="size-4" /> Sprawdź połączenie
                                </Button>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={form.data.remove_fakturownia}
                                        onChange={(e) => form.setData('remove_fakturownia', e.target.checked)}
                                    />
                                    odłącz Fakturownię
                                </label>
                            </div>
                        )}
                    </div>
                </Section>

                <Section title="Rentowność" description="Klienci, u których realna stawka za godzinę jest niższa, są oznaczeni na czerwono.">
                    <div className="grid max-w-xs gap-1.5">
                        <Label htmlFor="target_hourly_rate">Docelowa stawka za godzinę (zł)</Label>
                        <Input
                            id="target_hourly_rate"
                            type="number"
                            min={0}
                            step="1"
                            value={form.data.target_hourly_rate}
                            placeholder="np. 150"
                            onChange={(e) => form.setData('target_hourly_rate', e.target.value)}
                        />
                        <InputError message={form.errors.target_hourly_rate} />
                    </div>
                </Section>

                <div>
                    <Button type="submit" disabled={form.processing}>
                        Zapisz ustawienia
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
