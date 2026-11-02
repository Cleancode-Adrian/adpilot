import { NativeSelect, Section } from '@/components/adpilot/ui';
import { CATEGORY_META, CopyButton, generatePassword, reveal, SecretValue } from '@/components/adpilot/vault';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ClientLayout from '@/layouts/client-layout';
import { date, dateTime } from '@/lib/format';
import { type ClientProps } from '@/types/adpilot';
import { router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    Database,
    Dices,
    ExternalLink,
    Eye,
    EyeOff,
    FolderTree,
    Globe,
    KeyRound,
    Lock,
    LogIn,
    Pencil,
    Plus,
    Server,
    Trash2,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';

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

/** Stały podział sejfu. „Pozostałe” zbiera domenę, pocztę, Google, social media i inne. */
const SECTIONS = [
    { key: 'cms', title: 'Logowanie do strony', icon: LogIn, categories: ['cms'], hint: 'Panel administracyjny strony (WordPress, sklep, CMS).' },
    { key: 'hosting', title: 'Hosting', icon: Server, categories: ['hosting'], hint: 'Panel firmy hostingowej – faktury, kopie, PHP, poczta.' },
    { key: 'ftp', title: 'FTP / SFTP / SSH', icon: FolderTree, categories: ['ftp'], hint: 'Dostęp do plików strony na serwerze.' },
    { key: 'database', title: 'Baza danych', icon: Database, categories: ['database'], hint: 'MySQL / phpMyAdmin.' },
    {
        key: 'other',
        title: 'Pozostałe',
        icon: Globe,
        categories: ['domain', 'email', 'google', 'social', 'other'],
        hint: 'Domena i DNS, poczta, konta Google, social media, inne usługi.',
    },
] as const;

function CredentialForm({
    clientId,
    categories,
    editing,
    defaultCategory,
    allowedCategories,
    defaultUrl,
    onDone,
}: {
    clientId: number;
    categories: Record<string, string>;
    editing?: Credential;
    defaultCategory: string;
    allowedCategories: readonly string[];
    defaultUrl?: string | null;
    onDone: () => void;
}) {
    const form = useForm({
        category: editing?.category ?? defaultCategory,
        label: editing?.label ?? '',
        url: editing?.url ?? (defaultCategory === 'cms' ? (defaultUrl ?? '') : ''),
        username: editing?.username ?? '',
        secret: '',
        notes: '',
        notes_changed: !editing,
    });
    const [show, setShow] = useState(false);
    const [loadingNotes, setLoadingNotes] = useState(!!editing?.has_notes);
    const meta = CATEGORY_META[form.data.category] ?? CATEGORY_META.other;

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
        form.transform((d) => ({ ...d, label: d.label || meta.labelPlaceholder }));
        const opts = { preserveScroll: true, onSuccess: () => onDone() };
        if (editing) form.put(`/credentials/${editing.id}`, opts);
        else form.post(`/clients/${clientId}/credentials`, opts);
    };

    return (
        <form onSubmit={submit} className="bg-muted/30 grid gap-3 rounded-lg border p-3" autoComplete="off">
            <div className="grid gap-3 sm:grid-cols-2">
                {allowedCategories.length > 1 && (
                    <div className="grid gap-1.5">
                        <Label>Rodzaj</Label>
                        <NativeSelect value={form.data.category} onChange={(e) => form.setData('category', e.target.value)}>
                            {allowedCategories.map((k) => (
                                <option key={k} value={k}>
                                    {categories[k]}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                )}
                <div className="grid gap-1.5">
                    <Label>Nazwa</Label>
                    <Input value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} placeholder={meta.labelPlaceholder} />
                    <InputError message={form.errors.label} />
                </div>
                <div className={`grid gap-1.5 ${allowedCategories.length > 1 ? 'sm:col-span-2' : ''}`}>
                    <Label>{meta.url}</Label>
                    <Input value={form.data.url} onChange={(e) => form.setData('url', e.target.value)} placeholder={meta.urlPlaceholder} />
                </div>
                <div className="grid gap-1.5">
                    <Label>{meta.userLabel}</Label>
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
                    rows={2}
                    value={form.data.notes}
                    disabled={loadingNotes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                    className="border-input bg-background rounded-md border px-3 py-2 font-mono text-sm"
                    placeholder={loadingNotes ? 'Wczytywanie…' : 'np. nazwa bazy, port, kody zapasowe 2FA, PIN, numer klienta'}
                />
            </div>
            <div className="flex gap-2">
                <Button type="submit" size="sm" disabled={form.processing || loadingNotes}>
                    <Check className="size-4" /> {editing ? 'Zapisz zmiany' : 'Zapisz'}
                </Button>
                <Button type="button" variant="ghost" size="sm" onClick={onDone}>
                    <X className="size-4" /> Anuluj
                </Button>
            </div>
        </form>
    );
}

function CredentialRow({ c, categoryLabel, onEdit }: { c: Credential; categoryLabel?: string; onEdit: () => void }) {
    const [notes, setNotes] = useState<string | null>(null);
    const old = c.secret_changed_at && Date.now() - new Date(c.secret_changed_at).getTime() > 180 * 86400_000;
    const isWeb = !!c.url && /^(https?:\/\/|www\.)/i.test(c.url);

    return (
        <li className="py-2.5 first:pt-0 last:pb-0">
            <div className="grid items-center gap-x-4 gap-y-1 md:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
                <div className="min-w-0">
                    <div className="truncate text-sm font-medium">
                        {c.label}
                        {categoryLabel && <span className="text-muted-foreground ml-2 text-xs font-normal">{categoryLabel}</span>}
                    </div>
                    {c.url &&
                        (isWeb ? (
                            <a
                                href={/^https?:\/\//i.test(c.url) ? c.url : `https://${c.url}`}
                                target="_blank"
                                rel="noreferrer noopener"
                                className="text-muted-foreground inline-flex max-w-full items-center gap-1 text-xs hover:underline"
                            >
                                <span className="truncate">{c.url}</span> <ExternalLink className="size-3 shrink-0" />
                            </a>
                        ) : (
                            <span className="text-muted-foreground inline-flex items-center gap-0.5 font-mono text-xs">
                                {c.url} <CopyButton value={c.url} label="Kopiuj adres" />
                            </span>
                        ))}
                </div>
                <div className="flex min-w-0 items-center gap-1 text-sm">
                    {c.username ? (
                        <>
                            <span className="truncate font-mono">{c.username}</span>
                            <CopyButton value={c.username} label="Kopiuj login" />
                        </>
                    ) : (
                        <span className="text-muted-foreground text-xs">brak loginu</span>
                    )}
                </div>
                <div className="text-sm">
                    {c.has_secret ? (
                        <SecretValue id={c.id} onNotes={setNotes} />
                    ) : c.has_notes ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-7 px-2"
                            onClick={async () => setNotes(notes ? null : (await reveal(c.id)).notes)}
                        >
                            <Eye className="size-4" /> notatki
                        </Button>
                    ) : (
                        <span className="text-muted-foreground text-xs">brak hasła</span>
                    )}
                </div>
                <div className="flex justify-end">
                    <Button type="button" variant="ghost" size="sm" className="h-7 px-2" title="Edytuj" onClick={onEdit}>
                        <Pencil className="size-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-7 px-2"
                        title="Usuń"
                        onClick={() =>
                            confirm(`Usunąć „${c.label}”? Tej operacji nie da się cofnąć.`) &&
                            router.delete(`/credentials/${c.id}`, { preserveScroll: true })
                        }
                    >
                        <Trash2 className="size-4" />
                    </Button>
                </div>
            </div>
            {(old || (c.has_notes && !notes)) && (
                <div className="text-muted-foreground mt-1 flex flex-wrap gap-x-3 text-xs">
                    {old && (
                        <span className="inline-flex items-center gap-1 font-medium text-[#a4471f] dark:text-[#ec835a]">
                            <AlertTriangle className="size-3" /> hasło z {date(c.secret_changed_at)} – ponad pół roku, rozważ zmianę
                        </span>
                    )}
                    {c.has_notes && !notes && <span>ma notatki (widoczne po „Pokaż”)</span>}
                </div>
            )}
            {notes && <pre className="bg-muted mt-2 rounded-md p-2 font-mono text-xs whitespace-pre-wrap">{notes}</pre>}
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
    // 'add:<sekcja>' albo id edytowanego wpisu
    const [mode, setMode] = useState<string | number | null>(null);

    return (
        <ClientLayout client={client} title="Dane dostępowe">
            <div className="flex items-start gap-2 rounded-lg border px-4 py-3 text-sm">
                <Lock className="mt-0.5 size-4 shrink-0" />
                <p className="text-muted-foreground">
                    Hasła i notatki są <strong className="text-foreground">szyfrowane</strong>. Widzą je tylko administratorzy, a każde odsłonięcie
                    trafia do dziennika. Hasło samo się ukrywa po 30 s; „Kopiuj” kopiuje je bez wyświetlania.
                </p>
            </div>

            <div className="grid gap-4 xl:grid-cols-[1fr_320px]">
                <div className="grid content-start gap-4">
                    {SECTIONS.map((s) => {
                        const items = credentials.filter((c) => (s.categories as readonly string[]).includes(c.category));
                        const adding = mode === `add:${s.key}`;
                        return (
                            <Section
                                key={s.key}
                                title={`${s.title}${items.length ? ` (${items.length})` : ''}`}
                                description={s.hint}
                                actions={
                                    !adding && (
                                        <Button variant="outline" size="sm" onClick={() => setMode(`add:${s.key}`)}>
                                            <Plus className="size-4" /> Dodaj
                                        </Button>
                                    )
                                }
                            >
                                {adding && (
                                    <div className="mb-3">
                                        <CredentialForm
                                            clientId={client.id}
                                            categories={categories}
                                            defaultCategory={s.categories[0]}
                                            allowedCategories={s.categories}
                                            defaultUrl={client.admin_url}
                                            onDone={() => setMode(null)}
                                        />
                                    </div>
                                )}
                                {items.length === 0 && !adding ? (
                                    <button
                                        type="button"
                                        onClick={() => setMode(`add:${s.key}`)}
                                        className="text-muted-foreground text-sm hover:underline"
                                    >
                                        Brak – kliknij, żeby dodać.
                                    </button>
                                ) : (
                                    <ul className="divide-y">
                                        {items.map((c) =>
                                            mode === c.id ? (
                                                <li key={c.id} className="py-2.5">
                                                    <CredentialForm
                                                        clientId={client.id}
                                                        categories={categories}
                                                        editing={c}
                                                        defaultCategory={c.category}
                                                        allowedCategories={s.categories}
                                                        onDone={() => setMode(null)}
                                                    />
                                                </li>
                                            ) : (
                                                <CredentialRow
                                                    key={c.id}
                                                    c={c}
                                                    categoryLabel={s.categories.length > 1 ? categories[c.category] : undefined}
                                                    onEdit={() => setMode(c.id)}
                                                />
                                            ),
                                        )}
                                    </ul>
                                )}
                            </Section>
                        );
                    })}
                </div>

                <Section title="Dziennik dostępu" description="Ostatnie 30 operacji" className="self-start">
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
