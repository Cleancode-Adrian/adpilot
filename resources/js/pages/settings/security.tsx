import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Copy, KeyRound, Laptop, LogOut, ShieldCheck, ShieldOff, Smartphone, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface Props {
    twoFactor: {
        enabled: boolean;
        confirmed_at: string | null;
        recovery_left: number;
        required: boolean;
        setup: { secret: string; qr: string } | null;
    };
    recoveryCodes: string[] | null;
    devices: { id: number; user_agent: string | null; ip: string | null; last_used_at: string | null; created_at: string; current: boolean }[];
    sessions: { ip: string | null; agent: string; last_activity: string; current: boolean }[];
    events: {
        id: number;
        type: string;
        label: string;
        email: string | null;
        ip: string | null;
        user_agent: string | null;
        created_at: string;
        user: string | null;
    }[];
    eventsScope: 'all' | 'own';
}

const BAD = new Set([
    'login_failed',
    'login_locked',
    'two_factor_failed',
    'vault_unlock_failed',
    'login_new_device',
    'two_factor_disabled',
    'email_changed',
]);

/** Uproszczona nazwa przeglądarki i systemu z User-Agent. */
function device(ua: string | null) {
    if (!ua) return 'Nieznane urządzenie';
    const browser = /Edg\//.test(ua)
        ? 'Edge'
        : /OPR\//.test(ua)
          ? 'Opera'
          : /Firefox\//.test(ua)
            ? 'Firefox'
            : /Chrome\//.test(ua)
              ? 'Chrome'
              : /Safari\//.test(ua)
                ? 'Safari'
                : 'Przeglądarka';
    const os = /Windows/.test(ua)
        ? 'Windows'
        : /iPhone|iPad/.test(ua)
          ? 'iOS'
          : /Android/.test(ua)
            ? 'Android'
            : /Mac OS/.test(ua)
              ? 'macOS'
              : /Linux/.test(ua)
                ? 'Linux'
                : '';
    return `${browser}${os ? ` · ${os}` : ''}`;
}

