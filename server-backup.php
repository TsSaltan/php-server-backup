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

    /**
     * Add files and directories for backup
     * @param string $path Path to file or directory
     * @param string|null $relativePath Relative path in archive, if null - use $path
     * @return self
     */
    public function addPath(string $path, ?string $relativePath = null): self {
        $relativePath = is_null($relativePath) ? $path : $relativePath;
        $relativePath = rtrim($relativePath, '/\\');

        if(is_dir($path) || is_file($path)) {
            $this->paths[] = [
                'path' => realpath($path),
                'relative' => $relativePath,
            ];
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
            $db = new PDO($dbh, $user, $pass);
            $this->databases[] = [
                'db' => $db,
                'dbh' => $dbh,
                'user' => $user,
                'pass' => $pass,
                'tables' => $tables
            ];
        } catch (PDOException $e) {
            $this->callErrorHandler('Database connection error:' . $e->getMessage(), func_get_args());
        }
    }

    public function createBackup(string $filepath): bool {
        $archive = new ZipArchive();
        $open = $archive->open($filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if (!$open) {
            $this->callErrorHandler('Fail on creating archive file', ['filepath' => $filepath, 'error' => $open]);
            return false;
        } 

        $this->backupFiles($archive);

        $archive->close();
        return true;
    }

    protected function backupFiles($archive){
        foreach($this->paths as $paths){
            $path = $paths['path'];
            $relativePath = $paths['relative'];

            if(is_dir($path)){
                //$archive->addGlob($p . '/*.*', GLOB_BRACE, [/*'add_path' => $p, 'remove_all_path' => true*/]);

                // Create recursive directory iterator
                /** @var SplFileInfo[] $files */
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ($files as $file){
                    // Skip directories (they would be added automatically)
                    if(!$file->isDir()){
                        // Get real and relative path for current file
                        $filePath = $file->getRealPath();
                        $relative = $relativePath . DIRECTORY_SEPARATOR . substr($filePath, strlen($path) + 1);

                        // Add current file to archive
                        $archive->addFile($filePath, $relative);
                    }
                }
            }
            
            if(is_file($path)){
                $archive->addFile($path, $relativePath . DIRECTORY_SEPARATOR . basename($path));
            }
        }
    }
}