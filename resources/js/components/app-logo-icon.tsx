import { useId } from 'react';
import type { SVGAttributes } from 'react';
import { cn } from '@/lib/utils';

/**
 * Excubia mark: a brazier with a flame, lit from above. Same geometry as
 * qiick-website `public/brand/excubia-mark-{light,dark}.svg` and `public/favicon.svg`.
 * Light and dark fills via Tailwind `dark:`. The clip-path ids come from `useId`, so the mark
 * can render several times on one page (sidebar, header) without id clashes.
 * Size via className.
 */
export default function AppLogoIcon({
    className,
    ...props
}: SVGAttributes<SVGElement>) {
    const id = useId();
    const bowlClipId = `${id}-bowl`;
    const flameClipId = `${id}-flame`;

    return (
        <svg
            {...props}
            viewBox="0 0 512 512"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden={props['aria-hidden'] ?? true}
            className={cn(className)}
        >
            <defs>
                <clipPath id={bowlClipId}>
                    <path d="M78 278L184 370A72 36.79 0 0 0 328 370L434 278Z" />
                </clipPath>
                <clipPath id={flameClipId}>
                    <path d="M0 0H512V278H408A152 77.67 0 0 1 104 278H0Z" />
                </clipPath>
            </defs>
            <g transform="translate(6.51 6.51) scale(0.9746)">
                <g transform="matrix(1.01075 0 0 0.99473 -2.753 -12.448)">
                    <path
                        className="fill-[#08496A] stroke-[#08496A] dark:fill-[#0D5D85] dark:stroke-[#0D5D85]"
                        strokeWidth={16}
                        strokeLinejoin="round"
                        d="M152 428L152 446A104 53.14 0 0 0 360 446L360 428Z"
                    />
                    <g
                        className="fill-[#1375A7] stroke-[#1375A7] dark:fill-[#1A8EC9] dark:stroke-[#1A8EC9]"
                        strokeWidth={16}
                        strokeLinejoin="round"
                    >
                        <path d="M152 428A104 53.14 0 1 1 360 428A104 53.14 0 1 1 152 428Z" />
                        <path d="M200 360L186 428A70 35.77 0 0 0 326 428L312 360Z" />
                        <path d="M78 278L184 370A72 36.79 0 0 0 328 370L434 278Z" />
                    </g>
                    <g clipPath={`url(#${bowlClipId})`}>
                        <path
                            className="fill-none stroke-[#08496A] dark:stroke-[#0D5D85]"
                            strokeWidth={20}
                            d="M123 319.4A133 67.96 0 0 0 389 319.4"
                        />
                    </g>
                    <path
                        className="fill-[#2FA8EA] stroke-[#2FA8EA] dark:fill-[#7BCAFC] dark:stroke-[#7BCAFC]"
                        strokeWidth={16}
                        strokeLinejoin="round"
                        d="M78 278A178 90.96 0 1 1 434 278A178 90.96 0 1 1 78 278Z"
                    />
                    <path
                        className="fill-[#04364F]"
                        d="M104 278A152 77.67 0 1 1 408 278A152 77.67 0 1 1 104 278Z"
                    />
                    <g clipPath={`url(#${flameClipId})`}>
                        <g transform="translate(256 278) scale(1.0811 1.0811) translate(-256 -250)">
                            <path
                                className="fill-[#2FA8EA] stroke-[#2FA8EA] dark:fill-[#7BCAFC] dark:stroke-[#7BCAFC]"
                                strokeWidth={10}
                                strokeLinejoin="round"
                                d="M170 252C146 214 146 170 172 112C178 150 194 172 214 182C204 124 220 74 256 28C268 76 296 104 306 146C316 132 326 116 336 92C366 150 364 214 342 252Z"
                            />
                            <path
                                className="fill-[#7BCAFC] dark:fill-[#B1DEFD]"
                                d="M214 252C204 224 212 198 234 170C238 196 252 210 266 210C268 192 276 178 288 168C304 200 302 232 294 252Z"
                            />
                        </g>
                    </g>
                </g>
            </g>
        </svg>
    );
}
