<?php
/**
 * Plugin Name: MITKIN — Готовые образы
 * Description: Витрина комплектов WooCommerce, выбор размеров и скидка 7% за полный образ.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

defined('ABSPATH') || exit;

add_action('before_woocommerce_init', static function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('plugins_loaded', static function () {
    if (!class_exists('WooCommerce')) {
        return;
    }
    require __DIR__ . '/catalog.php';
    require __DIR__ . '/cart.php';
});

const MITKIN_LOOKS_RATE = 0.07;
