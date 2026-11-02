import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function ConfirmPassword({ needsCode = false }: { needsCode?: boolean }) {
    const { data, setData, post, processing, errors, reset } = useForm({ password: '', code: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('password.confirm'), { onFinish: () => reset('password', 'code') });
    };

    return (
        <AuthLayout
            title="Potwierdź, że to Ty"
            description={`To chroniona część panelu. Podaj hasło${needsCode ? ' i kod z aplikacji' : ''}, aby kontynuować.`}
        >
            <Head title="Potwierdzenie" />

            <form onSubmit={submit} autoComplete="off">
                <div className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="password">Hasło</Label>
                        <Input
                            id="password"
                            type="password"
                            name="password"
                            autoComplete="current-password"
                            value={data.password}
                            autoFocus
                            onChange={(e) => setData('password', e.target.value)}
                        />
                        <InputError message={errors.password} />
                    </div>

                    {needsCode && (
                        <div className="grid gap-2">
                            <Label htmlFor="code">Kod z aplikacji</Label>
                            <Input
                                id="code"
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                maxLength={7}
                                placeholder="123456"
                                className="font-mono tracking-widest"
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value.replace(/[^\d]/g, ''))}
                            />
                            <InputError message={errors.code} />
                        </div>
                    )}

                    <Button className="w-full" disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Potwierdź
                    </Button>
                </div>
            </form>
        </AuthLayout>
    );
}
