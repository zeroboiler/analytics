<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsServiceProvider;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Exceptions\AnalyticsException;
use ZeroBoiler\Analytics\Exceptions\AnalyticsRuntimeException;
use ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException;

// ─── Phase 47 Production Readiness Audit ──────────────────────────────────

describe('Phase 47 — PHPStan Config Parity', function () {
    it('phpstan.neon.dist has checkMissingIterableValueType: false', function () {
        $dist = file_get_contents(__DIR__ . '/../phpstan.neon.dist');
        expect($dist)->toContain('checkMissingIterableValueType: false');
    });

    it('phpstan.neon.dist has checkUnusedParameters: true', function () {
        $dist = file_get_contents(__DIR__ . '/../phpstan.neon.dist');
        expect($dist)->toContain('checkUnusedParameters: true');
    });

    it('phpstan.neon.dist has checkUninitializedProperties: true', function () {
        $dist = file_get_contents(__DIR__ . '/../phpstan.neon.dist');
        expect($dist)->toContain('checkUninitializedProperties: true');
    });

    it('phpstan.neon.dist has treatPhpDocTypesAsCertain: false', function () {
        $dist = file_get_contents(__DIR__ . '/../phpstan.neon.dist');
        expect($dist)->toContain('treatPhpDocTypesAsCertain: false');
    });

    it('phpstan.neon.dist has reportUnmatchedIgnoredErrors: false', function () {
        $dist = file_get_contents(__DIR__ . '/../phpstan.neon.dist');
        expect($dist)->toContain('reportUnmatchedIgnoredErrors: false');
    });

    it('phpstan.neon.dist has checkGenericClassInNonGenericObjectType: true', function () {
        $dist = file_get_contents(__DIR__ . '/../phpstan.neon.dist');
        expect($dist)->toContain('checkGenericClassInNonGenericObjectType: true');
    });

    it('phpstan.neon.dist level is 9', function () {
        $dist = file_get_contents(__DIR__ . '/../phpstan.neon.dist');
        expect($dist)->toContain('level: 9');
    });

    it('phpstan.neon has checkMissingIterableValueType: false', function () {
        $neon = file_get_contents(__DIR__ . '/../phpstan.neon');
        expect($neon)->toContain('checkMissingIterableValueType(false)');
    });
});

describe('Phase 47 — Project Structure Files', function () {
    it('.editorconfig exists', function () {
        expect(file_exists(__DIR__ . '/../.editorconfig'))->toBeTrue();
    });

    it('.editorconfig has PHP section', function () {
        $content = file_get_contents(__DIR__ . '/../.editorconfig');
        expect($content)->toContain('[*.php]');
    });

    it('.editorconfig enforces LF line endings', function () {
        $content = file_get_contents(__DIR__ . '/../.editorconfig');
        expect($content)->toContain('end_of_line = lf');
    });

    it('.editorconfig enforces 4-space indent', function () {
        $content = file_get_contents(__DIR__ . '/../.editorconfig');
        expect($content)->toContain('indent_size = 4');
    });

    it('.editorconfig enforces final newline', function () {
        $content = file_get_contents(__DIR__ . '/../.editorconfig');
        expect($content)->toContain('insert_final_newline = true');
    });

    it('.gitattributes exists', function () {
        expect(file_exists(__DIR__ . '/../.gitattributes'))->toBeTrue();
    });

    it('.gitattributes excludes dev files from dist', function () {
        $content = file_get_contents(__DIR__ . '/../.gitattributes');
        expect($content)->toContain('export-ignore');
    });
});

describe('Phase 47 — Version Consistency', function () {
    it('composer.json version matches AnalyticsEvent::VERSION', function () {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($composer['version'])->toBe(AnalyticsEvent::VERSION);
    });

    it('README badge version matches composer.json', function () {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $readme = file_get_contents(__DIR__ . '/../README.md');

        expect($readme)->toContain('version-' . $composer['version']);
    });
});

