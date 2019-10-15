<?php
namespace Bilaliqbalr\Athena;

use Aws\S3\S3Client;
use Exception;
use Goodby\CSV\Import\Standard\Interpreter;
use Goodby\CSV\Import\Standard\Lexer;
use Goodby\CSV\Import\Standard\LexerConfig;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Bilaliqbalr\Athena\Query\Grammar as QueryGrammar;
use Bilaliqbalr\Athena\Query\Processor;
use Illuminate\Support\Facades\Config;
use Bilaliqbalr\Athena\Schema\Builder;
use Bilaliqbalr\Athena\Schema\Grammar as SchemaGrammar;
use Bilaliqbalr\Athena\Schema\Grammar;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\Filesystem;

class Connection extends MySqlConnection
{

    /**
     * @var \Aws\Athena\AthenaClient|null
     */
    protected $athenaClient = null;

    /**
     * Local file path downloaded from S3 in Athena response
     * @var
     */
    private $localFilePath = null;

    public function __construct($config)
    {
        $this->database = $config['database'];

        $this->config = Config::get('athena');

        $this->tablePrefix = isset($config['prefix']) ? $config['prefix'] : '';

        $this->athenaClient = $this->getAthenaObj();

        $this->useDefaultQueryGrammar();

        $this->useDefaultPostProcessor();
    }

    private function getAthenaObj()
    {
        if (is_null($this->athenaClient)) {
            $options = [
                'version' => 'latest',
                'region' => $this->config['region'],
                'credentials' => $this->config['credentials']
            ];
            $this->athenaClient = new \Aws\Athena\AthenaClient($options);
        }

        return $this->athenaClient;
    }

    public function getDefaultPostProcessor()
    {
        return new Processor;
    }

    public function getDefaultQueryGrammar()
    {
        return $this->withTablePrefix(new QueryGrammar);
    }

    public function useDefaultPostProcessor()
    {
        $this->postProcessor = $this->getDefaultPostProcessor();
    }

