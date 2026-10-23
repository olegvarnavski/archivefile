# Развёртывание и серверные проверки

Поддерживаемая ветка — MODX 2.8.x, целевая среда — 2.8.5-pl / PHP 7.4.
MODX 3 заблокирован в manifest и установщике. Для PHP 8 требуется отдельный прогон на
выбранной версии MODX; устранение одного deprecated-вызова не подтверждает совместимость целиком.

## Хранение и публичные файлы

- Закрытые архивы: archivefile.storage_path. Apache получает `Require all denied`.
- Публичные lastfile/parts: assets/archivefile/ID/. Они доступны без входа.
- Исторические копии: file.php, проверка SHA-256, attachment/nosniff/CSP sandbox/no-store.
- Apache: публичная папка получает no-cache, attachment, nosniff и CSP sandbox через mod_headers.
- Nginx не читает .htaccess. Закрытый storage должен быть недоступен по HTTP; лучше размещать его вне document root. Перенос существующего storage требует отдельного копирования файлов и проверки путей, модуль сам его не выполняет.

Пример Nginx для обычного пути `/assets/` (адаптировать к реальному assets_url и существующим location):

```nginx
location ^~ /assets/archivefile/ {
    autoindex off;
    add_header Cache-Control "no-cache" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Content-Security-Policy "sandbox; default-src 'none'" always;
    add_header Content-Disposition "attachment" always;
}
# Только если core находится в web root и ещё не закрыт более широким правилом:
location ^~ /core/components/archivefile/storage/ {
    deny all;
}
```

Эти настройки автоматически не устанавливаются. При другом расположении core/storage
правило должно соответствовать фактическому HTTP-пути, а не пути файловой системы.
Проверить фактические ответы через Network/curl: закрытый архив 403/404, публичный XML
200 с нужными заголовками. На CDN отключить долгое кеширование постоянных адресов.

## Права и диагностика

PHP сайта и CLI должны читать/писать один storage и публичную папку. При несовпадении
пользователей проверить группу/права; не назначать 0777 как универсальное исправление.
Команда cron в компоненте включает фактический путь сайта и настроенный PHP CLI.

Журнал компонента показывает ошибки сети/XML/публикации. Ошибки bootstrap/очистки ищутся
в журнале MODX/PHP или stderr cron. Не отправлять исходные ссылки с токенами и содержимое
приватных runtime/config в Issues. При зависшем running следующая операция под lock
отметит прежний запуск как прерванный; реальный долгий запуск не прерывается по таймеру.

Не удалять source-ID.publication.json или резерв вручную: следующий save/fetch/delete
сначала пытается восстановить согласованное состояние. При постоянной ошибке проверить
свободное место/права и журнал. Установка откажет при занятых source/cron-lock.
