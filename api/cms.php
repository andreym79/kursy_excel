<?php
/**
 * Тексты страницы из Mini CRM (модуль «Тексты сайта»).
 *
 * Разметка в a/index.html, c/index.html:
 *   data-cms="ключ"        — текст элемента берётся из CRM (в HTML стоит текст по умолчанию);
 *   data-cms-label="…"     — название поля в CRM;
 *   data-cms-multiline     — многострочное поле (переносы строк → <br>);
 *   data-cms-tel           — у ссылки tel: номер берётся из того же поля;
 *   data-cms-href="ключ"   — адрес ссылки берётся из CRM (только http(s)://, tg://, mailto:, tel:).
 * Элемент с data-cms — «листовой»: внутри только текст, без вложенных тегов того же типа.
 *
 * Тексты хранятся в storage/cms-cache.json и проверяются в CRM раз в crm.cache_seconds.
 * CRM недоступна → показываются последние загруженные; их нет → текст из HTML.
 * Требования: PHP 7.4+, расширение curl.
 */
declare(strict_types=1);

const CMS_TEXT = '~<([a-z][a-z0-9]*)\b([^>]*?\sdata-cms="([a-z][a-z0-9_]*)"[^>]*)>(.*?)</\1>~s';
const CMS_HREF = '~<a\b([^>]*?\sdata-cms-href="([a-z][a-z0-9_]*)"[^>]*)>~';

function cms_config(): array {
    static $crm = null;
    if ($crm === null) {
        $file = __DIR__ . '/config.php';
        $cfg = is_file($file) ? require $file : [];
        $crm = is_array($cfg['crm'] ?? null) ? $cfg['crm'] : [];
    }
    return $crm;
}

/** key → текст из CRM (или из кэша); [] — подставлять нечего. */
function cms_content(): array {
    $crm = cms_config();
    $url = rtrim((string)($crm['url'] ?? ''), '/');
    $key = (string)($crm['site_key'] ?? '');
    if ($url === '' || !preg_match('/^[a-f0-9]{32}$/', $key)) return [];

    $ttl = max(10, (int)($crm['cache_seconds'] ?? 60));
    $file = __DIR__ . '/../storage/cms-cache.json';
    $cache = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    $cached = is_array($cache['content'] ?? null) ? $cache['content'] : [];
    if (is_array($cache) && time() - (int)($cache['checked_at'] ?? 0) < $ttl) return $cached;

    // CRM спрашивает один запрос, остальные посетители в это время получают копию
    $lock = @fopen($file . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return $cached;
    $fresh = cms_fetch($url . '/api/public/sites/' . $key . '/content', (string)($crm['ca_file'] ?? ''));
    $state = [
        'checked_at' => time(),
        'ok'         => $fresh !== null,
        'loaded_at'  => $fresh !== null ? time() : ($cache['loaded_at'] ?? null),
        'content'    => $fresh ?? $cached,
    ];
    @file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);
    flock($lock, LOCK_UN);
    fclose($lock);
    return $state['content'];
}

function cms_fetch(string $url, string $caFile = ''): ?array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,   // посетитель не должен ждать CRM
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ];
    if ($caFile !== '') $opts[CURLOPT_CAINFO] = $caFile;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($body)) return null;
    $data = json_decode($body, true);
    if (!is_array($data['content'] ?? null)) return null;
    $content = [];
    foreach ($data['content'] as $k => $v) {
        if (is_string($k) && preg_match('/^[a-z][a-z0-9_]*$/', $k) && is_scalar($v)) $content[$k] = (string)$v;
    }
    return $content;
}

/** Подставляет тексты в HTML. Поля, которых нет в CRM, остаются как в HTML. */
function cms_apply(string $html, array $content): string {
    $html = preg_replace_callback(CMS_TEXT, static function (array $m) use ($content): string {
        [$all, $tag, $attrs, $key] = $m;
        if (!array_key_exists($key, $content)) return $all;
        $value = $content[$key];
        $text = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        if (preg_match('/\sdata-cms-multiline\b/', $attrs)) $text = nl2br($text, false);
        if (preg_match('/\sdata-cms-tel\b/', $attrs)) {
            $digits = preg_replace('/\D/', '', $value) ?? '';
            if (strlen($digits) === 11 && $digits[0] === '8') $digits = '7' . substr($digits, 1);
            if (strlen($digits) === 10) $digits = '7' . $digits;
            if (strlen($digits) >= 11) $attrs = preg_replace('/\shref="[^"]*"/', ' href="tel:+' . $digits . '"', $attrs, 1) ?? $attrs;
        }
        return '<' . $tag . $attrs . '>' . $text . '</' . $tag . '>';
    }, $html) ?? $html;

    return preg_replace_callback(CMS_HREF, static function (array $m) use ($content): string {
        [$all, $attrs, $key] = $m;
        $url = trim($content[$key] ?? '');
        if ($url === '' || !preg_match('~^(https?://|tg://|mailto:|tel:)~i', $url)) return $all;
        $href = ' href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"';
        $attrs = preg_match('/\shref="/', $attrs) ? (preg_replace('/\shref="[^"]*"/', $href, $attrs, 1) ?? $attrs) : $href . $attrs;
        return '<a' . $attrs . '>';
    }, $html) ?? $html;
}

/** Отдаёт страницу-шаблон с текстами из CRM. */
function cms_render(string $template): void {
    $html = (string)file_get_contents($template);
    $content = cms_content();
    header('Content-Type: text/html; charset=utf-8');
    echo $content ? cms_apply($html, $content) : $html;
}

/**
 * Поля всех страниц для CRM: [{key, label, default, multiline}] в порядке появления на страницах.
 * default — текст, который сейчас стоит в HTML.
 */
function cms_fields(array $templates): array {
    $fields = [];
    foreach ($templates as $template) {
        $html = (string)file_get_contents($template);
        $found = [];
        preg_match_all(CMS_TEXT, $html, $text, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($text as $m) {
            $default = html_entity_decode(strip_tags(preg_replace('~<br\s*/?>~i', "\n", $m[4][0]) ?? ''), ENT_QUOTES, 'UTF-8');
            $found[] = [$m[0][1], $m[3][0], $m[2][0], trim($default), (bool)preg_match('/\sdata-cms-multiline\b/', $m[2][0])];
        }
        preg_match_all(CMS_HREF, $html, $links, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($links as $m) {
            $href = preg_match('/\shref="([^"]*)"/', $m[1][0], $h) ? html_entity_decode($h[1], ENT_QUOTES, 'UTF-8') : '';
            $found[] = [$m[0][1], $m[2][0], $m[1][0], $href === '#' ? '' : $href, false];
        }
        usort($found, static fn($a, $b) => $a[0] <=> $b[0]);
        foreach ($found as [, $key, $attrs, $default, $multiline]) {
            if (isset($fields[$key])) continue;
            $label = preg_match('/\sdata-cms-label="([^"]*)"/', $attrs, $l) ? html_entity_decode($l[1], ENT_QUOTES, 'UTF-8') : $key;
            $fields[$key] = ['key' => $key, 'label' => $label, 'default' => $default, 'multiline' => $multiline];
        }
    }
    return array_values($fields);
}
