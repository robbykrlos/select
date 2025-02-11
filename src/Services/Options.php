<?php

namespace LaravelEnso\Select\Services;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use LaravelEnso\Filters\Services\Search;
use LaravelEnso\Helpers\Traits\When;

class Options implements Responsable
{
    use When;

    protected const Limit = 100;

    protected string $trackBy;
    protected Collection $queryAttributes;
    protected string $searchMode;
    protected ?string $resource;
    protected ?array $appends;
    protected Request $request;
    protected Collection $selected;
    protected array $value;
    protected ?string $orderBy;

    public function __construct(protected Builder $query)
    {
        $this->trackBy = Config::get('enso.select.trackBy');
        $this->queryAttributes = new Collection(Config::get('enso.select.queryAttributes'));
        $this->searchMode = Config::get('enso.select.searchMode');
        $this->resource = null;
        $this->appends = null;
    }

    public function toResponse($request)
    {
        $this->request = $request;

        return $this->resource
            ? App::make($this->resource, ['resource' => null])::collection($this->data())
            : $this->data();
    }

    public function trackBy(string $trackBy): self
    {
        $this->trackBy = $trackBy;

        return $this;
    }

    public function queryAttributes(array $queryAttributes): self
    {
        $this->queryAttributes = new Collection($queryAttributes);

        return $this;
    }

    public function searchMode(string $searchMode): self
    {
        $this->searchMode = $searchMode;

        return $this;
    }

    public function resource(?string $resource): self
    {
        $this->resource = $resource;

        return $this;
    }

    public function appends(?array $appends): self
    {
        $this->appends = $appends;

        return $this;
    }

    protected function data(): Collection
    {
        return $this->init()
            ->applyParams()
            ->applyPivotParams()
            ->selected()
            ->search()
            ->order()
            ->limit()
            ->get();
    }

    protected function init(): self
    {
        $this->value = $this->request->has('value')
            ? (array) $this->request->get('value')
            : [];

        $attribute = $this->queryAttributes->first();
        $this->orderBy = $this->isNested($attribute) ? null : $attribute;

        return $this;
    }

    protected function applyParams(): self
    {
        $this->params()->each(fn ($value, $column) => $this->query
            ->when($value === null, fn ($query) => $query->whereNull($column))
            ->when($value !== null, fn ($query) => $query->whereIn($column, (array) $value)));

        return $this;
    }

    protected function applyPivotParams(): self
    {
        $this->pivotParams()->each(fn ($param, $relation) => $this->query
            ->whereHas($relation, fn ($query) => Collection::wrap($param)
                ->each(fn ($value, $attribute) => $query
                    ->whereIn($attribute, (array) $value))));

        return $this;
    }

    protected function selected(): self
    {
        $this->selected = (clone $this->query)
            ->whereIn($this->trackBy, $this->value)
            ->get();

        return $this;
    }

    protected function search(): self
    {
        $search = $this->request->get('query');

        if (! $search) {
            return $this;
        }

        (new Search($this->query, $this->attributes(), $search))
            ->relations($this->relations())
            ->searchMode($this->searchMode)
            ->comparisonOperator(Config::get('enso.select.comparisonOperator'))
            ->handle();

        return $this;
    }

    protected function attributes(): array
    {
        return $this->queryAttributes
            ->reject(fn ($attribute) => $this->isNested($attribute))
            ->toArray();
    }

    protected function relations(): array
    {
        return $this->queryAttributes
            ->filter(fn ($attribute) => $this->isNested($attribute))
            ->toArray();
    }

    protected function order(): self
    {
        $this->query->when($this->orderBy, fn ($query) => $query->orderBy($this->orderBy));

        return $this;
    }

    protected function limit(): self
    {
        $limit = $this->request->get('paginate') ?? self::Limit;

        $this->query->limit($limit);

        return $this;
    }

    protected function get(): Collection
    {
        return $this->query->whereNotIn($this->trackBy, $this->value)->get()
            ->toBase()
            ->merge($this->selected)
            ->when($this->orderBy !== null, fn ($results) => $results
                ->sortBy($this->orderBy, Config::get('enso.select.sortByOptions')))
            ->values()
            ->when($this->appends, fn ($results) => $results->each->setAppends($this->appends));
    }

    protected function params(): Collection
    {
        return new Collection(json_decode($this->request->get('params'), true));
    }

    protected function pivotParams(): Collection
    {
        return new Collection(json_decode($this->request->get('pivotParams'), true));
    }

    protected function isNested($attribute): bool
    {
        return Str::contains($attribute, '.');
    }
}
