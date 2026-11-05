import { NativeSelect, NetGross } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { date, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { AlarmClock, Check, CheckCircle2, Globe, Pencil, RefreshCw, Trash2, X } from 'lucide-react';
import { useState } from 'react';

export interface BillingItem {
    id: number;
    client_id: number;
    type: 'subscription' | 'domain' | 'hosting' | 'license' | 'other';
    name: string;
    provider: string | null;
    amount: number | null;
    currency: string;
    period: 'monthly' | 'quarterly' | 'yearly' | 'once';
    next_date: string | null;
    auto_renew: boolean;
    remind_days: number;
    active: boolean;
    notes: string | null;
    days_left: number | null;
    monthly_value: number;
    client?: { id: number; name: string };
}

type Form = Pick<BillingItem, 'type' | 'name' | 'provider' | 'period' | 'next_date' | 'auto_renew' | 'remind_days' | 'notes'> & {
    amount: string;
    active: boolean;
};

const PLACEHOLDER: Record<BillingItem['type'], string> = {
    subscription: 'np. Opieka nad stroną – Standard',
    domain: 'np. firma.pl',
    hosting: 'np. Hosting WWW + poczta',
    license: 'np. Elementor Pro / WP Rocket',
    other: 'np. Certyfikat, usługa SMS',
};

/** Termin: ikona + tekst (nie sam kolor). */
export function DueLabel({ item }: { item: BillingItem }) {
    if (!item.next_date) return <span className="text-muted-foreground text-xs">bez terminu</span>;
    const d = item.days_left ?? 0;
    const cls =
        d < 0
            ? 'text-[#b42f2f] dark:text-[#f08080] font-semibold'
            : d <= item.remind_days
              ? 'text-[#a4471f] dark:text-[#ec835a] font-medium'
              : 'text-muted-foreground';
    return (
        <span className={cn('inline-flex items-center gap-1 text-xs whitespace-nowrap', cls)}>
            {d <= item.remind_days && <AlarmClock className="size-3.5" />}
            {date(item.next_date)} · {d < 0 ? `${-d} dni po terminie` : d === 0 ? 'dziś' : `za ${d} dni`}
        </span>
    );
}

export interface ExtraWork {
    client: { id: number; name: string; currency: string };
    total: number;
    tasks: { id: number; title: string; amount: number; done_at: string | null; minutes: number | null }[];
}

/** Wykonane zadania z kwotą – do dopisania na fakturę. */
export function ExtraWorkList({ groups }: { groups: ExtraWork[] }) {
    if (!groups.length)
        return <p className="text-muted-foreground text-sm">Brak prac dodatkowych do zafakturowania. Kwotę wpiszesz w rozwiniętym zadaniu.</p>;
    return (
        <div className="grid gap-3">
            {groups.map((g) => (
                <div key={g.client.id} className="rounded-lg border p-3">
                    <div className="flex flex-wrap items-center gap-3">
                        <Link href={`/clients/${g.client.id}/billing`} className="font-medium hover:underline">
                            {g.client.name}
                        </Link>
                        <NetGross value={g.total} currency={g.client.currency} className="ml-auto font-semibold" />
                        <Button
                            size="sm"
                            variant="outline"
                            className="h-7"
                            onClick={() =>
                                confirm(`Oznaczyć ${g.tasks.length} prac (${money(g.total, g.client.currency, 2)}) jako zafakturowane?`) &&
                                router.post(`/clients/${g.client.id}/billing/extra-work-invoiced`, {}, { preserveScroll: true })
                            }
                        >
                            <CheckCircle2 className="size-3.5" /> Zafakturowano
                        </Button>
                    </div>
                    <ul className="mt-2 grid gap-0.5 text-sm">
                        {g.tasks.map((t) => (
                            <li key={t.id} className="flex justify-between gap-3">
                                <span>
                                    {t.title} <span className="text-muted-foreground text-xs">{date(t.done_at)}</span>
                                </span>
                                <NetGross value={t.amount} currency={g.client.currency} className="text-right" />
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
        </div>
    );
}

export function BillingForm({
    clientId,
    item,
    types,
    periods,
    onDone,
}: {
    clientId: number;
    item?: BillingItem;
    types: Record<string, string>;
    periods: Record<string, string>;
    onDone: () => void;
}) {
    const [f, setF] = useState<Form>({
        type: item?.type ?? 'subscription',
        name: item?.name ?? '',
        provider: item?.provider ?? '',
        amount: item?.amount != null ? String(item.amount) : '',
        period: item?.period ?? 'monthly',
        next_date: item?.next_date?.slice(0, 10) ?? '',
        auto_renew: item?.auto_renew ?? false,
        remind_days: item?.remind_days ?? 14,
        notes: item?.notes ?? '',
        active: item?.active ?? true,
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const set = <K extends keyof Form>(k: K, v: Form[K]) => setF((p) => ({ ...p, [k]: v }));

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const payload = { ...f, amount: f.amount === '' ? null : Number(f.amount), next_date: f.next_date || null };
        const opts = { preserveScroll: true, onSuccess: onDone, onError: (er: Record<string, string>) => setErrors(er) };
        if (item) router.put(`/billing/${item.id}`, payload, opts);
        else router.post(`/clients/${clientId}/billing`, payload, opts);
    };

    return (
        <form onSubmit={submit} className="bg-muted/30 grid gap-3 rounded-lg border p-3">
            <div className="grid gap-3 sm:grid-cols-[auto_1fr_auto]">
                <div className="grid gap-1.5">
                    <Label>Rodzaj</Label>
                    <NativeSelect
                        value={f.type}
                        onChange={(e) => {
                            const t = e.target.value as Form['type'];
                            setF((p) => ({
                                ...p,
                                type: t,
                                period: t === 'subscription' ? 'monthly' : p.period === 'monthly' ? 'yearly' : p.period,
                                remind_days: t === 'domain' ? 30 : p.remind_days,
                            }));
                        }}
                    >
                        {Object.entries(types).map(([k, v]) => (
                            <option key={k} value={k}>
                                {v}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
                <div className="grid gap-1.5">
                    <Label>{f.type === 'domain' ? 'Domena' : 'Nazwa'}</Label>
                    <Input required value={f.name} onChange={(e) => set('name', e.target.value)} placeholder={PLACEHOLDER[f.type]} />
                    {errors.name && <span className="text-xs text-[#b42f2f]">{errors.name}</span>}
                </div>
                <div className="grid gap-1.5">
                    <Label>Dostawca / rejestrator</Label>
                    <Input value={f.provider ?? ''} onChange={(e) => set('provider', e.target.value)} placeholder="np. OVH, home.pl" />
                </div>
            </div>
            <div className="grid gap-3 sm:grid-cols-5">
                <div className="grid gap-1.5">
                    <Label>Kwota netto</Label>
                    <Input type="number" min={0} step="0.01" value={f.amount} onChange={(e) => set('amount', e.target.value)} placeholder="0,00" />
                </div>
                <div className="grid gap-1.5">
                    <Label>Okres</Label>
                    <NativeSelect value={f.period} onChange={(e) => set('period', e.target.value as Form['period'])}>
                        {Object.entries(periods).map(([k, v]) => (
                            <option key={k} value={k}>
                                {v}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
                <div className="grid gap-1.5">
                    <Label>{f.type === 'subscription' ? 'Następna faktura' : 'Odnowienie / wygasa'}</Label>
                    <Input type="date" value={f.next_date ?? ''} onChange={(e) => set('next_date', e.target.value)} />
                </div>
                <div className="grid gap-1.5">
                    <Label>Przypomnij (dni przed)</Label>
                    <Input type="number" min={0} max={120} value={f.remind_days} onChange={(e) => set('remind_days', Number(e.target.value))} />
                </div>
                <label className="flex items-end gap-2 pb-2 text-sm">
                    <input type="checkbox" checked={f.auto_renew} onChange={(e) => set('auto_renew', e.target.checked)} /> Odnawia się automatycznie
                </label>
            </div>
            <Input
                value={f.notes ?? ''}
                onChange={(e) => set('notes', e.target.value)}
                placeholder="Notatki (np. faktura na spółkę, karta klienta, numer umowy)"
            />
            <div className="flex flex-wrap gap-2">
                <Button type="submit" size="sm">
                    <Check className="size-4" /> {item ? 'Zapisz' : 'Dodaj'}
                </Button>
                <Button type="button" variant="ghost" size="sm" onClick={onDone}>
                    <X className="size-4" /> Anuluj
                </Button>
                {item && (
                    <label className="ml-auto flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={f.active} onChange={(e) => set('active', e.target.checked)} /> Aktywna
                    </label>
                )}
            </div>
        </form>
    );
}

export function BillingRow({
    item,
    types,
    periods,
    showClient = false,
    onEdit,
}: {
    item: BillingItem;
    types: Record<string, string>;
    periods: Record<string, string>;
    showClient?: boolean;
    onEdit?: () => void;
}) {
    return (
        <li className={cn('flex flex-wrap items-center gap-x-4 gap-y-1 py-2.5', !item.active && 'opacity-50')}>
            <div className="min-w-48 flex-1">
                <div className="text-sm font-medium">
                    {item.name}
                    <span className="text-muted-foreground ml-2 text-xs font-normal">
                        {types[item.type]}
                        {item.provider && ` · ${item.provider}`}
                        {item.auto_renew && ' · auto-odnowienie'}
                    </span>
                </div>
                {showClient && item.client && (
                    <Link href={`/clients/${item.client.id}/billing`} className="text-muted-foreground text-xs hover:underline">
                        {item.client.name}
                    </Link>
                )}
                {item.notes && <div className="text-muted-foreground text-xs">{item.notes}</div>}
            </div>
            <div className="text-sm tabular-nums">
                <NetGross value={item.amount} currency={item.currency} />{' '}
                <span className="text-muted-foreground text-xs">{periods[item.period]}</span>
            </div>
            <DueLabel item={item} />
            <div className="flex">
                {item.active && item.next_date && (
                    <Button
                        variant="outline"
                        size="sm"
                        className="h-7"
                        title={item.period === 'once' ? 'Zamknij pozycję' : 'Przesuń termin o okres'}
                        onClick={() => router.post(`/billing/${item.id}/advance`, {}, { preserveScroll: true })}
                    >
                        <CheckCircle2 className="size-3.5" /> {item.type === 'subscription' ? 'Rozliczono' : 'Odnowiono'}
                    </Button>
                )}
                {item.type === 'domain' && (
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-7"
                        title="Pobierz datę wygaśnięcia z rejestru domen"
                        onClick={() => router.post(`/billing/${item.id}/lookup`, {}, { preserveScroll: true })}
                    >
                        <Globe className="size-3.5" /> <RefreshCw className="size-3" />
                    </Button>
                )}
                {onEdit && (
                    <Button variant="ghost" size="sm" className="h-7" title="Edytuj" onClick={onEdit}>
                        <Pencil className="size-3.5" />
                    </Button>
                )}
                <Button
                    variant="ghost"
                    size="sm"
                    className="h-7"
                    title="Usuń"
                    onClick={() => confirm(`Usunąć „${item.name}”?`) && router.delete(`/billing/${item.id}`, { preserveScroll: true })}
                >
                    <Trash2 className="size-3.5" />
                </Button>
            </div>
        </li>
    );
}
