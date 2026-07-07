<?php
namespace Custom\SmartSchema;

class Scanner
{
    private HtmlAnalyzer $analyzer;
    private Auditor $auditor;
    private SchemaBuilder $builder;
    private TemplateDetector $templates;

    public function __construct()
    {
        $this->analyzer = new HtmlAnalyzer();
        $this->auditor = new Auditor();
        $this->builder = new SchemaBuilder();
        $this->templates = new TemplateDetector();
    }

    public function run(): array
    {
        $checked = 0;
        $created = 0;
        $errors = [];
        Db::install();
        Db::log(null, 'scan_start', 'Запущен анализ типовых и вручную добавленных страниц. Сканируются только заданные URL, а не весь каталог.');
        foreach ($this->targets() as $target) {
            $url = (string)$target['url'];
            if ($url === '') {
                $errors[] = $target['title'] . ': URL не задан.';
                continue;
            }
            $result = $this->scanTarget($target['kind'], $url, $target['title'], 'page_kind', '');
            $checked += $result['checked'];
            $created += $result['created'];
            $errors = array_merge($errors, $result['errors']);
        }
        foreach ($this->manualUrls() as $url) {
            $result = $this->scanManualUrl($url);
            $checked += $result['checked'];
            $created += $result['created'];
            $errors = array_merge($errors, $result['errors']);
        }
        foreach (['Organization','WebSite'] as $type) {
            $created += $this->createSiteProposal($type);
        }
        Db::log(null, 'scan_finish', 'Анализ завершён.', ['checked' => $checked, 'created' => $created, 'errors' => $errors]);
        return ['checked' => $checked, 'created' => $created, 'errors' => $errors];
    }

    public function scanManualUrl(string $url): array
    {
        $url = Security::normalizeUrl($url);
        $pre = $this->analyzer->analyzeUrl($url);
        if (empty($pre['fetch']['ok'])) {
            $message = 'Вручную добавленная страница: ' . (string)($pre['fetch']['error'] ?? 'ошибка загрузки');
            Db::log(null, 'manual_scan_error', $message, ['url' => $url, 'status' => $pre['fetch']['status'] ?? 0]);
            return ['checked' => 1, 'created' => 0, 'errors' => [$message . ' — ' . $url]];
        }
        $kind = $this->detectKind($url, (array)$pre['analysis'], (string)($pre['fetch']['html'] ?? ''));
        return $this->scanTarget($kind, $url, 'ручная страница', 'exact_url', 'manual:' . md5($url), $pre);
    }

