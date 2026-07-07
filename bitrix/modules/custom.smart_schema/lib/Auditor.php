<?php
namespace Custom\SmartSchema;

class Auditor
{
    public function audit(array $analysis, string $kind = ''): array
    {
        $issues = [];
        $schemas = (array)($analysis['json_ld'] ?? []);
        $types = (array)($analysis['json_ld_types'] ?? []);
        foreach ($schemas as $schema) {
            if (isset($schema['_invalid_json'])) {
                $issues[] = [
                    'level' => 'error',
                    'type' => '',
                    'code' => 'invalid_json',
                    'replace_candidate' => true,
                    'message' => 'На странице найден script application/ld+json с невалидным JSON: ' . (string)($schema['_error'] ?? ''),
                ];
            }
        }
        foreach (array_count_values($types) as $type => $count) {
            if ($count > 1 && in_array($type, ['Product','ProductGroup','BreadcrumbList','Article','BlogPosting','FAQPage','VideoObject','Blog','ItemList','CollectionPage'], true)) {
                $issues[] = [
                    'level' => 'warning',
                    'type' => $type,
                    'code' => 'duplicate_type',
                    'replace_candidate' => true,
                    'message' => 'Найден возможный дубль Schema.org типа ' . $type . ': ' . $count . ' блока.',
                ];
            }
        }
        foreach ($schemas as $schema) {
            $this->auditNode($schema, $issues);
        }
        $this->auditMicrodata($analysis, $kind, $issues);
        return $issues;
    }

    private function auditMicrodata(array $analysis, string $kind, array &$issues): void
    {
        $microTypes = (array)($analysis['microdata_types'] ?? []);
        $microCounts = (array)($analysis['microdata_type_counts'] ?? []);
        $jsonTypes = (array)($analysis['json_ld_types'] ?? []);

        if (Options::get('yandex_product_audit', 'Y') === 'Y' && $kind === 'product_detail') {
            $this->auditYandexProductMicrodata($analysis, $issues);
        }

        if (!$microTypes) { return; }

        if ($kind === 'product_category') {
            if (in_array('ProductCollection', $microTypes, true) && !in_array('CollectionPage', $jsonTypes, true)) {
                $issues[] = [
                    'level' => 'warning',
                    'type' => 'ProductCollection',
                    'code' => 'category_product_collection_microdata',
                    'replace_candidate' => true,
                    'message' => 'Категория товаров размечена microdata ProductCollection. Для страницы раздела безопаснее заменить это на компактный JSON-LD CollectionPage.',
                ];
            }
            if (in_array('ItemList', $microTypes, true) && !in_array('ItemList', $jsonTypes, true)) {
                $issues[] = [
                    'level' => 'warning',
                    'type' => 'ItemList',
                    'code' => 'category_itemlist_microdata_replace',
                    'replace_candidate' => true,
                    'message' => 'Список товаров размечен microdata ItemList. Модуль может заменить его компактным JSON-LD ItemList из реальных карточек товаров.',
                ];
            }
            if (($microCounts['Product'] ?? 0) > 10) {
                $issues[] = [
                    'level' => 'notice',
                    'type' => 'Product',
                    'code' => 'many_product_microdata_inside_category',
                    'replace_candidate' => false,
                    'message' => 'В категории найдено много microdata Product внутри карточек товаров: ' . (int)$microCounts['Product'] . '. Это не создаёт предложение Product для категории, чтобы не размечать раздел как отдельный товар.',
                ];
            }
        }

        if ($kind === 'blog_list') {
            if (!in_array('Blog', $jsonTypes, true) && in_array('ItemList', $microTypes, true)) {
                $issues[] = [
                    'level' => 'warning',
                    'type' => 'ItemList',
                    'code' => 'blog_itemlist_microdata_replace',
                    'replace_candidate' => true,
                    'message' => 'Список материалов размечен только microdata ItemList. Модуль может заменить его JSON-LD ItemList, если на странице нет корректного Blog/blogPost.',
                ];
            }
        }
    }

    private function auditYandexProductMicrodata(array $analysis, array &$issues): void
    {
        $micro = (array)($analysis['microdata_product'] ?? []);
        if (empty($micro['found'])) { return; }
        $missing = (array)($micro['missing_yandex_fields'] ?? []);
        if (!$missing) { return; }
        $issues[] = [
            'level' => 'warning',
            'type' => 'Product',
            'code' => 'yandex_product_microdata_incomplete',
            'replace_candidate' => true,
            'message' => 'На карточке товара найдена microdata ' . (string)($micro['type'] ?? 'Product') . ', но для Яндекс Товаров не хватает обязательных полей: ' . implode(', ', $missing) . '. Модуль может заменить её компактным JSON-LD Product + Offer по данным текущей карточки.',
        ];
    }

