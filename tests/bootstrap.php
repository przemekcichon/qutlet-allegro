<?php
/**
 * Bootstrap PHPUnit (P-6.7b, rozszerzony P-13.4a): autoloader Composera +
 * dublery WP hooków/klas Woo potrzebne przez wąski wycinek testów dotykających
 * WP/Woo (`ProductWriterGtinFilterTest`, `ProductWriterAttributesTest`,
 * `ProductWriterManualImagesTest`, P-9.1d).
 * Świadomie NIE jako composer `autoload-dev` „files" — koliduje z
 * `__return_true()`/`__return_false()` z pakietu `php-stubs/wordpress-stubs`
 * ładowanym osobno przez PHPStan (redeclare fatal). Ten plik ładuje się TYLKO
 * w przebiegu PHPUnit.
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Stubs/wc-gtin-filter-stubs.php';
require __DIR__ . '/Stubs/wc-product-attribute-stub.php';
require __DIR__ . '/Stubs/wp-post-meta-stub.php';