    public function scanTarget(string $kind, string $url, string $label = '', string $urlMatchMode = 'page_kind', string $templateKeyOverride = '', ?array $preloadedResult = null): array
    {
        $checked = 1;
        $created = 0;
        $errors = [];
        $result = $preloadedResult ?: $this->analyzer->analyzeUrl($url);
        $fetch = (array)$result['fetch'];
        if (empty($fetch['ok'])) {
            $errors[] = ($label ?: $kind) . ': ' . (string)($fetch['error'] ?? 'ошибка загрузки');
            Db::log(null, 'scan_error', end($errors), ['url' => $url, 'status' => $fetch['status'] ?? 0]);
            return compact('checked', 'created', 'errors');
        }
        $analysis = (array)$result['analysis'];
        $audit = $this->auditor->audit($analysis, $kind);
        $candidates = $this->templates->detect($kind);
        $templateKey = $templateKeyOverride !== '' ? $templateKeyOverride : $this->templates->templateKey($candidates, $kind);
        $recommendation = $this->templates->recommendation($kind, $candidates);
        if ($urlMatchMode === 'exact_url') {
            $recommendation = 'Динамический вывод только для конкретного URL: ' . Security::normalizeUrl($url) . "\n" . $recommendation;
        }
        $recommendedTypes = $this->builder->recommendedTypes($kind, $analysis);
        $replacementTypes = $this->replacementTypes($kind, $audit, $analysis);
        $allTypes = array_values(array_unique(array_merge($recommendedTypes, $replacementTypes)));
        foreach ($allTypes as $schemaType) {
            $schema = $this->builder->buildForKind($kind, $analysis, $schemaType);
            if (!$schema) { continue; }
            $isReplacement = in_array($schemaType, $replacementTypes, true);
            $created += Db::upsertProposal([
                'PAGE_KIND' => $kind,
                'TEMPLATE_KEY' => $templateKey,
                'URL_MATCH_MODE' => $urlMatchMode,
                'SAMPLE_URL' => $url,
                'SCHEMA_TYPE' => $schemaType,
                'TITLE' => $this->title($kind, $schemaType, $analysis, $urlMatchMode, $isReplacement),
                'PLAIN_DESCRIPTION' => $this->description($kind, $schemaType, $analysis, $urlMatchMode, $isReplacement),
                'REASON' => $this->reason($schemaType) . ($isReplacement ? "\n\nДополнительно: существующая разметка этого типа содержит ошибки, дубли или выглядит слишком громоздкой по аудиту модуля. Такой пункт можно внести в режиме замены." : ''),
                'SEARCH_PREVIEW' => $this->preview($schemaType),
                'TARGET_LOCATION' => $recommendation,
                'INSERTION_MODE' => $isReplacement ? 'replace_existing_jsonld_buffer_injection' : 'dynamic_buffer_injection',
                'REPLACE_EXISTING' => $isReplacement ? 'Y' : 'N',
                'DETECTED_DATA' => ['fetch' => $this->safeFetch($fetch), 'analysis' => $this->safeAnalysis($analysis), 'template_candidates' => $candidates, 'manual_url' => $urlMatchMode === 'exact_url'],
                'EXISTING_SCHEMA' => $analysis['json_ld'] ?? [],
                'AUDIT_JSON' => $audit,
                'SCHEMA_JSON' => $schema,
                'CONFIDENCE' => $this->confidence($analysis, $schemaType, $isReplacement),
            ]) ? 1 : 0;
        }
        Db::skipPendingNotIn($kind, $allTypes, 'Сканер обновил список актуальных предложений для типа страницы: ' . $kind, $templateKey);
        if ($urlMatchMode === 'exact_url' && $templateKey !== '') {
            Db::skipPendingForTemplateExcept($templateKey, $kind, $allTypes, 'Ручной URL переопределён более точным типом страницы: ' . $kind);
        }
        $ai = new AiClient();
        if ($ai->isReady()) {
            $aiResult = $ai->analyze([
                'page_kind' => $kind,
                'sample_url' => $url,
                'existing_json_ld' => $analysis['json_ld'] ?? [],
                'audit_hints' => $audit,
                'html_analysis' => $this->safeAnalysis($analysis),
                'template_candidates' => $candidates,
                'rules' => [
                    'Do not invent facts.',
                    'Suggest only schema that matches visible HTML or Bitrix data available in the analysis.',
                    'The implementation must work on page types/templates or exact manual URLs, not individual full-catalog scans.',
                ],
            ]);
            if (!$aiResult['ok']) { Db::log(null, 'ai_error', 'AI-анализ не выполнен: ' . (string)($aiResult['error'] ?? 'unknown')); }
            else { Db::log(null, 'ai_ok', 'AI-анализ выполнен для ' . $url, ['summary' => $aiResult['summary'] ?? '']); }
        }
        return compact('checked', 'created', 'errors');
    }

