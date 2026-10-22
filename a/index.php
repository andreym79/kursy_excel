<?php
// Вариант A: разметка — index.html рядом, тексты из CRM подставляет api/cms.php.
require __DIR__ . '/../api/cms.php';
cms_render(__DIR__ . '/index.html');
