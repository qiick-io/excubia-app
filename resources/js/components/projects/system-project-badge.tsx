import { Info } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

/**
 * "System project" in the header, with what it means in a tooltip (keyboard reachable: the
 * badge is a button).
 */
export function SystemProjectBadge() {
    const { t } = useTranslation();

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Badge variant="info" asChild>
                    <button type="button">
                        <Info />
                        {t('report.overview.systemProject')}
                    </button>
                </Badge>
            </TooltipTrigger>
            <TooltipContent className="max-w-xs">
                {t('report.overview.systemProjectHint')}
            </TooltipContent>
        </Tooltip>
    );
}
