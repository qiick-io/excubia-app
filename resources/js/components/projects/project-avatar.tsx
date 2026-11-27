import type { LucideIcon, LucideProps } from 'lucide-react';
import { forwardRef } from 'react';
import { getInitials } from '@/components/admin/user-avatar';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { avatarToneClass } from '@/lib/avatar-tone';
import { cn } from '@/lib/utils';

type ProjectAvatarProps = {
    /** Picks the stable colour of the initials. */
    slug: string;
    /** For the initials. */
    name: string;
    /** The uploaded logo (#287), null without one. */
    logoUrl?: string | null;
    /** `sm` (24px) in lists, `xs` (20px) in sidebar rows, the palette and the header. */
    size?: 'xs' | 'sm' | 'default' | 'lg';
    className?: string;
};

/**
 * A project's visual identity (#287): its uploaded logo, else its initials on a colour picked
 * from the slug. The colours are the theme's avatar swatches (`avatar-1` … `avatar-10` with
 * `avatar-foreground`, through `avatarToneClass`, as for users): the same in light and dark,
 * and the initials reach at least 6:1 on every swatch. Decorative, like `UserAvatar`: the
 * project's name is always next to it, so the image has an empty alt text.
 */
export function ProjectAvatar({
    slug,
    name,
    logoUrl,
    size = 'default',
    className,
}: ProjectAvatarProps) {
    return (
        <Avatar
            size={size === 'xs' ? 'sm' : size}
            className={cn(size === 'xs' && 'size-5', className)}
            aria-hidden="true"
            data-test="project-avatar"
        >
            {logoUrl ? (
                // A logo is padded to a transparent square on upload: `object-contain` shows
                // all of it, inside the avatar's border ring.
                <AvatarImage src={logoUrl} alt="" className="object-contain" />
            ) : null}
            <AvatarFallback
                className={cn(avatarToneClass(slug), 'text-xs font-medium')}
            >
                {getInitials(name)}
            </AvatarFallback>
        </Avatar>
    );
}

/**
 * The avatar of a project as a command palette result icon. The palette (`command-palette`
 * registry item) takes a lucide component per result, so this returns one that renders the
 * avatar instead of an SVG, without changing the registry copy.
 */
export function projectAvatarIcon(project: {
    slug: string;
    name: string;
    logoUrl: string | null;
}): LucideIcon {
    const ProjectAvatarIcon = forwardRef<SVGSVGElement, LucideProps>(
        function ProjectAvatarIcon() {
            return (
                <ProjectAvatar
                    slug={project.slug}
                    name={project.name}
                    logoUrl={project.logoUrl}
                    size="xs"
                />
            );
        },
    );

    return ProjectAvatarIcon;
}
