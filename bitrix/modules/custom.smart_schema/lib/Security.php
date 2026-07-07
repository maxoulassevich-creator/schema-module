<?php
namespace Custom\SmartSchema;

class Security
{
    public static function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, SITE_CHARSET ?: 'UTF-8');
    }

    public static function jsonScript(array $schema, array $attrs = []): string
    {
        $json = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json) || $json === '') { return ''; }
        $json = str_replace('</script', '<\/script', $json);
        $attrs = array_merge(['type' => 'application/ld+json', 'class' => 'custom-smart-schema'], $attrs);
        $htmlAttrs = '';
        foreach ($attrs as $key => $value) {
            $key = preg_replace('/[^a-zA-Z0-9_:-]/', '', (string)$key);
            if ($key === '') { continue; }
            $htmlAttrs .= ' ' . $key . '="' . self::e((string)$value) . '"';
        }
        return "\n<!-- Smart Schema Enterprise for Bitrix -->\n<script" . $htmlAttrs . ">\n" . $json . "\n</script>\n";
    }

    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') { return ''; }
        if (strpos($url, '//') === 0) { $url = 'https:' . $url; }
        if (!preg_match('~^https?://~i', $url)) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = (string)($_SERVER['HTTP_HOST'] ?? '');
            if ($host !== '') { $url = $scheme . '://' . $host . '/' . ltrim($url, '/'); }
        }
        return $url;
    }

    public static function absUrl(string $url, string $base = ''): string
    {
        $url = trim($url);
        if ($url === '') { return ''; }
        if (preg_match('~^https?://~i', $url)) { return $url; }
        if (strpos($url, '//') === 0) { return 'https:' . $url; }
        $base = self::normalizeUrl($base !== '' ? $base : '/');
        $parts = parse_url($base);
        if (empty($parts['scheme']) || empty($parts['host'])) { return $url; }
        if (strpos($url, '/') === 0) { return $parts['scheme'] . '://' . $parts['host'] . $url; }
        $path = isset($parts['path']) ? rtrim(dirname($parts['path']), '/') : '';
        return $parts['scheme'] . '://' . $parts['host'] . $path . '/' . $url;
    }

    public static function text(string $html, int $limit = 5000): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?: '';
        $text = trim($text);
        return mb_substr($text, 0, $limit);
    }
}
