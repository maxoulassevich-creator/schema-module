<?php
namespace Custom\SmartSchema;

class Verifier
{
    private HtmlAnalyzer $analyzer;

    public function __construct()
    {
        $this->analyzer = new HtmlAnalyzer();
    }

    public function verifyProposal(int $proposalId): array
    {
        $proposal = Db::getProposal($proposalId);
        if (!$proposal) {
            return ['ok' => false, 'message' => 'Пункт не найден.', 'data' => []];
        }

        $url = (string)($proposal['SAMPLE_URL'] ?? '');
        if ($url === '') { $url = Security::normalizeUrl('/'); }

        // Важно: сразу после нажатия «Внести/Заменить своей» обычный URL может отдать
        // старую HTML-версию из кеша. Поэтому проверяем URL с одноразовым параметром.
        $verificationUrl = $this->cacheBustUrl($url, $proposalId);
        $fetch = $this->analyzer->fetchUrl($verificationUrl);
        if (empty($fetch['ok'])) {
            $message = 'Проверка не выполнена: страница не загрузилась. ' . (string)($fetch['error'] ?? '');
            Db::setVerification($proposalId, false, $message, ['url' => $url, 'verification_url' => $verificationUrl, 'fetch' => $this->safeFetch($fetch)]);
            return ['ok' => false, 'message' => $message, 'data' => ['fetch' => $this->safeFetch($fetch)]];
        }

        $html = (string)$fetch['html'];
        $analysis = $this->analyzer->analyzeHtml($html, $url);
        $schemaType = (string)($proposal['SCHEMA_TYPE'] ?? '');
        $replace = ((string)($proposal['REPLACE_EXISTING'] ?? 'N') === 'Y');

        $customCount = $this->countCustomScripts($html, $schemaType, $proposalId);
        $marker = $customCount > 0 || $this->hasModuleMarker($html, $proposalId, $schemaType);
        $typeFound = $customCount > 0 || $this->hasEquivalentType($schemaType, (array)($analysis['json_ld_types'] ?? []));
        $oldCount = $this->countNonCustomScripts($html, $schemaType);
        $oldMicrodataCount = $this->countEquivalentMicrodata($schemaType, (array)($analysis['microdata_type_counts'] ?? []));
        $disabledMicrodataCount = $this->countDisabledMicrodata($html, $schemaType);

        $ok = $marker && $typeFound && (!$replace || ($oldCount === 0 && $oldMicrodataCount === 0));

        if ($ok) {
            $message = 'Проверка пройдена: модуль реально вывел подтверждённую JSON-LD-разметку на странице-примере.';
        } elseif ($marker && $typeFound && $replace && ($oldCount > 0 || $oldMicrodataCount > 0)) {
            $message = 'Проверка не пройдена полностью: JSON-LD-блок модуля найден, но старая разметка того же типа ещё осталась на странице. JSON-LD вне модуля: ' . $oldCount . ', microdata: ' . $oldMicrodataCount . '. Очистите кеш Битрикса/композитный кеш и проверьте, не выводится ли старая microdata после события OnEndBufferContent.';
        } elseif (!$marker && $typeFound) {
            $message = 'Проверка не пройдена: разметка такого типа на странице есть, но не найден служебный маркер модуля custom.smart_schema. Значит, эту разметку вывел шаблон сайта или другой код, а не модуль.';
        } else {
            $message = 'Проверка не пройдена: в свежем HTML страницы-примера не найден JSON-LD-блок модуля для этого пункта. Проверьте кеш страницы, включён ли вывод разметки и подходит ли URL этому типу страницы.';
        }

        $data = [
            'url' => $url,
            'verification_url' => $verificationUrl,
            'schema_type' => $schemaType,
            'module_marker_found' => $marker,
            'schema_type_found' => $typeFound,
            'custom_schema_count' => $customCount,
            'non_custom_same_type_count' => $oldCount,
            'old_microdata_same_type_count' => $oldMicrodataCount,
            'disabled_microdata_same_type_count' => $disabledMicrodataCount,
            'replace_existing' => $replace ? 'Y' : 'N',
            'json_ld_types' => $analysis['json_ld_types'] ?? [],
            'microdata_types' => $analysis['microdata_types'] ?? [],
            'status' => (int)($fetch['status'] ?? 0),
            'html_length' => strlen($html),
        ];
        Db::setVerification($proposalId, $ok, $message, $data);
        return ['ok' => $ok, 'message' => $message, 'data' => $data];
    }

