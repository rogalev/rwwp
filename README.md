# RussiaWW Parser Agent

Parser-agent - отдельное Symfony CLI-приложение. Оно рассчитано на запуск на
отдельных машинах и не должно требовать прямого доступа к базе данных основного
приложения.

Разработка выполняется через Docker Compose. Для повседневных команд не надо
полагаться на локально установленные PHP или Composer.

## Разработка

Собрать PHP-образ:

```bash
make build
```

Запустить Symfony console command:

```bash
make console cmd="list parser"
```

Показать доступные команды parser-agent:

```bash
make parser-list
```

Показать статус последней проверки parser-agent:

```bash
make status
```

Запустить базовую проверку HTTP-загрузки:

```bash
make test-fetch
```

Удалить остановленные контейнеры и orphan-ресурсы Compose:

```bash
make clean
```

## Локальные Runtime-Данные

Parser-agent хранит локальное состояние и output-файлы в `var/`.

- SQLite state database: `var/state/parser.sqlite`
- JSON-статус последнего запуска: `var/status/parser-run.json`
- Диагностический NDJSON-лог dev-режима: `var/log/parser-diagnostic.ndjson`

Директория `var/` содержит runtime-данные и не должна коммититься.

## Работа С Main

Parser-agent не подключается к базе main напрямую. Вся связь идет через Parser
API main-приложения.

Минимальные переменные окружения для связи с main:

```dotenv
MAIN_API_BASE_URL=http://host.docker.internal:8080
PARSER_INSTANCE_ID=0196a111-1111-7111-8111-111111111111
PARSER_API_KEY=parser_api_key
PARSER_AGENT_VERSION=0.1.0
PARSER_AGENT_GIT_COMMIT=abc1234
```

В production реальные значения задаются через окружение процесса или
`.env.local` на конкретной машине. `PARSER_API_KEY` нельзя коммитить.
`PARSER_AGENT_VERSION` и `PARSER_AGENT_GIT_COMMIT` необязательны, но полезны в
админке main: по heartbeat видно, какая версия агента запущена на конкретной
машине. Если commit неизвестен, можно не задавать `PARSER_AGENT_GIT_COMMIT`.

Проверить, что parser-agent видит main и получает свои назначения:

```bash
make console cmd="parser:main:assignments"
```

Перед запуском агента на новой машине полезно выполнить startup self-check:

```bash
make console cmd="parser:self-check"
```

Команда проверяет `pcntl_fork`, PDO SQLite, права на runtime-файлы и доступность
main API. Если хотя бы одна проверка не прошла, команда завершается с ошибкой.

Если назначений нет, сначала проверь в main, что parser instance включен и ему
назначены источники.

Вручную отправить сырой HTML статьи в main:

```bash
make console cmd="parser:main:raw-article:send 0196a222-2222-7222-8222-222222222222 https://example.com/news/1 /app/var/tmp/article.html --status=200"
```

### Скачивание изображений

Main после extraction может создать задачи скачивания изображений. Parser-agent
забирает эти задачи, скачивает файлы со своего IP и отправляет результат обратно
в main:

```bash
make console cmd="parser:image-download:run-once --limit=10"
```

Скачивание изображений запускается отдельным процессом, чтобы тяжелые CDN/медиа
запросы не замедляли получение raw articles. Локальный daemon:

```bash
make image-daemon-start
```

Управление image daemon:

```bash
make image-daemon-logs
make image-daemon-stop
make image-daemon-restart
```

Обработать одно назначение через актуальный scheduled pipeline:

```bash
make console cmd="parser:assignment:run-once 0196a222-2222-7222-8222-222222222222 --limit=1"
```

Обработать все назначения вручную:

```bash
make console cmd="parser:assignments:process --limit-per-assignment=1"
```

Команда для production-запуска из cron или systemd timer:

```bash
make console cmd="parser:run-once --limit-per-assignment=1"
```

Запустить локальный Docker-демон, который повторяет production-цикл каждые 30 секунд:

```bash
make daemon-start
```

Этот daemon обрабатывает assignments и raw articles. Изображения обрабатываются
отдельным `image-daemon-start`.

Управление локальным демоном:

```bash
make daemon-logs
make daemon-stop
make daemon-restart
```

Интервал и лимит можно переопределить:

```bash
make daemon-start interval=60 limit=5
```

В dev-режиме можно включить диагностический NDJSON-лог в `.env.local`:

```dotenv
PARSER_DIAGNOSTIC_LOG_ENABLED=1
```

Лог показывает pipeline stages и main API request/response без API key и без raw HTML:

```bash
make diagnostic-tail
make diagnostic-clear
```

Повторная обработка временно упавших статей настраивается окружением:

```dotenv
PARSER_PENDING_RETRY_DELAY_SECONDS=300
PARSER_PENDING_MAX_ATTEMPTS=5
```

Timeout одного assignment настраивается отдельно:

```dotenv
PARSER_ASSIGNMENT_TIMEOUT_SECONDS=120
```

