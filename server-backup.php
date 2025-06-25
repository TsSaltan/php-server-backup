<?php

use Ifsnop\Mysqldump\Mysqldump;

class ServerBackup {
    protected $paths = [];
    protected $databases = [];
    protected $errorHandler;
    protected $logHandler;
    protected $removeFiles = [];

    /**
     * Filename of saved archive
     * @var string
     */
    protected $archiveFile;

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
        $relativePath = is_null($relativePath) ? (is_dir($path) ? $path : dirname($path)) : $relativePath;
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

    public function createBackup(?string $filepath = null): bool {
        $this->archiveFile = is_null($filepath) ? 'backup-' . date('Y-m-d_H-i-s') . '.zip' : $filepath;

        $archive = new ZipArchive();
        $open = $archive->open($this->archiveFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if (!$open) {
            $this->callErrorHandler('Fail on creating archive file', ['filepath' => $this->archiveFile, 'error' => $open]);
            return false;
        } 
        $this->callLogHandler('Creating backup archive: ' . $this->archiveFile);

        $this->backupDatabases($archive);
        $this->backupFiles($archive);

        $archive->close();

        foreach($this->removeFiles as $file){
            if(file_exists($file)){
                @unlink($file);
            }
        }
        return true;
    }

    protected function isBackupCreated(): bool {
        return file_exists($this->archiveFile);
    }

    public function getArchiveFile(): ?string {
        return $this->isBackupCreated() ? realpath($this->archiveFile) : null;
    }

    public function uploadYandexDisk(string $accessToken, string $remotePath, bool $removeAfterUpload = false): bool {
        if(!$this->isBackupCreated()){
            $this->callErrorHandler('Backup file not created', ['archiveFile' => $this->archiveFile]);
            return false;
        }

        $filePath = realpath($this->archiveFile);
        $remotePath = rtrim($remotePath, '/\\') . '/' . basename($filePath);

        // Step 1: Get the upload URL from Yandex Disk API
        $url = "https://cloud-api.yandex.net/v1/disk/resources/upload?overwrite=true&path=" . urlencode($remotePath);
        $headers = [
            "Authorization: OAuth $accessToken"
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $this->callErrorHandler("Yandex API: Failed to get upload URL", ['httpCode' => $httpCode, 'response' => $response]);
            return false;
        } else {
            $this->callLogHandler('Got upload URL from Yandex Disk API', ['response' => $response]);
        }

        $responseData = json_decode($response, true);
        if (!isset($responseData['href'])) {
            $this->callErrorHandler("Yandex API: Invalid response", ['httpCode' => $httpCode, 'response' => $response]);
            return false;
        }

        $uploadUrl = $responseData['href'];

        // Step 2: Upload the file to the obtained URL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $uploadUrl);
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_INFILE, fopen($filePath, 'r'));
        curl_setopt($ch, CURLOPT_INFILESIZE, filesize($filePath));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 201) {
            $this->callErrorHandler("Yandex API: Failed to upload file", ['httpCode' => $httpCode, 'response' => $response]);
            return false;
        }

        $this->callLogHandler('File successfully uploaded to Yandex.Disk', ['httpCode' => $httpCode, 'response' => $response]);

        if($removeAfterUpload){
            unlink($filePath);
        }
        return true;
    }

    protected $tablesNum = 0;
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
                $filename = "{$table}-{$this->tablesNum}.sql";
                $this->tablesNum++;
                
                $this->callLogHandler('Backup table: `' . $table . '` to file ' . $filename);
                $dump = new Mysqldump($db['dbh'], $db['user'], $db['pass'], ["include-tables" => [$table]]);
                $dump->start($filename);
                $this->addPath($filename, '/.databases/' . $db['type'] . '-' . $db['host'] . '/');
                $this->removeFiles[] = $filename;
            }
        }

        $this->callLogHandler('Backuped ' . $this->tablesNum . ' table(s)');
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