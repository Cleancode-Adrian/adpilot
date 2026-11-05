import { NativeSelect, Section } from '@/components/adpilot/ui';
import { CopyButton } from '@/components/adpilot/vault';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dateTime, money } from '@/lib/format';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Copy, Download, ExternalLink, Lock, Mail, Plus, Save, Send, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { QuoteStatus } from './index';

type Item = { name: string; description: string; quantity: number; unit: string; unit_price: number; recurring: '' | 'monthly' | 'yearly' };
interface Quote {
    id: number;
    number: string;
    title: string;
    intro: string | null;
    terms: string | null;
    status: 'draft' | 'sent' | 'accepted' | 'rejected';
    valid_until: string | null;
    currency: string;
    vat_rate: number | null;
    sent_at: string | null;
    viewed_at: string | null;
    decided_at: string | null;
    decided_name: string | null;
    rejection_reason: string | null;
    expired: boolean;
    client: { id: number; name: string; contact_email: string | null; contact_name: string | null };
    items: (Omit<Item, 'description' | 'recurring'> & { description: string | null; recurring: string | null })[];
}

const LABEL = { '': 'Jednorazowo', monthly: 'Miesięcznie', yearly: 'Rocznie' } as const;

export default function QuoteEdit({ quote, public_url, pdf_url }: { quote: Quote; public_url: string; pdf_url: string }) {
    const locked = quote.status === 'accepted' || quote.status === 'rejected';
    const fromServer = () => ({
        title: quote.title,
        intro: quote.intro ?? '',
        terms: quote.terms ?? '',
        valid_until: quote.valid_until?.slice(0, 10) ?? '',
        currency: quote.currency,
        vat: quote.vat_rate === null ? 'zw' : String(Number(quote.vat_rate)),
        items: quote.items.map((i) => ({
            ...i,
            description: i.description ?? '',
            recurring: (i.recurring ?? '') as Item['recurring'],
            quantity: Number(i.quantity),
            unit_price: Number(i.unit_price),
        })),
    });
    const [d, setD] = useState(fromServer);
    const [email, setEmail] = useState(quote.client.contact_email ?? '');
    const [saving, setSaving] = useState(false);
    useEffect(() => setD(fromServer()), [quote]); // eslint-disable-line react-hooks/exhaustive-deps

    const dirty = JSON.stringify(d) !== JSON.stringify(fromServer());
    const set = <K extends keyof typeof d>(k: K, v: (typeof d)[K]) => setD((p) => ({ ...p, [k]: v }));
    const setItem = (i: number, patch: Partial<Item>) => setD((p) => ({ ...p, items: p.items.map((it, k) => (k === i ? { ...it, ...patch } : it)) }));
    const move = (i: number, dir: -1 | 1) =>
        setD((p) => {
            const items = [...p.items];
            const j = i + dir;
            if (j < 0 || j >= items.length) return p;
            [items[i], items[j]] = [items[j], items[i]];
            return { ...p, items };
        });

    const totals = useMemo(() => {
        const vat = d.vat === 'zw' ? null : Number(d.vat);
        const groups: Record<string, number> = {};
        d.items.forEach((i) => (groups[i.recurring] = (groups[i.recurring] ?? 0) + (Number(i.quantity) || 0) * (Number(i.unit_price) || 0)));
        return (['', 'monthly', 'yearly'] as const)
            .filter((k) => groups[k] !== undefined)
            .map((k) => ({ key: k, label: LABEL[k], net: groups[k], gross: vat === null ? groups[k] : groups[k] * (1 + vat / 100) }));
    }, [d]);

    const save = (after?: () => void) => {
        setSaving(true);
        router.put(
            `/quotes/${quote.id}`,
            { ...d, vat_rate: d.vat === 'zw' ? null : Number(d.vat), items: d.items.map((i) => ({ ...i, recurring: i.recurring || null })) },
            { preserveScroll: true, onFinish: () => setSaving(false), onSuccess: () => after?.() },
        );
    };
    const guard = (fn: () => void) => () => (dirty ? save(fn) : fn());

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Oferty', href: '/quotes' },
                { title: quote.number, href: `/quotes/${quote.id}` },
            ]}
        >
            <Head title={`Oferta ${quote.number}`} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="flex flex-wrap items-center gap-2 text-xl font-semibold">
                            {quote.number} <QuoteStatus status={quote.status} expired={quote.expired} />
                        </h1>
                        <div className="text-muted-foreground text-sm">
                            dla{' '}
                            <Link href={`/clients/${quote.client.id}`} className="hover:underline">
                                {quote.client.name}
                            </Link>
                            {quote.sent_at && <> · wysłana {dateTime(quote.sent_at)}</>}
                            {quote.viewed_at && <> · otwarta przez klienta {dateTime(quote.viewed_at)}</>}
                            {quote.decided_at && (
                                <>
                                    {' '}
                                    · decyzja {dateTime(quote.decided_at)}
                                    {quote.decided_name && ` (${quote.decided_name})`}
                                </>
                            )}
                        </div>
                        {quote.rejection_reason && <div className="mt-1 text-sm">Powód odrzucenia: „{quote.rejection_reason}”</div>}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {!locked && (
                            <Button onClick={() => save()} disabled={!dirty || saving}>
                                <Save className="size-4" /> {saving ? 'Zapisywanie…' : dirty ? 'Zapisz' : 'Zapisano'}
                            </Button>
                        )}
                        <Button variant="outline" asChild>
                            <a
                                href={public_url}
                                target="_blank"
                                rel="noreferrer"
                                onClick={(e) => dirty && (e.preventDefault(), save(() => window.open(public_url, '_blank')))}
                            >
                                <ExternalLink className="size-4" /> Podgląd
                            </a>
                        </Button>
                        <Button variant="outline" asChild>
                            <a href={pdf_url} onClick={(e) => dirty && (e.preventDefault(), save(() => (window.location.href = pdf_url)))}>
                                <Download className="size-4" /> PDF
                            </a>
                        </Button>
                        <Button variant="outline" onClick={() => router.post(`/quotes/${quote.id}/duplicate`)}>
                            <Copy className="size-4" /> Duplikuj
                        </Button>
                        <Button
                            variant="ghost"
                            onClick={() => confirm(`Usunąć ofertę ${quote.number}?`) && router.delete(`/quotes/${quote.id}`)}
                            title="Usuń"
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    </div>
                </div>

                {locked && (
                    <div className="flex items-center gap-2 rounded-lg border px-4 py-2.5 text-sm">
                        <Lock className="size-4" /> Klient podjął decyzję – oferta jest zablokowana. Aby coś zmienić, kliknij „Duplikuj”.
                    </div>
                )}

                {!locked && (
                    <Section
                        title="Wysyłka do klienta"
                        description="Klient dostaje link – może przejrzeć ofertę, pobrać PDF i zaakceptować ją jednym kliknięciem."
                    >
                        <div className="flex flex-wrap items-center gap-2">
                            <Input
                                type="email"
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                placeholder="e-mail klienta"
                                className="w-64"
                            />
                            <Button
                                disabled={!email}
                                onClick={guard(() => router.post(`/quotes/${quote.id}/send`, { email }, { preserveScroll: true }))}
                            >
                                <Mail className="size-4" /> Wyślij e-mailem
                            </Button>
                            <span className="text-muted-foreground text-xs">albo skopiuj link:</span>
                            <code className="bg-muted max-w-72 truncate rounded px-2 py-1 text-xs">{public_url}</code>
                            <CopyButton value={public_url} label="Kopiuj link do oferty" />
                            {quote.status === 'draft' && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={guard(() => router.post(`/quotes/${quote.id}/mark-sent`, {}, { preserveScroll: true }))}
                                >
                                    <Send className="size-4" /> Oznacz jako wysłaną
                                </Button>
                            )}
                        </div>
                    </Section>
                )}

                <Section title="Nagłówek">
                    <fieldset disabled={locked} className="grid gap-3">
                        <div className="grid gap-3 sm:grid-cols-[1fr_auto_auto_auto]">
                            <div className="grid gap-1.5">
                                <Label>Tytuł oferty</Label>
                                <Input value={d.title} onChange={(e) => set('title', e.target.value)} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label>Ważna do</Label>
                                <Input type="date" value={d.valid_until} onChange={(e) => set('valid_until', e.target.value)} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label>VAT</Label>
                                <NativeSelect value={d.vat} onChange={(e) => set('vat', e.target.value)}>
                                    <option value="zw">bez VAT</option>
                                    {['23', '8', '5', '0'].map((v) => (
                                        <option key={v} value={v}>
                                            {v}%
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                            <div className="grid gap-1.5">
                                <Label>Waluta</Label>
                                <NativeSelect value={d.currency} onChange={(e) => set('currency', e.target.value)}>
                                    {['PLN', 'EUR', 'USD', 'GBP'].map((c) => (
                                        <option key={c}>{c}</option>
                                    ))}
                                </NativeSelect>
                            </div>
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Wstęp</Label>
                            <textarea
                                rows={3}
                                value={d.intro}
                                onChange={(e) => set('intro', e.target.value)}
                                className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                placeholder="Krótko: co proponujesz i jaki problem klienta to rozwiązuje."
                            />
                        </div>
                    </fieldset>
                </Section>

                <Section
                    title={`Pozycje (${d.items.length})`}
                    description={`${d.vat === 'zw' ? 'Ceny końcowe (bez VAT).' : 'Ceny netto.'} Pozycje „miesięcznie/rocznie” sumują się osobno (abonament).`}
                    actions={
                        !locked && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    set('items', [...d.items, { name: '', description: '', quantity: 1, unit: 'szt.', unit_price: 0, recurring: '' }])
                                }
                            >
                                <Plus className="size-4" /> Pozycja
                            </Button>
                        )
                    }
                >
                    <fieldset disabled={locked}>
                        <ol className="grid gap-3">
                            {d.items.map((it, i) => (
                                <li key={i} className="grid gap-2 rounded-lg border p-3">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-muted-foreground w-6 text-right text-sm tabular-nums">{i + 1}.</span>
                                        <Input
                                            value={it.name}
                                            onChange={(e) => setItem(i, { name: e.target.value })}
                                            placeholder="Nazwa usługi"
                                            className="min-w-56 flex-1 font-medium"
                                        />
                                        <Input
                                            type="number"
                                            min={0}
                                            step="0.5"
                                            value={it.quantity}
                                            onChange={(e) => setItem(i, { quantity: Number(e.target.value) })}
                                            className="w-20"
                                            aria-label="Ilość"
                                        />
                                        <Input
                                            value={it.unit}
                                            onChange={(e) => setItem(i, { unit: e.target.value })}
                                            className="w-20"
                                            aria-label="Jednostka"
                                        />
                                        <Input
                                            type="number"
                                            min={0}
                                            step="10"
                                            value={it.unit_price}
                                            onChange={(e) => setItem(i, { unit_price: Number(e.target.value) })}
                                            className="w-28"
                                            aria-label="Cena"
                                        />
                                        <NativeSelect
                                            value={it.recurring}
                                            onChange={(e) => setItem(i, { recurring: e.target.value as Item['recurring'] })}
                                            aria-label="Płatność"
                                        >
                                            <option value="">jednorazowo</option>
                                            <option value="monthly">co miesiąc</option>
                                            <option value="yearly">co rok</option>
                                        </NativeSelect>
                                        <span className="w-28 text-right text-sm font-medium tabular-nums">
                                            {money(it.quantity * it.unit_price, d.currency, 2)}
                                        </span>
                                        {!locked && (
                                            <div className="flex">
                                                <Button variant="ghost" size="sm" disabled={i === 0} onClick={() => move(i, -1)} title="Wyżej">
                                                    <ArrowUp className="size-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={i === d.items.length - 1}
                                                    onClick={() => move(i, 1)}
                                                    title="Niżej"
                                                >
                                                    <ArrowDown className="size-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        set(
                                                            'items',
                                                            d.items.filter((_, k) => k !== i),
                                                        )
                                                    }
                                                    title="Usuń pozycję"
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                    <textarea
                                        rows={Math.max(1, it.description.split('\n').length)}
                                        value={it.description}
                                        onChange={(e) => setItem(i, { description: e.target.value })}
                                        placeholder="Opis – co dokładnie obejmuje (opcjonalnie)"
                                        className="border-input bg-background ml-8 rounded-md border px-3 py-1.5 text-sm"
                                    />
                                </li>
                            ))}
                        </ol>
                    </fieldset>

                    {totals.length > 0 && (
                        <div className="mt-4 ml-auto grid w-full max-w-sm gap-1 text-sm">
                            {totals.map((t) => (
                                <div key={t.key} className="flex justify-between gap-4 border-t pt-1">
                                    <span className="text-muted-foreground">{t.label}</span>
                                    <span className="tabular-nums">
                                        <strong>{money(t.net, d.currency, 2)}</strong>
                                        {d.vat !== 'zw' && ` netto · ${money(t.gross, d.currency, 2)} brutto`}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </Section>

                <Section title="Warunki">
                    <textarea
                        rows={4}
                        disabled={locked}
                        value={d.terms}
                        onChange={(e) => set('terms', e.target.value)}
                        className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                    />
                </Section>

                {dirty && !locked && (
                    <div className="bg-background/95 sticky bottom-3 z-10 flex items-center justify-between gap-3 rounded-lg border px-4 py-2 shadow-sm">
                        <span className="text-sm">Masz niezapisane zmiany.</span>
                        <Button size="sm" onClick={() => save()} disabled={saving}>
                            <Save className="size-4" /> Zapisz
                        </Button>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
