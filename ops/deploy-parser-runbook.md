# Parser-agent production deploy runbook

Документ описывает ручной production deploy parser-agent без Docker.

Текущая схема директорий:

- `/opt/russiaww-parser/releases` - immutable release-директории;
- `/opt/russiaww-parser/current` - symlink на активный release;
- `/opt/russiaww-parser/shared/.env.local` - production env конкретного агента;
- `/opt/russiaww-parser/shared/var` - SQLite state, status и diagnostic logs между релизами.

Команды выполняются пользователем `parser`, кроме явно отмеченных `sudo`.

## 1. Pre-check

```bash
whoami
sudo -v
php -v
composer --version
ls -la /opt/russiaww-parser
ls -la /opt/russiaww-parser/shared/.env.local
ls -la /opt/russiaww-parser/shared/var
```

Ожидания:

- `whoami` возвращает `parser`;
- PHP major/minor: `8.4`;
- `shared/.env.local` существует и содержит `MAIN_API_BASE_URL`, `PARSER_INSTANCE_ID`, `PARSER_API_KEY`;
- `shared/var` доступен на запись пользователю `parser`.

## 2. Prepare release

```bash
export RELEASE_ID="$(date -u +%Y%m%d%H%M%S)"
export RELEASE_DIR="/opt/russiaww-parser/releases/${RELEASE_ID}"
mkdir -p "$RELEASE_DIR"
cd "$RELEASE_DIR"
```

Склонировать код:

```bash
git clone git@github.com:rogalev/russiaww-parser.git .
```

Если используется другой remote, заменить URL на актуальный.

Проверить commit:

```bash
git rev-parse --short HEAD
git status --short
```

## 3. Install dependencies

```bash
composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction
```

Parser-agent не требует frontend assets.

## 4. Link shared runtime

```bash
ln -sfn /opt/russiaww-parser/shared/.env.local "$RELEASE_DIR/.env.local"
rm -rf "$RELEASE_DIR/var"
ln -sfn /opt/russiaww-parser/shared/var "$RELEASE_DIR/var"
```

Проверить:

```bash
ls -la "$RELEASE_DIR/.env.local"
ls -la "$RELEASE_DIR/var"
```

## 5. Validate release before switch

```bash
APP_ENV=prod APP_DEBUG=0 php bin/console lint:container --no-debug
APP_ENV=prod APP_DEBUG=0 php bin/console parser:self-check
APP_ENV=prod APP_DEBUG=0 php bin/console parser:main:assignments
```

Если `parser:self-check` падает по `pcntl_fork`, production timeout guard работать не будет. Такой release нельзя включать на VPS до установки/исправления PHP CLI окружения.

Если assignments пустые, это не всегда ошибка deploy. Нужно проверить в main:

- parser instance включен;
- API key совпадает;
- source section назначен этому parser instance;
- source/source section/assignment включены.

## 6. Stop timers

```bash
sudo systemctl stop russiaww-parser-run-once.timer
sudo systemctl stop russiaww-parser-image-download.timer
```

Если service прямо сейчас выполняется, дождаться завершения:

```bash
systemctl status russiaww-parser-run-once.service --no-pager
systemctl status russiaww-parser-image-download.service --no-pager
```

## 7. Switch current symlink

Запомнить предыдущий release:

```bash
export PREVIOUS_RELEASE="$(readlink -f /opt/russiaww-parser/current || true)"
echo "$PREVIOUS_RELEASE"
```

Переключить release:

```bash
ln -sfn "$RELEASE_DIR" /opt/russiaww-parser/current
```

## 8. Post-deploy checks

```bash
cd /opt/russiaww-parser/current
APP_ENV=prod APP_DEBUG=0 php bin/console parser:self-check
APP_ENV=prod APP_DEBUG=0 php bin/console parser:main:assignments
APP_ENV=prod APP_DEBUG=0 php bin/console parser:production:run-once --limit-per-assignment=1
APP_ENV=prod APP_DEBUG=0 php bin/console parser:image-download:run-once --limit=2
APP_ENV=prod APP_DEBUG=0 php bin/console parser:status:show
```

Проверить, что main видит heartbeat и assignment stats на странице конкретного parser instance.

Вернуть timers:

```bash
sudo systemctl start russiaww-parser-run-once.timer
sudo systemctl start russiaww-parser-image-download.timer
systemctl list-timers 'russiaww-parser-*'
```

Проверить логи:

```bash
journalctl -u russiaww-parser-run-once.service -n 120 --no-pager
journalctl -u russiaww-parser-image-download.service -n 120 --no-pager
```

## 9. Rollback

Rollback parser-agent обычно безопаснее, чем rollback main: parser-agent не меняет схему БД main и хранит локальное state в `shared/var`.

Важно: rollback кода не откатывает SQLite state. Это ожидаемо: state хранится между релизами, чтобы не терять dedupe/pending queue.

Переключить symlink назад:

```bash
sudo systemctl stop russiaww-parser-run-once.timer
sudo systemctl stop russiaww-parser-image-download.timer
ln -sfn "$PREVIOUS_RELEASE" /opt/russiaww-parser/current
cd /opt/russiaww-parser/current
APP_ENV=prod APP_DEBUG=0 php bin/console parser:self-check
sudo systemctl start russiaww-parser-run-once.timer
sudo systemctl start russiaww-parser-image-download.timer
```

Проверить:

```bash
APP_ENV=prod APP_DEBUG=0 php /opt/russiaww-parser/current/bin/console parser:status:show
journalctl -u russiaww-parser-run-once.service -n 120 --no-pager
```

## 10. Cleanup old releases

После стабильной работы нового release можно удалить старые releases, оставив последние 3-5:

```bash
ls -1dt /opt/russiaww-parser/releases/* | tail -n +6
```

Удалять только после ручной проверки списка:

```bash
rm -rf /opt/russiaww-parser/releases/OLD_RELEASE_ID
```
