<?php

declare(strict_types=1);

namespace App\Service;

/**
 * User themes in data/themes/<slug>/. A theme is CSS only: theme.css (required),
 * map.css (optional, map pages only) and its own fonts or images. Releases never
 * touch data/, so themes survive every update.
 *
 * Files are served by ThemeController, never directly: data/ stays closed.
 */
final class Themes
{
    /** Theme API this release understands; themes declare theirs with "sv-theme-api: N". */
    public const API = 1;

    private const SLUG = '/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/';

    /** File types a theme may contain, with the type they are served as. */
    private const TYPES = [
        'css' => 'text/css; charset=utf-8',
        'woff2' => 'font/woff2',
        'png' => 'image/png',
    ];

    public function __construct(private string $dir)
    {
    }

    public static function validSlug(string $slug): bool
    {
        return preg_match(self::SLUG, $slug) === 1;
    }

    /**
     * All installed themes, sorted by name.
     *
     * @return array<string, array{slug: string, name: string, author: string, version: string, api: ?int, compatible: bool}>
     */
    public function all(): array
    {
        $themes = [];
        foreach ((is_dir($this->dir) ? scandir($this->dir) : false) ?: [] as $slug) {
            $theme = $this->find($slug);
            if ($theme !== null) {
                $themes[$slug] = $theme;
            }
        }
        uasort($themes, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        return $themes;
    }

    /**
     * @return array{slug: string, name: string, author: string, version: string, api: ?int, compatible: bool}|null
     *         null when the folder is missing, has no theme.css or an invalid name
     */
    public function find(string $slug): ?array
    {
        if (!self::validSlug($slug) || !is_file("{$this->dir}/$slug/theme.css")) {
            return null;
        }
        $meta = self::header((string) file_get_contents("{$this->dir}/$slug/theme.css", false, null, 0, 4096));
        $api = isset($meta['sv-theme-api']) && ctype_digit($meta['sv-theme-api']) ? (int) $meta['sv-theme-api'] : null;
        return [
            'slug' => $slug,
            'name' => $meta['name'] ?? $slug,
            'author' => $meta['author'] ?? '',
            'version' => $meta['version'] ?? '',
            'api' => $api,
            'compatible' => $api === self::API,
        ];
    }

    public function hasFile(string $slug, string $file): bool
    {
        return $this->path($slug, $file) !== null;
    }

    /** Changes whenever the file changes; appended to URLs to bypass browser caches. */
    public function fileVersion(string $slug, string $file): string
    {
        $path = $this->path($slug, $file);
        return $path === null ? '0' : (string) filemtime($path);
    }

    /**
     * Absolute path of a theme file, or null if it must not be served: unknown theme,
     * file type not allowed, hidden file, or a path (also via symlink) leaving the theme folder.
     */
    public function path(string $slug, string $file): ?string
    {
        if ($this->find($slug) === null || self::type($file) === null) {
            return null;
        }
        // Lowercase names, at most one sub-folder, no dot files and no "..".
        if (!preg_match('#^(?:[a-z0-9][a-z0-9_-]*/)?[a-z0-9][a-z0-9_.-]*$#', $file) || str_contains($file, '..')) {
            return null;
        }
        $base = realpath("{$this->dir}/$slug");
        $path = realpath("{$this->dir}/$slug/$file");
        if ($base === false || $path === false || !is_file($path) || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $path;
    }

    public static function type(string $file): ?string
    {
        return self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
    }

    /**
     * "Key: value" lines from the first comment of theme.css.
     *
     * @return array<string, string> lowercase keys
     */
    private static function header(string $css): array
    {
        if (!preg_match('#^\s*/\*(.*?)\*/#s', $css, $m)) {
            return [];
        }
        $meta = [];
        foreach (preg_split('/\R/', $m[1]) ?: [] as $line) {
            if (preg_match('/^[\s*]*([a-z][a-z0-9-]*)\s*:\s*(.+?)\s*$/i', $line, $kv)) {
                $meta[strtolower($kv[1])] ??= mb_substr($kv[2], 0, 100);
            }
        }
        return $meta;
    }
}
