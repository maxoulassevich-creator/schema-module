<?php
namespace Custom\SmartSchema;

use Bitrix\Main\Web\HttpClient;

class HtmlAnalyzer
{
    public function fetchUrl(string $url): array
    {
        $url = Security::normalizeUrl($url);
        if ($url === '') {
            return ['ok' => false, 'status' => 0, 'error' => 'URL не задан', 'html' => '', 'url' => $url];
        }
        $client = new HttpClient(['socketTimeout' => (int)Options::get('scan_external_http_timeout', '15'), 'streamTimeout' => (int)Options::get('scan_external_http_timeout', '15')]);
        $client->setHeader('User-Agent', 'SmartSchemaBitrix/1.0 (+1C-Bitrix module)');
        $client->setHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
        $client->setHeader('Pragma', 'no-cache');
        $html = (string)$client->get($url);
        $status = (int)$client->getStatus();
        if ($status < 200 || $status >= 400 || $html === '') {
            return ['ok' => false, 'status' => $status, 'error' => 'Страница не загружена или вернула HTTP ' . $status, 'html' => $html, 'url' => $url];
        }
        return ['ok' => true, 'status' => $status, 'error' => '', 'html' => $html, 'url' => $url];
    }

    public function analyzeUrl(string $url): array
    {
        $fetch = $this->fetchUrl($url);
        $analysis = $fetch['ok'] ? $this->analyzeHtml((string)$fetch['html'], (string)$fetch['url']) : [];
        return ['fetch' => $fetch, 'analysis' => $analysis];
    }

    public function analyzeHtml(string $html, string $url = ''): array
    {
        $jsonLd = $this->extractJsonLd($html);
        $images = $this->extractImages($html, $url);
        $microdataTypeList = $this->extractMicrodataTypeList($html);
        $microdataTypes = array_values(array_unique($microdataTypeList));
        $jsonTypes = $this->schemaTypes($jsonLd);

        return [
            'url' => $url,
            'title' => $this->extractTitle($html),
            'meta_description' => $this->extractMeta($html, 'description'),
            'canonical' => $this->extractCanonical($html, $url),
            'og_title' => $this->extractMeta($html, 'og:title', true),
            'og_description' => $this->extractMeta($html, 'og:description', true),
            'og_image' => $this->extractMeta($html, 'og:image', true),
            'h1' => $this->extractHeadings($html, 'h1'),
            'h2' => $this->extractHeadings($html, 'h2'),
            'json_ld' => $jsonLd,
            'json_ld_types' => $jsonTypes,
            'microdata_types' => $microdataTypes,
            'microdata_type_counts' => $this->typeCounts($microdataTypeList),
            'existing_schema_types' => array_values(array_unique(array_merge($jsonTypes, $microdataTypes))),
            'microdata_count' => preg_match_all('/\bitemscope\b/i', $html),
            'breadcrumbs' => $this->extractBreadcrumbs($html, $url),
            'faq_pairs' => $this->extractFaq($html),
            'videos' => $this->extractVideos($html, $url),
            'images' => $images,
            'primary_image' => $this->primaryImage($images, $html, $url),
            'product' => $this->shouldExtractProductSignals($html, $url, $jsonLd) ? $this->extractProductSignals($html, $url, $jsonLd) : $this->emptyProductSignals($url),
            'microdata_product' => $this->shouldExtractProductSignals($html, $url, $jsonLd) ? $this->extractMicrodataProductSignals($html, $url) : $this->emptyMicrodataProductSignals(),
            'book' => $this->shouldExtractProductSignals($html, $url, $jsonLd) ? $this->bookSignals($html, $url, $jsonLd) : $this->emptyBookSignals(),
            'items' => $this->extractListItems($html, $url, $jsonLd),
            'reviews' => $this->extractReviews($html, $url),
            'dates' => $this->extractDates($html),
            'word_count' => $this->countWords(Security::text($html, 50000)),
            'html_length' => strlen($html),
        ];
    }

