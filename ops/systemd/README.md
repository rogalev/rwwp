# Parser-agent production systemd units

Эти unit/timer-файлы описывают production-запуск parser-agent на отдельной VPS без Docker.

## Процессы

- `russiaww-parser-run-once.timer` каждые 30 секунд запускает `parser:production:run-once`. Команда получает assignments, выполняет listing/article fetch, отправляет raw articles, assignment stats и heartbeat в main.
- `russiaww-parser-image-download.timer` каждые 2 минуты запускает `parser:image-download:run-once`. Команда забирает задачи скачивания изображений у main, скачивает файлы со своего IP и отправляет результат обратно.

Используются `oneshot` services, а не бесконечные shell-loop процессы. Так проще видеть каждый запуск в `journalctl`, systemd сам управляет расписанием, а зависший assignment дополнительно защищается `PARSER_ASSIGNMENT_TIMEOUT_SECONDS`.

## Предпосылки

- Код развернут в `/opt/russiaww-parser/current`.
- Runtime env лежит в `/opt/russiaww-parser/shared/.env.local`.
- Runtime `var` вынесен в `/opt/russiaww-parser/shared/var` и доступен пользователю `parser`.
- Приложение запускается пользователем `parser`.
- На сервере установлен `/usr/bin/php8.4`.
- Перед включением timers успешно проходит:

```bash
cd /opt/russiaww-parser/current
php8.4 bin/console parser:self-check
php8.4 bin/console parser:main:assignments
```

## Установка

```bash
sudo cp ops/systemd/russiaww-parser-*.service /etc/systemd/system/
sudo cp ops/systemd/russiaww-parser-*.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now russiaww-parser-run-once.timer
sudo systemctl enable --now russiaww-parser-image-download.timer
```

## Проверка

```bash
systemctl list-timers 'russiaww-parser-*'
systemctl status russiaww-parser-run-once.timer --no-pager
systemctl status russiaww-parser-image-download.timer --no-pager
journalctl -u russiaww-parser-run-once.service -n 120 --no-pager
journalctl -u russiaww-parser-image-download.service -n 120 --no-pager
```

## Ручной запуск

```bash
sudo systemctl start russiaww-parser-run-once.service
sudo systemctl start russiaww-parser-image-download.service
```
