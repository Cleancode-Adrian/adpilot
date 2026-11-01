import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { BarChart3 } from 'lucide-react';

interface AuthLayoutProps {
    children: React.ReactNode;
    name?: string;
    title?: string;
    description?: string;
}

export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    const { brand } = usePage<SharedData>().props;
    return (
        <div className="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <Link href="/login" className="flex flex-col items-center gap-2 font-medium">
                            {brand.logo_url ? (
                                <img src={brand.logo_url} alt={brand.name} className="h-12 max-w-48 object-contain" />
                            ) : (
                                <span className="flex size-11 items-center justify-center rounded-xl text-white" style={{ background: brand.color }}>
                                    <BarChart3 className="size-6" />
                                </span>
                            )}
                            <span className="text-muted-foreground text-sm">{brand.name}</span>
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">{title}</h1>
                            <p className="text-muted-foreground text-center text-sm">{description}</p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
