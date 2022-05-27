<?php

namespace App\Console\Commands\Researches;

use App\Models\Device;
use ReflectionClass;
use ReflectionMethod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

class CheckForeignKeys extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'appoly:morelfk-check {--dir=} {--show-all} {--show-others}' ;

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
     * An array of warning when a relations is from a dependency and
     * maybe optional.
     *
     * @var array
     */
    protected $optionalRelationshipWarnings = [];

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
                        // prepare parent keys
                        $pkParts = explode('.', $return->getQualifiedParentKeyName());
                        $pkTable = $pkParts[0];
                        $pkColumn = $pkParts[1];

                        // prepare foreign keys
                        $fkParts = explode('.',  $return->getQualifiedForeignKeyName());
                        $fkTable = $fkParts[0];
                        $fkColumn = $fkParts[1];

                        // add fk details of the array key  - used for sorting
                        $relationships[$pkTable . '.' . $method->getName()] = [
                            'method'        => $method->getName(),
                            'pk_table'      => $pkTable,
                            'pk_column'     => $pkColumn,
                            'fk_table'      => $fkTable,
                            'fk_column'     => $fkColumn,
                            'fk_exists'     => $this->hasForeignKeys($pkTable, $pkColumn, $fkTable, $fkColumn)
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
                    $modelPath = str_replace(app_path() . '/', '',  $file->getRealPath());
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
     * Check if column has foreign key
     *
     * @param string $table
     * @param string $column
     * @return boolean
     */
    protected function hasForeignKeys(string $pkTable, string $pkColumn, string $fkTable, string $fkColumn)
    {
        $fk = null;
        try {
            $defaultDB = config('database.default');
            $dbConfig = config('database.connections.'.$defaultDB);

            $fk = DB::select("select
                        constraint_name
                    from information_schema.key_column_usage
                    where table_schema = '" . $dbConfig['database'] . "'
                        and table_name = '{ $pkTable }'
                        and column_name = '{ $pkColumn }'
                        and referenced_table_name = '{ $fkTable }'
                        and referenced_column_name = '{ $fkColumn }'"
                );

        } catch (\Throwable $th) {

        }

        return (! empty($fk));
    }

    /**
     * The truth about relationsships and complexities of balancing life.
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
            $fkString = $relationship['method'] . ': ' . $relationship['fk_table'] .  '.' . $relationship['fk_column'];

            if (! $relationship['fk_exists']) {
                // if not in optional list
                if (! in_array($fkString, $this->optionalRelationshipWarnings)) {
                    $this->error($fkString. ' has no foreign key constraints.');
                } else {
                    //if in optional list
                    $this->warn($fkString. ' has no foreign key constraints. However this may be a result from dependency relationship. Pleas verify if needed.');
                }
            } else {
                // only if show all is flag
                if ($this->option('show-all')) {
                    $this->info($fkString . '  has foreign key constraints.');
                }
            }
        }
    }
}
