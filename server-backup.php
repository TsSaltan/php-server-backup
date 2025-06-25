<?php

use Ifsnop\Mysqldump\Mysqldump;

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
     * 
     * @param string    $host     
     * @param string    $dbname
     * @param string    $user 
     * @param string    $pass 
     * @param array     $tables (optional) Tables to backup, if empty - backup all tables
     * @param string    $type (optional) Database type (default: 'mysql')
     * @param string    $charset (optional) Database charset (default: 'utf8')
     */
    public function addDatabase(string $host, string $dbname, string $user, string $pass, array $tables = [], string $type = 'mysql', string $charset = 'utf8'): self {
        $dbh = "{$type}:host={$host};dbname={$dbname};charset={$charset}";
        try {
            $db = new PDO($dbh, $user, $pass);
            $this->databases[] = [
                'pdo' => $db,
                'type' => $type,
                'host' => $host,
                'dbh' => $dbh,
                'user' => $user,
                'pass' => $pass,
                'tables' => $tables
            ];
        } catch (PDOException $e) {
            $this->callErrorHandler('Database connection error:' . $e->getMessage(), func_get_args());
        }

        return $this;
    }

    public function createBackup(string $filepath): bool {
        $archive = new ZipArchive();
        $open = $archive->open($filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if (!$open) {
            $this->callErrorHandler('Fail on creating archive file', ['filepath' => $filepath, 'error' => $open]);
            return false;
        } 
        $this->callLogHandler('Creating backup archive: ' . $filepath);

        $this->backupFiles($archive);
        $this->backupDatabases($archive);

        $archive->close();
        return true;
    }

    public function backupDatabases($archive){
        $this->callLogHandler('Backuping databases ...');

        foreach($this->databases as $db){
            $dbh = $db['pdo'];
            $this->callLogHandler('Backup database: ' . $db['dbh']);
            foreach($dbh->query("SHOW TABLES") as $row) {
                $table = current($row);
                if(sizeof($db['tables']) > 0 && !in_array($table, $db['tables'])){
                    continue;
                }
                $this->callLogHandler('Backup table: ' . $table);
                $dump = new Mysqldump($db['dbh'], $db['user'], $db['pass'], ["include-tables" => [$table]]);
                $dump->start("dump-{$table}.sql");
            }
        }
    }

    protected $filesNum = 0;
    protected function backupFiles($archive){
        $this->callLogHandler('Backuping files ...');

        foreach($this->paths as $paths){
            $path = $paths['path'];
            $relativePath = $paths['relative'];
            
            if(is_dir($path)){
                $this->callLogHandler('Backup from directory: ' . $path);
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
                        $this->callLogHandler('Backup file from directory: ' . $filePath);
                        // Add current file to archive
                        $archive->addFile($filePath, $relative);
                        $this->filesNum++;
                    }
                }
            }
            
            if(is_file($path)){
                $this->callLogHandler('Backup file: ' . $path);
                $archive->addFile($path, $relativePath . DIRECTORY_SEPARATOR . basename($path));
                $this->filesNum++;
            }
        }
        $this->callLogHandler('Backuped ' . $this->filesNum . ' file(s)');
    }
}