Этот timeout защищает весь batch от зависшего assignment. В production используется
`pcntl_fork`: каждый assignment обрабатывается в child process, parent ждет его
завершения и убивает child через `SIGKILL`, если превышен лимит. Такой guard
реально прерывает зависший assignment и позволяет batch перейти к следующему.
Если timeout сработал, assignment считается ошибочным, ошибка попадает в status
и best-effort отправляется в `main` как parser failure.

Heartbeat теперь отправляется не только в конце production-команды, но и по ходу
batch после каждого assignment, а также после финальной записи status. Эти
batch heartbeat best-effort: если main временно недоступен, batch продолжает
работу. Финальный heartbeat в `parser:production:run-once` сохраняет прежнее
строгое поведение команды.

Смысл статусов heartbeat:

- `ok` - запуск прошел без ошибок.
- `idle` - назначение есть, но сейчас не наступило время listing/article fetch.
- `partial` - часть работы выполнена, но были ошибки по статьям или назначениям.
- `degraded` - есть признаки деградации транспорта, например 429/5xx.
- `error` - полезная работа не выполнена из-за ошибки.

В status/heartbeat также есть progress-поля:

- `processedAssignments`
- `totalAssignments`
- `timedOutAssignments`
- `currentAssignmentId`
- `currentSource`
- `lastHeartbeatAt`

В heartbeat дополнительно отправляются диагностические поля агента:

- `agentVersion`
- `phpVersion`
- `gitCommit`
- `capabilities`

Если агент настроен через текущий DI-контейнер, heartbeat также содержит
runtime/VPS-метрики. Они собираются best-effort: если конкретный показатель
недоступен на текущей ОС или внутри контейнера, поле уйдет как `null`, а
production-run не должен падать из-за диагностики.

- `hostLabel`
- `hostname`
- `uptimeSeconds`
- `diskTotalBytes`
- `diskUsedBytes`
- `diskFreeBytes`
- `memoryTotalBytes`
- `memoryUsedBytes`
- `memoryAvailableBytes`
- `loadAverage1m`
- `loadAverage5m`
- `loadAverage15m`
- `sqliteStateSizeBytes`
- `diagnosticLogSizeBytes`
- `pendingQueueSize`
- `failedQueueSize`
- `seenArticlesCount`
- `oldestPendingAgeSeconds`

`PARSER_HOST_LABEL` можно задать на VPS вручную, чтобы в main видеть понятное
имя машины, не зависящее от системного hostname.

Показать последний сохраненный статус запуска:

```bash
make status
```

Рекомендуемый порядок диагностики:

1. Проверить назначения: `parser:main:assignments`.
2. Проверить ручную отправку HTML: `parser:main:raw-article:send`.
3. Проверить одно назначение через актуальный pipeline: `parser:assignment:run-once`.
4. Проверить обработку всех назначений через актуальный pipeline: `parser:assignments:process`.
5. Проверить полный один production-цикл: `parser:run-once`.
6. Проверить статус: `make status`.

## Добавление Или Ремонт Источника

Общий pipeline parser-agent должен оставаться стабильным. Правила, зависящие от
верстки конкретного источника, должны быть маленькими и простыми для изменения.

Если источник сломался, начинай с самого маленького слоя, который мог упасть:

1. Проверить, работает ли получение списка новостей.

```bash
make console cmd="parser:listing:fetch rss_feed bbc world https://feeds.bbci.co.uk/news/world/rss.xml"
```

Для HTML listing source настрой selector в `SourceSection.config` на стороне main.
Минимальный формат:

```json
{"listing":{"linkSelector":".article-link"}}
```

Затем запусти:

```bash
make console cmd="parser:listing:fetch html_section source category https://example.com/news"
```

2. Если listing работает, проверить получение HTML статьи и отправку raw article в main.

```bash
make console cmd="parser:main:raw-article:send assignment-id https://www.bbc.com/news/articles/example /app/var/tmp/article.html --status=200"
```

3. Перед изменением parser-кода обновить или добавить fixtures.

- RSS fixtures лежат в `tests/Fixtures/rss/`.
- HTML listing fixtures лежат в `tests/Fixtures/html/`.
- Article HTML fixtures тоже лежат в `tests/Fixtures/html/`.

4. Изменить минимально возможный selector или настройку на стороне main.

- HTML listing selectors приходят из main в assignment config: `listing.linkSelector`.
- Правила извлечения статьи живут на стороне main в extraction profiles; parser-agent отправляет raw HTML.
- Если у одного источника разные шаблоны страниц, сначала добавь отдельный fixture для каждого шаблона, а потом меняй логику.

5. После каждого ремонта запустить тесты.

```bash
make test
```

6. Когда тесты проходят, запустить реальную проверку.

```bash
make console cmd="parser:assignment:run-once assignment-id --limit=1"
```

Затем посмотреть статус последней проверки:

```bash
make status
```

## Git Hygiene

Не коммить локальные IDE-файлы, зависимости и runtime-данные:

- `.idea/`
- `.vscode/`
- `vendor/`
- `var/`
