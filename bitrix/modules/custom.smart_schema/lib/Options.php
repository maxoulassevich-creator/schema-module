<?php
namespace Custom\SmartSchema;

use Bitrix\Main\Config\Option;

class Options
{
    public const MODULE_ID = 'custom.smart_schema';

    public static function defaults(): array
    {
        return [
            'output_enabled' => 'Y',
            'avoid_duplicates' => 'Y',
            'yandex_product_audit' => 'Y',
            'product_brand_fallback' => 'organization',
            'scan_external_http_timeout' => '15',
            'site_name' => '',
            'organization_type' => 'Organization',
            'organization_name' => '',
            'organization_phone' => '',
            'organization_email' => '',
            'organization_logo' => '',
            'organization_same_as' => '',
            'search_url_template' => '/search/?q={search_term_string}',
            'product_sample_url' => '',
            'category_sample_url' => '',
            'blog_list_sample_url' => '',
            'news_detail_sample_url' => '',
            'manual_urls' => '',
            'catalog_iblock_id' => '',
            'news_iblock_id' => '',
            'blog_iblock_id' => '',
            'ai_enabled' => 'N',
            'openai_model' => 'gpt-5.5',
            'openai_api_key' => '',
            'ai_timeout' => '90',
            'proxy_enabled' => 'N',
            'proxy_host' => '',
            'proxy_port' => '',
            'proxy_login' => '',
            'proxy_password' => '',
            'include_ai_raw_response' => 'N',
        ];
    }

    public static function get(string $name, $default = null): string
    {
        $defaults = self::defaults();
        return (string)Option::get(self::MODULE_ID, $name, (string)($default ?? ($defaults[$name] ?? '')));
    }

    public static function all(): array
    {
        $values = self::defaults();
        foreach (array_keys($values) as $key) {
            $values[$key] = self::get($key, $values[$key]);
        }
        return $values;
    }

    public static function save(array $input): void
    {
        $defaults = self::defaults();
        foreach ($defaults as $key => $default) {
            if (in_array($key, ['output_enabled','avoid_duplicates','yandex_product_audit','ai_enabled','proxy_enabled','include_ai_raw_response'], true)) {
                $value = isset($input[$key]) && $input[$key] === 'Y' ? 'Y' : 'N';
            } else {
                $value = trim((string)($input[$key] ?? self::get($key, $default)));
            }
            if (in_array($key, ['openai_api_key', 'proxy_password'], true) && $value === '') {
                continue;
            }
            if ($key === 'proxy_port') {
                $port = (int)$value;
                $value = ($port >= 1 && $port <= 65535) ? (string)$port : '';
            }
            if (in_array($key, ['scan_external_http_timeout','ai_timeout'], true)) {
                $value = (string)max(5, min(180, (int)$value));
            }
            if ($key === 'organization_same_as') {
                $lines = preg_split('/[\r\n,]+/', $value) ?: [];
                $value = implode("\n", array_values(array_unique(array_filter(array_map('trim', $lines)))));
            }
            if ($key === 'product_brand_fallback' && !in_array($value, ['none','organization'], true)) {
                $value = 'organization';
            }
            if ($key === 'manual_urls') {
                $lines = preg_split('/[\r\n]+/', $value) ?: [];
                $clean = [];
                foreach ($lines as $line) {
                    $line = trim((string)$line);
                    if ($line === '') { continue; }
                    $clean[] = Security::normalizeUrl($line);
                }
                $value = implode("\n", array_values(array_unique(array_filter($clean))));
            }
            Option::set(self::MODULE_ID, $key, $value);
        }
    }

    public static function deleteAll(): void
    {
        Option::delete(self::MODULE_ID);
    }
}
