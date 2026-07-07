<?php
namespace Custom\SmartSchema;

class Output
{
    private HtmlAnalyzer $analyzer;
    private SchemaBuilder $builder;

    public function __construct()
    {
        $this->analyzer = new HtmlAnalyzer();
        $this->builder = new SchemaBuilder();
    }

    public function inject(string &$content): void
    {
        if (Options::get('output_enabled', 'Y') !== 'Y') { return; }
        if ($this->shouldSkip($content)) { return; }
        $currentUrl = $this->currentUrl();
        $analysis = $this->analyzer->analyzeHtml($content, $currentUrl);
        $kind = $this->resolveKind($content, $analysis);
        $schemas = [];
        $replaceTypes = [];

        foreach (Db::activeForKind('sitewide') as $proposal) {
            $decoded = json_decode((string)$proposal['SCHEMA_JSON'], true);
            if (is_array($decoded)) {
                $schemas[] = ['proposal' => $proposal, 'schema' => $decoded, 'kind' => 'sitewide'];
                if ((string)($proposal['REPLACE_EXISTING'] ?? 'N') === 'Y') { $replaceTypes[] = (string)$proposal['SCHEMA_TYPE']; }
            }
        }

        foreach (Db::activeForRequest($kind, $currentUrl) as $proposal) {
            $type = (string)$proposal['SCHEMA_TYPE'];
            $replace = (string)($proposal['REPLACE_EXISTING'] ?? 'N') === 'Y';
            if (!$replace && Options::get('avoid_duplicates', 'Y') === 'Y' && $this->hasEquivalentType($type, (array)($analysis['existing_schema_types'] ?? $analysis['json_ld_types']))) {
                continue;
            }
            $proposalKind = (string)($proposal['PAGE_KIND'] ?: $kind);
            $schema = $this->builder->buildForKind($proposalKind, $analysis, $type);
            if ($schema) {
                $schemas[] = ['proposal' => $proposal, 'schema' => $schema, 'kind' => $proposalKind];
                if ($replace) { $replaceTypes[] = $type; }
            }
        }

        if (!$schemas) { return; }
        if ($replaceTypes) {
            $replaceTypes = array_values(array_unique($replaceTypes));
            $this->removeExistingJsonLdByType($content, $replaceTypes);
            $this->disableExistingMicrodataRootsByType($content, $replaceTypes);
        }

        $block = '';
        $printed = [];
        foreach ($schemas as $entry) {
            $proposal = (array)$entry['proposal'];
            $schema = (array)$entry['schema'];
            $hash = md5(json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (isset($printed[$hash])) { continue; }
            $printed[$hash] = true;
            $block .= Security::jsonScript($schema, [
                'data-smart-schema-module' => Options::MODULE_ID,
                'data-smart-schema-proposal-id' => (string)($proposal['ID'] ?? ''),
                'data-smart-schema-kind' => (string)($proposal['PAGE_KIND'] ?? ''),
                'data-smart-schema-type' => (string)($proposal['SCHEMA_TYPE'] ?? ''),
                'data-smart-schema-replace' => (string)($proposal['REPLACE_EXISTING'] ?? 'N'),
            ]);
        }
        if ($block === '') { return; }
        if (stripos($content, '</head>') !== false) {
            $content = preg_replace('/<\/head>/i', $block . "\n</head>", $content, 1);
        } else {
            $content .= $block;
        }
    }

    private function removeExistingJsonLdByType(string &$content, array $types): void
    {
        if (!$types || stripos($content, 'application/ld+json') === false) { return; }
        $content = preg_replace_callback('/\s*<!--\s*Smart Schema Enterprise for Bitrix\s*-->\s*<script\b[^>]*type=["\']application\/ld\+json["\'][^>]*>.*?<\/script>\s*/isu', function ($m) use ($types) {
            return $this->scriptHasAnyType($m[0], $types) ? '' : $m[0];
        }, $content) ?? $content;
        $content = preg_replace_callback('/\s*<script\b[^>]*type=["\']application\/ld\+json["\'][^>]*>.*?<\/script>\s*/isu', function ($m) use ($types) {
            if (stripos($m[0], 'custom-smart-schema') !== false) { return $m[0]; }
            return $this->scriptHasAnyType($m[0], $types) ? "\n" : $m[0];
        }, $content) ?? $content;
    }


    private function disableExistingMicrodataRootsByType(string &$content, array $types): void
    {
        if (!$types || stripos($content, 'itemtype=') === false) { return; }
        $content = preg_replace_callback('/<([a-z0-9:-]+)\b([^>]*\bitemtype=["\']https?:\/\/schema\.org\/([^"\'#\s>]+)["\'][^>]*)>/isu', function ($m) use ($types) {
            $schemaType = (string)$m[3];
            $matches = false;
            foreach ($types as $type) {
                if ($this->hasEquivalentType((string)$type, [$schemaType])) { $matches = true; break; }
            }
            if (!$matches) { return $m[0]; }
            $attrs = (string)$m[2];
            $attrs = preg_replace('/\s+itemtype=["\'][^"\']*["\']/isu', '', $attrs) ?: $attrs;
            $attrs = preg_replace('/\s+itemscope(?:=["\'][^"\']*["\'])?/isu', '', $attrs) ?: $attrs;
            if (stripos($attrs, 'data-smart-schema-disabled-microdata') === false) {
                $attrs .= ' data-smart-schema-disabled-microdata="' . Security::e($schemaType) . '"';
            }
            return '<' . $m[1] . $attrs . '>';
        }, $content) ?? $content;
    }

    private function scriptHasAnyType(string $script, array $types): bool
    {
        if (!preg_match('/<script\b[^>]*>(.*?)<\/script>/isu', $script, $m)) { return false; }
        $decoded = json_decode(html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        if (!is_array($decoded)) { return false; }
        $found = $this->analyzer->schemaTypes([$decoded]);
        foreach ($types as $type) {
            if ($this->hasEquivalentType((string)$type, $found)) { return true; }
        }
        return false;
    }

    private function hasEquivalentType(string $type, array $existing): bool
    {
        $aliases = [
            'Product' => ['Product', 'ProductGroup'],
            'ProductGroup' => ['Product', 'ProductGroup'],
            'CollectionPage' => ['CollectionPage', 'ProductCollection'],
            'ProductCollection' => ['CollectionPage', 'ProductCollection'],
            'ItemList' => ['ItemList'],
            'BreadcrumbList' => ['BreadcrumbList'],
            'BlogPosting' => ['BlogPosting', 'Article', 'NewsArticle'],
            'Article' => ['BlogPosting', 'Article', 'NewsArticle'],
            'NewsArticle' => ['BlogPosting', 'Article', 'NewsArticle'],
            'Blog' => ['Blog'],
            'Organization' => ['Organization', 'LocalBusiness', 'Store'],
            'WebSite' => ['WebSite'],
        ];
        foreach (($aliases[$type] ?? [$type]) as $candidate) {
            if (in_array($candidate, $existing, true)) { return true; }
        }
        return false;
    }

    private function shouldSkip(string $content): bool
    {
        if (defined('ADMIN_SECTION') && ADMIN_SECTION === true) { return true; }
        if (defined('BX_CRONTAB') && BX_CRONTAB === true) { return true; }
        if (defined('PUBLIC_AJAX_MODE') && PUBLIC_AJAX_MODE === true) { return true; }
        if (stripos($content, '<html') === false && stripos($content, '</head>') === false) { return true; }
        if (stripos($content, 'application/json') !== false && stripos($content, '<html') === false) { return true; }
        return false;
    }

    private function currentUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . (string)($_SERVER['HTTP_HOST'] ?? '') . (string)($_SERVER['REQUEST_URI'] ?? '/');
    }

    private function resolveKind(string $content, array $analysis): string
    {
        $path = parse_url($this->currentUrl(), PHP_URL_PATH) ?: '';
        $text = mb_strtolower(Security::text($content, 20000));
        if (preg_match('~/(catalog|product|products|shop)/~i', $path) && (strpos($text, 'артикул') !== false || strpos($text, 'sku') !== false || !empty($analysis['product']['price']))) {
            return 'product_detail';
        }
        if (preg_match('~/(catalog|category|shop)/~i', $path)) { return 'product_category'; }
        if (preg_match('~/(blog|articles|stati|news)/~i', $path) && (!empty($analysis['dates']) || preg_match('/<article\b/i', $content))) { return 'news_detail'; }
        if (preg_match('~/(blog|articles|stati|news)/~i', $path)) { return 'blog_list'; }
        return 'webpage';
    }
}
