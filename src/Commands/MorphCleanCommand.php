<?php

namespace Cesargb\Database\Support\Commands;

use Cesargb\Database\Support\Morph;
use Illuminate\Console\Command;
use Illuminate\Support\Fluent;
use Illuminate\Support\Str;

use function Laravel\Prompts\progress;

class MorphCleanCommand extends Command
{
    protected $signature = 'morph:clean
                                {--dry-run : test clean}
                                {--tables= : Table list separated by comma}
                                {--limit=100 : Define limit for delete progress}';

    protected $description = 'Clean break relations morph';

    public function handle()
    {

        $morph = new Morph();

        $morph
            ->addModelToFilters(preg_split('/,/', $this->option('tables')))
            ->getModels()
            ->each(function ($relations, $model) use ($morph) {
                $relations->each(function (Fluent $children) use ($model, $morph) {
                    if ($children->toDelete <= 0) {
                        return true;
                    }

                    $progress = progress(label: sprintf(
                        '%s in table %s: %d',
                        Str::remove("dukmaurice\\fuel\Entities\\", $model),
                        $children->childTable,
                        $children->toDelete
                    ), steps: ceil($children->toDelete / $this->option('limit')));
                    $progress->start();
                    for ($i = 0; $i < ceil($children->toDelete / $this->option('limit')); $i++) {
                        if (! $this->option('dry-run')) {
                            $morph->deleteSpecificRelation(
                                parentModel: $children->parentModel,
                                childFieldType: $children->childFieldType,
                                childFieldId: $children->childFieldId,
                                childTable: $children->childTable,
                                limit: $this->option('limit')
                            );
                        }
                        $progress->advance();
                    }
                    $progress->finish();
                });
            });
    }
}
