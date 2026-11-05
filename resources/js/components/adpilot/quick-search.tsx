import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Result {
    type: string;
    title: string;
    hint: string | null;
    url: string;
}

/** Szybkie wyszukiwanie: Ctrl+K (⌘K) albo przycisk w nagłówku – klienci, agencje, oferty, zadania. */
export function QuickSearch() {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const [results, setResults] = useState<Result[]>([]);
    const [active, setActive] = useState(0);
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setOpen((o) => !o);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    useEffect(() => {
        clearTimeout(timer.current);
        if (q.trim().length < 2) {
            setResults([]);
            return;
        }
        timer.current = setTimeout(async () => {
            const res = await fetch(`/search?q=${encodeURIComponent(q.trim())}`, { headers: { Accept: 'application/json' } });
            if (res.ok) {
                setResults((await res.json()).results);
                setActive(0);
            }
        }, 200);
    }, [q]);

    const go = (r: Result | undefined) => {
        if (!r) return;
        setOpen(false);
        setQ('');
        router.visit(r.url);
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="text-muted-foreground hover:bg-muted inline-flex h-8 shrink-0 items-center gap-2 rounded-md border px-2.5 text-sm"
                title="Szukaj (Ctrl+K)"
            >
                <Search className="size-4" />
                <span className="hidden md:inline">Szukaj</span>
                <kbd className="bg-muted hidden rounded px-1.5 font-mono text-[10px] md:inline">Ctrl K</kbd>
            </button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="top-[20%] translate-y-0 gap-0 p-0 sm:max-w-xl">
                    <DialogTitle className="sr-only">Szukaj</DialogTitle>
                    <div className="flex items-center gap-2 border-b px-3">
                        <Search className="text-muted-foreground size-4" />
                        <input
                            autoFocus
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'ArrowDown') {
                                    e.preventDefault();
                                    setActive((a) => Math.min(a + 1, results.length - 1));
                                } else if (e.key === 'ArrowUp') {
                                    e.preventDefault();
                                    setActive((a) => Math.max(a - 1, 0));
                                } else if (e.key === 'Enter') {
                                    go(results[active]);
                                }
                            }}
                            placeholder="Klient, strona, agencja, oferta, zadanie…"
                            className="h-12 flex-1 bg-transparent text-sm outline-none"
                        />
                    </div>
                    <ul className="max-h-80 overflow-auto p-1">
                        {q.trim().length >= 2 && results.length === 0 && (
                            <li className="text-muted-foreground px-3 py-6 text-center text-sm">Brak wyników</li>
                        )}
                        {results.map((r, i) => (
                            <li key={r.url + i}>
                                <button
                                    type="button"
                                    onMouseEnter={() => setActive(i)}
                                    onClick={() => go(r)}
                                    className={cn(
                                        'flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm',
                                        i === active && 'bg-muted',
                                    )}
                                >
                                    <span className="text-muted-foreground w-16 shrink-0 text-xs">{r.type}</span>
                                    <span className="min-w-0 flex-1 truncate font-medium">{r.title}</span>
                                    {r.hint && <span className="text-muted-foreground max-w-[40%] truncate text-xs">{r.hint}</span>}
                                </button>
                            </li>
                        ))}
                    </ul>
                </DialogContent>
            </Dialog>
        </>
    );
}
