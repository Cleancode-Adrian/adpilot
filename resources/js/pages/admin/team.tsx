import { Section } from '@/components/adpilot/ui';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dateTime } from '@/lib/format';
import { type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Copy, Mail, ShieldCheck, Trash2, UserPlus } from 'lucide-react';
import { useState } from 'react';

interface Member {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    daily_digest: boolean;
    last_login_at: string | null;
}

export default function Team({ users }: { users: Member[] }) {
    const { auth, flash } = usePage<SharedData>().props;
    const [copied, setCopied] = useState(false);
    const form = useForm({ name: '', email: '', is_admin: false as boolean });
    const patch = (u: Member, data: Partial<Member>) => router.patch(`/admin/team/${u.id}`, data, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={[{ title: 'Zespół', href: '/admin/team' }]}>
            <Head title="Zespół" />
            <div className="flex max-w-4xl flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Zespół</h1>
                    <p className="text-muted-foreground text-sm">
                        Publiczna rejestracja jest wyłączona – konta zakładasz tutaj. Nowa osoba dostaje e-mail z linkiem do ustawienia hasła.
                    </p>
                </div>

                {flash?.invite_link && (
                    <div className="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm">
                        <p className="mb-2 font-medium">Link do ustawienia hasła – wyślij go tej osobie np. SMS-em lub komunikatorem:</p>
                        <div className="flex gap-2">
                            <Input readOnly value={flash.invite_link} onFocus={(e) => e.target.select()} className="font-mono text-xs" />
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    navigator.clipboard?.writeText(flash.invite_link!);
                                    setCopied(true);
                                }}
                            >
                                <Copy className="size-4" /> {copied ? 'Skopiowano' : 'Kopiuj'}
                            </Button>
                        </div>
                    </div>
                )}

                <Section title="Użytkownicy">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-muted-foreground border-b text-left text-xs">
                                <th className="py-2 font-medium">Osoba</th>
                                <th className="py-2 font-medium">Ostatnie logowanie</th>
                                <th className="py-2 font-medium">Administrator</th>
                                <th className="py-2 font-medium">Poranny e-mail</th>
                                <th className="py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {users.map((u) => (
                                <tr key={u.id} className="border-b last:border-0">
                                    <td className="py-2">
                                        <div className="font-medium">
                                            {u.name} {u.id === auth.user.id && <span className="text-muted-foreground text-xs">(Ty)</span>}
                                        </div>
                                        <div className="text-muted-foreground text-xs">{u.email}</div>
                                    </td>
                                    <td className="py-2 text-xs">{u.last_login_at ? dateTime(u.last_login_at) : 'jeszcze nie logował(a) się'}</td>
                                    <td className="py-2">
                                        <input
                                            type="checkbox"
                                            checked={u.is_admin}
                                            disabled={u.id === auth.user.id}
                                            onChange={(e) => patch(u, { is_admin: e.target.checked })}
                                        />
                                    </td>
                                    <td className="py-2">
                                        <input
                                            type="checkbox"
                                            checked={u.daily_digest}
                                            onChange={(e) => patch(u, { daily_digest: e.target.checked })}
                                        />
                                    </td>
                                    <td className="py-2 text-right whitespace-nowrap">
                                        {!u.last_login_at && u.id !== auth.user.id && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                title="Wyślij zaproszenie ponownie"
                                                onClick={() => {
                                                    setCopied(false);
                                                    router.post(`/admin/team/${u.id}/invite`, {}, { preserveScroll: true });
                                                }}
                                            >
                                                <Mail className="size-4" />
                                            </Button>
                                        )}
                                        {u.id !== auth.user.id && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    confirm(`Usunąć konto ${u.name}?`) &&
                                                    router.delete(`/admin/team/${u.id}`, { preserveScroll: true })
                                                }
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Section>

                <Section title="Dodaj osobę">
                    <form
                        className="flex flex-wrap items-end gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/admin/team', { preserveScroll: true, onSuccess: () => form.reset() });
                        }}
                    >
                        <div className="grid gap-1.5">
                            <Label htmlFor="name">Imię i nazwisko</Label>
                            <Input id="name" required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            <InputError message={form.errors.name} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="email">E-mail</Label>
                            <Input id="email" type="email" required value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                            <InputError message={form.errors.email} />
                        </div>
                        <label className="flex h-9 items-center gap-2 text-sm">
                            <input type="checkbox" checked={form.data.is_admin} onChange={(e) => form.setData('is_admin', e.target.checked)} />
                            <ShieldCheck className="size-4" /> Administrator
                        </label>
                        <Button type="submit" disabled={form.processing}>
                            <UserPlus className="size-4" /> Dodaj i wyślij zaproszenie
                        </Button>
                    </form>
                </Section>
            </div>
        </AppLayout>
    );
}
