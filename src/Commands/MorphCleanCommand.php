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
                                {--limit=1000 : Define limit for delete progress}';

    protected $description = 'Clean break relations morph';

    public function handle(): int
    {

        $morph = new Morph();

        $morph
            ->addModelToFilters(explode(',', $this->option('tables')))
            ->getModels()
            ->each(fn ($relations, $model) => $relations->each(function (Fluent $children) use ($model, $morph) {

                $this->info("Search $model on table {$children->childTable}...");

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
                            childTable: $children->childTable,
                            childFieldType: $children->childFieldType,
                            childFieldId: $children->childFieldId,
                            parentModel: $children->parentModel,
                            limit: $this->option('limit')
                        );
                    }
                    $progress->advance();
                }
                $progress->finish();
            }));

        return Command::SUCCESS;
    }
}
