## Required
- PHP v 8.0 or higher

### PHP extensions:
- [Zip](https://www.php.net/manual/en/class.ziparchive.php)
- [PDO](https://www.php.net/manual/en/class.pdo.php)
 
## How to get access-token for Yandex.Disk
[Yandex API documentation](https://yandex.ru/dev/disk-api/doc/ru/concepts/quickstart#quickstart__oauth)
- Create [new application](https://oauth.yandex.ru/client/new/)
- Set redirect URL `http://localhost/`
- Create application with access `cloud_api:disk.write`
- Copy your applicatipn client ID. Replace `{YOUR_CLIENT_ID}` in link and go to URI: `https://oauth.yandex.ru/authorize?response_type=token&client_id={YOUR_CLIENT_ID}`
- Tou will be redirected to `https://localhost/#access_token={YOUR_TOKEN}&token_type=bearer&expires_in=...&cid=...`
- Use `{YOUR_TOKEN}` in script