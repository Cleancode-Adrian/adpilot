import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { Lock, LockOpen, ShieldCheck } from 'lucide-react';
import { useEffect, useState } from 'react';

type Resolver = (ok: boolean) => void;
let open: ((resolve: Resolver) => void) | null = null;
let unlockedUntil = 0;
const listeners = new Set<(until: number) => void>();
const setUnlocked = (until: number) => {
    unlockedUntil = until;
    listeners.forEach((l) => l(until));
};

/** Prosi o hasło (+ kod 2FA) i zwraca true po odblokowaniu sejfu. */
export function requestVaultUnlock(): Promise<boolean> {
    return new Promise((resolve) => (open ? open(resolve) : resolve(false)));
}

async function post(url: string, body?: unknown) {
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

/** Okno potwierdzenia + licznik „sejf otwarty”. Montowane raz w układzie panelu. */
export function VaultUnlockHost() {
    const { auth, vault } = usePage<SharedData>().props;
    const needsCode = !!auth.user?.two_factor_enabled;
    const [resolver, setResolver] = useState<Resolver | null>(null);
    const [password, setPassword] = useState('');
    const [code, setCode] = useState('');
    const [errors, setErrors] = useState<{ password?: string; code?: string; message?: string }>({});
    const [busy, setBusy] = useState(false);
    const [until, setUntil] = useState(unlockedUntil || (vault?.unlocked_until ?? 0));
    const [now, setNow] = useState(() => Math.floor(Date.now() / 1000));

    useEffect(() => {
        open = (r) => {
            setErrors({});
            setPassword('');
            setCode('');
            setResolver(() => r);
        };
        const l = (u: number) => setUntil(u);
        listeners.add(l);
        return () => {
            open = null;
            listeners.delete(l);
        };
    }, []);

    useEffect(() => {
        if (until <= now) return;
        const t = setInterval(() => setNow(Math.floor(Date.now() / 1000)), 1000);
        return () => clearInterval(t);
    }, [until, now]);

    const close = (ok: boolean) => {
        resolver?.(ok);
        setResolver(null);
        setPassword('');
        setCode('');
    };

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        try {
            const res = await post('/vault/unlock', { password, code });
            const json = await res.json().catch(() => ({}));
            if (res.ok) {
                setUnlocked(json.unlocked_until);
                setNow(Math.floor(Date.now() / 1000));
                close(true);
            } else if (res.status === 419) {
                setErrors({ message: 'Sesja wygasła – odśwież stronę (F5).' });
            } else {
                setErrors({ ...(json.errors ?? {}), message: json.errors ? undefined : json.message });
            }
        } catch {
            setErrors({ message: 'Brak połączenia z serwerem.' });
        } finally {
            setBusy(false);
        }
    };

    const left = until - now;

    return (
        <>
            <Dialog open={!!resolver} onOpenChange={(o) => !o && close(false)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <ShieldCheck className="size-5" /> Potwierdź, że to Ty
                        </DialogTitle>
                        <DialogDescription>
                            Podgląd haseł klientów wymaga ponownego podania hasła{needsCode ? ' i kodu z aplikacji' : ''}. Sejf otworzy się na krótki
                            czas.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="grid gap-4" autoComplete="off">
                        <div className="grid gap-1.5">
                            <Label htmlFor="vault-password">Hasło do panelu</Label>
                            <Input
                                id="vault-password"
                                type="password"
                                autoComplete="current-password"
                                autoFocus
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                            />
                            <InputError message={errors.password} />
                        </div>
                        {needsCode && (
                            <div className="grid gap-1.5">
                                <Label htmlFor="vault-code">Kod z aplikacji</Label>
                                <Input
                                    id="vault-code"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    maxLength={7}
                                    placeholder="123456"
                                    className="font-mono tracking-widest"
                                    value={code}
                                    onChange={(e) => setCode(e.target.value.replace(/[^\d ]/g, ''))}
                                />
                                <InputError message={errors.code} />
                            </div>
                        )}
                        <InputError message={errors.message} />
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => close(false)}>
                                Anuluj
                            </Button>
                            <Button type="submit" disabled={busy || !password || (needsCode && code.replace(/\s/g, '').length < 6)}>
                                <LockOpen className="size-4" /> Odblokuj
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {left > 0 && (
                <div className="bg-background fixed right-4 bottom-4 z-50 flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs shadow-md">
                    <LockOpen className="size-3.5 text-amber-600" />
                    Sejf otwarty: {Math.floor(left / 60)}:{String(left % 60).padStart(2, '0')}
                    <button
                        type="button"
                        className="ml-1 inline-flex items-center gap-1 font-medium underline-offset-2 hover:underline"
                        onClick={async () => {
                            await post('/vault/lock');
                            setUnlocked(0);
                        }}
                    >
                        <Lock className="size-3" /> Zablokuj
                    </button>
                </div>
            )}
        </>
    );
}

/** Wywołanie chronionego endpointu: przy 423 prosi o potwierdzenie i ponawia raz. */
export async function withVault(call: () => Promise<Response>): Promise<Response> {
    let res = await call();
    if (res.status === 423) {
        if (!(await requestVaultUnlock())) throw new Error('cancelled');
        res = await call();
    }
    return res;
}

export const vaultPost = post;