    private function cacheBustUrl(string $url, int $proposalId): string
    {
        $url = Security::normalizeUrl($url);
        if ($url === '') { return $url; }
        $sep = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $sep . '_smart_schema_verify=Y&_smart_schema_proposal=' . $proposalId . '&_smart_schema_ts=' . time();
    }

    private function safeFetch(array $fetch): array
    {
        return [
            'url' => $fetch['url'] ?? '',
            'ok' => !empty($fetch['ok']),
            'status' => (int)($fetch['status'] ?? 0),
            'error' => $fetch['error'] ?? '',
            'html_length' => strlen((string)($fetch['html'] ?? '')),
        ];
    }

    private function hasModuleMarker(string $html, int $proposalId, string $type): bool
    {
        if (!preg_match_all('/<script\b([^>]*)type=["\']application\/ld\+json["\']([^>]*)>/isu', $html, $matches, PREG_SET_ORDER)) {
            return false;
        }
        foreach ($matches as $match) {
            $attrs = (string)$match[1] . ' ' . (string)$match[2];
            if (stripos($attrs, 'custom-smart-schema') === false && stripos($attrs, 'data-smart-schema-module') === false) {
                continue;
            }
            if ($proposalId > 0 && preg_match('/data-smart-schema-proposal-id=["\']' . preg_quote((string)$proposalId, '/') . '["\']/isu', $attrs)) {
                return true;
            }
            if ($type !== '' && preg_match('/data-smart-schema-type=["\']' . preg_quote($type, '/') . '["\']/isu', $attrs)) {
                return true;
            }
            // Если служебный класс есть, но id/type не совпали, всё равно это блок нашего модуля.
            // Точный тип дополнительно проверяется через декодирование JSON-LD в countCustomScripts().
            if (stripos($attrs, 'custom-smart-schema') !== false) {
                return true;
            }
        }
        return false;
    }

    private function countEquivalentMicrodata(string $type, array $microdataTypeCounts): int
    {
        $count = 0;
        foreach ($microdataTypeCounts as $schemaType => $schemaCount) {
            if ($this->hasEquivalentType($type, [(string)$schemaType])) {
                $count += (int)$schemaCount;
            }
        }
        return $count;
    }

    private function countDisabledMicrodata(string $html, string $type): int
    {
        if ($type === '' || stripos($html, 'data-smart-schema-disabled-microdata') === false) { return 0; }
        $count = 0;
        if (preg_match_all('/data-smart-schema-disabled-microdata=["\']([^"\']+)["\']/isu', $html, $m)) {
            foreach ($m[1] as $disabledType) {
                if ($this->hasEquivalentType($type, [html_entity_decode((string)$disabledType, ENT_QUOTES | ENT_HTML5, 'UTF-8')])) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function countCustomScripts(string $html, string $type, int $proposalId = 0): int
    {
        return $this->countScripts($html, $type, true, $proposalId);
    }

    private function countNonCustomScripts(string $html, string $type): int
    {
        return $this->countScripts($html, $type, false, 0);
    }

    private function countScripts(string $html, string $type, bool $custom, int $proposalId = 0): int
    {
        $count = 0;
        if (!preg_match_all('/<script\b([^>]*)type=["\']application\/ld\+json["\']([^>]*)>(.*?)<\/script>/isu', $html, $m, PREG_SET_ORDER)) {
            return 0;
        }
        foreach ($m as $script) {
            $attrs = $script[1] . ' ' . $script[2];
            $isCustom = stripos($attrs, 'custom-smart-schema') !== false || stripos($attrs, 'data-smart-schema-module') !== false;
            if ($isCustom !== $custom) { continue; }
            if ($custom && $proposalId > 0 && preg_match('/data-smart-schema-proposal-id=["\'](\d+)["\']/isu', $attrs, $pid) && (int)$pid[1] !== $proposalId) {
                // Не отбрасываем полностью: на случай повторного анализа ID может измениться,
                // но тип должен совпасть по JSON-LD ниже.
            }
            $decoded = json_decode(html_entity_decode(trim($script[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if (!is_array($decoded)) { continue; }
            $types = (new HtmlAnalyzer())->schemaTypes([$decoded]);
            if ($this->hasEquivalentType($type, $types)) { $count++; }
        }
        return $count;
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
}
