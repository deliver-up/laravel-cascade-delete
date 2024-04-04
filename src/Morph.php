<?php

namespace Cesargb\Database\Support;

use Cesargb\Database\Support\Events\RelationMorphFromModelWasCleaned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Fluent;
use Symfony\Component\Finder\Finder;

class Morph
{
    protected Collection $models;

    protected Collection $filters;

    public function __construct()
    {
        $this->models = collect();
        $this->filters = collect();
    }

    public function addModelToFilters(array $tables = []): static
    {
        collect($tables)
            ->reject(fn (string $table) => empty($table))
            ->each(fn (string $table) => $this->filters->push($table));

        return $this;
    }

    public function delete($model): void
    {
        foreach ($this->getValidMorphRelationsFromModel($model) as $relationMorph) {
            if ($relationMorph instanceof MorphOneOrMany) {
                $relationMorph->delete();
            } elseif ($relationMorph instanceof MorphToMany) {
                $relationMorph->detach();
            }
        }
    }

    public function cleanResidualAllModels(bool $dryRun = false): int
    {
        $numRowsDeleted = 0;
        foreach ($this->getCascadeDeleteModels() as $model) {

            if (! $this->filters->isEmpty() && ! $this->filters->flip()->has($model->getTable())) {
                continue;
            }
            $this->models->put($model::class, collect());
            $numRowsDeleted += $this->cleanResidualByModel($model, $dryRun);
        }

        return $numRowsDeleted;
    }

    public function cleanResidualByModel($model, bool $dryRun = false): int
    {
        $numRowsDeleted = 0;

        foreach ($this->getValidMorphRelationsFromModel($model) as $relation) {
            if ($relation instanceof MorphOneOrMany || $relation instanceof MorphToMany) {
                $deleted = $this->queryCleanOrphan($model, $relation, $dryRun);
                if ($deleted > 0) {
                    Event::dispatch(
                        new RelationMorphFromModelWasCleaned($model, $relation, $deleted, $dryRun)
                    );
                }

                $numRowsDeleted += $deleted;
            }
        }

        return $numRowsDeleted;
    }

    public function getModels()
    {
        if ($this->models->isEmpty()) {
            $this->cleanResidualAllModels(dryRun: true);
        }

        return $this->models;
    }

    protected function getCascadeDeleteModels(): array
    {
        $this->load();

        return array_map(
            function ($modelName) {
                return new $modelName();
            },
            $this->getModelsNameWithCascadeDeleteTrait()
        );
    }

    protected function queryCleanOrphan(Model $parentModel, Relation $relation, bool $dryRun = false): int
    {
        [$childTable, $childFieldType, $childFieldId] = $this->getStructureMorphRelation($relation);

        $query = DB::table($childTable)
            ->where($childFieldType, $parentModel->getMorphClass())
            ->whereNotExists(function ($query) use (
                $parentModel,
                $childTable,
                $childFieldId
            ) {
                $query->select(DB::raw(1))
                    ->from($parentModel->getTable())
                    ->whereColumn($parentModel->getTable().'.'.$parentModel->getKeyName(), '=', $childTable.'.'.$childFieldId);
            });

        $to_delete = $query->count();

        $this->models->get($parentModel::class)->push(new Fluent([
            'parentModel' => $parentModel,
            'childTable' => $childTable,
            'childFieldType' => $childFieldType,
            'childFieldId' => $childFieldId,
            'toDelete' => $to_delete,
        ]));

        return $to_delete;
    }

    public function deleteSpecificRelation($childTable, $childFieldType, $childFieldId, $parentModel, $limit = 10): int
    {
        return DB::table($childTable)
            ->where($childFieldType, $parentModel->getMorphClass())
            ->whereNotExists(function ($query) use (
                $parentModel,
                $childTable,
                $childFieldId
            ) {
                $query->select(DB::raw(1))
                    ->from($parentModel->getTable())
                    ->whereColumn($parentModel->getTable().'.'.$parentModel->getKeyName(), '=', $childTable.'.'.$childFieldId);
            })->limit($limit)->delete();
    }

    protected function getStructureMorphRelation(Relation $relation): array
    {
        $fieldType = $relation->getMorphType();

        if ($relation instanceof MorphOneOrMany) {
            $table = $relation->getRelated()->getTable();
            $fieldId = $relation->getForeignKeyName();
        } elseif ($relation instanceof MorphToMany) {
            $table = $relation->getTable();
            $fieldId = $relation->getForeignPivotKeyName();
        } else {
            throw new \Exception('Invalid morph relation');
        }

        return [$table, $fieldType, $fieldId];
    }

    protected function getModelsNameWithCascadeDeleteTrait(): array
    {
        return array_filter(
            get_declared_classes(),
            function ($class) {
                return array_key_exists(
                    CascadeDelete::class,
                    class_uses($class)
                );
            }
        );
    }

    protected function getValidMorphRelationsFromModel($model): array
    {
        if (! method_exists($model, 'getCascadeDeleteMorph')) {
            return [];
        }

        return array_filter(
            array_map(
                function ($methodName) use ($model) {
                    return $this->methodReturnedMorphRelation($model, $methodName);
                },
                $model->getCascadeDeleteMorph()
            ),
            function ($relation) {
                return $relation;
            }
        );
    }

    protected function methodReturnedMorphRelation($model, $methodName)
    {
        if (! method_exists($model, $methodName)) {
            return false;
        }

        $relation = $model->$methodName();

        return $this->isMorphRelation($relation) ? $relation : null;
    }

    protected function isMorphRelation($relation): bool
    {
        return $relation instanceof MorphOneOrMany || $relation instanceof MorphToMany;
    }

    protected function load(): void
    {
        foreach ($this->findModels() as $file) {
            require_once $file->getPathname();
        }
    }

    protected function findModels()
    {
        return Finder::create()
            ->files()
            ->in(config('morph.models_paths', app_path()))
            ->name('*.php')
            ->contains('CascadeDelete');
    }
}
