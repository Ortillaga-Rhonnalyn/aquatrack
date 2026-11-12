<?php
declare(strict_types=1);
/** Reads a local .env file without external packages. Never serve this directory publicly. */
function aquatrackEnv(string $key, ?string $default = null): ?string {
    static $vars = null;
    if ($vars === null) {
        $vars = [];
        $file = dirname(__DIR__) . '/.env';
        if (is_file($file) && is_readable($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                [$name,$value] = explode('=', $line, 2);
                $name = trim($name);
                if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) continue;
                $value = trim($value);
                if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[strlen($value)-1] === $value[0]) {
                    $value = substr($value,1,-1);
                }
                $vars[$name] = $value;
            }
        }
    }
    $external = getenv($key);
    return $external !== false ? $external : ($vars[$key] ?? $default);
}
