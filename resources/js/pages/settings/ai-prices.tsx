import { Head, useForm } from '@inertiajs/react';
import { CalendarPlus, Info, Plus, RotateCcw, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { SettingsFormActions } from '@/components/settings-form-actions';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import aiPricesRoutes from '@/routes/ai-prices';
import type { BreadcrumbItem } from '@/types';
import { edit as editProfile } from '@/routes/profile';

/** The price fields of a row, as AiPriceService::PRICE_FIELDS. */
const PRICE_FIELDS = [
    'input',
    'cache_read',
    'cache_write',
    'output',
    'reasoning',
    'image_input',
    'image_output',
    'audio_second',
    'search_unit',
] as const;

type PriceField = (typeof PRICE_FIELDS)[number];

/** A price row; `effective_from` is its first day (UTC), null for since always (#202). */
type PriceRow = {
    provider: string;
    model: string;
    effective_from: string | null;
} & Record<PriceField, number | null>;

/** A row as the form edits it: every number and the date as text, empty for none. */
type FormRow = {
    provider: string;
    model: string;
    effective_from: string;
} & Record<PriceField, string>;

const toFormRow = (row: PriceRow): FormRow => ({
    provider: row.provider,
    model: row.model,
    effective_from: row.effective_from ?? '',
    ...(Object.fromEntries(
        PRICE_FIELDS.map((field) => [
            field,
            row[field] === null ? '' : String(row[field]),
        ]),
    ) as Record<PriceField, string>),
});

/**
 * Settings → AI prices (#129): the editable price table that turns the AI usage the projects
 * push into estimated costs, one row per provider (as named in the project's `config/ai.php`)
 * and model. Seeded with the list prices of the official pricing pages (`defaultsSources`, linked
 * in the note); "Restore defaults" puts
 * them back in the form. A model without a row shows its tokens without a cost. A row may start
 * on a day (#202): "New price from a date" adds a copy of a row right below it, so the history of
 * a model stays together and earlier days keep their price. Needs `can-manage-alerts`.
 */
export default function AiPricesSettings({
    prices,
    defaults,
    updatedOn,
    defaultsUpdatedOn,
    defaultsSources,
}: {
    prices: PriceRow[];
    defaults: PriceRow[];
    updatedOn: string;
    defaultsUpdatedOn: string;
    defaultsSources: Record<string, string>;
}) {
    const { t } = useTranslation();
    const form = useForm<{ prices: FormRow[] }>({
        prices: prices.map(toFormRow),
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('settings.layout.title'), href: editProfile() },
        {
            title: t('settings.aiPrices.breadcrumb'),
            href: aiPricesRoutes.edit.url(),
        },
    ];

    const setCell = (index: number, key: keyof FormRow, value: string): void =>
        form.setData(
            'prices',
            form.data.prices.map((row, rowIndex) =>
                rowIndex === index ? { ...row, [key]: value } : row,
            ),
        );

    /** A copy of a row right below it, from today (UTC), for the user to set its date and prices. */
    const addPriceFromDate = (index: number): void =>
        form.setData('prices', [
            ...form.data.prices.slice(0, index + 1),
            {
                ...form.data.prices[index],
                effective_from: new Date().toISOString().slice(0, 10),
            },
            ...form.data.prices.slice(index + 1),
        ]);

    const errors = form.errors as Record<string, string | undefined>;
    const rowError = (index: number): string | undefined =>
        Object.entries(errors).find(([key]) =>
            key.startsWith(`prices.${index}.`),
        )?.[1];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.aiPrices.head')} />
            <h1 className="sr-only">{t('settings.aiPrices.head')}</h1>

            <SettingsLayout wide>
                <form
                    className="space-y-6"
                    noValidate
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(aiPricesRoutes.update.url(), {
                            preserveScroll: true,
                        });
                    }}
                >
                    <Heading
                        variant="small"
                        title={t('settings.aiPrices.title')}
                        description={t('settings.aiPrices.description')}
                    />

                    <Alert variant="info">
                        <Info />
                        <AlertDescription>
                            {t('settings.aiPrices.estimates', {
                                date: updatedOn,
                                defaultsDate: defaultsUpdatedOn,
                            })}
                            <p>
                                {t('settings.aiPrices.sources', {
                                    date: defaultsUpdatedOn,
                                })}{' '}
                                {Object.entries(defaultsSources).map(
                                    ([provider, url], index) => (
                                        <span key={provider}>
                                            {index > 0 ? ', ' : null}
                                            <a
                                                href={url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="underline underline-offset-4"
                                            >
                                                {provider}
                                            </a>
                                        </span>
                                    ),
                                )}
                            </p>
                        </AlertDescription>
                    </Alert>

                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                form.setData('prices', [
                                    ...form.data.prices,
                                    toFormRow({
                                        provider: '',
                                        model: '',
                                        effective_from: null,
                                        ...(Object.fromEntries(
                                            PRICE_FIELDS.map((field) => [
                                                field,
                                                null,
                                            ]),
                                        ) as Record<PriceField, null>),
                                    }),
                                ])
                            }
                        >
                            <Plus />
                            {t('settings.aiPrices.add')}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                form.setData('prices', defaults.map(toFormRow))
                            }
                        >
                            <RotateCcw />
                            {t('settings.aiPrices.restore')}
                        </Button>
                    </div>

                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('settings.aiPrices.provider')}
                                    </TableHead>
                                    <TableHead>
                                        {t('settings.aiPrices.model')}
                                    </TableHead>
                                    <TableHead>
                                        {t('settings.aiPrices.effectiveFrom')}
                                    </TableHead>
                                    {PRICE_FIELDS.map((field) => (
                                        <TableHead
                                            key={field}
                                            className="text-right whitespace-nowrap"
                                        >
                                            {t(
                                                `settings.aiPrices.fields.${field}`,
                                            )}
                                        </TableHead>
                                    ))}
                                    <TableHead>
                                        <span className="sr-only">
                                            {t('settings.aiPrices.remove')}
                                        </span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {form.data.prices.map((row, index) => {
                                    const label =
                                        `${row.provider} ${row.model}`.trim();
                                    const error = rowError(index);

                                    return (
                                        <TableRow key={index}>
                                            {(
                                                ['provider', 'model'] as const
                                            ).map((key) => (
                                                <TableCell
                                                    key={key}
                                                    className={cn(
                                                        key === 'model'
                                                            ? 'min-w-56'
                                                            : 'min-w-32',
                                                        'align-top',
                                                    )}
                                                >
                                                    <Input
                                                        value={row[key]}
                                                        onChange={(event) =>
                                                            setCell(
                                                                index,
                                                                key,
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        aria-label={t(
                                                            `settings.aiPrices.${key}Of`,
                                                            {
                                                                row: index + 1,
                                                            },
                                                        )}
                                                        aria-invalid={
                                                            errors[
                                                                `prices.${index}.${key}`
                                                            ]
                                                                ? true
                                                                : undefined
                                                        }
                                                    />
                                                    {key === 'model' &&
                                                    error ? (
                                                        <p className="mt-1 text-sm text-destructive">
                                                            {error}
                                                        </p>
                                                    ) : null}
                                                </TableCell>
                                            ))}
                                            <TableCell className="min-w-36 align-top">
                                                <Input
                                                    type="date"
                                                    value={row.effective_from}
                                                    onChange={(event) =>
                                                        setCell(
                                                            index,
                                                            'effective_from',
                                                            event.target.value,
                                                        )
                                                    }
                                                    aria-label={t(
                                                        'settings.aiPrices.effectiveFromOf',
                                                        {
                                                            model:
                                                                label ||
                                                                index + 1,
                                                        },
                                                    )}
                                                    aria-invalid={
                                                        errors[
                                                            `prices.${index}.effective_from`
                                                        ]
                                                            ? true
                                                            : undefined
                                                    }
                                                />
                                            </TableCell>
                                            {PRICE_FIELDS.map((field) => (
                                                <TableCell
                                                    key={field}
                                                    className="min-w-24 align-top"
                                                >
                                                    <Input
                                                        type="number"
                                                        min="0"
                                                        step="any"
                                                        inputMode="decimal"
                                                        className="text-right tabular-nums"
                                                        value={row[field]}
                                                        onChange={(event) =>
                                                            setCell(
                                                                index,
                                                                field,
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        aria-label={t(
                                                            'settings.aiPrices.priceOf',
                                                            {
                                                                field: t(
                                                                    `settings.aiPrices.fields.${field}`,
                                                                ),
                                                                model:
                                                                    label ||
                                                                    index + 1,
                                                            },
                                                        )}
                                                        aria-invalid={
                                                            errors[
                                                                `prices.${index}.${field}`
                                                            ]
                                                                ? true
                                                                : undefined
                                                        }
                                                    />
                                                </TableCell>
                                            ))}
                                            <TableCell className="align-top whitespace-nowrap">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() =>
                                                        addPriceFromDate(index)
                                                    }
                                                    aria-label={t(
                                                        'settings.aiPrices.addFrom',
                                                        {
                                                            model:
                                                                label ||
                                                                index + 1,
                                                        },
                                                    )}
                                                    data-test={`ai-prices-add-from-${index}`}
                                                >
                                                    <CalendarPlus />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() =>
                                                        form.setData(
                                                            'prices',
                                                            form.data.prices.filter(
                                                                (_, rowIndex) =>
                                                                    rowIndex !==
                                                                    index,
                                                            ),
                                                        )
                                                    }
                                                    aria-label={t(
                                                        'settings.aiPrices.removeRow',
                                                        {
                                                            model:
                                                                label ||
                                                                index + 1,
                                                        },
                                                    )}
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {t('settings.aiPrices.units')}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {t('settings.aiPrices.history')}
                    </p>

                    <SettingsFormActions
                        processing={form.processing}
                        recentlySuccessful={form.recentlySuccessful}
                        data-test="ai-prices-save"
                    />
                </form>
            </SettingsLayout>
        </AppLayout>
    );
}
