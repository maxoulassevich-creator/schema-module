<?php
namespace Custom\SmartSchema;

class SchemaBuilder
{
    public function siteSchema(string $type): array
    {
        $siteUrl = Security::normalizeUrl('/');
        $siteName = Options::get('site_name') ?: (Options::get('organization_name') ?: (defined('SITE_SERVER_NAME') ? SITE_SERVER_NAME : ($_SERVER['HTTP_HOST'] ?? '')));
        if ($type === 'WebSite') {
            return $this->clean([
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                '@id' => $siteUrl . '#website',
                'url' => $siteUrl,
                'name' => $siteName,
                'publisher' => ['@id' => $siteUrl . '#organization'],
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => Security::absUrl(Options::get('search_url_template', '/search/?q={search_term_string}'), $siteUrl),
                    'query-input' => 'required name=search_term_string',
                ],
            ]);
        }
        $orgType = Options::get('organization_type', 'Organization') ?: 'Organization';
        if (!in_array($orgType, ['Organization','LocalBusiness','Store'], true)) { $orgType = 'Organization'; }
        return $this->clean([
            '@context' => 'https://schema.org',
            '@type' => $orgType,
            '@id' => $siteUrl . '#organization',
            'name' => Options::get('organization_name') ?: $siteName,
            'url' => $siteUrl,
            'logo' => Options::get('organization_logo') ? ['@type' => 'ImageObject', 'url' => Security::absUrl(Options::get('organization_logo'), $siteUrl)] : null,
            'image' => Options::get('organization_logo') ? Security::absUrl(Options::get('organization_logo'), $siteUrl) : null,
            'telephone' => Options::get('organization_phone'),
            'email' => Options::get('organization_email'),
            'sameAs' => $this->sameAs(),
        ]);
    }

    public function buildForKind(string $kind, array $analysis, string $schemaType): array
    {
        if ($schemaType === 'BreadcrumbList') { return $this->breadcrumb($analysis); }
        if ($schemaType === 'FAQPage') { return $this->faq($analysis); }
        if ($schemaType === 'VideoObject') { return $this->video($analysis); }
        if ($schemaType === 'ItemList') {
            return $kind === 'reviews_page' ? $this->reviewItemList($analysis) : $this->itemList($analysis);
        }
        if ($schemaType === 'CollectionPage') { return $this->collectionPage($analysis, $kind); }
        if ($schemaType === 'Product' || $schemaType === 'ProductGroup') { return $this->product($analysis, $schemaType); }
        if ($schemaType === 'Blog') { return $this->blog($analysis); }
        if (in_array($schemaType, ['BlogPosting','Article','NewsArticle'], true)) { return $this->article($analysis, $schemaType); }
        if (in_array($schemaType, ['WebPage','ContactPage','AboutPage','SearchResultsPage','ProfilePage'], true)) { return $this->webPage($analysis, $schemaType); }
        if ($schemaType === 'Service') { return $this->service($analysis); }
        if ($schemaType === 'Event') { return $this->event($analysis); }
        return $this->webPage($analysis);
    }

    public function recommendedTypes(string $kind, array $analysis): array
    {
        $types = [];
        $hasBreadcrumb = $this->hasType($analysis, ['BreadcrumbList']);
        $hasProduct = $this->hasType($analysis, ['Product','ProductGroup']);
        $hasBlog = $this->hasType($analysis, ['Blog']);
        $hasArticle = $this->hasType($analysis, ['BlogPosting','Article','NewsArticle']);
        $hasItemList = $this->hasType($analysis, ['ItemList']);
        $hasCollection = $this->hasType($analysis, ['CollectionPage','ProductCollection']);
        $crumbCount = count((array)($analysis['breadcrumbs'] ?? []));
        $itemCount = count((array)($analysis['items'] ?? []));
        $reviewCount = count((array)($analysis['reviews'] ?? []));
        $dateCount = count((array)($analysis['dates'] ?? []));

        if ($kind === 'product_detail') {
            if (!$hasProduct && !empty($analysis['product']['name'])) { $types[] = 'Product'; }
            if (!$hasBreadcrumb && $crumbCount >= 2) { $types[] = 'BreadcrumbList'; }
        } elseif ($kind === 'product_category') {
            if ($itemCount > 0 && !$hasCollection) { $types[] = 'CollectionPage'; }
            if ($itemCount > 0 && !$hasItemList) { $types[] = 'ItemList'; }
            if (!$hasBreadcrumb && $crumbCount >= 2) { $types[] = 'BreadcrumbList'; }
        } elseif ($kind === 'blog_list') {
            if (!$hasBlog && $itemCount > 0 && !$hasCollection) { $types[] = 'CollectionPage'; }
            if (!$hasBlog && $itemCount > 0 && !$hasItemList) { $types[] = 'ItemList'; }
            if (!$hasBreadcrumb && $crumbCount >= 2) { $types[] = 'BreadcrumbList'; }
        } elseif ($kind === 'news_detail') {
            if (!$hasArticle) { $types[] = 'BlogPosting'; }
            if (!$hasBreadcrumb && $crumbCount >= 2) { $types[] = 'BreadcrumbList'; }
        } elseif ($kind === 'contact_page') {
            if (!$this->hasType($analysis, ['ContactPage'])) { $types[] = 'ContactPage'; }
            if (!$hasBreadcrumb && $crumbCount >= 2) { $types[] = 'BreadcrumbList'; }
        } elseif ($kind === 'about_page') {
            if (!$this->hasType($analysis, ['AboutPage'])) { $types[] = 'AboutPage'; }
            if (!$hasBreadcrumb && $crumbCount >= 2) { $types[] = 'BreadcrumbList'; }
        } elseif (in_array($kind, ['delivery_page','payment_page','returns_page','legal_page','webpage','cart_page','checkout_page'], true)) {
            if (!$this->hasType($analysis, ['WebPage'])) { $types[] = 'WebPage'; }
            if (!$hasBreadcrumb && $crumbCount >= 2) { $types[] = 'BreadcrumbList'; }
        } elseif ($kind === 'reviews_page') {
            if (!$this->hasType($analysis, ['WebPage'])) { $types[] = 'WebPage'; }
            if ($reviewCount > 0 && !$this->hasType($analysis, ['ItemList','Review'])) { $types[] = 'ItemList'; }
            if (!$hasBreadcrumb && $crumbCount >= 2) { $types[] = 'BreadcrumbList'; }
        } elseif ($kind === 'faq_page') {
            if (!empty($analysis['faq_pairs']) && !$this->hasType($analysis, ['FAQPage'])) { $types[] = 'FAQPage'; }
            if (empty($analysis['faq_pairs']) && !$this->hasType($analysis, ['WebPage'])) { $types[] = 'WebPage'; }
            if (!$hasBreadcrumb && $crumbCount >= 2) { $types[] = 'BreadcrumbList'; }
        } elseif ($kind === 'search_results_page') {
            if (!$this->hasType($analysis, ['SearchResultsPage'])) { $types[] = 'SearchResultsPage'; }
            if ($itemCount > 0 && !$hasItemList) { $types[] = 'ItemList'; }
        } elseif (in_array($kind, ['brand_list','collection_page','event_list'], true)) {
            if ($itemCount > 0 && !$hasCollection) { $types[] = 'CollectionPage'; }
            if ($itemCount > 0 && !$hasItemList) { $types[] = 'ItemList'; }
            if ($itemCount === 0 && !$this->hasType($analysis, ['WebPage'])) { $types[] = 'WebPage'; }
            if (!$hasBreadcrumb && $crumbCount >= 2) { $types[] = 'BreadcrumbList'; }
        } elseif ($kind === 'brand_detail') {
            if (!$this->hasType($analysis, ['WebPage'])) { $types[] = 'WebPage'; }
            if (!$hasBreadcrumb && $crumbCount >= 2) { $types[] = 'BreadcrumbList'; }
        } elseif ($kind === 'service_page') {
            if (!$this->hasType($analysis, ['Service'])) { $types[] = 'Service'; }
            if (!$hasBreadcrumb && $crumbCount >= 2) { $types[] = 'BreadcrumbList'; }
        } elseif ($kind === 'event_detail') {
            if ($dateCount > 0 && !$this->hasType($analysis, ['Event'])) { $types[] = 'Event'; }
            if ($dateCount === 0 && !$this->hasType($analysis, ['WebPage'])) { $types[] = 'WebPage'; }
            if (!$hasBreadcrumb && $crumbCount >= 2) { $types[] = 'BreadcrumbList'; }
        } elseif ($kind === 'video_page') {
            if (!empty($analysis['videos']) && !$this->hasType($analysis, ['VideoObject'])) { $types[] = 'VideoObject'; }
            if (empty($analysis['videos']) && !$this->hasType($analysis, ['WebPage'])) { $types[] = 'WebPage'; }
            if (!$hasBreadcrumb && $crumbCount >= 2) { $types[] = 'BreadcrumbList'; }
        } elseif ($kind === 'profile_page') {
            if (!$this->hasType($analysis, ['ProfilePage'])) { $types[] = 'ProfilePage'; }
            if (!$hasBreadcrumb && $crumbCount >= 2) { $types[] = 'BreadcrumbList'; }
        } else {
            if (!$this->hasType($analysis, ['WebPage'])) { $types[] = 'WebPage'; }
        }

        if (!empty($analysis['faq_pairs']) && !$this->hasType($analysis, ['FAQPage']) && !in_array('FAQPage', $types, true)) { $types[] = 'FAQPage'; }
        if (!empty($analysis['videos']) && !$this->hasType($analysis, ['VideoObject']) && !in_array('VideoObject', $types, true)) { $types[] = 'VideoObject'; }
        return array_values(array_unique($types));
    }

    public function webPage(array $analysis, string $type = 'WebPage'): array
    {
        $url = $analysis['canonical'] ?? $analysis['url'] ?? Security::normalizeUrl('/');
        if (!in_array($type, ['WebPage','ContactPage','AboutPage','SearchResultsPage','ProfilePage'], true)) { $type = 'WebPage'; }
        return $this->clean([
            '@context' => 'https://schema.org',
            '@type' => $type,
            '@id' => $url . '#webpage',
            'url' => $url,
            'name' => $this->name($analysis),
            'description' => $this->description($analysis),
            'isPartOf' => ['@id' => Security::normalizeUrl('/') . '#website'],
            'publisher' => ['@id' => Security::normalizeUrl('/') . '#organization'],
            'primaryImageOfPage' => !empty($analysis['primary_image']) ? ['@type' => 'ImageObject', 'url' => Security::absUrl((string)$analysis['primary_image'], $url)] : null,
        ]);
    }

    public function product(array $analysis, string $type = 'Product'): array
    {
        $product = (array)($analysis['product'] ?? []);
        $url = $product['url'] ?? ($analysis['canonical'] ?? $analysis['url'] ?? Security::normalizeUrl('/'));
        $offer = [];
        if (!empty($product['price']) && !empty($product['priceCurrency'])) {
            $offer = [
                '@type' => 'Offer',
                'url' => $url,
                'price' => $product['price'],
                'priceCurrency' => $product['priceCurrency'],
                'availability' => $product['availability'] ?: null,
                'itemCondition' => 'https://schema.org/NewCondition',
            ];
        }
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => $type,
            '@id' => $url . ($type === 'ProductGroup' ? '#productgroup' : '#product'),
            'name' => $product['name'] ?: $this->name($analysis),
            'description' => $this->description($analysis),
            'url' => $url,
            'sku' => $product['sku'] ?: null,
            'brand' => $product['brand'] ? ['@type' => 'Brand', 'name' => $product['brand']] : null,
            'image' => $product['image'] ? [Security::absUrl((string)$product['image'], $url)] : null,
            'offers' => $offer ?: null,
        ];
        if ($type === 'ProductGroup') {
            $schema['productGroupID'] = $schema['sku'] ?: null;
            unset($schema['offers']);
        }
        return $this->clean($schema);
    }


    public function blog(array $analysis): array
    {
        $url = $analysis['canonical'] ?? $analysis['url'] ?? Security::normalizeUrl('/');
        $posts = [];
        foreach ((array)($analysis['items'] ?? []) as $item) {
            if (empty($item['url']) || empty($item['name'])) { continue; }
            $posts[] = $this->clean([
                '@type' => 'BlogPosting',
                'headline' => $item['name'],
                'url' => Security::absUrl((string)$item['url'], $url),
                'author' => ['@id' => Security::normalizeUrl('/') . '#organization'],
            ]);
            if (count($posts) >= 20) { break; }
        }
        return $this->clean([
            '@context' => 'https://schema.org',
            '@type' => 'Blog',
            '@id' => $url . '#blog',
            'url' => $url,
            'name' => $this->name($analysis),
            'description' => $this->description($analysis),
            'publisher' => ['@id' => Security::normalizeUrl('/') . '#organization'],
            'blogPost' => $posts ?: null,
        ]);
    }

    public function collectionPage(array $analysis, string $kind): array
    {
        $url = $analysis['canonical'] ?? $analysis['url'] ?? Security::normalizeUrl('/');
        if (empty($analysis['items'])) { return []; }
        return $this->clean([
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            '@id' => $url . '#collection',
            'url' => $url,
            'name' => $this->name($analysis),
            'description' => $this->description($analysis),
            'isPartOf' => ['@id' => Security::normalizeUrl('/') . '#website'],
            'mainEntity' => ['@id' => $url . '#itemlist'],
        ]);
    }

    public function itemList(array $analysis): array
    {
        $url = $analysis['canonical'] ?? $analysis['url'] ?? Security::normalizeUrl('/');
        $items = [];
        $pos = 1;
        foreach ((array)($analysis['items'] ?? []) as $item) {
            if (empty($item['url']) || empty($item['name'])) { continue; }
            $items[] = [
                '@type' => 'ListItem',
                'position' => $pos++,
                'url' => $item['url'],
                'name' => $item['name'],
            ];
            if ($pos > 16) { break; }
        }
        if (!$items) { return []; }
        return $this->clean([
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            '@id' => $url . '#itemlist',
            'itemListElement' => $items,
        ]);
    }


    public function reviewItemList(array $analysis): array
    {
        $url = $analysis['canonical'] ?? $analysis['url'] ?? Security::normalizeUrl('/');
        $items = [];
        $pos = 1;
        foreach ((array)($analysis['reviews'] ?? []) as $review) {
            $body = (string)($review['reviewBody'] ?? '');
            if ($body === '') { continue; }
            $node = [
                '@type' => 'Review',
                'reviewBody' => $body,
                'itemReviewed' => ['@id' => Security::normalizeUrl('/') . '#organization'],
                'author' => !empty($review['author']) ? ['@type' => 'Person', 'name' => $review['author']] : ['@id' => Security::normalizeUrl('/') . '#organization'],
                'reviewRating' => !empty($review['ratingValue']) ? [
                    '@type' => 'Rating',
                    'ratingValue' => $review['ratingValue'],
                    'bestRating' => 5,
                    'worstRating' => 1,
                ] : null,
            ];
            $items[] = [
                '@type' => 'ListItem',
                'position' => $pos++,
                'item' => $this->clean($node),
            ];
            if ($pos > 10) { break; }
        }
        if (!$items) { return []; }
        return $this->clean([
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            '@id' => $url . '#reviews',
            'name' => $this->name($analysis),
            'itemListElement' => $items,
        ]);
    }

    public function article(array $analysis, string $type = 'BlogPosting'): array
    {
        $url = $analysis['canonical'] ?? $analysis['url'] ?? Security::normalizeUrl('/');
        $dates = (array)($analysis['dates'] ?? []);
        $image = (string)(($analysis['primary_image'] ?? '') ?: ($analysis['og_image'] ?? ''));
        $published = $dates[0] ?? null;
        $modified = $dates[1] ?? $published;
        if ($published && $modified && preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$published) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$modified) && strcmp((string)$modified, (string)$published) < 0) {
            $modified = $published;
        }
        return $this->clean([
            '@context' => 'https://schema.org',
            '@type' => $type,
            '@id' => $url . '#article',
            'mainEntityOfPage' => ['@id' => $url . '#webpage'],
            'headline' => $this->name($analysis),
            'description' => $this->description($analysis),
            'datePublished' => $published,
            'dateModified' => $modified,
            'author' => ['@type' => 'Organization', '@id' => Security::normalizeUrl('/') . '#organization'],
            'publisher' => ['@id' => Security::normalizeUrl('/') . '#organization'],
            'image' => $image ? [Security::absUrl($image, $url)] : null,
        ]);
    }

    public function service(array $analysis): array
    {
        $url = $analysis['canonical'] ?? $analysis['url'] ?? Security::normalizeUrl('/');
        return $this->clean([
            '@context' => 'https://schema.org',
            '@type' => 'Service',
            '@id' => $url . '#service',
            'name' => $this->name($analysis),
            'description' => $this->description($analysis),
            'url' => $url,
            'provider' => ['@id' => Security::normalizeUrl('/') . '#organization'],
            'image' => !empty($analysis['primary_image']) ? Security::absUrl((string)$analysis['primary_image'], $url) : null,
        ]);
    }

    public function event(array $analysis): array
    {
        $url = $analysis['canonical'] ?? $analysis['url'] ?? Security::normalizeUrl('/');
        $dates = (array)($analysis['dates'] ?? []);
        if (empty($dates[0])) { return []; }
        return $this->clean([
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            '@id' => $url . '#event',
            'name' => $this->name($analysis),
            'description' => $this->description($analysis),
            'url' => $url,
            'startDate' => $dates[0],
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => null,
            'organizer' => ['@id' => Security::normalizeUrl('/') . '#organization'],
            'image' => !empty($analysis['primary_image']) ? [Security::absUrl((string)$analysis['primary_image'], $url)] : null,
        ]);
    }

    public function breadcrumb(array $analysis): array
    {
        $crumbs = (array)($analysis['breadcrumbs'] ?? []);
        if (!$crumbs) { return []; }
        $items = [];
        $pos = 1;
        foreach ($crumbs as $crumb) {
            if (empty($crumb['name']) || empty($crumb['url'])) { continue; }
            $items[] = ['@type' => 'ListItem', 'position' => $pos++, 'name' => $crumb['name'], 'item' => $crumb['url']];
        }
        if (!$items) { return []; }
        return $this->clean(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items]);
    }

    public function faq(array $analysis): array
    {
        $pairs = [];
        foreach ((array)($analysis['faq_pairs'] ?? []) as $pair) {
            if (empty($pair['question']) || empty($pair['answer'])) { continue; }
            $pairs[] = ['@type' => 'Question', 'name' => $pair['question'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $pair['answer']]];
        }
        if (!$pairs) { return []; }
        return $this->clean(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $pairs]);
    }

    public function video(array $analysis): array
    {
        $videos = (array)($analysis['videos'] ?? []);
        if (!$videos) { return []; }
        $v = $videos[0];
        return $this->clean([
            '@context' => 'https://schema.org',
            '@type' => 'VideoObject',
            'name' => $this->name($analysis),
            'description' => $this->description($analysis),
            'embedUrl' => $v['embedUrl'] ?? null,
            'contentUrl' => $v['contentUrl'] ?? null,
            'thumbnailUrl' => !empty($analysis['primary_image']) ? [Security::absUrl((string)$analysis['primary_image'], (string)($analysis['url'] ?? ''))] : null,
        ]);
    }

    private function hasType(array $analysis, array $types): bool
    {
        $existing = (array)($analysis['existing_schema_types'] ?? []);
        foreach ($types as $type) {
            if (in_array($type, $existing, true)) { return true; }
        }
        return false;
    }

    private function name(array $analysis): string
    {
        return (string)(($analysis['h1'][0] ?? '') ?: ($analysis['og_title'] ?? '') ?: ($analysis['title'] ?? ''));
    }

    private function description(array $analysis): string
    {
        return mb_substr((string)(($analysis['meta_description'] ?? '') ?: ($analysis['og_description'] ?? '')), 0, 500);
    }

    private function sameAs(): array
    {
        $lines = preg_split('/[\r\n,]+/', Options::get('organization_same_as', '')) ?: [];
        return array_values(array_filter(array_map('trim', $lines)));
    }

    private function clean($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $v = $this->clean($v);
                if ($v === null || $v === '' || $v === []) { continue; }
                $out[$k] = $v;
            }
            return $out;
        }
        if (is_string($value)) { return trim($value); }
        return $value;
    }
}