    private function replacementTypes(string $kind, array $audit, array $analysis): array
    {
        $map = [
            'ProductGroup' => 'Product',
            'Article' => 'BlogPosting',
            'NewsArticle' => 'BlogPosting',
            'ProductCollection' => 'CollectionPage',
        ];
        $allowed = [
            'product_detail' => ['Product','ProductGroup','BreadcrumbList','FAQPage','VideoObject'],
            'product_category' => ['CollectionPage','ItemList','BreadcrumbList','FAQPage','VideoObject'],
            'blog_list' => ['Blog','CollectionPage','ItemList','BreadcrumbList','FAQPage','VideoObject'],
            'news_detail' => ['BlogPosting','Article','NewsArticle','BreadcrumbList','FAQPage','VideoObject'],
            'webpage' => ['WebPage','BreadcrumbList','FAQPage','VideoObject'],
            'collection_page' => ['CollectionPage','ItemList','BreadcrumbList','FAQPage','VideoObject'],
            'contact_page' => ['ContactPage','BreadcrumbList','FAQPage','VideoObject'],
            'about_page' => ['AboutPage','BreadcrumbList','FAQPage','VideoObject'],
            'delivery_page' => ['WebPage','BreadcrumbList','FAQPage','VideoObject'],
            'payment_page' => ['WebPage','BreadcrumbList','FAQPage','VideoObject'],
            'returns_page' => ['WebPage','BreadcrumbList','FAQPage','VideoObject'],
            'legal_page' => ['WebPage','BreadcrumbList','FAQPage','VideoObject'],
            'reviews_page' => ['WebPage','ItemList','BreadcrumbList','FAQPage','VideoObject'],
            'faq_page' => ['FAQPage','BreadcrumbList','VideoObject'],
            'search_results_page' => ['SearchResultsPage','ItemList','BreadcrumbList'],
            'brand_list' => ['CollectionPage','ItemList','BreadcrumbList'],
            'brand_detail' => ['WebPage','BreadcrumbList'],
            'service_page' => ['Service','BreadcrumbList','FAQPage','VideoObject'],
            'event_list' => ['CollectionPage','ItemList','BreadcrumbList'],
            'event_detail' => ['Event','BreadcrumbList','FAQPage','VideoObject'],
            'video_page' => ['VideoObject','WebPage','BreadcrumbList'],
            'cart_page' => ['WebPage','BreadcrumbList'],
            'checkout_page' => ['WebPage','BreadcrumbList'],
            'profile_page' => ['ProfilePage','BreadcrumbList'],
        ][$kind] ?? ['WebPage','BreadcrumbList','FAQPage','VideoObject'];
        $types = [];
        foreach ($audit as $issue) {
            if (empty($issue['replace_candidate'])) { continue; }
            $type = (string)($issue['type'] ?? '');
            if ($type === '' && (string)($issue['code'] ?? '') === 'invalid_json') {
                $types = array_merge($types, $this->builder->recommendedTypes($kind, $analysis));
                continue;
            }
            $type = $map[$type] ?? $type;
            if (in_array($type, $allowed, true)) { $types[] = $type; }
        }
        return array_values(array_unique($types));
    }

    private function createSiteProposal(string $type): int
    {
        $schema = $this->builder->siteSchema($type);
        if (!$schema) { return 0; }
        return Db::upsertProposal([
            'PAGE_KIND' => 'sitewide',
            'TEMPLATE_KEY' => 'site_template_header',
            'URL_MATCH_MODE' => 'page_kind',
            'SAMPLE_URL' => Security::normalizeUrl('/'),
            'SCHEMA_TYPE' => $type,
            'TITLE' => $type === 'WebSite' ? 'Данные сайта и поиска по сайту' : 'Данные организации',
            'PLAIN_DESCRIPTION' => $type === 'WebSite' ? 'Выводит WebSite и SearchAction на страницах сайта.' : 'Выводит Organization/LocalBusiness из настроек модуля.',
            'REASON' => 'Поисковые системы получают согласованное описание сайта и организации.',
            'SEARCH_PREVIEW' => 'Может помочь поиску понять бренд, сайт и поиск по сайту. Показ расширенного результата не гарантируется.',
            'TARGET_LOCATION' => 'Динамический вывод в <head> через main:OnEndBufferContent. При ручном переносе — header.php шаблона сайта после ShowHead().',
            'DETECTED_DATA' => ['source' => 'module_options'],
            'EXISTING_SCHEMA' => [],
            'AUDIT_JSON' => [],
            'SCHEMA_JSON' => $schema,
            'CONFIDENCE' => 0.9,
        ]) ? 1 : 0;
    }

