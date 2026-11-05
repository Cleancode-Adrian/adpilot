import { Button } from '@/components/ui/button';
import { type SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { Square, Timer } from 'lucide-react';
import { useEffect, useState } from 'react';

/** „12:34” / „1:02:05” – czas od startu stopera. */
export function elapsed(startedAt: string, now: number): string {
    const s = Math.max(0, Math.floor((now - new Date(startedAt).getTime()) / 1000));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = String(s % 60).padStart(2, '0');
    return h ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`;
}

export function useNow(active: boolean) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        if (!active) return;
        const id = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(id);
    }, [active]);
    return now;
}

/** Pasek działającego stopera w nagłówku – widoczny na każdej stronie. */
export function TimerBar() {
    const { timer } = usePage<SharedData>().props;
    const now = useNow(!!timer);
    if (!timer) return null;
    return (
        <div className="ml-auto flex min-w-0 items-center gap-2 rounded-full border border-[#2a78d6]/40 bg-[#2a78d6]/10 py-0.5 pr-0.5 pl-3 text-sm">
            <Timer className="size-4 shrink-0 animate-pulse text-[#2a78d6]" />
            <Link href={timer.client ? `/clients/${timer.client.id}/care` : '/care'} className="min-w-0 truncate hover:underline" title={timer.title}>
                {timer.client && <span className="text-muted-foreground">{timer.client.name}: </span>}
                {timer.title}
            </Link>
            <span className="font-mono font-semibold tabular-nums">{elapsed(timer.started_at, now)}</span>
            <Button
                size="sm"
                variant="outline"
                className="h-7 rounded-full"
                title="Zatrzymaj i dolicz czas do zadania"
                onClick={() => router.post(`/care/tasks/${timer.id}/timer/stop`, {}, { preserveScroll: true })}
            >
                <Square className="size-3 fill-current" /> Stop
            </Button>
        </div>
    );
}