function TwoFactorSection({ twoFactor, recoveryCodes }: Pick<Props, 'twoFactor' | 'recoveryCodes'>) {
    const form = useForm({ code: '' });
    const [copied, setCopied] = useState(false);

    if (twoFactor.setup) {
        return (
            <div className="grid gap-4 rounded-xl border p-4">
                <div className="text-sm">
                    <strong>1.</strong> Zainstaluj w telefonie aplikację uwierzytelniającą (Google Authenticator, Microsoft Authenticator, 1Password,
                    Bitwarden…).
                    <br />
                    <strong>2.</strong> Zeskanuj kod QR (albo wpisz klucz ręcznie).
                    <br />
                    <strong>3.</strong> Wpisz 6-cyfrowy kod z aplikacji.
                </div>
                <div className="flex flex-wrap items-start gap-6">
                    <div className="rounded-lg bg-white p-2" dangerouslySetInnerHTML={{ __html: twoFactor.setup.qr }} />
                    <div className="grid min-w-56 flex-1 gap-3">
                        <div>
                            <div className="text-muted-foreground text-xs">Klucz do wpisania ręcznie</div>
                            <code className="bg-muted mt-1 block rounded px-2 py-1.5 font-mono text-sm break-all">{twoFactor.setup.secret}</code>
                        </div>
                        <form
                            className="grid gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post('/settings/security/two-factor/confirm', { preserveScroll: true, onFinish: () => form.reset() });
                            }}
                        >
                            <Label htmlFor="code">Kod z aplikacji</Label>
                            <div className="flex gap-2">
                                <Input
                                    id="code"
                                    autoFocus
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    maxLength={7}
                                    placeholder="123456"
                                    className="w-36 font-mono tracking-widest"
                                    value={form.data.code}
                                    onChange={(e) => form.setData('code', e.target.value.replace(/[^\d]/g, ''))}
                                />
                                <Button type="submit" disabled={form.processing || form.data.code.length < 6}>
                                    <CheckCircle2 className="size-4" /> Włącz
                                </Button>
                            </div>
                            <InputError message={form.errors.code} />
                        </form>
                        <button
                            type="button"
                            className="text-muted-foreground justify-self-start text-xs underline-offset-2 hover:underline"
                            onClick={() => router.delete('/settings/security/two-factor/setup', { preserveScroll: true })}
                        >
                            Anuluj
                        </button>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="grid gap-4">
            {recoveryCodes && (
                <div className="grid gap-3 rounded-xl border border-amber-500/50 bg-amber-500/10 p-4">
                    <div className="flex items-start gap-2 text-sm">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        <span>
                            <strong>Kody zapasowe – zapisz je teraz</strong> (menedżer haseł albo wydruk w bezpiecznym miejscu). Każdy działa raz, gdy
                            nie masz telefonu. Nie zobaczysz ich ponownie.
                        </span>
                    </div>
                    <div className="grid grid-cols-2 gap-1 font-mono text-sm sm:grid-cols-5">
                        {recoveryCodes.map((c) => (
                            <span key={c} className="bg-background rounded px-2 py-1 text-center">
                                {c}
                            </span>
                        ))}
                    </div>
                    <div>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                navigator.clipboard?.writeText(recoveryCodes.join('\n'));
                                setCopied(true);
                            }}
                        >
                            <Copy className="size-4" /> {copied ? 'Skopiowano' : 'Kopiuj wszystkie'}
                        </Button>
                    </div>
                </div>
            )}

            {twoFactor.enabled ? (
                <div className="grid gap-3 rounded-xl border p-4">
                    <div className="flex items-center gap-2 text-sm font-medium text-[#0a7d0a] dark:text-[#4ccc4c]">
                        <ShieldCheck className="size-5" /> Włączona
                        {twoFactor.confirmed_at && <span className="text-muted-foreground font-normal">od {dateTime(twoFactor.confirmed_at)}</span>}
                    </div>
                    <p className="text-muted-foreground text-sm">
                        Przy logowaniu, podglądzie haseł klientów i innych wrażliwych operacjach panel prosi o kod z aplikacji. Kody zapasowe:
                        pozostało <strong className={cn(twoFactor.recovery_left < 3 && 'text-[#b42f2f]')}>{twoFactor.recovery_left}</strong>.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => router.post('/settings/security/recovery-codes', {}, { preserveScroll: true })}
                        >
                            <KeyRound className="size-4" /> Nowe kody zapasowe
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => router.post('/settings/security/two-factor/reset', {}, { preserveScroll: true })}
                        >
                            <Smartphone className="size-4" /> Nowy telefon
                        </Button>
                        {!twoFactor.required && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    confirm('Wyłączyć weryfikację dwuetapową? Konto będzie chronione tylko hasłem.') &&
                                    router.delete('/settings/security/two-factor', { preserveScroll: true })
                                }
                            >
                                <ShieldOff className="size-4" /> Wyłącz
                            </Button>
                        )}
                    </div>
                </div>
            ) : (
                <div className="grid gap-3 rounded-xl border p-4">
                    {twoFactor.required && (
                        <div className="flex items-start gap-2 rounded-lg border border-[#d03b3b]/40 bg-[#d03b3b]/10 px-3 py-2 text-sm">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0 text-[#b42f2f]" />
                            Konto administratora ma dostęp do haseł klientów i stron – weryfikacja dwuetapowa jest obowiązkowa. Do czasu jej włączenia
                            reszta panelu jest zablokowana.
                        </div>
                    )}
                    <p className="text-muted-foreground text-sm">
                        Po włączeniu, poza hasłem, przy logowaniu potrzebny będzie kod z aplikacji w telefonie. Nawet jeśli ktoś pozna hasło, nie
                        zaloguje się bez Twojego telefonu.
                    </p>
                    <div>
                        <Button onClick={() => router.post('/settings/security/two-factor', {}, { preserveScroll: true })}>
                            <ShieldCheck className="size-4" /> Włącz weryfikację dwuetapową
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}

export default function Security({ twoFactor, recoveryCodes, devices, sessions, events, eventsScope }: Props) {
    const logoutForm = useForm({ password: '' });

    return (
        <AppLayout breadcrumbs={[{ title: 'Bezpieczeństwo', href: '/settings/security' }]}>
            <Head title="Bezpieczeństwo" />
            <SettingsLayout>
                <div className="space-y-10">
                    <section className="space-y-4">
                        <HeadingSmall
                            title="Weryfikacja dwuetapowa (2FA)"
                            description="Kod z aplikacji w telefonie przy logowaniu i podglądzie haseł."
                        />
                        <TwoFactorSection twoFactor={twoFactor} recoveryCodes={recoveryCodes} />
                    </section>

                    {sessions.length > 0 && (
                        <section className="space-y-4">
                            <HeadingSmall
                                title="Zalogowane sesje"
                                description="Gdzie jesteś teraz zalogowany. Nieznana sesja? Wyloguj wszystkie inne i zmień hasło."
                            />
                            <ul className="divide-y rounded-xl border">
                                {sessions.map((s, i) => (
                                    <li key={i} className="flex items-center gap-3 px-4 py-2.5 text-sm">
                                        <Laptop className="text-muted-foreground size-4 shrink-0" />
                                        <div className="min-w-0 flex-1">
                                            <div className="font-medium">
                                                {device(s.agent)}{' '}
                                                {s.current && (
                                                    <span className="text-xs font-normal text-[#0a7d0a] dark:text-[#4ccc4c]">(ta sesja)</span>
                                                )}
                                            </div>
                                            <div className="text-muted-foreground text-xs">
                                                IP {s.ip ?? '–'} · ostatnio {dateTime(s.last_activity)}
                                            </div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                            <form
                                className="flex flex-wrap items-start gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    logoutForm.post('/settings/security/logout-others', { preserveScroll: true, onFinish: () => logoutForm.reset() });
                                }}
                            >
                                <div className="grid gap-1">
                                    <Input
                                        type="password"
                                        placeholder="Twoje hasło"
                                        autoComplete="current-password"
                                        className="w-56"
                                        value={logoutForm.data.password}
                                        onChange={(e) => logoutForm.setData('password', e.target.value)}
                                    />
                                    <InputError message={logoutForm.errors.password} />
                                </div>
                                <Button type="submit" variant="outline" disabled={logoutForm.processing || !logoutForm.data.password}>
                                    <LogOut className="size-4" /> Wyloguj wszystkie inne
                                </Button>
                            </form>
                        </section>
                    )}

                    <section className="space-y-4">
                        <HeadingSmall
                            title="Zaufane urządzenia"
                            description="Przeglądarki, z których się logowałeś. Logowanie z nowej = e-mail z ostrzeżeniem."
                        />
                        {devices.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Brak – urządzenie zapisze się przy następnym logowaniu.</p>
                        ) : (
                            <ul className="divide-y rounded-xl border">
                                {devices.map((d) => (
                                    <li key={d.id} className="flex items-center gap-3 px-4 py-2.5 text-sm">
                                        <Smartphone className="text-muted-foreground size-4 shrink-0" />
                                        <div className="min-w-0 flex-1">
                                            <div className="font-medium">
                                                {device(d.user_agent)}{' '}
                                                {d.current && (
                                                    <span className="text-xs font-normal text-[#0a7d0a] dark:text-[#4ccc4c]">(to urządzenie)</span>
                                                )}
                                            </div>
                                            <div className="text-muted-foreground text-xs">
                                                IP {d.ip ?? '–'} · ostatnio {dateTime(d.last_used_at)} · dodane {dateTime(d.created_at)}
                                            </div>
                                        </div>
                                        {!d.current && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                title="Usuń"
                                                onClick={() => router.delete(`/settings/security/devices/${d.id}`, { preserveScroll: true })}
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section className="space-y-4">
                        <HeadingSmall
                            title="Dziennik bezpieczeństwa"
                            description={
                                eventsScope === 'all' ? 'Wszyscy użytkownicy panelu – ostatnie 60 zdarzeń.' : 'Twoje konto – ostatnie 60 zdarzeń.'
                            }
                        />
                        <ul className="divide-y rounded-xl border text-sm">
                            {events.length === 0 && <li className="text-muted-foreground px-4 py-3">Brak wpisów.</li>}
                            {events.map((e) => (
                                <li key={e.id} className="flex items-start gap-3 px-4 py-2">
                                    {BAD.has(e.type) ? (
                                        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-[#a4471f] dark:text-[#ec835a]" />
                                    ) : (
                                        <CheckCircle2 className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                                    )}
                                    <div className="min-w-0 flex-1">
                                        <div className={cn(BAD.has(e.type) && 'font-medium')}>
                                            {e.label}
                                            {eventsScope === 'all' && (e.user || e.email) && (
                                                <span className="text-muted-foreground font-normal"> · {e.user ?? e.email}</span>
                                            )}
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {dateTime(e.created_at)} · IP {e.ip ?? '–'} · {device(e.user_agent)}
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
