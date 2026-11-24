<?php
defined('ABSPATH') || exit;

add_action('wc_ajax_mitkin_add_look', 'mitkin_looks_add_to_cart');

function mitkin_looks_add_to_cart() {
    if ((get_http_origin() && !is_allowed_http_origin())
        || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || ($_SERVER['HTTP_X_MITKIN_LOOK_REQUEST'] ?? '') !== '1') {
        wp_send_json_error(['message' => 'Недопустимый запрос.'], 403);
    }

    $raw = $_POST['selection'] ?? '';
    $input = is_string($raw) ? json_decode(wp_unslash($raw), true) : null;
    $look_id = isset($_POST['look']) && is_scalar($_POST['look']) ? absint($_POST['look']) : 0;
    $config = mitkin_looks_config($look_id);
    if (count($config) < 2 || !is_array($input) || !$input || count($input) > count($config)) {
        wp_send_json_error(['message' => 'Выберите хотя бы одну вещь из доступного образа.'], 400);
    }

    $selection = [];
    foreach ($input as $product_id => $variation_id) {
        if (!is_int($product_id) || !is_int($variation_id) || !array_key_exists($product_id, $config)) {
            wp_send_json_error(['message' => 'Обновите страницу и выберите вещи снова.'], 400);
        }
        $product = wc_get_product($product_id);
        $choices = mitkin_looks_choices($product, $config[$product_id]);
        foreach ($choices as $choice) {
            if (($choice->is_type('variation') ? $choice->get_id() : 0) === $variation_id) {
                $selection[$product_id] = $choice;
                break;
            }
        }
        if (!isset($selection[$product_id])) {
            wp_send_json_error(['message' => 'Одна из выбранных вещей или размеров уже недоступна.'], 409);
        }
    }

    $cart = WC()->cart;
    $notices = wc_get_notices();
    $group = wp_generate_uuid4();
    $name = get_the_title($look_id);

    try {
        foreach ($config as $product_id => $allowed) {
            if (!isset($selection[$product_id])) {
                continue;
            }
            $choice = $selection[$product_id];
            $variation_id = $choice->is_type('variation') ? $choice->get_id() : 0;
            $attributes = $variation_id ? $choice->get_variation_attributes() : [];
            $metadata = [
                '_mitkin_group' => $group,
                '_mitkin_look_id' => $look_id,
                '_mitkin_look_name' => $name,
                '_mitkin_members' => array_keys($config),
            ];
            if (!apply_filters('woocommerce_add_to_cart_validation', true, $product_id, 1, $variation_id, $attributes, $metadata)) {
                throw new RuntimeException();
            }
            $key = $cart->add_to_cart($product_id, 1, $variation_id, $attributes, $metadata);
            if (!$key) {
                throw new RuntimeException();
            }
        }
    } catch (Throwable $error) {
        $errors = wc_get_notices('error');
        $latest = count($errors) > count($notices['error'] ?? []) ? end($errors) : [];
        foreach ($cart->get_cart() as $key => $item) {
            if (($item['_mitkin_group'] ?? '') === $group) {
                $cart->remove_cart_item($key);
            }
        }
        $cart->calculate_totals();
        wc_set_notices($notices);
        wp_send_json_error([
            'message' => !empty($latest['notice']) ? wp_strip_all_tags($latest['notice']) : 'Не удалось добавить выбранные вещи. Проверьте остатки и попробуйте ещё раз.',
        ], 409);
    }

    $cart->calculate_totals();
    wp_send_json_success(['cart' => wc_get_cart_url()]);
}

function mitkin_looks_cart_groups($cart) {
    $groups = [];
    foreach ($cart->get_cart() as $key => $item) {
        if (!empty($item['_mitkin_group'])) {
            $groups[$item['_mitkin_group']][$key] = $item;
        }
    }
    return $groups;
}

add_action('woocommerce_cart_loaded_from_session', static function ($cart) {
    if (mitkin_looks_cart_groups($cart)) {
        $cart->calculate_totals();
    }
}, 20);

