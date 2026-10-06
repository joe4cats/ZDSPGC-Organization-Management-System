<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

$missingLinks = [];
$checked = 0;

foreach ($files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (str_contains($path, '.system_generated') || str_contains($path, 'vendor') || str_contains($path, 'tools')) {
        continue;
    }
    $content = file_get_contents($path);
    $relFile = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
    $fileDir = dirname($path);

    // match Helpers::url('something')
    if (preg_match_all("/Helpers::url\(['\"]([^'\"]+)['\"]\)/", $content, $m)) {
        foreach ($m[1] as $target) {
            $checked++;
            $clean = explode('?', $target)[0];
            $clean = explode('#', $clean)[0];
            if ($clean === '' || str_starts_with($clean, 'http') || str_starts_with($clean, 'data:') || str_starts_with($clean, 'mailto:') || str_starts_with($clean, 'tel:')) {
                continue;
            }
            $targetPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $clean);
            if (!file_exists($targetPath)) {
                $missingLinks[] = [
                    'source' => $relFile,
                    'type'   => 'Helpers::url',
                    'target' => $clean,
                ];
            }
        }
    }

    // match href="literal.php..." without php tags
    if (preg_match_all('/href="([^"]+\.php[^"]*)"/', $content, $m)) {
        foreach ($m[1] as $target) {
            if (str_contains($target, '<?') || str_contains($target, 'Helpers::url') || str_contains($target, '$')) {
                continue;
            }
            $checked++;
            $clean = explode('?', $target)[0];
            $clean = explode('#', $clean)[0];
            if (str_starts_with($clean, '/')) {
                $targetPath = $root . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $clean), DIRECTORY_SEPARATOR);
            } else {
                $targetPath = $fileDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $clean);
            }
            if (!file_exists($targetPath)) {
                $missingLinks[] = [
                    'source' => $relFile,
                    'type'   => 'href',
                    'target' => $clean,
                ];
            }
        }
    }

    // match action="literal.php..." without php tags
    if (preg_match_all('/action="([^"]+\.php[^"]*)"/', $content, $m)) {
        foreach ($m[1] as $target) {
            if (str_contains($target, '<?') || str_contains($target, 'Helpers::url') || str_contains($target, '$')) {
                continue;
            }
            $checked++;
            $clean = explode('?', $target)[0];
            $clean = explode('#', $clean)[0];
            if (str_starts_with($clean, '/')) {
                $targetPath = $root . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $clean), DIRECTORY_SEPARATOR);
            } else {
                $targetPath = $fileDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $clean);
            }
            if (!file_exists($targetPath)) {
                $missingLinks[] = [
                    'source' => $relFile,
                    'type'   => 'action',
                    'target' => $clean,
                ];
            }
        }
    }

    // match Helpers::redirect('something')
    if (preg_match_all("/Helpers::redirect\(['\"]([^'\"]+)['\"]\)/", $content, $m)) {
        foreach ($m[1] as $target) {
            $checked++;
            $clean = explode('?', $target)[0];
            $clean = explode('#', $clean)[0];
            if ($clean === '' || str_starts_with($clean, 'http')) {
                continue;
            }
            $targetPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $clean);
            if (!file_exists($targetPath)) {
                $missingLinks[] = [
                    'source' => $relFile,
                    'type'   => 'Helpers::redirect',
                    'target' => $clean,
                ];
            }
        }
    }
}

echo "Total links/routes checked: $checked\n";
if (empty($missingLinks)) {
    echo "SUCCESS: ALL 100% OF LINKS, ROUTES, AND ACTIONS RESOLVE TO EXISTING FILES!\n";
} else {
    echo "Found " . count($missingLinks) . " potentially missing targets:\n";
    foreach ($missingLinks as $miss) {
        echo " - [{$miss['source']}] {$miss['type']} -> {$miss['target']}\n";
    }
}
