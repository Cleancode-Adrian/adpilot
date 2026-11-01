import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { BarChart3 } from 'lucide-react';

export default function AppLogo() {
    const { brand } = usePage<SharedData>().props;
    return (
        <>
            <div
                className="flex aspect-square size-8 items-center justify-center overflow-hidden rounded-md text-white"
                style={{ background: brand.logo_url ? 'transparent' : brand.color }}
            >
                {brand.logo_url ? <img src={brand.logo_url} alt="" className="size-8 object-contain" /> : <BarChart3 className="size-4" />}
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-none font-semibold">{brand.name}</span>
            </div>
        </>
    );
}
