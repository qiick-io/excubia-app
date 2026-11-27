import { X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { DrawerClose } from '@/components/ui/drawer';

/**
 * A visible close button in the top-right corner of a detail drawer (#454): on a phone the
 * drawer fills the screen, so there is no backdrop to tap and no Escape key. Place it last inside
 * `DrawerContent`; give the header `pr-14` so the title does not run under it.
 */
export function DrawerCloseButton() {
    const { t } = useTranslation();

    return (
        <DrawerClose asChild>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="absolute top-4 right-4"
                aria-label={t('a11y.close')}
                data-test="drawer-close"
            >
                <X aria-hidden="true" />
            </Button>
        </DrawerClose>
    );
}