    private function targets(): array
    {
        return [
            ['kind' => 'product_detail', 'title' => 'карточка товара', 'url' => Options::get('product_sample_url')],
            ['kind' => 'product_category', 'title' => 'категория товаров', 'url' => Options::get('category_sample_url')],
            ['kind' => 'blog_list', 'title' => 'список блога/новостей', 'url' => Options::get('blog_list_sample_url')],
            ['kind' => 'news_detail', 'title' => 'детальная страница новости/поста', 'url' => Options::get('news_detail_sample_url')],
        ];
    }

    private function manualUrls(): array
    {
        $lines = preg_split('/[\r\n]+/', Options::get('manual_urls', '')) ?: [];
        $urls = [];
        foreach ($lines as $line) {
            $url = Security::normalizeUrl(trim((string)$line));
            if ($url !== '') { $urls[] = $url; }
        }
        return array_values(array_unique($urls));
    }

    private function detectKind(string $url, array $analysis, string $html = ''): string
    {
        $normalizedUrl = Security::normalizeUrl($url);
        $path = parse_url($normalizedUrl, PHP_URL_PATH) ?: '';
        $pathLower = mb_strtolower($path);
        $text = mb_strtolower(Security::text($html, 120000));
        $title = mb_strtolower((string)(($analysis['h1'][0] ?? '') ?: ($analysis['title'] ?? '') ?: ($analysis['og_title'] ?? '')));
        $meta = mb_strtolower((string)(($analysis['meta_description'] ?? '') ?: ($analysis['og_description'] ?? '')));
        $haystack = $pathLower . ' ' . $title . ' ' . $meta . ' ' . mb_substr($text, 0, 50000);
        $existing = (array)($analysis['existing_schema_types'] ?? []);
        $items = (array)($analysis['items'] ?? []);
        $dates = (array)($analysis['dates'] ?? []);
        $reviews = (array)($analysis['reviews'] ?? []);
        $faq = (array)($analysis['faq_pairs'] ?? []);
        $videos = (array)($analysis['videos'] ?? []);
        $microProduct = (array)($analysis['microdata_product'] ?? []);
        $product = (array)($analysis['product'] ?? []);

        // 1. Сначала учитываем уже найденную семантическую разметку: это надёжнее, чем угадывание по URL.
        if ($this->hasAny($existing, ['CollectionPage','ProductCollection']) && preg_match('~/(catalog|category|shop)/~i', $path)) { return 'product_category'; }
        if (($this->hasAny($existing, ['Product','ProductGroup']) || !empty($microProduct['found'])) && $this->looksLikeProductDetail($analysis, $haystack, $path)) { return 'product_detail'; }
        if ($this->hasAny($existing, ['BlogPosting','Article','NewsArticle']) && preg_match('~/(blog|news|articles|stati|obzory)/~i', $path)) { return 'news_detail'; }
        if ($this->hasAny($existing, ['Event'])) { return 'event_detail'; }
        if ($this->hasAny($existing, ['FAQPage'])) { return 'faq_page'; }
        if ($this->hasAny($existing, ['VideoObject']) && !preg_match('~/(catalog|product|shop)/~i', $path)) { return 'video_page'; }

        // 2. Затем точные служебные и коммерческие страницы интернет-магазина.
        if (preg_match('~/(contacts?|kontakty?)/~i', $path) || preg_match('~\b(контакты|адрес|как нас найти)\b~u', $title)) { return 'contact_page'; }
        if (preg_match('~/(reviews?|otzyv|otzivy|testimonials)/~i', $path) || preg_match('~\b(отзывы|отзывы о нас|мнения клиентов)\b~u', $title)) { return 'reviews_page'; }
        if (preg_match('~/(faq|questions|vopros|voprosy|chasto-zadavaemye)~i', $path) || preg_match('~\b(faq|часто задаваемые вопросы|вопросы и ответы)\b~u', $haystack) || count($faq) >= 2) { return 'faq_page'; }
        if (preg_match('~/(delivery|shipping|dostavka|how-to-delivery)/~i', $path) || preg_match('~\b(доставка|самовывоз|курьер|транспортная компания)\b~u', $title)) { return 'delivery_page'; }
        if (preg_match('~/(payment|oplata|pay|how-to-pay)/~i', $path) || preg_match('~\b(оплата|способы оплаты|банковской картой|сч[её]т на оплату)\b~u', $title)) { return 'payment_page'; }
        if (preg_match('~/(refund|return|vozvrat|garantiya|warranty)/~i', $path) || preg_match('~\b(возврат|гарантия|обмен товара|денежных средств)\b~u', $title)) { return 'returns_page'; }
        if (preg_match('~/(privacy|personal|pdn|obrabotka-personalnykh-dannykh|policy|oferta|agreement|terms|usloviya|requisites|rekvizity)~i', $path) || preg_match('~\b(политика|персональных данных|оферта|соглашение|условия продажи|реквизиты)\b~u', $title)) { return 'legal_page'; }
        if (preg_match('~/(about|o-nas|company|kompaniya|relo?d)/~i', $path) || preg_match('~\b(о нас|о компании|история компании|миссия)\b~u', $title)) { return 'about_page'; }
        if (preg_match('~/(search|find)/~i', $path) || preg_match('~\b(поиск|результаты поиска)\b~u', $title)) { return 'search_results_page'; }
        if (preg_match('~/(cart|basket|korzina)/~i', $path)) { return 'cart_page'; }
        if (preg_match('~/(checkout|order|personal/order/make|oformlenie)/~i', $path)) { return 'checkout_page'; }
        if (preg_match('~/(personal|profile|account|cabinet|lichnyj-kabinet)/~i', $path)) { return 'profile_page'; }

        // 3. Каталог, товары, бренды/издательства.
        if (preg_match('~/(brands|publishers|izdatel|izdatelstva)/?[^/]+/?$~i', $path) && !preg_match('~/(brands|publishers|izdatel|izdatelstva)/?$~i', $path)) { return 'brand_detail'; }
        if (preg_match('~/(brands|publishers|izdatel|izdatelstva)/?$~i', $path) || preg_match('~\b(бренды|издатели|издательства|производители)\b~u', $title)) { return 'brand_list'; }
        if (preg_match('~/(catalog|product|products|shop)/~i', $path) && ($this->looksLikeProductDetail($analysis, $haystack, $path))) { return 'product_detail'; }
        if (preg_match('~/(catalog|category|shop)/~i', $path) || $this->hasAny($existing, ['CollectionPage','ProductCollection'])) { return 'product_category'; }

        // 4. Контентные разделы, статьи, события, видео, услуги.
        if (preg_match('~/(events?|webinars?|seminars?|conference|meropriyatiya|vebinary|seminary)/~i', $path) || preg_match('~\b(вебинар|семинар|конференция|мероприятие|мастер-класс)\b~u', $haystack)) {
            return !empty($dates) || preg_match('~/(events?|webinars?|seminars?)/[^/]+/?$~i', $path) ? 'event_detail' : 'event_list';
        }
        if (preg_match('~/(services?|uslugi)/~i', $path) || preg_match('~\b(услуга|услуги|сервис)\b~u', $title)) { return 'service_page'; }
        if (!empty($videos) || preg_match('~/(video|videos|youtube|rutube)/~i', $path)) { return 'video_page'; }
        if (preg_match('~/(blog|articles|stati|news|obzory)/~i', $path) && (!empty($dates) || preg_match('/<article\b/i', $html) || preg_match('~/(blog|articles|stati|news|obzory)/[^/]+/[^/]+/?$~i', $path))) { return 'news_detail'; }
        if (preg_match('~/(blog|articles|stati|news|obzory)/~i', $path)) { return 'blog_list'; }

        // 5. Если на странице много осмысленных внутренних элементов, это раздел/список, но не товар.
        if (count($items) >= 6 && !$this->looksLikeProductDetail($analysis, $haystack, $path)) { return 'collection_page'; }
        return 'webpage';
    }

