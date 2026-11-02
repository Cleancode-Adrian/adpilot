import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function TwoFactorChallenge() {
    const [recovery, setRecovery] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ code: '', recovery_code: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/two-factor-challenge', { onFinish: () => reset('code', 'recovery_code') });
    };

    return (
        <AuthLayout
            title="Weryfikacja dwuetapowa"
            description={
                recovery
                    ? 'Wpisz jeden z kodów zapasowych (każdy działa tylko raz).'
                    : 'Wpisz 6-cyfrowy kod z aplikacji uwierzytelniającej w telefonie.'
            }
        >
            <Head title="Weryfikacja dwuetapowa" />
            <form className="flex flex-col gap-6" onSubmit={submit} autoComplete="off">
                {recovery ? (
                    <div className="grid gap-2">
                        <Label htmlFor="recovery_code">Kod zapasowy</Label>
                        <Input
                            id="recovery_code"
                            autoFocus
                            className="font-mono tracking-wider uppercase"
                            placeholder="ABCDE-12345"
                            value={data.recovery_code}
                            onChange={(e) => setData('recovery_code', e.target.value)}
                        />
                        <InputError message={errors.recovery_code} />
                    </div>
                ) : (
                    <div className="grid gap-2">
                        <Label htmlFor="code">Kod z aplikacji</Label>
                        <Input
                            id="code"
                            autoFocus
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={7}
                            className="text-center font-mono text-2xl tracking-[0.4em]"
                            placeholder="000000"
                            value={data.code}
                            onChange={(e) => setData('code', e.target.value.replace(/[^\d]/g, ''))}
                        />
                        <InputError message={errors.code} />
                    </div>
                )}

                <Button
                    type="submit"
                    className="w-full"
                    disabled={processing || (!recovery && data.code.length < 6) || (recovery && !data.recovery_code)}
                >
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Potwierdź
                </Button>

                <button
                    type="button"
                    className="text-muted-foreground text-center text-sm underline-offset-2 hover:underline"
                    onClick={() => {
                        setRecovery(!recovery);
                        reset();
                    }}
                >
                    {recovery ? 'Użyj kodu z aplikacji' : 'Nie masz telefonu? Użyj kodu zapasowego'}
                </button>
            </form>
        </AuthLayout>
    );
}
