<?php
namespace Custom\SmartSchema;

class TemplateDetector
{
    public function detect(string $kind): array
    {
        $patterns = $this->patterns($kind);
        $roots = [
            $_SERVER['DOCUMENT_ROOT'] . '/local/templates',
            $_SERVER['DOCUMENT_ROOT'] . '/bitrix/templates',
        ];
        $found = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) { continue; }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            $checked = 0;
            foreach ($iterator as $file) {
                if ($checked++ > 3000) { break; }
                if (!$file->isFile()) { continue; }
                $path = (string)$file->getPathname();
                if (!preg_match('/\.(php|template)$/i', $path)) { continue; }
                if ($file->getSize() > 1024 * 1024) { continue; }
                $lower = mb_strtolower(str_replace('\\', '/', $path));
                $score = 0;
                foreach ($patterns as $needle => $weight) {
                    if (strpos($lower, $needle) !== false) { $score += $weight; }
                }
                if ($score <= 0) {
                    $snippet = @file_get_contents($path, false, null, 0, 200000) ?: '';
                    $snippetLower = mb_strtolower($snippet);
                    foreach ($patterns as $needle => $weight) {
                        if (strpos($snippetLower, $needle) !== false) { $score += $weight; }
                    }
                }
                if ($score > 0) {
                    $found[] = ['path' => $this->relative($path), 'score' => $score];
                }
            }
        }
        usort($found, static fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($found, 0, 20);
    }

    public function templateKey(array $candidates, string $kind): string
    {
        if (!$candidates) { return 'auto:' . $kind; }
        return (string)$candidates[0]['path'];
    }

    public function recommendation(string $kind, array $candidates): string
    {
        $common = "Модуль уже умеет выводить подтверждённую разметку динамически через событие main:OnEndBufferContent, поэтому руками править 10 000 товаров не требуется. Если нужно перенести вывод прямо в шаблон, безопасная точка — component_epilog.php соответствующего компонента, где уже доступен результат компонента.";
        if (!$candidates) {
            return $common . " Кандидаты файлов шаблона на этом окружении не найдены; укажите URL-пример и проверьте, что шаблон сайта лежит в /local/templates или /bitrix/templates.";
        }
        $lines = array_map(static fn($c) => '- ' . $c['path'] . ' (оценка совпадения: ' . $c['score'] . ')', array_slice($candidates, 0, 8));
        return $common . "\n\nНайденные кандидаты:\n" . implode("\n", $lines);
    }

    private function patterns(string $kind): array
    {
        if ($kind === 'product_detail') {
            return ['catalog.element' => 10, '/catalog/' => 2, '/element' => 3, 'detail.php' => 4, 'aspro' => 1, 'sku' => 1, 'price' => 1];
        }
        if ($kind === 'product_category') {
            return ['catalog.section' => 10, 'catalog.smart.filter' => 3, '/section' => 4, 'section.php' => 4, 'aspro' => 1];
        }
        if ($kind === 'blog_list') {
            return ['news.list' => 10, '/blog' => 5, 'list.php' => 4, 'blog' => 2, 'aspro' => 1];
        }
        if ($kind === 'news_detail') {
            return ['news.detail' => 10, '/news' => 4, 'detail.php' => 4, 'article' => 2, 'blogposting' => 2, 'aspro' => 1];
        }
        return [];
    }

    private function relative(string $path): string
    {
        $doc = rtrim(str_replace('\\', '/', (string)$_SERVER['DOCUMENT_ROOT']), '/');
        $path = str_replace('\\', '/', $path);
        return strpos($path, $doc) === 0 ? substr($path, strlen($doc)) : $path;
    }
}
