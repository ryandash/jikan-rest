<?php
namespace App\Services;

use App\JikanApiSearchableModel;
use Illuminate\Database\Eloquent\Model;
use Typesense\Client;
use Typesense\Collection as TypesenseCollection;
use Typesense\Exceptions\ObjectNotFound;
use Illuminate\Support\Collection;

/**
 * A service which helps to describe a collection in Typesense based on an eloquent model
 */
final class TypesenseCollectionDescriptor
{
    private readonly Collection $cache;

    public function __construct(private readonly Client $typesense)
    {
        $this->cache = collect();
    }

    public function client(): Client
    {
        return $this->typesense;
    }

    public function getCollectionIndex(JikanApiSearchableModel $model): TypesenseCollection
    {
        $collections = $this->typesense->getCollections();
        $collection = $collections->{$model->searchableAs()};

        try {
            $collection->retrieve();
        } catch (ObjectNotFound) {
            $collections->create($model->getCollectionSchema());
            $collection = $collections->{$model->searchableAs()};
        }

        return $collection;
    }

    public function getSearchableAttributes(JikanApiSearchableModel $model): array
    {
        $modelSearchableAs = $model->searchableAs();
        if ($this->cache->has($modelSearchableAs)) {
            return $this->cache->get($modelSearchableAs);
        }
        /**
         * @var TypesenseCollection $collection
         */
        $collection = $this->getCollectionIndex($model);
        $collectionDetails = $collection->retrieve();
        $fields = collect($collectionDetails["fields"]);
        $searchableAttributeNames = $fields->pluck("name")->all();
        $this->cache->put($modelSearchableAs, $searchableAttributeNames);

        return $searchableAttributeNames;
    }
}