    public function useDefaultQueryGrammar()
    {
        $this->queryGrammar = $this->getDefaultQueryGrammar();
    }

    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new Builder($this);
    }

    public function useDefaultSchemaGrammar()
    {
        $this->schemaGrammar = $this->getDefaultSchemaGrammar();
    }

    protected function getDefaultSchemaGrammar()
    {
        return new SchemaGrammar;
    }

    /**
     * Get the schema grammar used by the connection.
     *
     * @return \Illuminate\Database\Schema\Grammars\Grammar
     */
    public function getSchemaGrammar()
    {
        return $this->schemaGrammar;
    }

    function getS3Filesystem($bucket = '')
    {
        $client = new S3Client([
            'credentials' => $this->config['credentials'],
            'region' => $this->config['region'],
            'version' => $this->config['version'],
        ]);
        $adapter = new AwsS3Adapter($client, $bucket);
        $disk = new Filesystem($adapter);

        return $disk;
    }

    /**
     * @param string $s3Path S3 path of the athena query results
     * @param string $localPath Local path where to store the s3 file content
     *
     * @return bool
     * @throws Exception
     */
    public function downloadFileFromS3ToLocalServer($s3Path, $localPath)
    {
        $filesystem = $this->getS3Filesystem($this->config['bucket']);
        try {
            $s3_file = $filesystem->get($s3Path);
            $file = fopen($localPath, 'w');
            fwrite($file, $s3_file->read());
            fclose($file);
            return true;

        } catch (\Exception $e) {
            throw new Exception("Unable to download file from S3", 0, $e);
        }
    }

    /*
     * @param $filePath csv file path
     * @return array
     * formatCSVFileQueryResults convert csv file into array
     */
    public function formatCSVFileQueryResults($filePath)
    {
        $interpreter = new Interpreter();
        $lexer = new Lexer(new LexerConfig());
        $data = [];
        $interpreter->addObserver(function (array $row) use (&$data) {
            $data[] = $row;
        });
        try {
            $lexer->parse($filePath, $interpreter);
        } catch (\Exception $ex) {
            $response['error_msg'] = $ex->getMessage();

        }
        $attributes = [];
        $items = [];
        foreach ($data as $i => $row) {
            if (0 == $i) {
                $attributes = $row;
            }
            if ($i > 0) {
                $one = [];
                foreach ($attributes as $j => $attribute) {
                    if (array_key_exists($j, $row)) {
                        $one[$attribute] = $row[$j];
                    }
                }
                $items[] = $one;
            }
        }
        return $items;
    }

    /**
     * @param \Illuminate\Database\Query\Builder $builder
     * @param null $query
     * @param null $binding
     *
     * @return mixed
     */
    protected function prepareQuery(\Illuminate\Database\Query\Builder $builder, $query = null, $binding = null)
    {
        $query = is_null($query) ? $builder->toSql() : $query;
        $biding = is_null($binding) ? $builder->getBindings() : $binding;
        if (count($biding) > 0) {
            foreach ($biding as $oneBind) {
                $from = '/' . preg_quote('?', '/') . '/';
                $to = "'" . $oneBind . "'";
                $query = preg_replace($from, $to, $query, 1);
            }
        }

        return str_replace('`', '', $query);
    }

    /**
     * Return local file path downloaded from S3
     * @return null|string
     */
    public function getDownloadedFilePath()
    {
        return $this->localFilePath;
    }

    /**
     * @param $query
     *
     * @return array|\Aws\Result
     * @throws Exception
     */
    protected function executeQuery($query)
    {
        $query = $query instanceof \Illuminate\Database\Query\Builder ? $this->prepareQuery($query) : $query;

        $param_Query = [
            'QueryString' => $query,
            'QueryExecutionContext' => ["Database" => $this->config['database']],
            'ResultConfiguration' => [
                'OutputLocation' => $this->config['s3output']
            ]
        ];

        $response = $this->athenaClient->startQueryExecution($param_Query);

        if ($response) {
            $queryStatus = 'None';
            while ($queryStatus == 'None' or $queryStatus == 'RUNNING' or $queryStatus == 'QUEUED') {
                $executionResponse = $this->athenaClient->getQueryExecution(['QueryExecutionId' => $response['QueryExecutionId']]);
                $executionResponse = $executionResponse->toArray();

                $queryStatus = $executionResponse['QueryExecution']['Status']['State'];
                $stateChangeReason = @$executionResponse['QueryExecution']['Status']['StateChangeReason'];
                if ($queryStatus == 'FAILED' or $queryStatus == 'CANCELLED') {

                    if (stripos($stateChangeReason, 'Partition already exists') === false) {
                        throw new \Exception('Athena Query Error [' . $queryStatus . '] __ ' . $stateChangeReason);
                    }

                } else if ($queryStatus == 'RUNNING' or $queryStatus == 'QUEUED') {
                    sleep(1);
                }
            }

            return $executionResponse;

        } else {
            throw new \Exception('Got error while running athena query');
        }
    }

    /**
     * @param $query
     * @param array $bindings
     *
     * @return bool
     * @throws Exception
     */
    public function execute($query, $bindings = [])
    {
        if ($this->pretending()) {
            return true;
        }

        $start = microtime(true);

        $this->executeQuery($query);

        $this->logQuery(
            $query, $bindings, $this->getElapsedTime($start)
        );

        return true;
    }

    /**
     * Run a select statement against the database.
     *
     * @param  string $query
     * @param  array $bindings
     * @param  bool $useReadPdo
     *
     * @return array
     * @throws \Exception
     */
    public function select($query, $bindings = [], $useReadPdo = true)
    {
        if ($this->pretending()) {
            return [];
        }

        $result = [];
        $start = microtime(true);

        if ($executionResponse = $this->executeQuery($query)) {
            $S3OutputLocation = $executionResponse['QueryExecution']['ResultConfiguration']['OutputLocation'];
            $s3FilePath = '/' . $this->config['outputfolder'] . '/' . basename($S3OutputLocation);
            $localFilePath = public_path("report/" . basename($s3FilePath));

            if ($this->downloadFileFromS3ToLocalServer($s3FilePath, $localFilePath)) {
                $this->localFilePath = $localFilePath;
                $result = $this->formatCSVFileQueryResults($this->localFilePath);
            }
        }
        $this->logQuery(
            $query, $bindings, $this->getElapsedTime($start)
        );

        return $result;
    }

}
