import { Button } from '@/components/ui/button';
import { date } from '@/lib/format';
import { Link, router } from '@inertiajs/react';
import { BellOff, Check, FileSignature, Mail, Phone, ScanSearch } from 'lucide-react';

export interface Followup {
    type: 'quote' | 'audit';
    id: number;
    title: string;
    who: string | null;
    contact: { name?: string; email?: string; phone?: string };
    since: string;
    hint: string;
    url: string;
}

/** Lista „do kontaktu”: oferty bez odpowiedzi i audyty bez zamiany w klienta. */
export function FollowupList({ items }: { items: Followup[] }) {
    if (!items.length) return <p className="text-muted-foreground text-sm">Nikt nie czeka na kontakt. 👌</p>;
    const act = (f: Followup, action: 'contacted' | 'off') => router.post(`/followups/${f.type}/${f.id}`, { action }, { preserveScroll: true });

    return (
        <ul className="divide-y">
            {items.map((f) => {
                const Icon = f.type === 'quote' ? FileSignature : ScanSearch;
                const days = Math.floor((Date.now() - new Date(f.since).getTime()) / 86400000);
                return (
                    <li key={`${f.type}-${f.id}`} className="flex flex-wrap items-start gap-x-4 gap-y-2 py-2.5">
                        <Icon className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                        <div className="min-w-48 flex-1">
                            <Link href={f.url} className="text-sm font-medium hover:underline">
                                {f.who ? `${f.who} – ` : ''}
                                {f.title}
                            </Link>
                            <div className="text-muted-foreground text-xs">
                                od {date(f.since)} ({days} dni) · {f.hint}
                            </div>
                            <div className="mt-1 flex flex-wrap gap-3 text-xs">
                                {f.contact.phone && (
                                    <a href={`tel:${f.contact.phone.replace(/\s/g, '')}`} className="inline-flex items-center gap-1 hover:underline">
                                        <Phone className="size-3" /> {f.contact.phone}
                                    </a>
                                )}
                                {f.contact.email && (
                                    <a href={`mailto:${f.contact.email}`} className="inline-flex items-center gap-1 hover:underline">
                                        <Mail className="size-3" /> {f.contact.email}
                                    </a>
                                )}
                            </div>
                        </div>
                        <div className="flex shrink-0 gap-1">
                            <Button size="sm" variant="outline" className="h-7" title="Przypomnij znowu za 7 dni" onClick={() => act(f, 'contacted')}>
                                <Check className="size-3.5" /> Kontakt zrobiony
                            </Button>
                            <Button size="sm" variant="ghost" className="h-7" title="Nie przypominaj więcej" onClick={() => act(f, 'off')}>
                                <BellOff className="size-3.5" />
                            </Button>
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
