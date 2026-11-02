import { EmptyState, NativeSelect, Section } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ClientLayout from '@/layouts/client-layout';
import { date, dateTime } from '@/lib/format';
import { type ClientProps, type ReportRow } from '@/types/adpilot';
import { Link, router, useForm } from '@inertiajs/react';
import { Download, ExternalLink, FileText, RefreshCw } from 'lucide-react';

export default function Reports({
    client,
    reports,
    defaults,
    ai_enabled,
}: {
    client: ClientProps;
    reports: ReportRow[];
    defaults: { from: string; to: string };
    ai_enabled: boolean;
}) {
    const form = useForm({ type: 'client', from: defaults.from, to: defaults.to, send: false as boolean });

    return (
        <ClientLayout client={client} title="Raporty">
            <Section
                title="Nowy raport"
                description={
                    ai_enabled
                        ? 'Raport z komentarzem napisanym przez AI na podstawie danych i wykonanych zadań.'
                        : 'Komentarz zostanie wygenerowany z szablonu. Dodaj ANTHROPIC_API_KEY w .env, aby komentarze pisało AI.'
                }
            >
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/clients/${client.id}/reports`, { preserveScroll: true });
                    }}
                    className="flex flex-wrap items-end gap-3"
                >
                    <div className="grid gap-1.5">
                        <Label>Typ</Label>
                        <NativeSelect value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                            <option value="client">Dla klienta (ruch + wyniki reklam)</option>
                            <option value="internal">Wewnętrzny (plan działań)</option>
                        </NativeSelect>
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Od</Label>
                        <Input type="date" value={form.data.from} onChange={(e) => form.setData('from', e.target.value)} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Do</Label>
                        <Input type="date" value={form.data.to} onChange={(e) => form.setData('to', e.target.value)} />
                    </div>
                    {form.data.type === 'client' && client.contact_email && (
                        <label className="flex h-9 items-center gap-2 text-sm">
                            <input type="checkbox" checked={form.data.send} onChange={(e) => form.setData('send', e.target.checked)} />
                            Wyślij na {client.contact_email}
                        </label>
                    )}
                    <Button type="submit" disabled={form.processing}>
                        <FileText className="size-4" /> Generuj
                    </Button>
                </form>
            </Section>

            <Section
                title="Wygenerowane raporty"
                actions={
                    <Button variant="ghost" size="sm" onClick={() => router.reload({ only: ['reports'] })}>
                        <RefreshCw className="size-4" /> Odśwież
                    </Button>
                }
            >
                {reports.length === 0 ? (
                    <EmptyState title="Brak raportów" />
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-muted-foreground border-b text-left text-xs">
                                <th className="py-2 font-medium">Okres</th>
                                <th className="py-2 font-medium">Typ</th>
                                <th className="py-2 font-medium">Komentarz</th>
                                <th className="py-2 font-medium">Wysłano</th>
                                <th className="py-2 text-right font-medium">Akcje</th>
                            </tr>
                        </thead>
                        <tbody>
                            {reports.map((r) => (
                                <tr key={r.id} className="border-b last:border-0">
                                    <td className="py-2">
                                        <Link href={`/reports/${r.id}`} className="font-medium hover:underline">
                                            {date(r.period_start)} – {date(r.period_end)}
                                        </Link>
                                        <div className="text-muted-foreground text-xs">utworzono {dateTime(r.created_at)}</div>
                                    </td>
                                    <td className="py-2">{r.type === 'client' ? 'Dla klienta' : 'Plan działań'}</td>
                                    <td className="text-muted-foreground py-2 text-xs">
                                        {r.ai_model === 'szablon' ? 'szablon' : r.ai_model ? `AI (${r.ai_model})` : '–'}
                                    </td>
                                    <td className="py-2 text-xs">{r.sent_at ? dateTime(r.sent_at) : '–'}</td>
                                    <td className="py-2 text-right">
                                        {r.type === 'client' && (
                                            <div className="inline-flex gap-1">
                                                <Button variant="ghost" size="sm" asChild>
                                                    <a href={r.public_url} target="_blank" rel="noreferrer" title="Link dla klienta">
                                                        <ExternalLink className="size-4" />
                                                    </a>
                                                </Button>
                                                <Button variant="ghost" size="sm" asChild>
                                                    <a href={r.pdf_url} title="PDF">
                                                        <Download className="size-4" />
                                                    </a>
                                                </Button>
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Section>
        </ClientLayout>
    );
}
