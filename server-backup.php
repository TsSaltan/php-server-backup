<?php
class ServerBackup {
    protected $paths = [];
    protected $databases = [];
    protected $errorHandler;
    protected $logHandler;

    public function __construct() {
        // Initialization code here
    }

    public function setErrorHandler(callable $handler){
        $this->errorHandler = $handler;
    }

    protected function callErrorHandler(string $error, array $errorData = []){
        call_user_func(is_callable($this->errorHandler) ? $this->errorHandler : [$this, 'defaultErrorHandler'], $error, $errorData);
    }

    protected function defaultErrorHandler(string $error, array $errorData){
        throw new Exception('Error: ' . $error . PHP_EOL . var_export($errorData, true));
    }

    public function setLogHandler(callable $handler){
        $this->logHandler = $handler;
    }

    protected function callLogHandler(string $message, array $data = []){
        call_user_func(is_callable($this->logHandler) ? $this->logHandler : [$this, 'defaultLogHandler'], $message, $data);
    }

    protected function defaultLogHandler(string $text, array $data){
        echo date('[Y-M-d H:i:s] ') . $text . (sizeof($data) > 0 ? var_export($data, true) : '') . PHP_EOL;
    }

    public function addPath(string $path){
        if(is_dir($path) || is_file($path)) {
            $this->paths[] = $path;
        } else {
            $this->callErrorHandler('Invalid path: ' . $path, ['path' => realpath($path)]);
        }

        return $this;
    }

    /**
     * Add database for backup
     * @param string $dbh Database host string, f.e. "mysql:host=localhost;dbname=dbname;charset=utf8"
     * @param string $user
     * @param string $pass
     * @param array $tables Table names for backup, if empty - all tables
     */
    public function addDatabase(string $dbh, string $user, string $pass, array $tables = []){
        try {
            $dbh = new PDO($dbh, $user, $pass);
        } catch (PDOException $e) {
            $this->callErrorHandler('Database connection error:' . $e->getMessage(), func_get_args());
        }

    }

    public function setDestination(){

    }
}