describe('Phase 47 — Exception Hierarchy', function () {
    it('AnalyticsException is abstract', function () {
        expect((new ReflectionClass(AnalyticsException::class))->isAbstract())->toBeTrue();
    });

    it('AnalyticsException constructor has :void return type', function () {
        $constructor = (new ReflectionClass(AnalyticsException::class))->getConstructor();
        expect($constructor)->not->toBeNull();
        expect($constructor->hasReturnType())->toBeTrue();
        expect((string) $constructor->getReturnType())->toBe('void');
    });

    it('AnalyticsRuntimeException is final', function () {
        expect((new ReflectionClass(AnalyticsRuntimeException::class))->isFinal())->toBeTrue();
    });

    it('InvalidAnalyticsArgumentException is final', function () {
        expect((new ReflectionClass(InvalidAnalyticsArgumentException::class))->isFinal())->toBeTrue();
    });

    it('AnalyticsRuntimeException extends AnalyticsException', function () {
        expect((new ReflectionClass(AnalyticsRuntimeException::class))->isSubclassOf(AnalyticsException::class))->toBeTrue();
    });

    it('InvalidAnalyticsArgumentException extends AnalyticsException', function () {
        expect((new ReflectionClass(InvalidAnalyticsArgumentException::class))->isSubclassOf(AnalyticsException::class))->toBeTrue();
    });

    it('AnalyticsRuntimeException @see references AnalyticsException', function () {
        $doc = (new ReflectionClass(AnalyticsRuntimeException::class))->getDocComment();
        expect($doc)->toContain('@see');
        expect($doc)->toContain('AnalyticsException');
    });

    it('InvalidAnalyticsArgumentException @see references AnalyticsException', function () {
        $doc = (new ReflectionClass(InvalidAnalyticsArgumentException::class))->getDocComment();
        expect($doc)->toContain('@see');
        expect($doc)->toContain('AnalyticsException');
    });

    it('AnalyticsException @see references both leaves', function () {
        $doc = (new ReflectionClass(AnalyticsException::class))->getDocComment();
        expect($doc)->toContain('@see');
        expect($doc)->toContain('InvalidAnalyticsArgumentException');
        expect($doc)->toContain('AnalyticsRuntimeException');
    });
});

describe('Phase 47 — Composer Metadata Integrity', function () {
    it('composer.json has MIT license', function () {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        expect($composer['license'])->toBe('MIT');
    });

    it('composer.json has Laravel provider registered', function () {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $providers = $composer['extra']['laravel']['providers'] ?? [];
        expect($providers)->toContain(AnalyticsServiceProvider::class);
    });

    it('composer.json has Facade alias', function () {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $aliases = $composer['extra']['laravel']['aliases'] ?? [];
        expect($aliases)->toHaveKey('Analytics');
    });

    it('composer.json has quality scripts', function () {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        expect($composer['scripts'])->toHaveKey('test');
        expect($composer['scripts'])->toHaveKey('analyse');
        expect($composer['scripts'])->toHaveKey('lint');
        expect($composer['scripts'])->toHaveKey('rector');
        expect($composer['scripts'])->toHaveKey('quality');
        expect($composer['scripts'])->toHaveKey('ci');
    });

    it('autoload-dev has tests namespace', function () {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        expect($composer['autoload-dev']['psr-4'])->toHaveKey('ZeroBoiler\\Analytics\\Tests\\');
    });
});

describe('Phase 47 — Rector PHP 8.5 Target', function () {
    it('rector.php targets PHP 8.5', function () {
        $rector = file_get_contents(__DIR__ . '/../rector.php');
        expect($rector)->toContain('PHP_85');
    });

    it('rector.php includes src and tests paths', function () {
        $rector = file_get_contents(__DIR__ . '/../rector.php');
        expect($rector)->toContain("'/src'");
        expect($rector)->toContain("'/tests'");
    });
});

describe('Phase 47 — ServiceProvider Integrity', function () {
    it('AnalyticsServiceProvider is final', function () {
        expect((new ReflectionClass(AnalyticsServiceProvider::class))->isFinal())->toBeTrue();
    });

    it('AnalyticsServiceProvider constructor has :void return type', function () {
        $constructor = (new ReflectionClass(AnalyticsServiceProvider::class))->getConstructor();
        expect($constructor)->not->toBeNull();
        expect($constructor->hasReturnType())->toBeTrue();
        expect((string) $constructor->getReturnType())->toBe('void');
    });

    it('AnalyticsServiceProvider has register method', function () {
        $r = new ReflectionClass(AnalyticsServiceProvider::class);
        expect($r->hasMethod('register'))->toBeTrue();
    });

    it('AnalyticsServiceProvider has boot method', function () {
        $r = new ReflectionClass(AnalyticsServiceProvider::class);
        expect($r->hasMethod('boot'))->toBeTrue();
    });

    it('AnalyticsServiceProvider has provides method', function () {
        $r = new ReflectionClass(AnalyticsServiceProvider::class);
        expect($r->hasMethod('provides'))->toBeTrue();
    });
});

describe('Phase 47 — Facade Integrity', function () {
    it('Facade is final', function () {
        expect((new ReflectionClass(\ZeroBoiler\Analytics\Facades\Analytics::class))->isFinal())->toBeTrue();
    });

    it('getFacadeAccessor has #[Override]', function () {
        $r = new ReflectionClass(\ZeroBoiler\Analytics\Facades\Analytics::class);
        $m = $r->getMethod('getFacadeAccessor');
        $has = array_any(
            $m->getAttributes(),
            fn (\ReflectionAttribute $a): bool => $a->getName() === 'Override',
        );
        expect($has)->toBeTrue();
    });

    it('getFacadeAccessor returns string', function () {
        $r = new ReflectionClass(\ZeroBoiler\Analytics\Facades\Analytics::class);
        $m = $r->getMethod('getFacadeAccessor');
        expect((string) $m->getReturnType())->toBe('string');
    });
});

