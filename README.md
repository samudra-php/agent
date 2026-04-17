# Samudra Agent

CLI-агент для регистрации проекта, extraction, сборки bundle и upload на Samudra Platform.

## Установка для разработки

```bash
composer install
./bin/samudra list
```

Репозиторий ожидает рядом sibling checkout:

- `../extractor`
- `../indexbundle-contract`

## Установка как глобальной команды

Сборка PHAR:

```bash
composer install
./bin/build-phar
```

Установка команды `samudra` в `~/.local/bin`:

```bash
./bin/install.sh
```

После этого команда будет доступна как:

```bash
samudra --help
```

Если `~/.local/bin` не в `PATH`, установщик подскажет точную строку для shell.

Установка из release URL:

```bash
SAMUDRA_INSTALL_URL="https://example.com/samudra.phar" ./bin/install.sh
```

## Использование против локальной платформы

В целевом PHP-проекте:

```bash
samudra init --platform-url http://localhost:18000
samudra login --token "...токен_из_platform/bin/dev-token..."
samudra register
samudra extract
samudra status
```

Для локальной проверки без загрузки на платформу:

```bash
samudra extract --output bundle.json
```

## Команды Agent DX

- `samudra init` — создаёт/дополняет `.samudra.yml` безопасными дефолтами.
- `samudra login` — проверяет API и сохраняет `platform.url` + `auth.token`.
- `samudra status` — показывает local state, доступность API и статус последнего run.

Если токен не сохранён в `.samudra.yml`, агент использует `SAMUDRA_TOKEN` из окружения как fallback.
