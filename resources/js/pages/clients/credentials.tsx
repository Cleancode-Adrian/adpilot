import { EmptyState, NativeSelect, Section } from '@/components/adpilot/ui';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ClientLayout from '@/layouts/client-layout';
import { date, dateTime } from '@/lib/format';
import { type ClientProps } from '@/types/adpilot';
import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, Check, Copy, Dices, ExternalLink, Eye, EyeOff, KeyRound, Lock, Pencil, Plus, Trash2, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Credential {
    id: number;
    category: string;
    label: string;
    url: string | null;
    username: string | null;
    has_secret: boolean;
    has_notes: boolean;
    secret_changed_at: string | null;
    updated_at: string;
}

interface Log {
    id: number;
    action: 'view' | 'create' | 'update' | 'delete';
    label: string;
    ip: string | null;
    created_at: string;
    user: { id: number; name: string } | null;
}

const ACTIONS: Record<Log['action'], string> = { view: 'odsłonił(a)', create: 'dodał(a)', update: 'zmienił(a)', delete: 'usunął/usunęła' };

/** Odczyt hasła – POST z tokenem CSRF; 423 = trzeba ponownie potwierdzić hasło do panelu. */
async function reveal(id: number): Promise<{ secret: string | null; notes: string | null }> {
    const xsrf = decodeURIComponent(
        document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );
    const res = await fetch(`/credentials/${id}/reveal`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf, 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    if (res.status === 423 || res.status === 401) {
        window.location.reload(); // przekieruje do potwierdzenia hasła
        throw new Error('confirm');
    }
    if (!res.ok) throw new Error(String(res.status));
    return res.json();
}

function generatePassword(len = 20) {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*-_=+';
    const arr = new Uint32Array(len);
    crypto.getRandomValues(arr);
    return Array.from(arr, (n) => chars[n % chars.length]).join('');
}

function CopyButton({ value, label = 'Kopiuj' }: { value: string | null | (() => Promise<string | null>); label?: string }) {
    const [ok, setOk] = useState(false);
    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            title={label}
            onClick={async () => {
                const v = typeof value === 'function' ? await value() : value;
                if (!v) return;
                await navigator.clipboard?.writeText(v);
                setOk(true);
                setTimeout(() => setOk(false), 1500);
            }}
        >
            {ok ? <Check className="size-4" /> : <Copy className="size-4" />}
        </Button>
    );
}

