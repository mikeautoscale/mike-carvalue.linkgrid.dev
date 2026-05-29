<?php
/**
 * Lightweight autoloader mapping `CarValue\StudlyClass` to `src/studly-class.php`.
 *
 * The project uses lower-case kebab filenames (per the design doc); this bridges
 * them to StudlyCase class names without requiring Composer at runtime.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'CarValue\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $segments = explode('\\', $relative);
    $name = array_pop($segments);

    // StudlyCase -> kebab-case (e.g. ValueEstimator -> value-estimator)
    $kebab = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name));

    $dir = __DIR__;
    if ($segments) {
        $dir .= '/' . strtolower(implode('/', $segments));
    }

    $file = $dir . '/' . $kebab . '.php';
    if (is_file($file)) {
        require $file;
    }
});