    private function hasAny(array $existing, array $types): bool
    {
        foreach ($types as $type) {
            if (in_array($type, $existing, true)) { return true; }
        }
        return false;
    }

    private function looksLikeProductDetail(array $analysis, string $haystack, string $path): bool
    {
        $product = (array)($analysis['product'] ?? []);
        $items = (array)($analysis['items'] ?? []);
        $segments = substr_count(trim($path, '/'), '/');
        if (!empty($product['sku'])) { return true; }
        if (!empty($product['price']) && preg_match('~/(catalog|product|products|shop)/~i', $path) && $segments >= 3) { return true; }
        if (count($items) > 3 && preg_match('~/(catalog|category|shop)/~i', $path)) { return false; }
        if (!empty($product['price']) && preg_match('~\b(купить|добавить в корзину|в корзину|оформить заказ)\b~u', $haystack)) { return true; }
        return preg_match('~\b(артикул|sku|isbn|цена|в наличии|купить|добавить в корзину)\b~u', $haystack) === 1 && preg_match('~/(catalog|product|products|shop)/~i', $path) === 1 && $segments >= 3;
    }

    private function title(string $kind, string $type, array $analysis, string $urlMatchMode = 'page_kind', bool $replacement = false): string
    {
        $map = [
            'product_detail' => 'Карточка товара',
            'product_category' => 'Категория товаров',
            'blog_list' => 'Список блога/новостей',
            'news_detail' => 'Новость/пост',
            'sitewide' => 'Сайт',
            'webpage' => 'Обычная информационная страница',
            'collection_page' => 'Страница списка/подборки',
            'contact_page' => 'Страница контактов',
            'about_page' => 'Страница о компании',
            'delivery_page' => 'Страница доставки',
            'payment_page' => 'Страница оплаты',
            'returns_page' => 'Страница возврата/гарантии',
            'legal_page' => 'Юридическая/служебная страница',
            'reviews_page' => 'Страница отзывов',
            'faq_page' => 'FAQ / вопросы и ответы',
            'search_results_page' => 'Страница результатов поиска',
            'brand_list' => 'Список брендов/издательств',
            'brand_detail' => 'Страница бренда/издательства',
            'service_page' => 'Страница услуги',
            'event_list' => 'Список мероприятий',
            'event_detail' => 'Мероприятие',
            'video_page' => 'Видео-страница',
            'cart_page' => 'Корзина',
            'checkout_page' => 'Оформление заказа',
            'profile_page' => 'Личный кабинет/профиль',
        ];
        $name = $analysis['h1'][0] ?? $analysis['title'] ?? '';
        $prefix = ($urlMatchMode === 'exact_url' ? 'Ручная проверка: ' : '') . ($replacement ? 'Замена: ' : '');
        return $prefix . ($map[$kind] ?? $kind) . ': ' . $type . ($name ? ' — ' . mb_substr((string)$name, 0, 100) : '');
    }

