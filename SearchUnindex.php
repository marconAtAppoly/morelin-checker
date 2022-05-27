<?php

namespace App\Console\Commands\Researches;

use ReflectionClass;
use ReflectionMethod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

class SearchUnindex extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'appoly:morelin-check {--dir=} {--show-all} {--show-others}' ;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Loops through all models and check the relationship definitions. Then check if the key field used in the relationship is indexed. At end it will produce a report.';

    /**
     * The directory name for models.
     *
     * @var string
     */
    protected $modelsDir = '';

    /**
     * The file path name for models.
     *
     * @var string
     */
    protected $modelsPath;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->resolveModelsFolderPath();
        // get all models
        $modelReflections = $this->getModelRefelctions();

        if (! $modelReflections) {
            return;
        }
        $relationships = [];

        if ($modelReflections->count() == 0) {
            $this->warn('No models found. Try another agency.');
            return;
        }

        $this->output->progressStart($modelReflections->count());
        // loop through all found models
        foreach ($modelReflections as $modelReflection) {
            $this->output->progressAdvance();

            $modelClassName =  $modelReflection->getName();

            if ($this->option('show-others')) {
                $this->info('Processing model: ' . $modelClassName);
            }

            foreach (($modelReflection->getMethods(ReflectionMethod::IS_PUBLIC)) as $method) {

                $model = new $modelClassName;
                if (
                    $method->class != $modelClassName
                    || !empty($method->getParameters())
                    || $method->getName() == __FUNCTION__
                ) {
                    continue;
                }

                try {
                    $return = $method->invoke($model);

                    if ($return instanceof Relation) {
                        $fkParts = explode('.',  $return->getQualifiedForeignKeyName());
                        $fkTable = $fkParts[0];
                        $fkColumn = $fkParts[1];

                        $relationships[$fkTable . '-' . $fkColumn] = [
                            'fk_table'      => $fkTable,
                            'fk_column'     => $fkColumn,
                            'fk_indexed'    => $this->isIndexed($fkTable, $fkColumn)
                        ];
                    }
                } catch (\Throwable $th) {
                    if ($this->option('show-others')) {
                        $this->error($th->getMessage());
                    }
                }
            }
        }
        $this->output->progressFinish();

        $this->theTruthAbout($relationships);
     }

    /**
     * Generates model path
     *
     * @return void
     */
    protected function resolveModelsFolderPath()
    {
        // check if models directory is provided
        if (!empty ($this->option('dir'))) {
            $this->modelsDir = $this->option('dir') . '/';
        }

        $this->modelsPath = app_path();

        if (! empty($this->modelsDir)) {
            $this->modelsPath .= '/' . $this->modelsDir;
        }
    }

    /**
     * returns a collection of reflection of all models found in an app
     *
     * @return Collection
     */
    protected function getModelRefelctions()
    {
        if (! file_exists($this->modelsPath)) {
            $this->error($this->modelsPath . ' does not exist.');

            return false;
        }

        return collect(File::allFiles($this->modelsPath))
            ->map(function ($file) {

                if (strpos($file->getRealPath(), '.php')) {
                    $modelPath = str_replace($file->getPath() . '/', '',  $file->getRealPath());
                    $modelPathArray =  explode('/', str_replace('.php', '', $modelPath));
                    $classNameSpace = 'App\\' . implode('\\', $modelPathArray);

                    try {

                        if (class_exists($classNameSpace)) {
                            $reflection = new ReflectionClass($classNameSpace);
                            if (
                                $reflection->isSubclassOf(Model::class) &&
                                !$reflection->isAbstract()
                            ) {
                                return $reflection;
                            }
                        }
                    } catch (\Throwable $th) {
                        $this->error($th->getMessage());
                        $this->warn($file->getFilename() . ' is not a model');
                    }
                }

                return null;
            })
            ->filter(function ($model) {
                return (! empty($model));
            });
    }

    /**
     * Check if column is index
     *
     * @param string $table
     * @param string $column
     * @return boolean
     */
    protected function isIndexed(string $table, string $column)
    {
       $index = DB::select("show index from `{$table}` where Column_name='{$column}';");

       return ! empty($index);
    }

    /**
     * The truth about relationsships
     *
     * no comment.... :)
     *
     * @param array $relationships
     * @return void
     */
    protected function theTruthAbout(array $relationships)
    {
        // sort by key
        ksort($relationships);

        // tell the world about the truth in each relationship.
        foreach ($relationships as $relationship) {

            if (! $relationship['fk_indexed']) {
                $this->error($relationship['fk_table'] .  '.' . $relationship['fk_column'] . ' is not indexed');
            } else {
                // only if show all is flag
                if ($this->option('show-all')) {
                    $this->info($relationship['fk_table'] .  '.' . $relationship['fk_column'] . ' is indexed');
                }
            }
        }
    }
}
