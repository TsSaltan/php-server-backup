<?php
use Ifsnop\Mysqldump\Mysqldump;

class ServerBackup {
    /**
     * Paths to files and directories for backup
     * @var array
     */
    protected $paths = [];

    /**
     * Databases for backup
     * @var array
     */
    protected $databases = [];

    /**
     * Error handler
     * @var callable
     */
    protected $errorHandler;

    /**
     * Log handler
     * @var callable
     */
    protected $logHandler;

    /**
     * Files to remove after backup
     * @var array
     */
    protected $removeFiles = [];

    /**
     * Filename of saved archive
     * @var string
     */
    protected $archiveFile;
    
    /**
     * Number of backuped tables
     * @var int
     */
    protected $tablesNum = 0;

    /**
     * Number of backuped files
     * @var int
     */
    protected $filesNum = 0;

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
        echo date('[Y-M-d H:i:s] ') . $text . (sizeof($data) > 0 ? PHP_EOL . var_export($data, true) : '') . PHP_EOL;
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
        $relativePath = ltrim($relativePath, '/\\');

        if(is_dir($path) || is_file($path)) {
            $this->paths[] = [
                'path' => realpath($path),
                'relative' => $relativePath,
            ];
        } else {
            $this->callErrorHandler('Invalid backup path: ' . $path);
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
     * @param int       $port (optional) (default: 3306)
     */
    public function addDatabase(string $host, string $dbname, string $user, string $pass, array $tables = [], string $type = 'mysql', string $charset = 'utf8', int $port = 3306): self {
        $dsn = "{$type}:host={$host};port={$port};dbname={$dbname};charset={$charset}";
        try {
            $db = new PDO($dsn, $user, $pass);
            $this->databases[] = [
                'pdo' => $db,
                'type' => $type,
                'host' => $host,
                'port' => $port,
                'dsn' => $dsn,
                'user' => $user,
                'pass' => $pass,
                'tables' => $tables
            ];
        } catch (PDOException $e) {
            $this->callErrorHandler('Cannot connect to database (' . $dsn . '): ' . $e->getMessage(), func_get_args());
        }

        return $this;
    }

    /**
     * Create backup archive
     * 
     * @param string|null $filepath Path to save archive, if null - use default name with current date and time
     * @return bool True on success, false on failure
     */
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
        $this->callLogHandler('Backup saved to archive: ' . $this->archiveFile);
        return true;
    }

    protected function isBackupCreated(): bool {
        return file_exists($this->archiveFile);
    }

    /**
     * Get path to created archive file
     * 
     * @return string|null Path to archive file or null if backup not created
     */
    public function getArchiveFile(): ?string {
        return $this->isBackupCreated() ? realpath($this->archiveFile) : null;
    }

    /**
     * Upload backup archive to Yandex Disk
     * 
     * @param string $accessToken OAuth access token for Yandex Disk API
     * @param string $remotePath Remote path on Yandex Disk where the file will be uploaded
     * @param bool $removeAfterUpload Remove local file after upload (default: false)
     * @return bool
     */
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

        if($httpCode !== 200) {
            $this->callErrorHandler("Yandex API: Failed to get upload URL (code: " . $httpCode . "): " . $response, ['httpCode' => $httpCode, 'response' => $response]);
            return false;
        } 
        
        $this->callLogHandler('Uploading file to Yandex Disk ...', ['response' => $response]);

        $responseData = json_decode($response, true);
        if (!isset($responseData['href'])) {
            $this->callErrorHandler("Yandex API: Invalid response (code: " . $httpCode . "): " . $response, ['httpCode' => $httpCode, 'response' => $response]);
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
            $this->callErrorHandler("Yandex API: Failed to upload file (code: " . $httpCode . "): " . $response, ['httpCode' => $httpCode, 'response' => $response]);
            return false;
        }

        $this->callLogHandler('File successfully uploaded to Yandex.Disk: ' . $remotePath);

        if($removeAfterUpload){
            unlink($filePath);
        }
        return true;
    }

    public function uploadDropbox(string $accessToken, string $remotePath, bool $removeAfterUpload = false): bool {
        if(!$this->isBackupCreated()){
            $this->callErrorHandler('Backup file not created', ['archiveFile' => $this->archiveFile]);
            return false;
        }

        $filePath = realpath($this->archiveFile);
        $remotePath = '/' . ltrim(trim($remotePath, '/\\') . '/' . basename($filePath), '/\\');
        
        $fp = fopen($filePath , 'rb');
        $size = filesize($filePath);
        $apiArgs = [
            'path' => $remotePath,
            'mode' => 'add',
        ];
        
        $this->callLogHandler('Uploading file to Dropbox ...', ['api_arg' => $apiArgs]);
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/octet-stream',
            'Dropbox-API-Arg: ' . json_encode($apiArgs),
        ];

        $ch = curl_init('https://content.dropboxapi.com/2/files/upload');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_INFILE, $fp);
        curl_setopt($ch, CURLOPT_INFILESIZE, $size);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $jsonResponse = json_decode($response, true);
 
        curl_close($ch);
        fclose($fp);

        if($removeAfterUpload){
            unlink($filePath);
        }

        if(isset($jsonResponse['path_display'])){
            $this->callLogHandler('File successfully uploaded to Dropbox at path: ' . $jsonResponse['path_display'], ['response' => $jsonResponse]);
            return true;
        } else {
            $this->callErrorHandler('Fail to upload file to Dropbox. API answer: ' . $response, ['response' => $response]);
            return false;
        }

    }

    protected function backupDatabases($archive){
        $this->callLogHandler('Backuping databases ...');

        foreach($this->databases as $db){
            $dbh = $db['pdo'];
            $this->callLogHandler('Backup database: ' . $db['dsn']);
            foreach($dbh->query("SHOW TABLES") as $row) {
                $table = current($row);
                if(sizeof($db['tables']) > 0 && !in_array($table, $db['tables'])){
                    continue;
                }
                
                $filename = sys_get_temp_dir() . '/' . sprintf("%02d", $this->tablesNum) . "-{$table}.sql";
                $this->tablesNum++;
                
                $this->callLogHandler('Backup table: `' . $table . '` to file ' . $filename);
                $dump = new Mysqldump($db['dsn'], $db['user'], $db['pass'], ["include-tables" => [$table]]);
                $dump->start($filename);
                $this->addPath($filename, '/.databases/' . $db['user'] . '@' . $db['host'] . '/');
                $this->removeFiles[] = $filename;
            }
        }

        $this->callLogHandler('Backuped ' . $this->tablesNum . ' table(s)');
    }
    
    protected function backupFiles($archive){
        $this->callLogHandler('Backuping files ...');

        foreach($this->paths as $paths){
            $path = $paths['path'];
            $relativePath = $paths['relative'];
            
            if(is_dir($path)){
                $this->callLogHandler('Backup from directory: ' . $path);

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
                        //$this->callLogHandler('Backup file from directory: ' . $filePath);
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