    private function description(string $kind, string $type, array $analysis, string $urlMatchMode = 'page_kind', bool $replacement = false): string
    {
        if ($urlMatchMode === 'exact_url') {
            return 'Пункт подготовлен по вручную добавленному URL. После подтверждения модуль выводит этот тип разметки только на этой конкретной странице.' . ($replacement ? ' Режим замены удаляет из HTML старые JSON-LD-блоки этого же типа перед вставкой корректного блока модуля.' : '');
        }
        return 'Пункт подготовлен по одному реальному URL-примеру. После подтверждения модуль выводит этот тип разметки динамически для всех страниц такого типа, используя данные текущей HTML-страницы, а не данные одного товара.' . ($replacement ? ' Режим замены удаляет из HTML старые JSON-LD-блоки этого же типа перед вставкой корректного блока модуля.' : '');
    }

    private function reason(string $type): string
    {
        return [
            'Product' => 'Описывает товар: название, изображение, цену, валюту, наличие, бренд и артикул — только если данные найдены на странице.',
            'ProductGroup' => 'Описывает группу вариантов товара, если шаблон и данные позволяют связать варианты.',
            'BreadcrumbList' => 'Описывает видимую навигационную цепочку страницы.',
            'CollectionPage' => 'Описывает страницу раздела/списка как подборку материалов или товаров.',
            'ItemList' => 'Описывает элементы списка в категории или блоге.',
            'Blog' => 'Описывает страницу блога и список публикаций.',
            'BlogPosting' => 'Описывает новость/пост как публикацию с заголовком, описанием, датой и изображением при наличии.',
            'FAQPage' => 'Добавляется только если вопросы и ответы реально найдены в HTML.',
            'VideoObject' => 'Добавляется только если видео реально найдено в HTML.',
            'Organization' => 'Описывает организацию по настройкам модуля.',
            'WebSite' => 'Описывает сайт и действие поиска по сайту.',
            'ContactPage' => 'Описывает страницу контактов как специальный тип WebPage.',
            'AboutPage' => 'Описывает страницу «О компании / О нас» как специальный тип WebPage.',
            'SearchResultsPage' => 'Описывает страницу результатов поиска.',
            'Service' => 'Описывает услугу только если страница действительно посвящена услуге.',
            'Event' => 'Описывает мероприятие только если на странице найдены его название и дата.',
            'Article' => 'Описывает информационную статью.',
            'NewsArticle' => 'Описывает новостной материал.',
            'ProfilePage' => 'Описывает страницу профиля/личного кабинета как тип WebPage.',
        ][$type] ?? 'Помогает поисковым системам понять тип страницы.';
    }

