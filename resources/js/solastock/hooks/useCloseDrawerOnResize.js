import { useEffect } from 'react';

// Drawer state is temporary. Preserve desktop preferences and ignore height-only
// changes (mobile keyboards) and synthetic resize events from layout refreshes.
export function useCloseDrawerOnResize(setOpen) {
    useEffect(() => {
        let width = window.innerWidth;
        const onResize = () => {
            const nextWidth = window.innerWidth;
            if (nextWidth === width) return;
            width = nextWidth;
            setOpen(false);
        };
        window.addEventListener('resize', onResize);
        return () => window.removeEventListener('resize', onResize);
    }, [setOpen]);
}
