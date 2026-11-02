import { withVault } from '@/components/adpilot/vault-unlock';
import { Button } from '@/components/ui/button';
import { Check, Copy, Eye, EyeOff } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/** Pola formularza zależne od rodzaju dostępu. */
export const CATEGORY_META: Record<string, { url: string; urlPlaceholder: string; labelPlaceholder: string; userLabel: string }> = {
    cms: { url: 'Adres logowania', urlPlaceholder: 'https://strona.pl/wp-admin', labelPlaceholder: 'Panel strony', userLabel: 'Login' },
    hosting: {
        url: 'Adres panelu hostingu',
        urlPlaceholder: 'https://panel.hosting.pl',
        labelPlaceholder: 'Hosting – panel klienta',
        userLabel: 'Login / nr klienta',
    },
    ftp: {
        url: 'Serwer (host:port)',
        urlPlaceholder: 'ftp.strona.pl:21  albo  sftp://s1.hosting.pl:22',
        labelPlaceholder: 'FTP – konto główne',
        userLabel: 'Użytkownik',
    },
    database: {
        url: 'Host bazy / phpMyAdmin',
        urlPlaceholder: 'localhost  albo  https://pma.hosting.pl',
        labelPlaceholder: 'MySQL – baza strony (nazwa bazy w notatce)',
        userLabel: 'Użytkownik bazy',
    },
    domain: { url: 'Panel rejestratora', urlPlaceholder: 'https://panel.rejestrator.pl', labelPlaceholder: 'Domena strona.pl', userLabel: 'Login' },
    email: {
        url: 'Webmail / serwer poczty',
        urlPlaceholder: 'https://poczta.strona.pl',
        labelPlaceholder: 'biuro@strona.pl',
        userLabel: 'Adres e-mail',
    },
    google: { url: 'Adres', urlPlaceholder: 'https://analytics.google.com', labelPlaceholder: 'Konto Google firmy', userLabel: 'E-mail' },
    social: { url: 'Adres', urlPlaceholder: 'https://business.facebook.com', labelPlaceholder: 'Facebook / Instagram', userLabel: 'Login' },
    other: { url: 'Adres', urlPlaceholder: 'https://…', labelPlaceholder: 'np. Panel płatności, CRM, newsletter', userLabel: 'Login' },
};

/** POST JSON z tokenem CSRF (fetch – bez okna błędu Inertii). */
export async function postJson(url: string, body?: unknown): Promise<Response> {
    const xsrf = decodeURIComponent(
        document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );
    return fetch(url, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf, 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
    });
}

/**
 * Odczyt hasła. Gdy sejf jest zamknięty (423), pokazuje okno: hasło + kod z aplikacji, potem ponawia.
 * Anulowanie okna → wyjątek 'cancelled' (wywołujący po prostu nic nie pokazuje).
 */
export async function reveal(id: number): Promise<{ secret: string | null; notes: string | null }> {
    const res = await withVault(() => postJson(`/credentials/${id}/reveal`));
    if (res.status === 419) throw new Error('Sesja wygasła – odśwież stronę (F5).');
    if (!res.ok) throw new Error(String(res.status));
    return res.json();
}

/** reveal() bez wyjątku – null, gdy anulowano albo błąd. */
export async function tryReveal(id: number) {
    try {
        return await reveal(id);
    } catch (e) {
        if (e instanceof Error && e.message !== 'cancelled') alert(`Nie udało się pobrać hasła: ${e.message}`);
        return null;
    }
}

export function generatePassword(len = 20) {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*-_=+';
    const arr = new Uint32Array(len);
    crypto.getRandomValues(arr);
    return Array.from(arr, (n) => chars[n % chars.length]).join('');
}

export function CopyButton({ value, label = 'Kopiuj' }: { value: string | null | (() => Promise<string | null>); label?: string }) {
    const [ok, setOk] = useState(false);
    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            className="h-7 px-2"
            title={label}
            aria-label={label}
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

/** Hasło: kropki → „Pokaż” (auto-ukrycie po 30 s) i „Kopiuj” – bez wyświetlania. */
export function SecretValue({ id, onNotes }: { id: number; onNotes?: (notes: string | null) => void }) {
    const [value, setValue] = useState<string | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);
    useEffect(() => () => clearTimeout(timer.current), []);

    const toggle = async () => {
        if (value !== null) {
            setValue(null);
            onNotes?.(null);
            return;
        }
        const r = await tryReveal(id);
        if (!r) return;
        setValue(r.secret ?? '');
        onNotes?.(r.notes);
        clearTimeout(timer.current);
        timer.current = setTimeout(() => {
            setValue(null);
            onNotes?.(null);
        }, 30_000);
    };

    return (
        <span className="inline-flex items-center gap-0.5">
            <span className="font-mono text-sm">{value !== null ? value : '••••••••••'}</span>
            <Button type="button" variant="ghost" size="sm" className="h-7 px-2" title={value !== null ? 'Ukryj' : 'Pokaż'} onClick={toggle}>
                {value !== null ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
            </Button>
            <CopyButton value={async () => value ?? (await tryReveal(id))?.secret ?? null} label="Kopiuj hasło" />
        </span>
    );
}
