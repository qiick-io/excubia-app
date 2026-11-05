<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the search, sort and pagination query of the admin lists (users, groups, roles).
 * Controllers authorize through their policies.
 */
class ListQueryRequest extends FormRequest
{
    /** @var list<int> Page sizes offered by the "Rows per page" select (qiick app UI). */
    public const PER_PAGE_OPTIONS = [10, 20, 50, 100];

    public const DEFAULT_PER_PAGE = 20;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'string'],
            'direction' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function search(): string
    {
        return $this->string('search')->trim()->toString();
    }

    /**
     * The requested sort column when allowed, otherwise the default.
     *
     * @param  list<string>  $allowed
     */
    public function sortColumn(array $allowed, string $default): string
    {
        $sort = $this->string('sort')->toString();

        return in_array($sort, $allowed, true) ? $sort : $default;
    }

    /**
     * @return 'asc'|'desc'
     */
    public function sortDirection(string $default): string
    {
        return $this->string('direction', $default)->toString() === 'asc' ? 'asc' : 'desc';
    }

    /**
     * One of the "Rows per page" sizes; anything else (missing, stale or hand-edited URL) falls
     * back to the default instead of failing, as externa-core's ValidatesListPerPageTrait.
     */
    public function perPage(): int
    {
        $perPage = $this->integer('per_page');

        return in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::DEFAULT_PER_PAGE;
    }

    /**
     * Filters the query to rows whose columns contain the search term (case-insensitive on PostgreSQL too).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     * @return Builder<TModel>
     */
    public function applySearch(Builder $query, array $columns): Builder
    {
        $search = $this->search();

        if ($search === '') {
            return $query;
        }

        $operator = $query->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return $query->where(function (Builder $inner) use ($columns, $operator, $search): void {
            foreach ($columns as $column) {
                $inner->orWhere($column, $operator, '%'.$search.'%');
            }
        });
    }

    /**
     * The filters echoed back to the list page.
     *
     * @return array{search: string, sort: string, direction: 'asc'|'desc'}
     */
    public function filters(string $sort, string $direction): array
    {
        return ['search' => $this->search(), 'sort' => $sort, 'direction' => $direction === 'asc' ? 'asc' : 'desc'];
    }
}