function CredentialForm({
    clientId,
    categories,
    editing,
    onDone,
}: {
    clientId: number;
    categories: Record<string, string>;
    editing?: Credential;
    onDone: () => void;
}) {
    const form = useForm({
        category: editing?.category ?? 'cms',
        label: editing?.label ?? '',
        url: editing?.url ?? '',
        username: editing?.username ?? '',
        secret: '',
        notes: '',
        notes_changed: !editing,
    });
    const [show, setShow] = useState(false);
    const [loadingNotes, setLoadingNotes] = useState(!!editing?.has_notes);

    useEffect(() => {
        if (editing?.has_notes) {
            reveal(editing.id)
                .then((r) => form.setData((d) => ({ ...d, notes: r.notes ?? '', notes_changed: true })))
                .finally(() => setLoadingNotes(false));
        } else if (editing) {
            form.setData('notes_changed', true);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onDone() };
        if (editing) form.put(`/credentials/${editing.id}`, opts);
        else form.post(`/clients/${clientId}/credentials`, opts);
    };

    return (
        <form onSubmit={submit} className="grid gap-3" autoComplete="off">
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="grid gap-1.5">
                    <Label>Rodzaj</Label>
                    <NativeSelect value={form.data.category} onChange={(e) => form.setData('category', e.target.value)}>
                        {Object.entries(categories).map(([k, v]) => (
                            <option key={k} value={k}>
                                {v}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
                <div className="grid gap-1.5">
                    <Label>Nazwa</Label>
                    <Input
                        required
                        value={form.data.label}
                        onChange={(e) => form.setData('label', e.target.value)}
                        placeholder="np. WordPress – administrator"
                    />
                    <InputError message={form.errors.label} />
                </div>
                <div className="grid gap-1.5 sm:col-span-2">
                    <Label>Adres logowania</Label>
                    <Input value={form.data.url} onChange={(e) => form.setData('url', e.target.value)} placeholder="https://strona.pl/wp-admin" />
                </div>
                <div className="grid gap-1.5">
                    <Label>Login</Label>
                    <Input value={form.data.username} onChange={(e) => form.setData('username', e.target.value)} autoComplete="off" />
                </div>
                <div className="grid gap-1.5">
                    <Label>Hasło</Label>
                    <div className="flex gap-1">
                        <Input
                            type={show ? 'text' : 'password'}
                            value={form.data.secret}
                            onChange={(e) => form.setData('secret', e.target.value)}
                            autoComplete="new-password"
                            placeholder={editing?.has_secret ? 'zostaw puste – bez zmian' : ''}
                            className="font-mono"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-9"
                            title={show ? 'Ukryj' : 'Pokaż'}
                            onClick={() => setShow(!show)}
                        >
                            {show ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-9"
                            title="Wygeneruj silne hasło"
                            onClick={() => {
                                form.setData('secret', generatePassword());
                                setShow(true);
                            }}
                        >
                            <Dices className="size-4" />
                        </Button>
                    </div>
                </div>
            </div>
            <div className="grid gap-1.5">
                <Label>Notatki (szyfrowane)</Label>
                <textarea
                    rows={3}
                    value={form.data.notes}
                    disabled={loadingNotes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                    className="border-input bg-background rounded-md border px-3 py-2 font-mono text-sm"
                    placeholder={loadingNotes ? 'Wczytywanie…' : 'np. kody zapasowe 2FA, PIN, pytanie pomocnicze, numer klienta u hostingodawcy'}
                />
            </div>
            <div className="flex gap-2">
                <Button type="submit" size="sm" disabled={form.processing || loadingNotes}>
                    <Check className="size-4" /> {editing ? 'Zapisz zmiany' : 'Zapisz dostęp'}
                </Button>
                <Button type="button" variant="ghost" size="sm" onClick={onDone}>
                    <X className="size-4" /> Anuluj
                </Button>
            </div>
        </form>
    );
}

function CredentialRow({ c, onEdit }: { c: Credential; onEdit: () => void }) {
    const [revealed, setRevealed] = useState<{ secret: string | null; notes: string | null } | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);
    const old = c.secret_changed_at && Date.now() - new Date(c.secret_changed_at).getTime() > 180 * 86400_000;

    const show = async () => {
        if (revealed) {
            setRevealed(null);
            return;
        }
        setRevealed(await reveal(c.id));
        clearTimeout(timer.current);
        timer.current = setTimeout(() => setRevealed(null), 30_000); // auto-ukrycie po 30 s
    };
    useEffect(() => () => clearTimeout(timer.current), []);

    return (
        <li className="rounded-lg border px-3 py-2">
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                <div className="min-w-48 flex-1">
                    <div className="text-sm font-medium">{c.label}</div>
                    {c.url && (
                        <a
                            href={/^https?:\/\//i.test(c.url) ? c.url : `https://${c.url}`}
                            target="_blank"
                            rel="noreferrer noopener"
                            className="text-muted-foreground inline-flex items-center gap-1 text-xs hover:underline"
                        >
                            {c.url} <ExternalLink className="size-3" />
                        </a>
                    )}
                </div>
                {c.username && (
                    <div className="flex items-center gap-1 text-sm">
                        <span className="text-muted-foreground text-xs">login:</span>
                        <span className="font-mono">{c.username}</span>
                        <CopyButton value={c.username} label="Kopiuj login" />
                    </div>
                )}
                {c.has_secret && (
                    <div className="flex items-center gap-1 text-sm">
                        <span className="text-muted-foreground text-xs">hasło:</span>
                        <span className="font-mono">{revealed ? revealed.secret : '••••••••••'}</span>
                        <Button type="button" variant="ghost" size="sm" title={revealed ? 'Ukryj' : 'Pokaż'} onClick={show}>
                            {revealed ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                        </Button>
                        <CopyButton value={async () => revealed?.secret ?? (await reveal(c.id)).secret} label="Kopiuj hasło" />
                    </div>
                )}
                <div className="flex gap-1">
                    {c.has_notes && !c.has_secret && (
                        <Button type="button" variant="ghost" size="sm" title="Pokaż notatki" onClick={show}>
                            {revealed ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                        </Button>
                    )}
                    <Button type="button" variant="ghost" size="sm" title="Edytuj" onClick={onEdit}>
                        <Pencil className="size-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        title="Usuń"
                        onClick={() =>
                            confirm(`Usunąć dostęp „${c.label}”? Tej operacji nie da się cofnąć.`) &&
                            router.delete(`/credentials/${c.id}`, { preserveScroll: true })
                        }
                    >
                        <Trash2 className="size-4" />
                    </Button>
                </div>
            </div>
            <div className="text-muted-foreground mt-1 flex flex-wrap gap-x-3 text-xs">
                {c.secret_changed_at && (
                    <span className={old ? 'inline-flex items-center gap-1 font-medium text-[#a4471f] dark:text-[#ec835a]' : ''}>
                        {old && <AlertTriangle className="size-3" />}
                        hasło ustawione {date(c.secret_changed_at)}
                        {old && ' – ponad pół roku, rozważ zmianę'}
                    </span>
                )}
                {c.has_notes && !revealed && <span>ma notatki</span>}
            </div>
            {revealed?.notes && <pre className="bg-muted mt-2 rounded-md p-2 font-mono text-xs whitespace-pre-wrap">{revealed.notes}</pre>}
        </li>
    );
}

export default function Credentials({
    client,
    credentials,
    logs,
    categories,
}: {
    client: ClientProps;
    credentials: Credential[];
    logs: Log[];
    categories: Record<string, string>;
}) {
    const [mode, setMode] = useState<'list' | 'add' | number>('list');
    const groups = Object.keys(categories)
        .map((k) => ({ key: k, label: categories[k], items: credentials.filter((c) => c.category === k) }))
        .filter((g) => g.items.length);
    const editing = typeof mode === 'number' ? credentials.find((c) => c.id === mode) : undefined;

    return (
        <ClientLayout client={client} title="Dostępy">
            <div className="flex items-start gap-2 rounded-lg border px-4 py-3 text-sm">
                <Lock className="mt-0.5 size-4 shrink-0" />
                <p className="text-muted-foreground">
                    Hasła i notatki są <strong className="text-foreground">szyfrowane</strong> w bazie. Widzą je tylko administratorzy, po ponownym
                    podaniu hasła do panelu. Każde odsłonięcie zapisuje się w dzienniku, a hasło samo się ukrywa po 30 sekundach.
                </p>
            </div>

            <div className="grid gap-4 lg:grid-cols-[1fr_340px]">
                <div className="grid content-start gap-4">
                    {mode !== 'list' ? (
                        <Section title={editing ? `Edycja: ${editing.label}` : 'Nowy dostęp'}>
                            <CredentialForm
                                key={String(mode)}
                                clientId={client.id}
                                categories={categories}
                                editing={editing}
                                onDone={() => setMode('list')}
                            />
                        </Section>
                    ) : (
                        <div>
                            <Button size="sm" onClick={() => setMode('add')}>
                                <Plus className="size-4" /> Dodaj dostęp
                            </Button>
                        </div>
                    )}

                    {credentials.length === 0 ? (
                        <EmptyState title="Brak zapisanych dostępów">
                            Zapisz tu loginy do panelu strony, hostingu, FTP, bazy danych, domeny i poczty klienta.
                        </EmptyState>
                    ) : (
                        groups.map((g) => (
                            <Section key={g.key} title={g.label}>
                                <ul className="grid gap-2">
                                    {g.items.map((c) => (
                                        <CredentialRow key={c.id} c={c} onEdit={() => setMode(c.id)} />
                                    ))}
                                </ul>
                            </Section>
                        ))
                    )}
                </div>

                <Section title="Dziennik dostępu" description="Ostatnie 30 operacji">
                    {logs.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Brak wpisów.</p>
                    ) : (
                        <ul className="grid gap-2 text-xs">
                            {logs.map((l) => (
                                <li key={l.id} className="flex gap-2">
                                    <KeyRound className="text-muted-foreground mt-0.5 size-3 shrink-0" />
                                    <span>
                                        <span className="text-muted-foreground">{dateTime(l.created_at)}</span> ·{' '}
                                        <strong>{l.user?.name ?? 'usunięty użytkownik'}</strong> {ACTIONS[l.action]} „{l.label}”
                                        {l.ip && <span className="text-muted-foreground"> ({l.ip})</span>}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>
            </div>
        </ClientLayout>
    );
}
