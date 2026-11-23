import { EmptyState, Section } from '@/components/adpilot/ui';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Head, Link, useForm } from '@inertiajs/react';
import { ListPlus } from 'lucide-react';

export interface Progress {
    total: number;
    done: number;
    failed: number;
    running: number;
    waiting: number;
    skipped: number;
    seconds_per_site: number;
    eta_seconds: number;
}

interface Row {
    id: number;
    name: string;
    status: 'draft' | 'running' | 'paused' | 'done';
    created_at: string;
    finished_at: string | null;
    progress: Progress;
}

export const BATCH_STATUS: Record<Row['status'], { label: string; cls: string }> = {
    draft: { label: 'do uruchomienia', cls: 'bg-muted text-muted-foreground' },
    running: { label: 'w trakcie', cls: 'bg-[#e8f0fb] text-[#2a5b9c] dark:bg-[#17304f] dark:text-[#8db8e8]' },
    paused: { label: 'wstrzymany', cls: 'bg-[#fff4d6] text-[#7a5600] dark:bg-[#3d2f08] dark:text-[#fab219]' },
    done: { label: 'gotowy', cls: 'bg-[#e4f5e4] text-[#0a6b0a] dark:bg-[#12331a] dark:text-[#4ccc4c]' },
};

/** „ok. 4 h 30 min” / „ok. 12 min” */
export function duration(seconds: number) {
    const min = Math.max(1, Math.round(seconds / 60));
    if (min < 60) return `ok. ${min} min`;
    const h = Math.floor(min / 60);
    const m = Math.round((min % 60) / 5) * 5;
    return m ? `ok. ${h} h ${m} min` : `ok. ${h} h`;
}

export default function AuditBatchesIndex({ batches, seconds_per_site, has_ai }: { batches: Row[]; seconds_per_site: number; has_ai: boolean }) {
    const form = useForm({ name: '', list: '' });

    return (
        <AppLayout breadcrumbs={[{ title: 'Audyt zbiorczy', href: '/audit-batches' }]}>
            <Head title="Audyt zbiorczy" />
            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Audyt zbiorczy</h1>
                    <p className="text-muted-foreground text-sm">
                        Wiele stron naraz – np. wszystkie serwisy agencji z listą najważniejszych podstron. Każda strona dostaje pełny audyt (treść,
                        kod, zgodność z prawem, wydajność), a na końcu jest jeden wspólny wynik: zestawienie stron, najczęstsze problemy, raport
                        zbiorczy i arkusz wszystkich uwag.
                    </p>
                </div>

                <Section
                    title="Nowa lista"
                    description="Wklej adresy stron albo link do Arkusza Google. W następnym kroku zobaczysz, co zostało wczytane, i dopiero wtedy uruchomisz audyt."
                >
                    <form
                        className="grid gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/audit-batches');
                        }}
                    >
                        <div className="grid gap-1.5 sm:max-w-md">
                            <Label htmlFor="name">Nazwa (opcjonalnie)</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="np. Serwisy agencji X – listopad"
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="list">Lista stron albo link do arkusza</Label>
                            <textarea
                                id="list"
                                required
                                rows={9}
                                value={form.data.list}
                                onChange={(e) => form.setData('list', e.target.value)}
                                placeholder={
                                    'https://docs.google.com/spreadsheets/d/…\n\nalbo adresy, po jednym w linii:\nfirma.pl\nhttps://firma.pl/oferta/\nhttps://firma.pl/blog/wazny-artykul/\ninna-firma.com'
                                }
                                className="border-input bg-background focus-visible:ring-ring rounded-md border px-3 py-2 font-mono text-sm focus-visible:ring-2 focus-visible:outline-none"
                            />
                            <InputError message={form.errors.list} />
                            <ul className="text-muted-foreground grid gap-0.5 text-xs">
                                <li>
                                    <strong>Arkusz lub Dokument Google</strong> – musi być udostępniony „każdej osobie mającej link”. Możesz też
                                    zaznaczyć komórki w arkuszu, skopiować je i wkleić tutaj.
                                </li>
                                <li>
                                    <strong>Adresy</strong> – z „https://” albo same domeny. Adresy z tej samej domeny łączą się w jedną pozycję:
                                    strona główna + wskazane podstrony (do 6 na stronę).
                                </li>
                                <li>Dopiski bez adresu (np. „czekamy na nową stronę”) zostają jako notatka przy stronie.</li>
                            </ul>
                        </div>
                        <div className="flex flex-wrap items-center gap-3">
                            <Button type="submit" disabled={form.processing}>
                                <ListPlus className="size-4" /> Wczytaj listę
                            </Button>
                            <span className="text-muted-foreground text-xs">
                                Audyt jednej strony trwa zwykle {duration(seconds_per_site)} – strony idą po kolei w tle
                                {has_ai ? '' : ' (bez przeglądu treści przez AI – brak klucza ANTHROPIC_API_KEY)'}.
                            </span>
                        </div>
                    </form>
                </Section>

                <Section title={`Audyty zbiorcze (${batches.length})`}>
                    {batches.length === 0 ? (
                        <EmptyState title="Jeszcze nie było audytów zbiorczych" />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-left text-xs">
                                        <th className="py-2 font-medium">Nazwa</th>
                                        <th className="py-2 font-medium">Stan</th>
                                        <th className="py-2 font-medium">Strony</th>
                                        <th className="py-2 font-medium">Utworzono</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {batches.map((b) => (
                                        <tr key={b.id} className="border-b last:border-0">
                                            <td className="py-2">
                                                <Link href={`/audit-batches/${b.id}`} className="font-medium hover:underline">
                                                    {b.name}
                                                </Link>
                                            </td>
                                            <td className="py-2">
                                                <span className={cn('rounded-full px-2 py-0.5 text-xs font-medium', BATCH_STATUS[b.status].cls)}>
                                                    {BATCH_STATUS[b.status].label}
                                                </span>
                                            </td>
                                            <td className="py-2 tabular-nums">
                                                {b.status === 'draft' ? b.progress.total : `${b.progress.done} z ${b.progress.total}`}
                                                {b.progress.failed > 0 && (
                                                    <span className="text-[#b42f2f] dark:text-[#f08080]"> · {b.progress.failed} nieudane</span>
                                                )}
                                            </td>
                                            <td className="text-muted-foreground py-2 text-xs">{dateTime(b.created_at)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Section>
            </div>
        </AppLayout>
    );
}
