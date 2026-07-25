<?php
/**
 * Bootstrap PHPUnit (P-6.7b): autoloader Composera + dublery WP hooków
 * (`tests/Stubs/wc-gtin-filter-stubs.php`) potrzebne przez
 * `ProductWriterGtinFilterTest`. Świadomie NIE jako composer `autoload-dev`
 * „files" — koliduje z `__return_true()`/`__return_false()` z pakietu
 * `php-stubs/wordpress-stubs` ładowanym osobno przez PHPStan (redeclare
 * fatal). Ten plik ładuje się TYLKO w przebiegu PHPUnit.
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Stubs/wc-gtin-filter-stubs.php';
