import { CareTaskRow } from '@/components/adpilot/care-task-row';
import { EmptyState } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type CareTask } from '@/types/adpilot';
import { Head, Link } from '@inertiajs/react';

export default function Requests({ status, tasks, counts }: { status: 'todo' | 'done'; tasks: CareTask[]; counts: { todo: number } }) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Zgłoszenia', href: '/requests' }]}>
            <Head title="Zgłoszenia" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Zgłoszenia</h1>
                        <p className="text-muted-foreground max-w-2xl text-sm">
                            Prośby od klientów i agencji z formularza zgłoszeń. Każde zgłoszenie to zadanie z terminem (zwykłe – 3 dni robocze, pilne
                            – następny dzień). Link do formularza skopiujesz w karcie klienta albo agencji.
                        </p>
                    </div>
                    <div className="flex rounded-md border p-0.5">
                        <Button variant={status === 'todo' ? 'secondary' : 'ghost'} size="sm" className="h-8" asChild>
                            <Link href="/requests">Do zrobienia ({counts.todo})</Link>
                        </Button>
                        <Button variant={status === 'done' ? 'secondary' : 'ghost'} size="sm" className="h-8" asChild>
                            <Link href="/requests?status=done">Załatwione</Link>
                        </Button>
                    </div>
                </div>

                {tasks.length === 0 ? (
                    <EmptyState title={status === 'todo' ? 'Brak zgłoszeń do zrobienia' : 'Brak załatwionych zgłoszeń'} />
                ) : (
                    <ul className="grid gap-2">
                        {tasks.map((t) => (
                            <CareTaskRow key={t.id} task={t} showClient />
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