function mitkin_looks_group_complete(array $items) {
    $first = reset($items);
    $remaining = array_fill_keys($first['_mitkin_members'] ?? [], true);
    if (count($remaining) < 2 || count($remaining) !== count($items)) {
        return false;
    }
    foreach ($items as $item) {
        $id = $item['product_id'];
        if (!isset($remaining[$id]) || (float) $item['quantity'] !== 1.0
            || !$item['data']->is_in_stock() || !$item['data']->is_purchasable()) {
            return false;
        }
        unset($remaining[$id]);
    }
    return !$remaining;
}

add_action('woocommerce_before_calculate_totals', static function ($cart) {
    // Reset prices applied by this plugin before WooCommerce recalculates the cart.
    foreach (mitkin_looks_cart_groups($cart) as $items) {
        foreach ($items as $item) {
            mitkin_looks_discount_item($item['data'], true);
        }
    }
}, 1);

add_action('woocommerce_before_calculate_totals', static function ($cart) {
    $groups = mitkin_looks_cart_groups($cart);
    $ordered = [];
    foreach ($cart->get_cart_contents() as $key => $item) {
        if (empty($item['_mitkin_group'])) {
            $ordered[$key] = $item;
        } elseif (!isset($ordered[$key])) {
            $ordered += $groups[$item['_mitkin_group']];
        }
    }
    $cart->set_cart_contents($ordered);

    foreach ($groups as $items) {
        if (!mitkin_looks_group_complete($items)) {
            continue;
        }
        foreach ($items as $item) {
            mitkin_looks_discount_item($item['data']);
        }
    }
}, 1000);

function mitkin_looks_discount_item($product, $reset = false) {
    static $prices = [];
    $id = spl_object_id($product);
    if ($reset) {
        if (isset($prices[$id]) && $prices[$id][0] === $product) {
            [, $base, $discounted] = $prices[$id];
            if ((float) $product->get_price('edit') === $discounted) {
                $product->set_price($base);
            }
        }
        unset($prices[$id]);
        return;
    }
    $base = $product->get_price('edit');
    $discounted = mitkin_looks_sale_price($base);
    $prices[$id] = [$product, $base, $discounted];
    $product->set_price($discounted);
}

add_filter('woocommerce_cart_item_class', static function ($class, $item, $key) {
    if (empty($item['_mitkin_group'])) {
        return $class;
    }
    $group = WC()->cart ? (mitkin_looks_cart_groups(WC()->cart)[$item['_mitkin_group']] ?? []) : [];
    return $class . ' ml-cart-member' . (array_key_first($group) === $key ? ' ml-cart-first' : '');
}, 10, 3);

add_filter('woocommerce_cart_item_name', static function ($name, $item, $key) {
    if (!is_cart() || empty($item['_mitkin_group']) || !WC()->cart) {
        return $name;
    }
    $group = mitkin_looks_cart_groups(WC()->cart)[$item['_mitkin_group']] ?? [];
    if (array_key_first($group) !== $key) {
        return $name;
    }
    $complete = mitkin_looks_group_complete($group);
    return '<span class="ml-cart-heading"><strong>Готовый образ · ' . esc_html($item['_mitkin_look_name'])
        . '</strong><small>' . ($complete ? 'Полный комплект · скидка 7%' : 'Выбранные вещи · без скидки') . '</small></span>' . $name;
}, 99, 3);

add_filter('woocommerce_cart_item_quantity', static function ($html, $key, $item) {
    return empty($item['_mitkin_group']) ? $html : esc_html($item['quantity']);
}, 10, 3);

add_filter('woocommerce_get_item_data', static function ($data, $item) {
    if (!empty($item['_mitkin_group'])) {
        $data[] = ['key' => 'Готовый образ', 'value' => $item['_mitkin_look_name']];
    }
    return $data;
}, 10, 2);

add_action('woocommerce_checkout_create_order_line_item', static function ($line, $key, $item) {
    if (!empty($item['_mitkin_group'])) {
        $line->add_meta_data('Готовый образ', $item['_mitkin_look_name'], true);
        $line->add_meta_data('_mitkin_group', $item['_mitkin_group'], true);
    }
}, 10, 3);
