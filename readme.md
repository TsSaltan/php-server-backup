# Script for backup your web-project
## Usage
```php
set_time_limit(0);

$b = new ServerBackup;
$b->addPath('/var/www/html/'); // add directory with all files
$b->addPath('/var/www/private/index.php'); // add file
$b->addDatabase('localhost', 'database', 'user', 'pass'); // add database dump
$b->createBackup(); // zip-archive with backup was created
$archive = $b->getArchiveFile(); // zip-archive filename

// Upload backup to Yandex.Disk
$ydToken = 'y0__abcdefghijklmnopqrstuvwxyz0123456789';
$b->uploadYandexDisk($ydToken, '/backups/my-webserver-backup/', true); // if third param is true - archive file will be deleted

// Upload backup to Dropbox
$dToken = 'dropbox_token';
$b->uploadDropbox($dToken, '/backups/my-webserver-backup/'); // upload archive to dropbox
```

## Requirements
- PHP v 8.0 or higher
- PHP extensions:
  - [Zip](https://www.php.net/manual/en/class.ziparchive.php)
  - [PDO](https://www.php.net/manual/en/class.pdo.php)
 
## How to get access-token for remote services
### Yandex.Disk
[Yandex API documentation](https://yandex.ru/dev/disk-api/doc/ru/concepts/quickstart#quickstart__oauth)
- Create [new application](https://oauth.yandex.ru/client/new/)
- Set redirect URL `http://localhost/`
- Create application with access `cloud_api:disk.write`
- Copy your applicatipn client ID. Replace `{YOUR_CLIENT_ID}` in link and go to URI: `https://oauth.yandex.ru/authorize?response_type=token&client_id={YOUR_CLIENT_ID}`
- Tou will be redirected to `https://localhost/#access_token={YOUR_TOKEN}&token_type=bearer&expires_in=...&cid=...`
- Use `{YOUR_TOKEN}` in script

### Dropbox
[Dropbox developers portal](https://www.dropbox.com/developers/)
- Create [new application](https://www.dropbox.com/developers/apps/create)
- Click *Permissions* tab and select `files.content.write`
- Click *Settings* tab and click to *Generated access token*
- Use generated token in your script
