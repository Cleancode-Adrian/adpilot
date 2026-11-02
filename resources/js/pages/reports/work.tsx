import { localDate } from '@/components/adpilot/care-task-row';
import { Section } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import ClientLayout from '@/layouts/client-layout';
import { dateTime } from '@/lib/format';
import { type ClientProps } from '@/types/adpilot';
import { router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Download, ExternalLink, ListPlus, Mail, Plus, Save, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

type Item = {
    task_id: number | null;
    title: string;
    description: string;
    date: string | null;
};

interface WorkReport {
    id: number;
    period_start: string;
    note: string | null;
    sent_at: string | null;
    updated_at: string;
    data: { items: Item[] };
}

const MONTHS = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

export default function WorkReportEditor({
    client,
    report,
    public_url,
    pdf_url,
}: {
    client: ClientProps;
    report: WorkReport;
    public_url: string;
    pdf_url: string;
}) {
    const [note, setNote] = useState(report.note ?? '');
    const [items, setItems] = useState<Item[]>(report.data.items ?? []);
    const [saving, setSaving] = useState(false);

    // po „Dodaj nowe wykonane zadania” serwer zwraca nową listę
    useEffect(() => setItems(report.data.items ?? []), [report.data.items]);

    const initial = JSON.stringify({ n: report.note ?? '', i: report.data.items ?? [] });
    const dirty = JSON.stringify({ n: note, i: items }) !== initial;
    const d = new Date(`${report.period_start.slice(0, 10)}T12:00:00`);
    const month = `${MONTHS[d.getMonth()]} ${d.getFullYear()}`;

    const set = (i: number, patch: Partial<Item>) => setItems((list) => list.map((it, k) => (k === i ? { ...it, ...patch } : it)));
    const move = (i: number, dir: -1 | 1) =>
        setItems((list) => {
            const j = i + dir;
            if (j < 0 || j >= list.length) return list;
            const copy = [...list];
            [copy[i], copy[j]] = [copy[j], copy[i]];
            return copy;
        });

    const save = (after?: () => void) => {
        setSaving(true);
        router.put(
            `/reports/${report.id}/work`,
            { note: note || null, items: items.filter((i) => i.title.trim()) },
            { preserveScroll: true, onFinish: () => setSaving(false), onSuccess: () => after?.() },
        );
    };

    const guard = (fn: () => void) => () => (dirty ? save(fn) : fn());

    return (
        <ClientLayout client={client} title={`Raport z prac – ${month}`}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-lg font-semibold capitalize">Raport z prac – {month}</h2>
                    <p className="text-muted-foreground text-xs">
                        {report.sent_at ? `Wysłano ${dateTime(report.sent_at)}` : 'Jeszcze nie wysłany'} · ostatnia zmiana{' '}
                        {dateTime(report.updated_at)}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button onClick={() => save()} disabled={!dirty || saving}>
                        <Save className="size-4" /> {saving ? 'Zapisywanie…' : dirty ? 'Zapisz zmiany' : 'Zapisano'}
                    </Button>
                    <Button variant="outline" asChild>
                        <a
                            href={public_url}
                            target="_blank"
                            rel="noreferrer"
                            onClick={(e) => dirty && (e.preventDefault(), save(() => window.open(public_url, '_blank')))}
                        >
                            <ExternalLink className="size-4" /> Podgląd dla klienta
                        </a>
                    </Button>
                    <Button variant="outline" asChild>
                        <a href={pdf_url} onClick={(e) => dirty && (e.preventDefault(), save(() => (window.location.href = pdf_url)))}>
                            <Download className="size-4" /> PDF
                        </a>
                    </Button>
                    <Button
                        variant="outline"
                        disabled={!client.contact_email}
                        title={client.contact_email ? `Wyślij na ${client.contact_email}` : 'Uzupełnij e-mail kontaktowy klienta'}
                        onClick={guard(
                            () =>
                                confirm(`Wysłać raport na ${client.contact_email}?`) &&
                                router.post(`/reports/${report.id}/send`, {}, { preserveScroll: true }),
                        )}
                    >
                        <Mail className="size-4" /> Wyślij klientowi
                    </Button>
                </div>
            </div>

            <Section
                title="Podsumowanie dla klienta"
                description="Kilka zdań o miesiącu: co zrobiliśmy, co zauważyliśmy, co proponujemy dalej. Można używać **pogrubienia** i list (- punkt)."
            >
                <textarea
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    rows={Math.min(16, Math.max(5, note.split('\n').length + 1))}
                    className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm leading-relaxed"
                    placeholder={
                        'np. W listopadzie zaktualizowaliśmy stronę do najnowszej wersji WordPressa i wszystkie wtyczki. Skan bezpieczeństwa nie wykazał zagrożeń.\n\nRekomendujemy w grudniu:\n- odnowienie certyfikatu SSL (wygasa 14.01)\n- usunięcie nieużywanej wtyczki slidera'
                    }
                />
            </Section>

            <Section
                title={`Wykonane prace (${items.length})`}
                description="Pozycje z odhaczonych zadań – tytuł i opis możesz zmienić tylko w raporcie (zadania zostają bez zmian)."
                actions={
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={guard(() => router.post(`/reports/${report.id}/work/refresh`, {}, { preserveScroll: true }))}
                        >
                            <ListPlus className="size-4" /> Dodaj nowe wykonane zadania
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setItems((l) => [...l, { task_id: null, title: '', description: '', date: localDate() }])}
                        >
                            <Plus className="size-4" /> Pozycja
                        </Button>
                    </div>
                }
            >
                {items.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        Brak pozycji. Odhacz wykonane zadania w zakładce Zadania albo dodaj pozycję ręcznie.
                    </p>
                ) : (
                    <ol className="grid gap-3">
                        {items.map((it, i) => (
                            <li key={i} className="grid gap-2 rounded-lg border p-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-muted-foreground w-6 text-right text-sm tabular-nums">{i + 1}.</span>
                                    <Input
                                        value={it.title}
                                        onChange={(e) => set(i, { title: e.target.value })}
                                        placeholder="Co zostało zrobione"
                                        className="min-w-56 flex-1 font-medium"
                                    />
                                    <Input
                                        type="date"
                                        value={it.date ?? ''}
                                        onChange={(e) => set(i, { date: e.target.value || null })}
                                        className="w-40"
                                        aria-label="Data"
                                    />
                                    <div className="flex">
                                        <Button variant="ghost" size="sm" title="Wyżej" disabled={i === 0} onClick={() => move(i, -1)}>
                                            <ArrowUp className="size-4" />
                                        </Button>
                                        <Button variant="ghost" size="sm" title="Niżej" disabled={i === items.length - 1} onClick={() => move(i, 1)}>
                                            <ArrowDown className="size-4" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            title="Usuń z raportu"
                                            onClick={() => setItems((l) => l.filter((_, k) => k !== i))}
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                </div>
                                <textarea
                                    value={it.description}
                                    onChange={(e) => set(i, { description: e.target.value })}
                                    rows={Math.max(2, it.description.split('\n').length)}
                                    placeholder="Opis dla klienta (opcjonalnie), np. zaktualizowano 12 wtyczek, WordPress 6.9, sprawdzono formularze"
                                    className="border-input bg-background ml-8 rounded-md border px-3 py-2 text-sm"
                                />
                            </li>
                        ))}
                    </ol>
                )}
            </Section>

            {dirty && (
                <div className="bg-background/95 sticky bottom-3 z-10 flex items-center justify-between gap-3 rounded-lg border px-4 py-2 shadow-sm">
                    <span className="text-sm">Masz niezapisane zmiany.</span>
                    <Button size="sm" onClick={() => save()} disabled={saving}>
                        <Save className="size-4" /> Zapisz
                    </Button>
                </div>
            )}
        </ClientLayout>
    );
}
