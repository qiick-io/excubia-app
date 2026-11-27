import { useState } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

/**
 * Checkbox posted as `1` / `0` through a hidden input, so an unchecked box is sent too.
 */
export default function BooleanField({
    name,
    label,
    defaultChecked,
}: {
    name: string;
    label: string;
    defaultChecked: boolean;
}) {
    const [isChecked, setIsChecked] = useState(defaultChecked);

    return (
        <div className="flex items-center gap-3">
            <input type="hidden" name={name} value={isChecked ? '1' : '0'} />
            <Checkbox
                id={name}
                checked={isChecked}
                onCheckedChange={(checked) => setIsChecked(checked === true)}
            />
            <Label htmlFor={name}>{label}</Label>
        </div>
    );
}