    private function auditNode($node, array &$issues): void
    {
        if (!is_array($node)) { return; }
        $type = $node['@type'] ?? '';
        if (is_array($type)) { $type = implode(',', $type); }
        $jsonSize = strlen(json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($type !== '' && $jsonSize > $this->bulkyLimit((string)$type)) {
            $issues[] = [
                'level' => 'warning',
                'type' => (string)$type,
                'code' => 'too_bulky',
                'replace_candidate' => true,
                'message' => 'Schema.org ' . $type . ' выглядит слишком громоздкой: примерно ' . $jsonSize . ' байт JSON. Лучше заменить компактной разметкой только по видимым данным страницы.',
            ];
        }
        if ($type === 'Product' || $type === 'ProductGroup') {
            if (empty($node['name'])) { $issues[] = ['level' => 'error', 'type' => $type, 'code' => 'product_no_name', 'replace_candidate' => true, 'message' => 'Product/ProductGroup без name.']; }
            if (empty($node['image'])) { $issues[] = ['level' => 'warning', 'type' => $type, 'code' => 'product_no_image', 'replace_candidate' => true, 'message' => 'Product/ProductGroup без image.']; }
            if (Options::get('yandex_product_audit', 'Y') === 'Y' && empty($node['brand'])) { $issues[] = ['level' => 'warning', 'type' => $type, 'code' => 'product_no_brand_yandex', 'replace_candidate' => true, 'message' => 'Product/ProductGroup без brand. Для Яндекс Товаров brand является обязательным полем товара.']; }
            if ($type === 'Product' && empty($node['offers'])) { $issues[] = ['level' => 'warning', 'type' => $type, 'code' => 'product_no_offers', 'replace_candidate' => true, 'message' => 'Product без offers: для Яндекс Товаров нужен Offer или AggregateOffer с ценой, валютой и наличием.']; }
            if (!empty($node['offers']) && is_array($node['offers'])) {
                $offers = array_keys($node['offers']) === range(0, count($node['offers'])-1) ? $node['offers'] : [$node['offers']];
                foreach ($offers as $offer) {
                    if (empty($offer['price'])) { $issues[] = ['level' => 'warning', 'type' => 'Product', 'code' => 'offer_no_price', 'replace_candidate' => true, 'message' => 'Offer без price/lowPrice.']; }
                    if (empty($offer['priceCurrency'])) { $issues[] = ['level' => 'warning', 'type' => 'Product', 'code' => 'offer_no_currency', 'replace_candidate' => true, 'message' => 'Offer без priceCurrency.']; }
                    if (empty($offer['availability'])) { $issues[] = ['level' => 'warning', 'type' => 'Product', 'code' => 'offer_no_availability', 'replace_candidate' => true, 'message' => 'Offer без availability.']; }
                }
            }
        }
        if ($type === 'FAQPage' && empty($node['mainEntity'])) {
            $issues[] = ['level' => 'warning', 'type' => 'FAQPage', 'code' => 'faq_no_main_entity', 'replace_candidate' => true, 'message' => 'FAQPage без mainEntity.'];
        }
        if ($type === 'BreadcrumbList' && empty($node['itemListElement'])) {
            $issues[] = ['level' => 'warning', 'type' => 'BreadcrumbList', 'code' => 'breadcrumbs_no_items', 'replace_candidate' => true, 'message' => 'BreadcrumbList без itemListElement.'];
        }
        if (in_array($type, ['BlogPosting','Article','NewsArticle'], true)) {
            if (empty($node['headline']) && empty($node['name'])) { $issues[] = ['level' => 'error', 'type' => $type, 'code' => 'article_no_headline', 'replace_candidate' => true, 'message' => $type . ' без headline/name.']; }
            if (empty($node['image'])) { $issues[] = ['level' => 'warning', 'type' => $type, 'code' => 'article_no_image', 'replace_candidate' => true, 'message' => $type . ' без image.']; }
        }
        foreach (['@graph','mainEntity','itemListElement','hasVariant','offers','blogPost','review','aggregateRating','author','publisher'] as $key) {
            if (!isset($node[$key]) || !is_array($node[$key])) { continue; }
            $children = array_keys($node[$key]) === range(0, count($node[$key])-1) ? $node[$key] : [$node[$key]];
            foreach ($children as $child) { $this->auditNode($child, $issues); }
        }
    }

    private function bulkyLimit(string $type): int
    {
        return [
            'Product' => 12000,
            'ProductGroup' => 16000,
            'Blog' => 30000,
            'ItemList' => 24000,
            'CollectionPage' => 10000,
            'BreadcrumbList' => 8000,
            'BlogPosting' => 14000,
            'Article' => 14000,
            'NewsArticle' => 14000,
            'FAQPage' => 18000,
        ][$type] ?? 20000;
    }
}
