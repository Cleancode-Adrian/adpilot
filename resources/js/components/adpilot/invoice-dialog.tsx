import { NetGross } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Link, router } from '@inertiajs/react';
import { AlertTriangle, Receipt } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Line {
    key: string;
    type: 'task' | 'subscription';
    name: string;
    quantity: number;
    unit_price: number;
    site?: string;
}
interface Draft {
    buyer: { name: string; tax_no: string | null; street: string | null; post_code: string | null; city: string | null; email: string | null };
    lines: Line[];
    configured: boolean;
    missing: string[];
    edit_url: string;
}

/** Przycisk „Wystaw fakturę” + okno z pozycjami do wyboru (Fakturownia). */
export function InvoiceButton({
    clientId,
    agencyId,
    currency = 'PLN',
    size = 'sm',
    variant = 'outline',
}: {
    clientId?: number;
    agencyId?: number;
    currency?: string;
    size?: 'sm' | 'default';
    variant?: 'outline' | 'default';
}) {
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState<Draft | null>(null);
    const [picked, setPicked] = useState<Set<string>>(new Set());
    const [send, setSend] = useState(true);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!open) return;
        setDraft(null);
        fetch(`/invoices/draft?${agencyId ? `agency_id=${agencyId}` : `client_id=${clientId}`}`, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((d: Draft) => {
                setDraft(d);
                setPicked(new Set(d.lines.map((l) => l.key)));
            });
    }, [open, clientId, agencyId]);

    const total = draft ? draft.lines.filter((l) => picked.has(l.key)).reduce((s, l) => s + l.quantity * l.unit_price, 0) : 0;
    const toggle = (key: string) =>
        setPicked((p) => {
            const n = new Set(p);
            if (n.has(key)) n.delete(key);
            else n.add(key);
            return n;
        });

    return (
        <>
            <Button size={size} variant={variant} className={size === 'sm' ? 'h-7' : undefined} onClick={() => setOpen(true)}>
                <Receipt className="size-3.5" /> Wystaw fakturę
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-2xl">
                    <DialogTitle>Wystaw fakturę</DialogTitle>
                    <DialogDescription>
                        Faktura powstanie w Fakturowni. Wybrane prace oznaczą się jako zafakturowane, a abonamenty przesuną się o okres.
                    </DialogDescription>
                    {!draft ? (
                        <p className="text-muted-foreground py-6 text-center text-sm">Wczytywanie…</p>
                    ) : !draft.configured ? (
                        <div className="rounded-lg border p-4 text-sm">
                            Fakturownia nie jest podłączona.{' '}
                            <Link href="/admin/settings#fakturownia" className="underline">
                                Podłącz ją w Ustawieniach agencji
                            </Link>{' '}
                            (adres konta i kod API) – albo oznaczaj prace jako zafakturowane ręcznie.
                        </div>
                    ) : (
                        <div className="grid gap-3 text-sm">
                            <div className="bg-muted/40 rounded-lg p-3">
                                <div className="font-medium">{draft.buyer.name}</div>
                                <div className="text-muted-foreground text-xs">
                                    {[
                                        draft.buyer.tax_no && `NIP ${draft.buyer.tax_no}`,
                                        draft.buyer.street,
                                        [draft.buyer.post_code, draft.buyer.city].filter(Boolean).join(' '),
                                        draft.buyer.email,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </div>
                                {draft.missing.length > 0 && (
                                    <div className="mt-1 inline-flex items-center gap-1 text-xs text-[#a4471f] dark:text-[#ec835a]">
                                        <AlertTriangle className="size-3.5" /> Brakuje: {draft.missing.join(', ')} –{' '}
                                        <Link href={draft.edit_url} className="underline">
                                            uzupełnij dane do faktury
                                        </Link>
                                    </div>
                                )}
                            </div>
                            {draft.lines.length === 0 ? (
                                <p className="text-muted-foreground">
                                    Brak pozycji do zafakturowania (prace z kwotą, abonamenty z terminem w tym miesiącu).
                                </p>
                            ) : (
                                <ul className="max-h-72 divide-y overflow-auto rounded-lg border">
                                    {draft.lines.map((l) => (
                                        <li key={l.key}>
                                            <label className="flex cursor-pointer items-center gap-3 px-3 py-2">
                                                <input
                                                    type="checkbox"
                                                    checked={picked.has(l.key)}
                                                    onChange={() => toggle(l.key)}
                                                    className="size-4"
                                                />
                                                <span className="min-w-0 flex-1">
                                                    {l.name}
                                                    <span className="text-muted-foreground ml-1 text-xs">
                                                        {l.type === 'subscription' ? 'abonament' : 'praca'}
                                                    </span>
                                                </span>
                                                <NetGross value={l.quantity * l.unit_price} currency={currency} className="shrink-0" />
                                            </label>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <label className="flex items-center gap-2">
                                    <input type="checkbox" checked={send} onChange={(e) => setSend(e.target.checked)} className="size-4" />
                                    Wyślij fakturę e-mailem {draft.buyer.email ? `(${draft.buyer.email})` : '(brak e-maila nabywcy)'}
                                </label>
                                <div className="font-semibold">
                                    Razem: <NetGross value={total} currency={currency} />
                                </div>
                            </div>
                            <div className="flex justify-end gap-2">
                                <Button variant="ghost" onClick={() => setOpen(false)}>
                                    Anuluj
                                </Button>
                                <Button
                                    disabled={busy || picked.size === 0}
                                    onClick={() => {
                                        setBusy(true);
                                        router.post(
                                            '/invoices',
                                            {
                                                ...(agencyId ? { agency_id: agencyId } : { client_id: clientId }),
                                                keys: [...picked],
                                                send: send && !!draft.buyer.email,
                                            },
                                            { preserveScroll: true, onFinish: () => setBusy(false), onSuccess: () => setOpen(false) },
                                        );
                                    }}
                                >
                                    <Receipt className="size-4" /> {busy ? 'Wystawianie…' : 'Wystaw fakturę'}
                                </Button>
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
