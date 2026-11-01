import InputError from '@/components/input-error';
import { Section } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type Brand } from '@/types';
import { Head, useForm } from '@inertiajs/react';

export default function AgencySettings({ settings, brand }: { settings: Record<string, string | null>; brand: Brand }) {
    const form = useForm({
        agency_name: brand.name ?? '',
        agency_email: settings.agency_email ?? brand.email ?? '',
        agency_phone: settings.agency_phone ?? '',
        agency_website: settings.agency_website ?? '',
        brand_color: brand.color ?? '#2a78d6',
        report_footer: settings.report_footer ?? '',
        logo: null as File | null,
        remove_logo: false as boolean,
    });

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
                    <p className="text-muted-foreground text-sm">Nazwa, logo i dane kontaktowe – widoczne w panelu, raportach dla klientów i e-mailach.</p>
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
                                <div className="text-muted-foreground flex h-12 w-32 items-center justify-center rounded border border-dashed text-xs">brak logo</div>
                            )}
                            <div className="grid gap-1.5">
                                <Label htmlFor="logo">Logo (PNG/JPG/WebP, maks. 2 MB)</Label>
                                <Input id="logo" type="file" accept="image/png,image/jpeg,image/webp" onChange={(e) => form.setData('logo', e.target.files?.[0] ?? null)} />
                                <InputError message={form.errors.logo} />
                            </div>
                            {brand.logo_url && (
                                <label className="flex items-center gap-2 text-sm">
                                    <input type="checkbox" checked={form.data.remove_logo} onChange={(e) => form.setData('remove_logo', e.target.checked)} /> Usuń logo
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
