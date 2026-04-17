# Samudra Agent

CLI-агент для подключения проекта к Samudra Platform.

## Требования

- PHP 8.4+
- `curl`
- `bash`

## Установка

Поставить последний release:

```bash
curl -fsSL https://raw.githubusercontent.com/samudra-php/agent/main/bin/install.sh | bash
```

Проверить установку:

```bash
samudra --help
```

Если `~/.local/bin` не в `PATH`, установщик подскажет точную строку для shell.

Если нужен конкретный release:

```bash
curl -fsSL https://raw.githubusercontent.com/samudra-php/agent/main/bin/install.sh | \
  SAMUDRA_INSTALL_URL="https://github.com/samudra-php/agent/releases/download/v0.1.0/samudra.phar" bash
```

## Подключение проекта

В каталоге индексируемого PHP-проекта:

```bash
samudra init
samudra login --token "...ваш_токен..."
samudra register
samudra extract
samudra status
```

Если платформа доступна не по `http://localhost:18000`, укажи URL явно:

```bash
samudra init --platform-url http://your-host:port
```

Что делают команды:

- `samudra init` — создаёт `.samudra.yml`
- `samudra login` — сохраняет URL платформы и токен
- `samudra register` — регистрирует проект на платформе
- `samudra extract` — собирает и отправляет bundle
- `samudra status` — показывает состояние конфигурации и последнего run

## Локальная проверка без upload

```bash
samudra extract --output bundle.json
```
