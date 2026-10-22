<?php

namespace App\Services;

use App\JikanApiSearchableModel;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Typesense\Exceptions\ObjectNotFound;

class TypesenseScoutEngine extends Engine
{
    public function __construct(private readonly TypesenseCollectionDescriptor $collections)
    {
    }

    public function update($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $documents = $models->map(fn (Model $model): array => $model->toSearchableArray())->all();
        $responses = $this->collections
            ->getCollectionIndex($models->first())
            ->getDocuments()
            ->import($documents, ['action' => 'upsert']);

        foreach ($responses as $response) {
            if (!($response['success'] ?? false)) {
                throw new \RuntimeException('Typesense document import failed: ' . ($response['error'] ?? 'unknown error'));
            }
        }
    }

    public function delete($models): void
    {
        foreach ($models as $model) {
            try {
                $this->collections
                    ->getCollectionIndex($model)
                    ->getDocuments()[(string) $model->getScoutKey()]
                    ->delete();
            } catch (ObjectNotFound) {
            }
        }
    }

    public function search(Builder $builder): mixed
    {
        return $this->performSearch($builder, 1, $builder->limit);
    }

    public function paginate(Builder $builder, $perPage, $page): mixed
    {
        return $this->performSearch($builder, $page, $perPage);
    }

    public function mapIds($results): Collection
    {
        return collect($results['hits'] ?? [])
            ->pluck('document.id')
            ->values();
    }

    public function map(Builder $builder, $results, $model): EloquentCollection
    {
        $ids = $this->mapIds($results);
        if ($ids->isEmpty()) {
            return $model->newCollection();
        }

        $models = $model->queryScoutModelsByIds($builder, $ids->all())
            ->get()
            ->keyBy(fn (Model $result): string => (string) $result->getScoutKey());

        return $model->newCollection(
            $ids->map(fn ($id) => $models->get((string) $id))
                ->filter()
                ->values()
                ->all()
        );
    }

    public function lazyMap(Builder $builder, $results, $model): LazyCollection
    {
        return LazyCollection::make(fn () => $this->map($builder, $results, $model));
    }

    public function getTotalCount($results): int
    {
        return (int) ($results['found'] ?? 0);
    }

    public function flush($model): void
    {
        $collectionName = $model->searchableAs();

        try {
            $this->collections->client()->getCollections()->{$collectionName}->delete();
        } catch (ObjectNotFound) {
        }
    }

    public function createIndex($name, array $options = []): mixed
    {
        return $this->collections->client()->getCollections()->create([
            'name' => $name,
            ...$options,
        ]);
    }

    public function deleteIndex($name): mixed
    {
        return $this->collections->client()->getCollections()->{$name}->delete();
    }

    private function performSearch(Builder $builder, int $page, ?int $perPage): mixed
    {
        if (!$builder->model instanceof JikanApiSearchableModel) {
            throw new \InvalidArgumentException('Typesense search requires a Jikan API searchable model.');
        }

        $options = [
            'q' => $builder->query,
            'query_by' => implode(',', $builder->model->typesenseQueryBy()),
            'page' => $page,
        ];

        if ($perPage !== null) {
            $options['per_page'] = $perPage;
        }

        $filters = $this->filters($builder);
        if ($filters !== '') {
            $options['filter_by'] = $filters;
        }

        if ($builder->orders !== []) {
            $options['sort_by'] = collect($builder->orders)
                ->map(fn (array $order): string => $order['column'] . ':' . $order['direction'])
                ->implode(',');
        }

        $options = array_merge($options, $builder->options);
        $documents = $this->collections->getCollectionIndex($builder->model)->getDocuments();

        return $builder->callback
            ? ($builder->callback)($documents, $builder->query, $options)
            : $documents->search($options);
    }

    private function filters(Builder $builder): string
    {
        $filters = [];

        foreach ($builder->wheres as $where) {
            $filters[] = $this->formatFilter(
                $where['field'],
                $where['operator'],
                $where['value'],
            );
        }

        foreach ($builder->whereIns as $field => $values) {
            $filters[] = $field . ':=[' . collect($values)->map($this->formatValue(...))->implode(',') . ']';
        }

        foreach ($builder->whereNotIns as $field => $values) {
            $filters[] = $field . ':!=[' . collect($values)->map($this->formatValue(...))->implode(',') . ']';
        }

        return implode(' && ', $filters);
    }

    private function formatFilter(string $field, string $operator, mixed $value): string
    {
        $typesenseOperator = match ($operator) {
            '=' => ':=',
            '!=' => ':!=',
            '>' => ':>',
            '>=' => ':>=',
            '<' => ':<',
            '<=' => ':<=',
            default => throw new \InvalidArgumentException("Unsupported Typesense filter operator: {$operator}"),
        };

        if (is_array($value)) {
            return $field . $typesenseOperator . '[' . collect($value)->map($this->formatValue(...))->implode(',') . ']';
        }

        return $field . $typesenseOperator . $this->formatValue($value);
    }

    private function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '`' . str_replace(['\\', '`'], ['\\\\', '\\`'], (string) $value) . '`';
    }
}
