import { EmptyState, Section } from '@/components/adpilot/ui';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Head, Link, useForm } from '@inertiajs/react';
import { RefreshCw, ScanSearch } from 'lucide-react';

interface Row {
    id: number;
    url: string;
    name: string | null;
    status: 'pending' | 'running' | 'done' | 'failed';
    score: number | null;
    created_at: string;
    client: { id: number; name: string } | null;
    area_scores: Record<string, number | null>;
}

/** Ocena: liczba + słowo, kolor tylko pomocniczo. */
export function scoreTone(v: number | null) {
    if (v === null) return { label: '–', cls: 'text-muted-foreground' };
    if (v >= 90) return { label: 'bardzo dobrze', cls: 'text-[#0a7d0a] dark:text-[#4ccc4c]' };
    if (v >= 70) return { label: 'dobrze', cls: 'text-[#0a7d0a] dark:text-[#4ccc4c]' };
    if (v >= 50) return { label: 'do poprawy', cls: 'text-[#a4471f] dark:text-[#ec835a]' };
    return { label: 'słabo', cls: 'text-[#b42f2f] dark:text-[#f08080]' };
}

export default function AuditsIndex({ audits, areas }: { audits: Row[]; areas: Record<string, string> }) {
    const form = useForm({ url: '', name: '', email: '' });

    return (
        <AppLayout breadcrumbs={[{ title: 'Audyt dowolnej strony', href: '/audits' }]}>
            <Head title="Audyt dowolnej strony" />
            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Audyt dowolnej strony</h1>
                    <p className="text-muted-foreground text-sm">
                        Dla potencjalnych klientów – bez zakładania klienta. Sprawdza: {Object.values(areas).join(', ')}. Wynik to raport z Twoim logo
                        (link i PDF) do wysłania razem z ofertą.
                    </p>
                </div>

                <Section title="Nowy audyt">
                    <form
                        className="grid gap-3 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-end"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/audits');
                        }}
                    >
                        <div className="grid gap-1.5">
                            <Label htmlFor="url">Adres strony</Label>
                            <Input
                                id="url"
                                required
                                autoFocus
                                value={form.data.url}
                                onChange={(e) => form.setData('url', e.target.value)}
                                placeholder="np. firma-konkurencji.pl"
                            />
                            <InputError message={form.errors.url} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="name">Firma (opcjonalnie)</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="nazwa do raportu"
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="email">E-mail (opcjonalnie)</Label>
                            <Input
                                id="email"
                                type="email"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                placeholder="kontakt"
                            />
                            <InputError message={form.errors.email} />
                        </div>
                        <Button type="submit" disabled={form.processing}>
                            <ScanSearch className="size-4" /> Audytuj
                        </Button>
                    </form>
                </Section>

                <Section title={`Wykonane audyty (${audits.length})`}>
                    {audits.length === 0 ? (
                        <EmptyState title="Jeszcze nie było audytów" />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-left text-xs">
                                        <th className="py-2 font-medium">Strona</th>
                                        <th className="py-2 font-medium">Ocena</th>
                                        {Object.values(areas).map((a) => (
                                            <th key={a} className="hidden py-2 font-medium md:table-cell">
                                                {a}
                                            </th>
                                        ))}
                                        <th className="py-2 font-medium">Kiedy</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {audits.map((a) => {
                                        const t = scoreTone(a.score);
                                        return (
                                            <tr key={a.id} className="border-b last:border-0">
                                                <td className="py-2">
                                                    <Link href={`/audits/${a.id}`} className="font-medium hover:underline">
                                                        {a.name || a.url.replace(/^https?:\/\/(www\.)?/, '')}
                                                    </Link>
                                                    <div className="text-muted-foreground text-xs">
                                                        {a.url.replace(/^https?:\/\//, '')}
                                                        {a.client && <> · klient: {a.client.name}</>}
                                                    </div>
                                                </td>
                                                <td className="py-2">
                                                    {a.status === 'done' ? (
                                                        <span className={cn('font-semibold tabular-nums', t.cls)}>{a.score}/100</span>
                                                    ) : a.status === 'failed' ? (
                                                        <span className="text-xs text-[#b42f2f]">błąd</span>
                                                    ) : (
                                                        <span className="text-muted-foreground inline-flex items-center gap-1 text-xs">
                                                            <RefreshCw className="size-3 animate-spin" /> trwa
                                                        </span>
                                                    )}
                                                </td>
                                                {Object.keys(areas).map((k) => (
                                                    <td
                                                        key={k}
                                                        className={cn(
                                                            'hidden py-2 tabular-nums md:table-cell',
                                                            scoreTone(a.area_scores?.[k] ?? null).cls,
                                                        )}
                                                    >
                                                        {a.area_scores?.[k] ?? '–'}
                                                    </td>
                                                ))}
                                                <td className="text-muted-foreground py-2 text-xs">{dateTime(a.created_at)}</td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Section>
            </div>
        </AppLayout>
    );
}