    private function preview(string $type): string
    {
        return [
            'Product' => 'Цена, наличие и изображение могут использоваться поисковыми системами в товарных представлениях, если данные соответствуют правилам.',
            'ContactPage' => 'Помогает поиску понять, что это именно страница контактов организации.',
            'AboutPage' => 'Помогает поиску отличить страницу о компании от обычной информационной страницы.',
            'SearchResultsPage' => 'Помогает явно обозначить страницу результатов поиска.',
            'Service' => 'Помогает обозначить страницу услуги, если услуга реально описана на странице.',
            'Event' => 'Может помочь поиску распознать мероприятие, если дата и описание достоверно извлечены.',
            'BreadcrumbList' => 'Вместо длинного URL поисковая система может использовать цепочку разделов.',
            'FAQPage' => 'Вопросы и ответы могут быть использованы поиском, но показ не гарантируется.',
            'VideoObject' => 'Поиск может лучше понять встроенное видео.',
        ][$type] ?? 'Разметка помогает интерпретации страницы. Показ расширенного сниппета не гарантируется.';
    }

    private function confidence(array $analysis, string $type, bool $replacement = false): float
    {
        if ($replacement) { return 0.72; }
        $existing = (array)($analysis['existing_schema_types'] ?? ($analysis['json_ld_types'] ?? []));
        if (in_array($type, $existing, true)) { return 0.55; }
        if ($type === 'Product' && empty($analysis['product']['price'])) { return 0.7; }
        return 0.88;
    }

    private function safeFetch(array $fetch): array
    {
        return ['url' => $fetch['url'] ?? '', 'ok' => !empty($fetch['ok']), 'status' => (int)($fetch['status'] ?? 0), 'error' => $fetch['error'] ?? '', 'html_length' => strlen((string)($fetch['html'] ?? ''))];
    }

    private function safeAnalysis(array $analysis): array
    {
        unset($analysis['raw_html']);
        return $analysis;
    }
}
