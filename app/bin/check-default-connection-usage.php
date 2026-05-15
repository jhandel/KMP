#!/usr/bin/env php
<?php
declare(strict_types=1);

$appRoot = dirname(__DIR__);
$allowListPath = $appRoot . '/config/static-analysis/default-connection-allowlist.php';
$scanRoots = [
    $appRoot . '/src',
    $appRoot . '/plugins',
];

if (!is_file($allowListPath)) {
    fwrite(STDERR, "Missing allow-list: {$allowListPath}\n");
    exit(1);
}

/** @var array<string, array{category: string, justification: string}> $allowList */
$allowList = require $allowListPath;
$validCategories = ['platform', 'health', 'legacy'];
$allowListErrors = [];
foreach ($allowList as $path => $meta) {
    if (!isset($meta['category'], $meta['justification'])) {
        $allowListErrors[] = "Allow-list entry '{$path}' must define category and justification.";
        continue;
    }
    if (!in_array($meta['category'], $validCategories, true)) {
        $allowListErrors[] = "Allow-list entry '{$path}' has invalid category '{$meta['category']}'.";
    }
    if (trim($meta['justification']) === '') {
        $allowListErrors[] = "Allow-list entry '{$path}' must include a non-empty justification.";
    }
}

if ($allowListErrors !== []) {
    fwrite(STDERR, "Invalid default-connection allow-list configuration:\n");
    foreach ($allowListErrors as $error) {
        fwrite(STDERR, "  - {$error}\n");
    }
    exit(1);
}

$pattern = '/ConnectionManager::get\s*\(\s*[\'"]default[\'"]\s*\)/';
$matches = [];

foreach ($scanRoots as $root) {
    if (!is_dir($root)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $absolutePath = (string)$file->getPathname();
        $relativePath = ltrim(str_replace($appRoot, '', $absolutePath), DIRECTORY_SEPARATOR);
        $lines = file($absolutePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            continue;
        }

        foreach ($lines as $lineNumber => $lineText) {
            if (!preg_match($pattern, $lineText)) {
                continue;
            }

            $matches[] = [
                'path' => $relativePath,
                'line' => $lineNumber + 1,
                'code' => trim($lineText),
            ];
        }
    }
}

$violations = [];
foreach ($matches as $match) {
    if (!array_key_exists($match['path'], $allowList)) {
        $violations[] = $match;
    }
}

$staleAllowListEntries = [];
foreach (array_keys($allowList) as $allowListedPath) {
    $found = false;
    foreach ($matches as $match) {
        if ($match['path'] === $allowListedPath) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        $staleAllowListEntries[] = $allowListedPath;
    }
}

if ($violations === []) {
    echo "✅ Default datasource guardrail passed.\n";
    echo "Checked " . count($matches) . " ConnectionManager::get('default') usage(s).\n";
    if ($staleAllowListEntries !== []) {
        echo "ℹ️  Stale allow-list entries (safe to remove):\n";
        foreach ($staleAllowListEntries as $stalePath) {
            echo "  - {$stalePath}\n";
        }
    }
    exit(0);
}

fwrite(STDERR, "❌ Disallowed ConnectionManager::get('default') usage detected:\n");
foreach ($violations as $violation) {
    fwrite(STDERR, "  - {$violation['path']}:{$violation['line']} {$violation['code']}\n");
}
fwrite(STDERR, "\nAdd intentional usages to config/static-analysis/default-connection-allowlist.php");
fwrite(STDERR, " with category (platform|health|legacy) and a justification.\n");
exit(1);
