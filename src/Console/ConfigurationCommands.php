<?php

declare(strict_types=1);

namespace FormGuard\Console;

use FormGuard\Configuration;

/** Developer CLI only. Configuration files are trusted executable PHP, never visitor uploads. */
final class ConfigurationCommands
{
    /** @param list<string> $arguments */
    public static function run(string $command, array $arguments): int
    {
        $locale = 'en';
        try {
            $args = [];
            for ($i = 0; $i < count($arguments); $i++) {
                if ($arguments[$i] === '--interactive') {
                    if (isset($args['interactive'])) {
                        throw new \InvalidArgumentException('duplicate');
                    }
                    $args['interactive'] = true;
                    continue;
                }
                if (!preg_match('/^--(profile|locale|scope|output|config)(?:=(.*))?$/sD', $arguments[$i], $match)) {
                    throw new \InvalidArgumentException('argument');
                }
                $value = $match[2] ?? ($arguments[++$i] ?? '');
                if ($value === '' || str_starts_with($value, '--') || isset($args[$match[1]])) {
                    throw new \InvalidArgumentException('argument');
                }
                $args[$match[1]] = $value;
                if ($match[1] === 'locale') {
                    $locale = $value;
                }
            }
            if (!in_array($locale, ['en', 'fr'], true)) {
                throw new \InvalidArgumentException('locale');
            }
            $allowed = $command === 'init' ? ['profile', 'locale', 'scope', 'output', 'interactive'] : ['config', 'locale'];
            if (array_diff(array_keys($args), $allowed)) {
                throw new \InvalidArgumentException('argument');
            }
            if ($command === 'init') {
                if (isset($args['interactive'])) {
                    $prompt = static function (string $english, string $french, string $default) use ($locale): string {
                        fwrite(STDERR, ($locale === 'fr' ? $french : $english) . ' [' . $default . ']: ');
                        $line = fgets(STDIN);
                        if ($line === false) {
                            throw new \InvalidArgumentException('input');
                        }
                        return trim($line) ?: $default;
                    };
                    $args['profile'] = $prompt('Profile (tolerant/standard/reinforced)', 'Profil (tolerant/standard/reinforced)', $args['profile'] ?? 'standard');
                    $args['scope'] = $prompt('Application scope', 'Périmètre de l’application', $args['scope'] ?? 'contact');
                    $args['output'] = $prompt('New configuration file', 'Nouveau fichier de configuration', $args['output'] ?? 'form-guard.config.php');
                }
                $config = ['profile' => $args['profile'] ?? 'standard', 'scope' => $args['scope'] ?? 'contact',
                    'forms' => [], 'limits' => [], 'browser' => [], 'storage' => [], 'language' => ['locale' => $locale], 'rules' => []];
                $resolved = Configuration::fromArray($config);
                $path = $args['output'] ?? 'form-guard.config.php';
                if (!str_ends_with($path, '.php') || str_contains($path, "\0") || !is_dir(dirname($path))) {
                    throw new \InvalidArgumentException('output');
                }
                $comment = $locale === 'fr'
                    ? "// Configuration de confiance. Hors racine HTTP ; ne jamais charger un fichier visiteur.\n// Groupes : formulaires, tentatives, navigateur, stockage, langue, budget des règles.\n// Les groupes vides utilisent le profil ; voir les guides pour personnaliser.\n"
                    : "// Trusted configuration. Keep outside the web root; never load visitor uploads.\n// Groups: forms, attempts, browser, storage, language, additional rule budget.\n// Empty groups inherit the profile; see guides for overrides.\n";
                $contents = "<?php\ndeclare(strict_types=1);\n\n" . $comment . 'return ' . var_export($config, true) . ";\n";
                $handle = @fopen($path, 'x+b');
                if (!$handle) {
                    throw new \InvalidArgumentException('existing_or_unwritable_output');
                }
                $complete = false;
                try {
                    if (!@chmod($path, 0600)) {
                        throw new \RuntimeException('permissions');
                    }
                    $offset = 0;
                    while ($offset < strlen($contents)) {
                        $count = fwrite($handle, substr($contents, $offset));
                        if ($count === false || $count === 0) {
                            throw new \RuntimeException('write');
                        }
                        $offset += $count;
                    }
                    if (!fflush($handle)) {
                        throw new \RuntimeException('flush');
                    }
                    $complete = true;
                } finally {
                    fclose($handle);
                    if (!$complete) {
                        @unlink($path);
                    }
                }
                echo json_encode(['command' => 'init', 'created' => true, 'profile' => $resolved->level->value, 'locale' => $locale], JSON_THROW_ON_ERROR) . "\n";
                return 0;
            }
            if (!isset($args['config'])) {
                throw new \InvalidArgumentException('config_required');
            }
            $config = self::load($args['config']);
            $result = $command === 'config:show' ? $config->describe() : ['valid' => true, 'profile' => $config->level->value, 'customized' => $config->customized];
            echo json_encode(['command' => $command] + $result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
            return 0;
        } catch (\Throwable) {
            fwrite(STDERR, $locale === 'fr'
                ? "Configuration refusée. Vérifiez les arguments, le fichier PHP de confiance, le profil et les options ; aucun fichier existant n’est remplacé.\n"
                : "Configuration rejected. Check arguments, trusted PHP file, profile and options; existing files are never replaced.\n");
            return 2;
        }
    }

    public static function load(string $path): Configuration
    {
        if (!str_ends_with($path, '.php') || !is_file($path) || is_link($path) || filesize($path) > 65536) {
            throw new \InvalidArgumentException('Invalid trusted configuration file.');
        }
        // This is intentional code execution, like an application's own PHP config.
        $absolute = realpath($path);
        if ($absolute === false) {
            throw new \InvalidArgumentException('Invalid trusted configuration file.');
        }
        ob_start();
        try {
            $input = (static fn (string $file): mixed => require $file)($absolute);
        } catch (\Throwable $error) {
            throw new \InvalidArgumentException('Unable to load trusted configuration.', previous: $error);
        } finally {
            ob_end_clean();
        }
        if ($input instanceof Configuration) {
            return $input;
        }
        if (!is_array($input)) {
            throw new \InvalidArgumentException('Configuration must return an array.');
        }
        return Configuration::fromArray($input);
    }
}
