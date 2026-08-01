<?php

declare(strict_types=1);

/*
 * Test-Bootstrap für Unit- und Integration-Lauf.
 *
 * Erst der lokale `vendor/`-Autoloader (Plugin-Standalone), dann der Shopware-Core-Autoloader
 * eines über dem Plugin liegenden Composer-Setups (`../../..`). Fällt beides aus, springt der
 * eigene PSR-4-Loader für `Ruhrcoder\RcColorPicker\` ein.
 *
 * Damit laufen die Unit-Tests sowohl lokal mit `vendor/bin/phpunit` als auch auf dem Server im
 * Shopware-Test-Kontext — und die Integration-Tests bekommen dort den Kernel, den sie brauchen.
 */

$pluginAutoloader = \dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($pluginAutoloader)) {
    require_once $pluginAutoloader;
}

$shopwareAutoloader = \dirname(__DIR__, 4) . '/vendor/autoload.php';
if (file_exists($shopwareAutoloader)) {
    require_once $shopwareAutoloader;
}

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Ruhrcoder\\RcColorPicker\\Tests\\' => __DIR__ . '/',
        'Ruhrcoder\\RcColorPicker\\' => \dirname(__DIR__) . '/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $length = \strlen($prefix);
        if (strncmp($class, $prefix, $length) !== 0) {
            continue;
        }

        $file = $baseDir . str_replace('\\', '/', substr($class, $length)) . '.php';
        if (file_exists($file)) {
            require_once $file;

            return;
        }
    }
});

/*
 * Shopware-Root suchen. Der Kernel-Bootstrap darf NUR laufen, wenn das Plugin tatsächlich
 * innerhalb einer Shopware-Installation getestet wird.
 *
 * Nicht auf `class_exists(TestBootstrapper::class)` prüfen: `shopware/core` ist eine
 * `require`-Abhängigkeit, die Klasse existiert also auch im Standalone-Checkout (CI, frischer
 * `composer install`). Der Bootstrap versuchte dann einen Shop zu booten, den es dort nicht gibt,
 * und stürbe mit „Could not find plugin: RcColorPicker" — noch bevor ein Unit-Test läuft.
 *
 * Kandidaten in dieser Reihenfolge:
 *   1. Aufruf-Verzeichnis — Konvention: Integration-Tests werden aus dem Shopware-Root gestartet
 *      (`vendor/bin/phpunit -c custom/plugins/…`).
 *   2. Vier Ebenen über `tests/` — greift bei `custom/plugins/<Plugin>/tests/`, solange der Pfad
 *      kein Symlink ist.
 */
$shopwareRoot = null;
foreach ([getcwd(), \dirname(__DIR__, 4)] as $candidate) {
    if (\is_string($candidate) && is_file($candidate . '/config/bundles.php')) {
        $shopwareRoot = $candidate;
        break;
    }
}

// Kernel-Lifecycle vorbereiten. IntegrationTestBehaviour erwartet, dass
// `KernelLifecycleManager::prepare($classLoader)` gelaufen ist, bevor der erste Test startet.
// Im Standalone-Unit-Lauf bleibt das ein No-op — die Unit-Tests brauchen nur den Autoloader.
if ($shopwareRoot !== null && class_exists(\Shopware\Core\TestBootstrapper::class)) {
    // KERNEL_CLASS-Pin: Das DDEV-Shopware-Setup hat in `.env.test` `KERNEL_CLASS=App\Kernel`
    // stehen — diese Klasse existiert in der Setup-Variante nicht (Production nutzt
    // `KernelFactory::create()` ohne App-Kernel). `Shopware\Core\Kernel` ist die konkrete
    // Default-Klasse. Früh setzen, damit Dotenv (override=false) sie nicht überschreibt.
    $currentKernelClass = getenv('KERNEL_CLASS') ?: ($_SERVER['KERNEL_CLASS'] ?? '');
    if ($currentKernelClass === '' || !class_exists($currentKernelClass)) {
        putenv('KERNEL_CLASS=Shopware\\Core\\Kernel');
        $_SERVER['KERNEL_CLASS'] = 'Shopware\\Core\\Kernel';
        $_ENV['KERNEL_CLASS'] = 'Shopware\\Core\\Kernel';
    }

    // `addCallingPlugin()` registriert RcColorPicker im Test-Kernel; beim ersten Bootstrap
    // installiert TestBootstrapper es automatisch. Kein `setForceInstallPlugins(true)` — das
    // löste einen uninstall->install-Zyklus aus, der nicht idempotent ist.
    $bootstrapper = (new \Shopware\Core\TestBootstrapper())
        ->setPlatformEmbedded(false)
        ->addCallingPlugin();

    // ProjectDir explizit auf das gefundene Shopware-Root setzen. Wichtig:
    // `KernelFactory::getProjectDir()` liest `$_SERVER['PROJECT_ROOT']` *vor* dem
    // Reflection-Fallback — der nähme sonst den Pfad der KernelFactory-Klasse selbst und zeigte
    // bei composer-installiertem vendor auf das Plugin statt auf die Instanz. `setProjectDir()`
    // allein genügt deshalb nicht, die Server-Variable ist die Quelle.
    $bootstrapper->setProjectDir($shopwareRoot);
    $_SERVER['PROJECT_ROOT'] = $shopwareRoot;
    $_ENV['PROJECT_ROOT'] = $shopwareRoot;
    putenv('PROJECT_ROOT=' . $shopwareRoot);

    $bootstrapper->bootstrap();
}