describe('Phase 47 — AnalyticsManager Core', function () {
    it('AnalyticsManager is final', function () {
        expect((new ReflectionClass(AnalyticsManager::class))->isFinal())->toBeTrue();
    });

    it('AnalyticsManager constructor has :void return type', function () {
        $constructor = (new ReflectionClass(AnalyticsManager::class))->getConstructor();
        expect($constructor)->not->toBeNull();
        expect($constructor->hasReturnType())->toBeTrue();
        expect((string) $constructor->getReturnType())->toBe('void');
    });
});

describe('Phase 47 — All Source Files Have @since', function () {
    it('every source file contains @since annotation', function () {
        $files = glob(__DIR__ . '/../src/**/*.php');
        $missing = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (!str_contains($content, '@since')) {
                $missing[] = str_replace(__DIR__ . '/../src/', '', $file);
            }
        }

        expect($missing)->toBeEmpty();
    });
});

describe('Phase 47 — No TODO/FIXME/HACK in Source', function () {
    it('no TODO markers in source files', function () {
        $files = glob(__DIR__ . '/../src/**/*.php');
        $violations = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            foreach (['TODO:', 'FIXME:', 'HACK:', 'XXX:'] as $marker) {
                if (str_contains($content, $marker)) {
                    $violations[] = $marker . ' in ' . str_replace(__DIR__ . '/../src/', '', $file);
                }
            }
        }

        expect($violations)->toBeEmpty();
    });
});

describe('Phase 47 — All Non-Abstract Classes Are Final', function () {
    it('all non-abstract classes in src are final', function () {
        $files = glob(__DIR__ . '/../src/**/*.php');
        $nonFinal = [];

        foreach ($files as $file) {
            $tokens = token_get_all(file_get_contents($file));
            $namespace = '';
            $className = '';
            $isAbstract = false;
            $isFinal = false;
            $inClass = false;

            for ($i = 0; $i < count($tokens); $i++) {
                if (is_array($tokens[$i])) {
                    if ($tokens[$i][0] === T_NAMESPACE) {
                        // Collect namespace
                        $j = $i + 1;
                        while ($j < count($tokens) && !(is_array($tokens[$j]) && $tokens[$j][0] === T_NAME_QUALIFIED || $tokens[$j][0] === T_STRING)) {
                            $j++;
                        }
                        if ($j < count($tokens) && is_array($tokens[$j])) {
                            $namespace = $tokens[$j][1];
                        }
                    }
                    if ($tokens[$i][0] === T_ABSTRACT) {
                        $isAbstract = true;
                    }
                    if ($tokens[$i][0] === T_FINAL) {
                        $isFinal = true;
                    }
                    if ($tokens[$i][0] === T_CLASS) {
                        $j = $i + 1;
                        while ($j < count($tokens) && !(is_array($tokens[$j]) && ($tokens[$j][0] === T_STRING || $tokens[$j][0] === T_NAME_QUALIFIED))) {
                            $j++;
                        }
                        if ($j < count($tokens) && is_array($tokens[$j])) {
                            $className = $tokens[$j][1];
                            if (!$isAbstract && !$isFinal) {
                                $nonFinal[] = $namespace . '\\' . $className;
                            }
                            // Reset
                            $isAbstract = false;
                            $isFinal = false;
                        }
                    }
                }
            }
        }

        expect($nonFinal)->toBeEmpty();
    });
});

describe('Phase 47 — TrackerInterface Compliance', function () {
    it('TrackerInterface has track, identify, pageView methods', function () {
        $r = new ReflectionClass(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class);
        expect($r->hasMethod('track'))->toBeTrue();
        expect($r->hasMethod('identify'))->toBeTrue();
        expect($r->hasMethod('pageView'))->toBeTrue();
    });
});

describe('Phase 47 — Config File Structure', function () {
    it('config file exists', function () {
        expect(file_exists(__DIR__ . '/../config/zeroboiler.php'))->toBeTrue();
    });

    it('config file returns array', function () {
        $config = require __DIR__ . '/../config/zeroboiler.php';
        expect($config)->toBeArray();
    });

    it('config file has analytics section', function () {
        $config = require __DIR__ . '/../config/zeroboiler.php';
        expect($config)->toHaveKey('analytics');
    });

    it('config has declare strict types', function () {
        $content = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        expect($content)->toContain('declare(strict_types=1)');
    });
});