    private function extractTitle(string $html): string
    {
        return preg_match('/<title[^>]*>(.*?)<\/title>/isu', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    }

    private function extractMeta(string $html, string $name, bool $property = false): string
    {
        $attr = $property ? 'property' : 'name';
        if (preg_match('/<meta[^>]+'.$attr.'=["\']'.preg_quote($name, '/').'["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/isu', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+'.$attr.'=["\']'.preg_quote($name, '/').'["\'][^>]*>/isu', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return '';
    }

    private function extractCanonical(string $html, string $url): string
    {
        if (preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/isu', $html, $m)) {
            return Security::absUrl(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $url);
        }
        return Security::normalizeUrl($url);
    }

    private function extractHeadings(string $html, string $tag): array
    {
        $items = [];
        if (preg_match_all('/<'.$tag.'\b[^>]*>(.*?)<\/'.$tag.'>/isu', $html, $m)) {
            foreach ($m[1] as $text) {
                $clean = $this->cleanText($text);
                if ($clean !== '') { $items[] = mb_substr($clean, 0, 300); }
            }
        }
        return array_slice(array_values(array_unique($items)), 0, 20);
    }

    public function extractJsonLd(string $html): array
    {
        $schemas = [];
        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/isu', $html, $matches)) {
            foreach ($matches[1] as $raw) {
                $raw = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($raw === '') { continue; }
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $schemas[] = $decoded;
                } else {
                    $schemas[] = ['_invalid_json' => true, '_error' => json_last_error_msg(), '_raw' => mb_substr($raw, 0, 1000)];
                }
            }
        }
        return $schemas;
    }

    public function schemaTypes(array $schemas): array
    {
        $types = [];
        foreach ($schemas as $schema) {
            $types = array_merge($types, $this->flattenTypes($schema));
        }
        return array_values(array_unique(array_filter($types)));
    }

    private function flattenTypes($node): array
    {
        if (!is_array($node)) { return []; }
        $types = [];
        if (isset($node['@type'])) {
            foreach ((array)$node['@type'] as $t) { $types[] = (string)$t; }
        }
        foreach (['@graph','mainEntity','itemListElement','hasVariant','offers','blogPost','review','aggregateRating','author','publisher'] as $key) {
            if (isset($node[$key])) {
                $children = is_array($node[$key]) && array_keys($node[$key]) === range(0, count($node[$key])-1) ? $node[$key] : [$node[$key]];
                foreach ($children as $child) { $types = array_merge($types, $this->flattenTypes($child)); }
            }
        }
        return $types;
    }

    private function extractMicrodataTypeList(string $html): array
    {
        $types = [];
        if (preg_match_all('/itemtype=["\']https?:\/\/schema\.org\/([^"\'#\s>]+)["\']/isu', $html, $m)) {
            foreach ($m[1] as $type) {
                $type = trim($type);
                if ($type !== '') { $types[] = $type; }
            }
        }
        return $types;
    }

    private function typeCounts(array $types): array
    {
        $counts = [];
        foreach ($types as $type) {
            $type = (string)$type;
            if ($type === '') { continue; }
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        return $counts;
    }

    private function extractBreadcrumbs(string $html, string $url): array
    {
        $dom = $this->dom($html);
        if ($dom) {
            $xp = new \DOMXPath($dom);
            $blocks = $xp->query('//*[contains(translate(@itemtype,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"), "schema.org/breadcrumblist")]');
            if ($blocks && $blocks->length) {
                foreach ($blocks as $block) {
                    $items = [];
                    foreach ($block->childNodes as $node) {
                        if (!$node instanceof \DOMElement) { continue; }
                        $class = ' ' . $node->getAttribute('class') . ' ';
                        if (strpos($class, ' breadcrumbs__item ') === false || $node->getAttribute('itemprop') !== 'itemListElement') { continue; }
                        $nameNode = $xp->query('.//*[@itemprop="name"]', $node)->item(0);
                        $name = $nameNode ? $this->normalizeItemName($nameNode->textContent) : '';
                        $urlNode = $xp->query('.//a[@itemprop="item"]/@href | .//link[@itemprop="item"]/@href | .//*[@itemprop="item"]/@href', $node)->item(0);
                        $href = $urlNode ? (string)$urlNode->nodeValue : '';
                        $posNode = $xp->query('.//*[@itemprop="position"]/@content', $node)->item(0);
                        $pos = $posNode ? (int)$posNode->nodeValue : 0;
                        if ($name !== '' && $href !== '') {
                            $items[] = ['name' => $name, 'url' => Security::absUrl($href, $url), 'position' => $pos ?: count($items) + 1];
                        }
                    }
                    if ($items) {
                        usort($items, static fn($a, $b) => ($a['position'] <=> $b['position']));
                        $out = [];
                        $seen = [];
                        foreach ($items as $item) {
                            $key = mb_strtolower($item['name'] . '|' . $item['url']);
                            if (isset($seen[$key])) { continue; }
                            $seen[$key] = true;
                            unset($item['position']);
                            $out[] = $item;
                        }
                        return array_slice($out, 0, 15);
                    }
                }
            }
        }

        $regexCrumbs = $this->breadcrumbsFromRegex($html, $url);
        if ($regexCrumbs) { return $regexCrumbs; }
        return [];
    }

    private function breadcrumbsFromRegex(string $html, string $url): array
    {
        $crumbs = [];
        if (preg_match_all('/<(div|span)\b[^>]*class=["\'][^"\']*breadcrumbs__item[^"\']*["\'][^>]*itemprop=["\']itemListElement["\'][^>]*>(.*?)<\/\1>/isu', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $block = $row[2];
                $name = '';
                $href = '';
                $pos = 0;
                if (preg_match('/itemprop=["\']name["\'][^>]*>([^<]+)/isu', $block, $nm)) {
                    $name = $this->normalizeItemName($nm[1]);
                }
                if (preg_match('/<a[^>]+itemprop=["\']item["\'][^>]+href=["\']([^"\']+)["\']/isu', $block, $hm) || preg_match('/<a[^>]+href=["\']([^"\']+)["\'][^>]+itemprop=["\']item["\']/isu', $block, $hm)) {
                    $href = html_entity_decode($hm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                } elseif (preg_match('/<link[^>]+href=["\']([^"\']+)["\'][^>]+itemprop=["\']item["\']/isu', $block, $hm) || preg_match('/<link[^>]+itemprop=["\']item["\'][^>]+href=["\']([^"\']+)["\']/isu', $block, $hm)) {
                    $href = html_entity_decode($hm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                if (preg_match('/itemprop=["\']position["\'][^>]+content=["\'](\d+)["\']/isu', $block, $pm) || preg_match('/content=["\'](\d+)["\'][^>]+itemprop=["\']position["\']/isu', $block, $pm)) {
                    $pos = (int)$pm[1];
                }
                if ($name !== '' && $href !== '') {
                    $crumbs[] = ['name' => $name, 'url' => Security::absUrl($href, $url), 'position' => $pos ?: count($crumbs) + 1];
                }
            }
        }
        if (!$crumbs) { return []; }
        usort($crumbs, static fn($a, $b) => ($a['position'] <=> $b['position']));
        $out = [];
        $seen = [];
        foreach ($crumbs as $crumb) {
            $key = mb_strtolower($crumb['name'] . '|' . $crumb['url']);
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            unset($crumb['position']);
            $out[] = $crumb;
        }
        return array_slice($out, 0, 15);
    }

    private function extractFaq(string $html): array
    {
        $pairs = array_merge($this->faqFromAccordion($html), $this->faqFromHeadings($html));
        $out = [];
        $seen = [];
        foreach ($pairs as $pair) {
            $key = mb_strtolower($pair['question']);
            if ($key === '' || isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $out[] = $pair;
        }
        return array_slice($out, 0, 12);
    }

    private function faqFromHeadings(string $html): array
    {
        $pairs = [];
        if (preg_match_all('/<(h[2-6]|summary)[^>]*>([^<]{5,200}\?)<\/\1>\s*<(p|div)[^>]*>(.*?)<\/\3>/isu', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $q = $this->cleanText($row[2]);
                $a = $this->cleanText($row[4]);
                if ($q !== '' && $a !== '' && mb_strlen($a) > 20) { $pairs[] = ['question' => $q, 'answer' => mb_substr($a, 0, 1000)]; }
            }
        }
        return $pairs;
    }

    // Аспро Премьер / Битрикс выводят FAQ как Bootstrap-аккордеон: заголовок-вопрос с
    // data-toggle="collapse" ссылается на скрытый блок с ответом по id. Разбираем такую пару.
    private function faqFromAccordion(string $html): array
    {
        $dom = $this->dom($html);
        if (!$dom) { return []; }
        $xp = new \DOMXPath($dom);
        $toggles = $xp->query('//*[@data-toggle="collapse" or @data-bs-toggle="collapse" or @aria-controls or (@href and starts-with(@href, "#")) or (@data-target and starts-with(@data-target, "#")) or (@data-bs-target and starts-with(@data-bs-target, "#"))]');
        if (!$toggles || !$toggles->length) { return []; }
        $pairs = [];
        foreach ($toggles as $toggle) {
            if (!$toggle instanceof \DOMElement) { continue; }
            $question = $this->cleanText($toggle->textContent);
            if ($question === '' || mb_strlen($question) < 6 || mb_strlen($question) > 250 || mb_substr($question, -1) !== '?') { continue; }
            $targetId = $this->collapseTargetId($toggle);
            if ($targetId === '') { continue; }
            $target = $xp->query('//*[@id=' . $this->xpathLiteral($targetId) . ']')->item(0);
            if (!$target instanceof \DOMElement) { continue; }
            $answer = $this->cleanText($target->textContent);
            // На случай, если триггер вложен в раскрываемый блок — убираем повтор вопроса из ответа.
            if ($answer !== '' && mb_stripos($answer, $question) === 0) { $answer = trim(mb_substr($answer, mb_strlen($question))); }
            if ($answer === '' || mb_strlen($answer) <= 20) { continue; }
            $pairs[] = ['question' => $question, 'answer' => mb_substr($answer, 0, 1000)];
            if (count($pairs) >= 12) { break; }
        }
        return $pairs;
    }

    private function collapseTargetId(\DOMElement $toggle): string
    {
        foreach (['data-target','data-bs-target','href'] as $attr) {
            $value = trim($toggle->getAttribute($attr));
            $hash = strpos($value, '#');
            if ($value !== '' && $hash !== false) {
                $id = substr($value, $hash + 1);
                if ($id !== '') { return $id; }
            }
        }
        $aria = trim($toggle->getAttribute('aria-controls'));
        if ($aria !== '') { return preg_split('/\s+/', $aria)[0]; }
        return '';
    }

    private function xpathLiteral(string $value): string
    {
        if (strpos($value, "'") === false) { return "'" . $value . "'"; }
        if (strpos($value, '"') === false) { return '"' . $value . '"'; }
        return "concat('" . str_replace("'", "',\"'\",'", $value) . "')";
    }

    private function extractVideos(string $html, string $url): array
    {
        $videos = [];
        if (preg_match_all('/<iframe[^>]+src=["\']([^"\']*(youtube\.com|youtu\.be|vimeo\.com)[^"\']*)["\'][^>]*>/isu', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) { $videos[] = ['embedUrl' => Security::absUrl(html_entity_decode($row[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $url)]; }
        }
        if (preg_match_all('/<video[^>]+src=["\']([^"\']+)["\'][^>]*>/isu', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) { $videos[] = ['contentUrl' => Security::absUrl(html_entity_decode($row[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $url)]; }
        }
        return array_slice($videos, 0, 10);
    }

    private function extractImages(string $html, string $url): array
    {
        $images = [];
        $dom = $this->dom($html);
        if ($dom) {
            foreach ($dom->getElementsByTagName('img') as $img) {
                $srcs = [];
                foreach (['data-src','data-lazy-src','data-original','src'] as $attr) {
                    if ($img->hasAttribute($attr)) { $srcs[] = $img->getAttribute($attr); }
                }
                foreach ($srcs as $src) {
                    $src = html_entity_decode(trim($src), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($this->isUsableImage($src)) { $images[] = Security::absUrl($src, $url); break; }
                }
            }
        } elseif (preg_match_all('/<img\b[^>]*>/isu', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                foreach (['data-src','data-lazy-src','data-original','src'] as $attr) {
                    if (preg_match('/\b'.$attr.'=["\']([^"\']+)["\']/isu', $tag, $m)) {
                        $src = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        if ($this->isUsableImage($src)) { $images[] = Security::absUrl($src, $url); break; }
                    }
                }
            }
        }
        return array_values(array_unique(array_slice($images, 0, 30)));
    }

    private function primaryImage(array $images, string $html, string $url): string
    {
        foreach ($images as $image) {
            if (!$this->isLogoOrCounter($image)) { return $image; }
        }
        $og = $this->extractMeta($html, 'og:image', true);
        return $og ? Security::absUrl($og, $url) : ($images[0] ?? '');
    }


    private function shouldExtractProductSignals(string $html, string $url, array $jsonLd): bool
    {
        foreach ($jsonLd as $schema) {
            if (in_array('Product', $this->flattenTypes($schema), true) || in_array('ProductGroup', $this->flattenTypes($schema), true)) {
                return true;
            }
        }
        if (preg_match('/itemtype=["\']https?:\/\/schema\.org\/(Product|ProductGroup)["\']/isu', $html)) {
            return true;
        }
        $path = parse_url(Security::normalizeUrl($url), PHP_URL_PATH) ?: '';
        if (!preg_match('~/(catalog|product|products|shop)/~i', $path)) {
            return false;
        }
        $text = mb_strtolower(Security::text($html, 30000));
        return strpos($text, 'артикул') !== false || strpos($text, 'sku') !== false || strpos($text, 'isbn') !== false;
    }

    private function emptyProductSignals(string $url): array
    {
        return [
            'name' => '',
            'sku' => '',
            'brand' => '',
            'price' => '',
            'priceCurrency' => '',
            'availability' => '',
            'url' => Security::normalizeUrl($url),
            'image' => '',
        ];
    }

    private function emptyBookSignals(): array
    {
        return ['author' => '', 'isbn' => '', 'inLanguage' => '', 'bookFormat' => '', 'numberOfPages' => '', 'publisher' => '', 'datePublished' => '', 'typicalAgeRange' => ''];
    }

    // Книжные признаки товара для мульти-типа Product+Book. Только реальные данные страницы:
    // существующий JSON-LD Book, свойства-пары (additionalProperty из Product JSON-LD и карточка
    // Аспро js-prop) и текст. Ничего не выдумываем.
    private function bookSignals(string $html, string $url, array $jsonLd): array
    {
        $book = $this->emptyBookSignals();
        foreach ($jsonLd as $schema) {
            foreach ($this->findNodesByType($schema, ['Book']) as $node) {
                if ($book['author'] === '') { $book['author'] = $this->personName($node['author'] ?? ''); }
                if ($book['isbn'] === '') { $book['isbn'] = $this->cleanIsbn((string)($node['isbn'] ?? '')); }
                if ($book['inLanguage'] === '' && !is_array($node['inLanguage'] ?? null)) { $book['inLanguage'] = $this->languageCode((string)($node['inLanguage'] ?? '')); }
                if ($book['numberOfPages'] === '' && !empty($node['numberOfPages'])) { $book['numberOfPages'] = preg_replace('/\D+/', '', (string)$node['numberOfPages']) ?: ''; }
                if ($book['bookFormat'] === '') { $book['bookFormat'] = $this->bookFormatCode((string)($node['bookFormat'] ?? '')); }
            }
            // additionalProperty у существующего Product/ProductGroup — самый надёжный источник в Аспро.
            foreach ($this->findNodesByType($schema, ['Product', 'ProductGroup']) as $node) {
                foreach ((array)($node['additionalProperty'] ?? []) as $prop) {
                    if (is_array($prop)) { $this->applyBookProperty($book, (string)($prop['name'] ?? ''), (string)($prop['value'] ?? '')); }
                }
            }
        }
        foreach ($this->extractProductProperties($html) as $title => $value) {
            $this->applyBookProperty($book, (string)$title, (string)$value);
        }
        if ($book['isbn'] === '') {
            $plain = Security::text($html, 60000);
            if (preg_match('/ISBN[\s:]*([0-9][0-9\-\x{2013}\s]{8,18}[0-9Xx])/u', $plain, $m)) { $book['isbn'] = $this->cleanIsbn($m[1]); }
        }
        return $book;
    }

    private function applyBookProperty(array &$book, string $title, string $value): void
    {
        $t = mb_strtolower(trim($title));
        $v = $this->cleanProductValue($value);
        if ($t === '' || $v === '') { return; }
        if ($book['author'] === '' && (mb_strpos($t, 'автор') !== false || mb_strpos($t, 'author') !== false)) { $book['author'] = $v; }
        elseif ($book['publisher'] === '' && (mb_strpos($t, 'издательств') !== false || mb_strpos($t, 'издатель') !== false || mb_strpos($t, 'publisher') !== false)) { $book['publisher'] = $v; }
        elseif ($book['inLanguage'] === '' && (mb_strpos($t, 'язык') !== false || mb_strpos($t, 'language') !== false)) { $book['inLanguage'] = $this->languageCode($v); }
        elseif ($book['numberOfPages'] === '' && (mb_strpos($t, 'страниц') !== false || mb_strpos($t, 'кол-во стр') !== false || mb_strpos($t, 'объем') !== false || mb_strpos($t, 'объём') !== false)) { if (preg_match('/\d+/', $v, $m)) { $book['numberOfPages'] = $m[0]; } }
        elseif ($book['bookFormat'] === '' && (mb_strpos($t, 'формат') !== false || mb_strpos($t, 'переплёт') !== false || mb_strpos($t, 'переплет') !== false || mb_strpos($t, 'обложк') !== false || mb_strpos($t, 'тип издания') !== false)) { $book['bookFormat'] = $this->bookFormatCode($v); }
        elseif ($book['datePublished'] === '' && (mb_strpos($t, 'год издания') !== false || mb_strpos($t, 'год публикации') !== false)) { if (preg_match('/(19|20)\d{2}/', $v, $m)) { $book['datePublished'] = $m[0]; } }
        elseif ($book['typicalAgeRange'] === '' && (mb_strpos($t, 'рекомендуемый возраст') !== false || mb_strpos($t, 'рекомендованный возраст') !== false)) { $book['typicalAgeRange'] = mb_substr($v, 0, 20); }
        elseif ($book['isbn'] === '' && mb_strpos($t, 'isbn') !== false) { $book['isbn'] = $this->cleanIsbn($v); }
    }

    private function personName($author): string
    {
        if (is_array($author)) {
            if (isset($author[0])) { return $this->personName($author[0]); }
            return $this->normalizeItemName((string)($author['name'] ?? ''));
        }
        return $this->normalizeItemName((string)$author);
    }

    private function cleanIsbn(string $value): string
    {
        $value = strtoupper(preg_replace('/[^0-9Xx]/', '', $value) ?? '');
        return (strlen($value) === 10 || strlen($value) === 13) ? $value : '';
    }

    private function languageCode(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') { return ''; }
        $map = [
            'английск' => 'en', 'english' => 'en', 'русск' => 'ru', 'немецк' => 'de', 'deutsch' => 'de',
            'французск' => 'fr', 'испанск' => 'es', 'итальянск' => 'it', 'китайск' => 'zh', 'японск' => 'ja',
            'корейск' => 'ko', 'арабск' => 'ar', 'португальск' => 'pt', 'турецк' => 'tr', 'польск' => 'pl',
        ];
        foreach ($map as $name => $code) { if (mb_strpos($value, $name) !== false) { return $code; } }
        return preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $value) ? $value : '';
    }

    private function bookFormatCode(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') { return ''; }
        if (preg_match('~^https?://schema\.org/(\w+)~i', $value, $m)) { return 'https://schema.org/' . $m[1]; }
        if (preg_match('~(тв[её]рд|hardcover|hardback|тв\.?\s*пер)~u', $value)) { return 'https://schema.org/Hardcover'; }
        if (preg_match('~(мягк|обл\.|paperback|softcover|брошюр)~u', $value)) { return 'https://schema.org/Paperback'; }
        if (preg_match('~(электрон|e-?book|pdf|epub|цифров)~u', $value)) { return 'https://schema.org/EBook'; }
        if (preg_match('~(аудио|audio)~u', $value)) { return 'https://schema.org/AudiobookFormat'; }
        return '';
    }

    private function emptyMicrodataProductSignals(): array
    {
        return [
            'found' => false,
            'type' => '',
            'name' => '',
            'description' => '',
            'brand' => '',
            'image' => '',
            'offers' => [],
            'missing_yandex_fields' => [],
        ];
    }

    private function extractReviews(string $html, string $url): array
    {
        $dom = $this->dom($html);
        if (!$dom) { return []; }
        $xp = new \DOMXPath($dom);
        $reviewPredicate = 'contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"), "review") or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZАБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ","abcdefghijklmnopqrstuvwxyzабвгдеёжзийклмнопрстуфхцчшщъыьэюя"), "отзыв")';
        $nodes = $xp->query('//*[' . $reviewPredicate . ']');
        $reviews = [];
        if ($nodes) {
            foreach ($nodes as $node) {
                if (!$node instanceof \DOMElement) { continue; }
                // Пропускаем контейнеры-обёртки: если внутри есть другой review-элемент, значит это
                // список/блок отзывов, а сам отзыв — вложенный элемент (его и возьмём отдельно).
                if ($xp->query('.//*[' . $reviewPredicate . ']', $node)->length > 0) { continue; }
                $text = $this->normalizeItemName($node->textContent);
                if (mb_strlen($text) < 40) { continue; }
                if (preg_match('/(все отзывы|оставить отзыв|отзывы о нас)/iu', $text)) { continue; }
                $author = '';
                $authorNode = $xp->query('.//*[@itemprop="author"]', $node)->item(0)
                    ?: $xp->query('.//*[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"), "author") or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZАБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ","abcdefghijklmnopqrstuvwxyzабвгдеёжзийклмнопрстуфхцчшщъыьэюя"), "автор") or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZАБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ","abcdefghijklmnopqrstuvwxyzабвгдеёжзийклмнопрстуфхцчшщъыьэюя"), "имя")]', $node)->item(0);
                if ($authorNode) { $author = $this->normalizeItemName($authorNode->textContent); }
                $rating = '';
                if (preg_match('/([1-5](?:[\.,]\d)?)\s*(?:\/\s*5|из\s*5|★|зв)/iu', $text, $m)) { $rating = str_replace(',', '.', $m[1]); }
                // Отзыв должен иметь признак отзыва: автора, оценку или явную разметку review.
                // Иначе легко захватить обычный текстовый блок (например, ответ из FAQ).
                $isReview = $author !== '' || $rating !== '';
                if (!$isReview) {
                    $itemtype = mb_strtolower($node->getAttribute('itemtype'));
                    $isReview = strpos($itemtype, 'schema.org/review') !== false
                        || $xp->query('.//*[@itemprop="reviewBody" or @itemprop="author" or @itemprop="reviewRating" or @itemprop="ratingValue"]', $node)->length > 0;
                }
                if (!$isReview) { continue; }
                $reviews[] = [
                    'reviewBody' => mb_substr($text, 0, 1000),
                    'author' => mb_substr($author, 0, 100),
                    'ratingValue' => $rating,
                ];
                if (count($reviews) >= 10) { break; }
            }
        }
        return array_values(array_unique($reviews, SORT_REGULAR));
    }

    private function extractProductSignals(string $html, string $url, array $jsonLd = []): array
    {
        $fromDom = $this->productFromDom($html, $url);
        $fromJson = $this->productFromJsonLd($jsonLd, $url);
        $product = $fromJson ?: $fromDom;
        foreach ($fromDom as $key => $value) {
            if (($product[$key] ?? '') === '' && $value !== '') { $product[$key] = $value; }
        }
        // Brand оставляем как есть (реальный бренд со страницы или пусто). Fallback на название
        // организации применяется в SchemaBuilder::product(), где известно, книга это или нет:
        // магазин не должен становиться брендом книги.
        return $product;
    }

    private function productFromDom(string $html, string $url): array
    {
        $plain = Security::text($html, 70000);
        $props = $this->extractProductProperties($html);
        $price = '';
        $currency = '';
        if (preg_match('/<meta[^>]+itemprop=["\']price["\'][^>]+content=["\']([^"\']+)["\']/isu', $html, $m) || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+itemprop=["\']price["\']/isu', $html, $m)) {
            $price = str_replace(',', '.', preg_replace('/\s+/u', '', html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $currency = 'RUB';
        }
        if ($price === '' && (preg_match('/(?:price|цена|стоимость)[^0-9]{0,40}([0-9][0-9\s.,]*)\s*(₽|руб\.?|RUB)/iu', $plain, $m) || preg_match('/([0-9][0-9\s.,]*)\s*(₽|руб\.?|RUB)/iu', $plain, $m))) {
            $price = str_replace(',', '.', preg_replace('/\s+/u', '', $m[1]));
            $currency = 'RUB';
        }
        if (preg_match('/<meta[^>]+itemprop=["\']priceCurrency["\'][^>]+content=["\']([^"\']+)["\']/isu', $html, $m) || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+itemprop=["\']priceCurrency["\']/isu', $html, $m)) {
            $currency = strtoupper(trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }
        $sku = '';
        foreach (['Артикул','SKU','ISBN','Код товара'] as $key) {
            if (!empty($props[$key])) { $sku = $this->cleanProductValue($props[$key]); break; }
        }
        if ($sku === '' && preg_match('/(?:арт\.?|артикул|sku|isbn)\s*[:№#.-]?\s*([A-Za-zА-Яа-я0-9._\-\/]{2,80})/iu', $plain, $m)) { $sku = preg_match('/\d/', $m[1]) ? $this->cleanProductValue($m[1]) : ''; }
        $brand = '';
        foreach (['Бренд','Brand','Производитель','Издательство','Издатель','Publisher'] as $key) {
            if (!empty($props[$key])) { $brand = $this->cleanProductValue($props[$key]); break; }
        }
        if ($brand === '' && preg_match('/(?:бренд|brand|производитель|издательство|издатель|publisher)\s*[:：]\s*([A-Za-zА-Яа-я0-9 ._\-]{2,80})/iu', $plain, $m)) { $brand = $this->cleanProductValue($m[1]); }
        $availability = '';
        if (preg_match('/itemprop=["\']availability["\'][^>]+(?:href|content)=["\']([^"\']+)["\']/isu', $html, $m) || preg_match('/(?:href|content)=["\']([^"\']+)["\'][^>]+itemprop=["\']availability["\']/isu', $html, $m)) {
            $availability = $this->normalizeAvailability(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if ($availability === '' && preg_match('/\b(в наличии|есть в наличии)\b/iu', $plain)) { $availability = 'https://schema.org/InStock'; }
        elseif ($availability === '' && preg_match('/\b(нет в наличии|под заказ|ожидается)\b/iu', $plain)) { $availability = 'https://schema.org/OutOfStock'; }
        return [
            'name' => ($this->extractHeadings($html, 'h1')[0] ?? $this->extractTitle($html)),
            'sku' => $sku,
            'brand' => $brand,
            'price' => $price,
            'priceCurrency' => $currency,
            'availability' => $availability,
            'url' => $this->extractCanonical($html, $url),
            'image' => $this->extractMeta($html, 'og:image', true) ?: ($this->primaryImage($this->extractImages($html, $url), $html, $url)),
        ];
    }

    private function extractProductProperties(string $html): array
    {
        $props = [];
        $dom = $this->dom($html);
        if (!$dom) { return $props; }
        $xp = new \DOMXPath($dom);
        $nodes = $xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " properties__item ")]');
        foreach ($nodes as $node) {
            $titleNode = $xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " js-prop-title ")]', $node)->item(0);
            $valueNode = $xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " js-prop-value ")]', $node)->item(0);
            if (!$titleNode || !$valueNode) { continue; }
            $title = $this->normalizeItemName($titleNode->textContent);
            $value = $this->normalizeItemName($valueNode->textContent);
            if ($title !== '' && $value !== '') { $props[$title] = $value; }
        }
        return $props;
    }

    private function extractMicrodataProductSignals(string $html, string $url): array
    {
        $empty = ['found' => false, 'type' => '', 'name' => '', 'description' => '', 'brand' => '', 'image' => '', 'offers' => [], 'missing_yandex_fields' => []];
        $dom = $this->dom($html);
        if (!$dom) { return $this->extractMicrodataProductSignalsRegex($html); }
        $xp = new \DOMXPath($dom);
        $nodes = $xp->query('//*[contains(translate(@itemtype,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"), "schema.org/product")]');
        if (!$nodes || !$nodes->length) { return $empty; }
        $node = $nodes->item(0);
        $type = preg_match("~schema\\.org/([^\"'\\s>]+)~i", $node->getAttribute('itemtype'), $m) ? $m[1] : 'Product';
        $getProp = function (string $prop) use ($xp, $node): string {
            $attr = $xp->query('.//*[@itemprop="'.$prop.'"]/@content | .//*[@itemprop="'.$prop.'"]/@href | .//*[@itemprop="'.$prop.'"]/@src', $node)->item(0);
            if ($attr) { return trim(html_entity_decode((string)$attr->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8')); }
            $el = $xp->query('.//*[@itemprop="'.$prop.'"]', $node)->item(0);
            return $el ? $this->normalizeItemName($el->textContent) : '';
        };
        $brand = $getProp('brand');
        $offers = [];
        $offerNodes = $xp->query('.//*[@itemprop="offers" and contains(translate(@itemtype,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"), "schema.org/offer")]', $node);
        foreach ($offerNodes as $offerNode) {
            $offerGet = function (string $prop) use ($xp, $offerNode): string {
                $attr = $xp->query('.//*[@itemprop="'.$prop.'"]/@content | .//*[@itemprop="'.$prop.'"]/@href | .//*[@itemprop="'.$prop.'"]/@src', $offerNode)->item(0);
                if ($attr) { return trim(html_entity_decode((string)$attr->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8')); }
                $el = $xp->query('.//*[@itemprop="'.$prop.'"]', $offerNode)->item(0);
                return $el ? $this->normalizeItemName($el->textContent) : '';
            };
            $offers[] = ['type' => preg_match('~AggregateOffer~i', $offerNode->getAttribute('itemtype')) ? 'AggregateOffer' : 'Offer', 'price' => $offerGet('price'), 'lowPrice' => $offerGet('lowPrice'), 'priceCurrency' => $offerGet('priceCurrency'), 'availability' => $offerGet('availability')];
        }
        $missing = [];
        $name = $getProp('name');
        $image = $getProp('image');
        if ($name === '') { $missing[] = 'Product.name'; }
        if ($brand === '') { $missing[] = 'Product.brand'; }
        if ($image === '') { $missing[] = 'Product.image'; }
        if (!$offers) { $missing[] = 'Product.offers'; }
        foreach ($offers as $offer) {
            if (($offer['price'] ?? '') === '' && ($offer['lowPrice'] ?? '') === '') { $missing[] = 'Offer.price_or_lowPrice'; }
            if (($offer['priceCurrency'] ?? '') === '') { $missing[] = 'Offer.priceCurrency'; }
            if (($offer['availability'] ?? '') === '') { $missing[] = 'Offer.availability'; }
        }
        return ['found' => true, 'type' => $type, 'name' => $name, 'description' => $getProp('description'), 'brand' => $brand, 'image' => $image, 'offers' => $offers, 'missing_yandex_fields' => array_values(array_unique($missing))];
    }

    private function extractMicrodataProductSignalsRegex(string $html): array
    {
        $empty = ['found' => false, 'type' => '', 'name' => '', 'description' => '', 'brand' => '', 'image' => '', 'offers' => [], 'missing_yandex_fields' => []];
        if (!preg_match('/itemtype=["\']https?:\/\/schema\.org\/(Product|ProductGroup)["\']/isu', $html, $typeMatch)) { return $empty; }
        $get = static function (string $prop) use ($html): string {
            if (preg_match('/<meta[^>]+itemprop=["\']'.preg_quote($prop, '/').'["\'][^>]+content=["\']([^"\']+)["\']/isu', $html, $m) || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+itemprop=["\']'.preg_quote($prop, '/').'["\']/isu', $html, $m)) {
                return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            if (preg_match('/<(?:img|source)[^>]+itemprop=["\']'.preg_quote($prop, '/').'["\'][^>]+(?:src|data-src|content)=["\']([^"\']+)["\']/isu', $html, $m) || preg_match('/<(?:img|source)[^>]+(?:src|data-src|content)=["\']([^"\']+)["\'][^>]+itemprop=["\']'.preg_quote($prop, '/').'["\']/isu', $html, $m)) {
                return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            if (preg_match('/<[^>]+itemprop=["\']'.preg_quote($prop, '/').'["\'][^>]*>(.*?)<\/[^>]+>/isu', $html, $m)) {
                return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: '');
            }
            return '';
        };
        $offerFound = preg_match('/itemprop=["\']offers["\'][^>]+itemtype=["\']https?:\/\/schema\.org\/(Offer|AggregateOffer)["\']/isu', $html) || preg_match('/itemtype=["\']https?:\/\/schema\.org\/(Offer|AggregateOffer)["\'][^>]+itemprop=["\']offers["\']/isu', $html);
        $offer = ['type' => 'Offer', 'price' => $get('price'), 'lowPrice' => $get('lowPrice'), 'priceCurrency' => $get('priceCurrency'), 'availability' => $get('availability')];
        $offers = $offerFound ? [$offer] : [];
        $missing = [];
        $name = $get('name');
        $brand = $get('brand');
        $image = $get('image');
        if ($name === '') { $missing[] = 'Product.name'; }
        if ($brand === '') { $missing[] = 'Product.brand'; }
        if ($image === '') { $missing[] = 'Product.image'; }
        if (!$offers) { $missing[] = 'Product.offers'; }
        foreach ($offers as $of) {
            if (($of['price'] ?? '') === '' && ($of['lowPrice'] ?? '') === '') { $missing[] = 'Offer.price_or_lowPrice'; }
            if (($of['priceCurrency'] ?? '') === '') { $missing[] = 'Offer.priceCurrency'; }
            if (($of['availability'] ?? '') === '') { $missing[] = 'Offer.availability'; }
        }
        return ['found' => true, 'type' => $typeMatch[1], 'name' => $name, 'description' => $get('description'), 'brand' => $brand, 'image' => $image, 'offers' => $offers, 'missing_yandex_fields' => array_values(array_unique($missing))];
    }

    private function extractListItems(string $html, string $url, array $jsonLd = []): array
    {
        $path = parse_url(Security::normalizeUrl($url), PHP_URL_PATH) ?: '';
        if (preg_match('~^/blog/?~i', $path)) {
            $items = $this->blogItemsFromJsonLd($jsonLd, $url);
            if ($items) { return array_slice($items, 0, 30); }
            return $this->blogItemsFromDom($html, $url);
        }
        if (preg_match('~^/catalog/?~i', $path)) {
            $catalogItems = $this->catalogItemsFromDom($html, $url);
            if (count($catalogItems) > 1) { return $catalogItems; }
            return [];
        }
        return $this->genericListItems($html, $url);
    }

    private function extractDates(string $html): array
    {
        $dates = [];
        if (preg_match_all('/<time[^>]+datetime=["\']([^"\']+)["\'][^>]*>/isu', $html, $m)) {
            foreach ($m[1] as $d) { $dates[] = $d; }
        }
        foreach (['article:published_time','article:modified_time'] as $prop) {
            $v = $this->extractMeta($html, $prop, true);
            if ($v !== '') { $dates[] = $v; }
        }
        $plain = Security::text($html, 90000);
        $months = 'января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря';
        if (preg_match_all('/\b(\d{1,2})\s+('.$months.')\s+(20\d{2})\b/iu', $plain, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $iso = $this->ruDateToIso((int)$row[1], mb_strtolower($row[2]), (int)$row[3]);
                if ($iso) { $dates[] = $iso; }
            }
        }
        return array_values(array_unique(array_slice($dates, 0, 5)));
    }

    private function productFromJsonLd(array $schemas, string $url): array
    {
        foreach ($schemas as $schema) {
            foreach ($this->findNodesByType($schema, ['Product','ProductGroup']) as $node) {
                $offer = [];
                if (!empty($node['offers']) && is_array($node['offers'])) {
                    $offers = array_keys($node['offers']) === range(0, count($node['offers']) - 1) ? $node['offers'] : [$node['offers']];
                    $offer = is_array($offers[0] ?? null) ? $offers[0] : [];
                }
                $image = $node['image'] ?? '';
                if (is_array($image)) { $image = $image[0] ?? ''; }
                $brand = $node['brand'] ?? '';
                if (is_array($brand)) { $brand = $brand['name'] ?? ''; }
                return [
                    'name' => (string)($node['name'] ?? ''),
                    'sku' => $this->cleanProductValue((string)($node['sku'] ?? '')),
                    'brand' => $this->cleanProductValue((string)$brand),
                    'price' => (string)($offer['price'] ?? ''),
                    'priceCurrency' => (string)($offer['priceCurrency'] ?? ''),
                    'availability' => $this->normalizeAvailability((string)($offer['availability'] ?? '')),
                    'url' => (string)($node['url'] ?? $url),
                    'image' => (string)$image,
                ];
            }
        }
        return [];
    }

    private function blogItemsFromJsonLd(array $schemas, string $url): array
    {
        $items = [];
        foreach ($schemas as $schema) {
            foreach ($this->findNodesByType($schema, ['Blog']) as $blog) {
                foreach ((array)($blog['blogPost'] ?? []) as $post) {
                    if (!is_array($post)) { continue; }
                    $name = $this->normalizeItemName((string)($post['headline'] ?? $post['name'] ?? ''));
                    $href = (string)($post['url'] ?? '');
                    if ($name !== '' && $href !== '') { $items[] = ['name' => $name, 'url' => Security::absUrl($href, $url)]; }
                }
            }
        }
        return array_values(array_unique($items, SORT_REGULAR));
    }

    private function blogItemsFromDom(string $html, string $url): array
    {
        $items = [];
        $dom = $this->dom($html);
        if (!$dom) { return $this->genericListItems($html, $url); }
        $xp = new \DOMXPath($dom);
        $links = $xp->query('//a[@href]');
        foreach ($links as $a) {
            $href = $a->getAttribute('href');
            $path = parse_url(Security::absUrl($href, $url), PHP_URL_PATH) ?: '';
            if (!preg_match('~^/blog/[^/]+/[^/]+/?$~i', $path)) { continue; }
            $name = $this->normalizeItemName($a->textContent);
            if ($name !== '') { $items[] = ['name' => $name, 'url' => Security::absUrl($href, $url)]; }
        }
        return array_slice(array_values(array_unique($items, SORT_REGULAR)), 0, 30);
    }

    private function catalogItemsFromDom(string $html, string $url): array
    {
        $items = [];
        $dom = $this->dom($html);
        if (!$dom) { return $this->catalogItemsFromRegex($html, $url); }
        $xp = new \DOMXPath($dom);
        $productNodes = $xp->query('//*[contains(translate(@itemtype,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"), "schema.org/product")]');
        foreach ($productNodes as $node) {
            $name = '';
            $meta = $xp->query('.//meta[@itemprop="description" or @itemprop="name"]/@content', $node)->item(0);
            if ($meta) { $name = $this->normalizeItemName($meta->nodeValue); }
            if ($name === '') {
                $linkName = $xp->query('.//a[@href and string-length(normalize-space(.)) > 3]', $node)->item(0);
                if ($linkName) { $name = $this->normalizeItemName($linkName->textContent); }
            }
            if ($name === '') {
                $imgAlt = $xp->query('.//img[@alt]/@alt', $node)->item(0);
                if ($imgAlt) { $name = $this->normalizeItemName($imgAlt->nodeValue); }
            }
            $href = '';
            $links = $xp->query('.//a[@href]', $node);
            foreach ($links as $a) {
                $candidate = (string)$a->getAttribute('href');
                $abs = Security::absUrl($candidate, $url);
                $path = parse_url($abs, PHP_URL_PATH) ?: '';
                if ($this->isCatalogProductUrl($path, $url)) { $href = $abs; break; }
            }
            if ($name !== '' && $href !== '') { $items[] = ['name' => $name, 'url' => $href]; }
        }
        return array_slice(array_values(array_unique($items, SORT_REGULAR)), 0, 30);
    }

    private function catalogItemsFromRegex(string $html, string $url): array
    {
        $items = [];
        if (!preg_match_all('/<div[^>]+class=["\'][^"\']*catalog-block__item[^"\']*["\'][^>]*>(.*?)(?=<div[^>]+class=["\'][^"\']*catalog-block__item|<\/body>|$)/isu', $html, $blocks)) {
            return [];
        }
        foreach ($blocks[1] as $block) {
            $name = '';
            if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+itemprop=["\'](?:description|name)["\']/isu', $block, $m) || preg_match('/<meta[^>]+itemprop=["\'](?:description|name)["\'][^>]+content=["\']([^"\']+)["\']/isu', $block, $m)) {
                $name = $this->normalizeItemName($m[1]);
            }
            $href = '';
            if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/isu', $block, $links, PREG_SET_ORDER)) {
                foreach ($links as $a) {
                    $abs = Security::absUrl(html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $url);
                    $path = parse_url($abs, PHP_URL_PATH) ?: '';
                    if (!$this->isCatalogProductUrl($path, $url)) { continue; }
                    $href = $abs;
                    if ($name === '') { $name = $this->normalizeItemName($a[2]); }
                    break;
                }
            }
            if ($name === '' && preg_match('/<img[^>]+alt=["\']([^"\']+)["\']/isu', $block, $im)) {
                $name = $this->normalizeItemName($im[1]);
            }
            if ($name !== '' && $href !== '') { $items[] = ['name' => $name, 'url' => $href]; }
        }
        return array_slice(array_values(array_unique($items, SORT_REGULAR)), 0, 30);
    }

    private function genericListItems(string $html, string $url): array
    {
        $dom = $this->dom($html);
        if (!$dom) { return $this->genericListItemsRegex($html, $url); }
        $xp = new \DOMXPath($dom);
        $items = [];
        $seen = [];
        foreach ($xp->query('//a[@href]') as $a) {
            if (!$a instanceof \DOMElement) { continue; }
            $raw = trim($a->getAttribute('href'));
            if ($this->isNoiseHref($raw) || $this->insideChrome($a)) { continue; }
            $name = $this->normalizeItemName($a->textContent);
            if ($name === '') { continue; }
            $href = Security::absUrl(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $url);
            if ($href === '' || $this->isNoiseUrl($href)) { continue; }
            $key = mb_strtolower($name . '|' . $href);
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $items[] = ['name' => $name, 'url' => $href];
            if (count($items) >= 30) { break; }
        }
        return $items;
    }

    private function genericListItemsRegex(string $html, string $url): array
    {
        $items = [];
        if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/isu', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $a) {
                $raw = trim($a[1]);
                if ($this->isNoiseHref($raw)) { continue; }
                $name = $this->normalizeItemName($a[2]);
                $href = Security::absUrl(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $url);
                if ($name !== '' && $href !== '' && !$this->isNoiseUrl($href)) { $items[] = ['name' => $name, 'url' => $href]; }
            }
        }
        return array_slice(array_values(array_unique($items, SORT_REGULAR)), 0, 30);
    }

    // Ссылки шапки/меню/подвала/сайдбара — это навигация сайта, а не элементы списка на странице.
    private function insideChrome(\DOMElement $node): bool
    {
        $chromeTags = ['header' => 1, 'nav' => 1, 'footer' => 1, 'aside' => 1];
        $p = $node->parentNode;
        $hops = 0;
        while ($p instanceof \DOMElement && $hops++ < 25) {
            if (isset($chromeTags[strtolower($p->nodeName)])) { return true; }
            $role = strtolower($p->getAttribute('role'));
            if ($role === 'navigation' || $role === 'banner' || $role === 'contentinfo') { return true; }
            $token = ' ' . mb_strtolower($p->getAttribute('class') . ' ' . $p->getAttribute('id')) . ' ';
            if (preg_match('~(^|[\s_-])(menu|nav|navbar|header|footer|breadcrumb|sidebar|side-bar|submenu|dropdown|socials?|basket|cart|compare|wishlist|top-panel|top_block|bottom_block)~u', $token)) {
                return true;
            }
            $p = $p->parentNode;
        }
        return false;
    }

    private function isNoiseHref(string $raw): bool
    {
        if ($raw === '' || $raw === '#') { return true; }
        return (bool)preg_match('~^(#|tel:|mailto:|callto:|skype:|whatsapp:|viber:|javascript:|data:)~i', $raw);
    }

    private function findNodesByType($node, array $types): array
    {
        if (!is_array($node)) { return []; }
        $found = [];
        $nodeTypes = array_map('strval', (array)($node['@type'] ?? []));
        foreach ($nodeTypes as $nodeType) {
            if (in_array($nodeType, $types, true)) { $found[] = $node; break; }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                if (array_keys($value) === range(0, count($value) - 1)) {
                    foreach ($value as $child) { $found = array_merge($found, $this->findNodesByType($child, $types)); }
                } else {
                    $found = array_merge($found, $this->findNodesByType($value, $types));
                }
            }
        }
        return $found;
    }

    private function dom(string $html): ?\DOMDocument
    {
        if (!class_exists('DOMDocument')) { return null; }
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $old = libxml_use_internal_errors(true);
        $encoded = '<?xml encoding="utf-8" ?>' . $html;
        $ok = $dom->loadHTML($encoded, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        return $ok ? $dom : null;
    }

    private function isCatalogProductUrl(string $path, string $currentUrl): bool
    {
        $current = parse_url(Security::normalizeUrl($currentUrl), PHP_URL_PATH) ?: '';
        if (!preg_match('~^/catalog/.+/$~i', $path)) { return false; }
        if (strpos($path, '/filter/') !== false || strpos($path, '/apply/') !== false) { return false; }
        if (rtrim($path, '/') === rtrim($current, '/')) { return false; }
        return substr_count(trim($path, '/'), '/') >= 2;
    }

    private function isNoiseUrl(string $href): bool
    {
        return preg_match('~/(bitrix/admin|personal|basket|favorite|search/|auth|login|logout)|^(tel:|mailto:)~i', $href) === 1;
    }

    private function isUsableImage(string $src): bool
    {
        if ($src === '' || strpos($src, 'data:') === 0) { return false; }
        if (strpos($src, 'mc.yandex.ru/watch') !== false) { return false; }
        return true;
    }

    private function isLogoOrCounter(string $src): bool
    {
        $s = mb_strtolower($src);
        return strpos($s, 'logo') !== false || strpos($s, '/cpremier/') !== false || strpos($s, 'mc.yandex') !== false || strpos($s, 'favicon') !== false;
    }

    private function normalizeAvailability(string $value): string
    {
        $value = trim($value);
        if ($value === '') { return ''; }
        if (preg_match('~^https?://~i', $value)) { return $value; }
        if (preg_match('/^(InStock|OutOfStock|PreOrder|PreSale|BackOrder|Discontinued|LimitedAvailability|OnlineOnly|SoldOut)$/i', $value, $m)) {
            return 'https://schema.org/' . $m[1];
        }
        return $value;
    }

    private function cleanProductValue(string $value): string
    {
        $value = $this->normalizeItemName($value);
        if ($value === '') { return ''; }
        if (preg_match('/^[a-z0-9_-]*__[a-z0-9_-]+$/i', $value)) { return ''; }
        if (preg_match('/^(s-list|props__item|item|value|name|price|brand)$/i', $value)) { return ''; }
        return mb_substr($value, 0, 120);
    }

    private function normalizeItemName(string $value): string
    {
        $value = $this->cleanText($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?: '';
        // Многобайтно-безопасная обрезка: обычный trim() режет по байтам и портит хвостовые
        // кириллические буквы, у которых последний байт совпадает с байтом символов ·›».
        $value = preg_replace('~^[\s\x00·›»/]+|[\s\x00·›»/]+$~u', '', $value) ?? $value;
        if ($value === '' || mb_strlen($value) > 200) { return ''; }
        if (preg_match('/^(0|войти|кабинет|корзина|избранное|меню|сайт|администрирование)$/iu', $value)) { return ''; }
        return $value;
    }

    private function cleanText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?: '';
        return trim($text);
    }

    private function ruDateToIso(int $day, string $month, int $year): string
    {
        $map = [
            'января' => 1, 'февраля' => 2, 'марта' => 3, 'апреля' => 4, 'мая' => 5, 'июня' => 6,
            'июля' => 7, 'августа' => 8, 'сентября' => 9, 'октября' => 10, 'ноября' => 11, 'декабря' => 12,
        ];
        $m = $map[$month] ?? 0;
        if ($m < 1 || !checkdate($m, $day, $year)) { return ''; }
        return sprintf('%04d-%02d-%02d', $year, $m, $day);
    }

    private function countWords(string $text): int
    {
        preg_match_all('/[\p{L}\p{N}]{2,}/u', $text, $m);
        return count($m[0] ?? []);
    }
}
