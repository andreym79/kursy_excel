<?php
/**
 * Список полей, которые меняются из CRM (кнопка «Обновить список полей» в CRM).
 * Открывается как /cms-fields.json (правило в .htaccess). Собирается из разметки страниц,
 * поэтому новое поле достаточно отметить data-cms="…" в HTML.
 */
declare(strict_types=1);
require __DIR__ . '/cms.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$templates = glob(__DIR__ . '/../*/index.html') ?: [];
echo json_encode(['fields' => cms_fields($templates)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
