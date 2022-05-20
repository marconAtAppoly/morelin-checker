<?php

namespace App\Console\Commands\Researches;

use App\Appointment;
use ReflectionClass;
use ReflectionMethod;
use Illuminate\Console\Command;
use PhpParser\Node\Stmt\TryCatch;
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
    protected $signature = 'appoly:model-relationship-index-hunt';

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
     * The namespace for models.
     *
     * @var string
     */
    protected $modelsNameSpace;


    public function __construct()
    {
        parent::__construct();

        $this->resolveModelsFolderPathAndNameSpace();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // get all models
        $modelReflections = $this->getModelRefelctions();

        $relationships = [];

        $this->output->progressStart($modelReflections->count());
        // loop through all found models
        foreach ($modelReflections as $modelReflection) {
            $this->output->progressAdvance();

            $modelClassName =  $modelReflection->getName();
            $this->info('Processing model: ' . $modelClassName);

            foreach (($modelReflection->getMethods(ReflectionMethod::IS_PUBLIC)) as $method) {

                $model = new $modelClassName;
                if (
                    $method->class != $modelClassName ||
                    !empty($method->getParameters()) ||
                    $method->getName() == __FUNCTION__
                ) {
                    continue;
                }

                try {
                    $return = $method->invoke($model);


                    if ($return instanceof Relation) {
                        $fkParts = explode('.',  $return->getQualifiedForeignKeyName());
                        $fkTable = $fkParts[0];
                        $fkColumn = $fkParts[1];

                        $relationships[$method->getName()] = [
                            'fk_table'      => $fkTable,
                            'fk_column'     => $fkColumn,
                            'fk_indexed'    => $this->isIndexed($fkTable, $fkColumn)
                        ];
                    }
                } catch (\Throwable $th) {
                    $this->error($th->getMessage());
                }
            }

            dd($relationships);

        }
        $this->output->progressFinish();
     }

    /**
     * Generates model path
     *
     * @return void
     */
    protected function resolveModelsFolderPathAndNameSpace()
    {
        $this->modelsPath = app_path();
        $this->modelsNameSpace = 'App\\';

        if (! empty($this->modelsDir)) {
            $this->modelsPath .= '/' . $this->modelsDir;
            $this->modelsNameSpace .= $this->modelsDir . '\\';
        }
    }

    /**
     * returns a collection of reflection of all models found in an app
     *
     * @return Collection
     */
    protected function getModelRefelctions()
    {
        return collect(File::files($this->modelsPath))
            ->map(function ($file) {
                $fileName = ($file->getRelativePathName());

                if (strpos($fileName, '.php')) {
                    $class = $this->modelsNameSpace . str_replace('.php', '', $fileName);

                    try {

                        if (class_exists($class)) {
                            $reflection = new ReflectionClass($class);
                            if (
                                $reflection->isSubclassOf(Model::class) &&
                                !$reflection->isAbstract()
                            ) {
                                return $reflection;
                            }
                        }
                    } catch (\Throwable $th) {
                        $this->error($th->getMessage());
                        $this->warn($fileName . ' is not a model');
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
}
