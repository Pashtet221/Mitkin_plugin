<?php
defined('ABSPATH') || exit;

add_action('init', static function () {
    register_post_type('mitkin_look', [
        'labels' => [
            'name' => 'Готовые образы', 'singular_name' => 'Готовый образ',
            'add_new_item' => 'Добавить готовый образ', 'edit_item' => 'Редактировать образ',
            'featured_image' => 'Фото для карточки', 'set_featured_image' => 'Выбрать фото для карточки',
        ],
        'show_ui' => true, 'show_in_menu' => 'woocommerce', 'public' => false,
        'supports' => ['title', 'thumbnail', 'page-attributes'],
        'capability_type' => 'product', 'map_meta_cap' => true,
    ]);
});

add_action('add_meta_boxes_mitkin_look', static function () {
    add_meta_box('mitkin_look_config', 'Состав и фото образа', 'mitkin_looks_admin_box', 'mitkin_look');
});

add_action('admin_enqueue_scripts', static function () {
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'mitkin_look' || $screen->base !== 'post') {
        return;
    }
    wp_enqueue_media();
    wp_enqueue_script('wc-enhanced-select');
    wp_enqueue_style('woocommerce_admin_styles');
    wp_enqueue_script('mitkin-looks-admin', plugins_url('assets/admin.js', __FILE__), ['jquery', 'wc-enhanced-select'], '1.0.0', true);
});

function mitkin_looks_admin_box($post) {
    wp_nonce_field('mitkin_save_look', 'mitkin_look_nonce');
    $products = (array) get_post_meta($post->ID, '_mitkin_products', true);
    $photo = absint(get_post_meta($post->ID, '_mitkin_photo', true));
    ?>
    <p><label for="mitkin-products"><strong>Товары из каталога</strong></label></p>
    <select id="mitkin-products" name="mitkin_products[]" class="wc-product-search" multiple
        style="width:100%" data-action="woocommerce_json_search_products" data-placeholder="Начните вводить название товара">
        <?php foreach ($products as $id) :
            $product = $id ? wc_get_product($id) : false;
            if ($product) : ?>
                <option value="<?php echo esc_attr($id); ?>" selected><?php echo esc_html($product->get_formatted_name()); ?></option>
            <?php endif;
        endforeach; ?>
    </select>
    <p>Добавьте минимум два простых или вариативных товара. Для вариативных товаров можно ограничить список, например оставить размеры только одного цвета.</p>
    <p><label for="mitkin-variations"><strong>Разрешённые вариации по товарам</strong></label></p>
    <textarea id="mitkin-variations" name="mitkin_variations" class="widefat" rows="3" placeholder="123: 456, 457, 458"><?php
        echo esc_textarea(get_post_meta($post->ID, '_mitkin_variations', true));
    ?></textarea>
    <p>Одна строка на товар. Слева ID товара, справа через запятую ID его вариаций. Оставьте поле пустым, чтобы показывать все доступные вариации.</p>
    <p><strong>Фото для окна выбора размеров</strong></p>
    <p id="mitkin-photo-preview"><?php echo $photo ? wp_get_attachment_image($photo, 'thumbnail') : ''; ?></p>
    <input type="hidden" name="mitkin_photo" id="mitkin-photo" value="<?php echo esc_attr($photo); ?>">
    <button type="button" class="button" id="mitkin-photo-pick">Выбрать фото</button>
    <button type="button" class="button" id="mitkin-photo-clear">Очистить</button>
    <p>Фото карточки задаётся справа. Если отдельное фото для окна пустое, используется фото карточки.</p>
    <?php
}

add_action('save_post_mitkin_look', static function ($id) {
    if (empty($_POST['mitkin_look_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mitkin_look_nonce'])), 'mitkin_save_look')
        || wp_is_post_autosave($id) || wp_is_post_revision($id) || !current_user_can('edit_post', $id)) {
        return;
    }
    $products = array_values(array_unique(array_filter(array_map('absint', (array) ($_POST['mitkin_products'] ?? [])))));
    update_post_meta($id, '_mitkin_products', $products);
    update_post_meta($id, '_mitkin_variations', sanitize_textarea_field(wp_unslash($_POST['mitkin_variations'] ?? '')));
    $photo = absint($_POST['mitkin_photo'] ?? 0);
    update_post_meta($id, '_mitkin_photo', wp_attachment_is_image($photo) ? $photo : 0);
});

function mitkin_looks_config($id) {
    $post = get_post($id);
    if (!$post || $post->post_type !== 'mitkin_look' || $post->post_status !== 'publish') {
        return [];
    }
    $limits = [];
    foreach (preg_split('/\R/', (string) get_post_meta($id, '_mitkin_variations', true)) as $line) {
        if (preg_match('/^\s*(\d+)\s*:\s*([\d,\s]+)$/', $line, $matches)) {
            $limits[(int) $matches[1]] = wp_parse_id_list($matches[2]);
        }
    }
    $products = get_post_meta($id, '_mitkin_products', true);
    foreach ((array) $products as $product_id) {
        if ($product_id) {
            $config[$product_id] = $limits[$product_id] ?? [];
        }
    }
    return $config ?? [];
}

function mitkin_looks_choices($product, array $allowed) {
    if (!$product || $product->get_status() !== 'publish') {
        return [];
    }
    if ($product->is_type('simple')) {
        return $product->is_purchasable() && $product->is_in_stock() ? [$product] : [];
    }
    if (!$product->is_type('variable')) {
        return [];
    }
    $choices = [];
    foreach ($product->get_children() as $id) {
        if ($allowed && !in_array($id, $allowed, true)) {
            continue;
        }
        $variation = wc_get_product($id);
        if (!$variation || !$variation->variation_is_active() || !$variation->variation_is_visible()
            || !$variation->is_purchasable() || !$variation->is_in_stock()
            || in_array('', $variation->get_variation_attributes(), true)) {
            continue;
        }
        $choices[] = $variation;
    }
    return $choices;
}

function mitkin_looks_sale_price($price) {
    return round((float) $price * (1 - MITKIN_LOOKS_RATE), wc_get_price_decimals());
}

function mitkin_looks_count($count) {
    $last = $count % 10;
    $ending = ($count % 100 >= 11 && $count % 100 <= 14) ? 'вещей'
        : ($last === 1 ? 'вещь' : ($last >= 2 && $last <= 4 ? 'вещи' : 'вещей'));
    return $count . ' ' . $ending;
}

add_action('wp_enqueue_scripts', static function () {
    wp_enqueue_style('mitkin-looks', plugins_url('assets/looks.css', __FILE__), [], '1.0.2');
    wp_register_script('mitkin-looks', plugins_url('assets/looks.js', __FILE__), [], '1.0.2', true);
});

add_shortcode('mitkin_looks', static function ($attributes) {
    $attributes = shortcode_atts(['ids' => '', 'heading' => 'yes', 'limit' => '', 'all_url' => '', 'size_chart_url' => ''], $attributes, 'mitkin_looks');
    $all_url = esc_url($attributes['all_url']);
    $size_chart_url = esc_url($attributes['size_chart_url']);
    $remaining = filter_var($attributes['limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $ids = wp_parse_id_list($attributes['ids']);
    $looks = get_posts([
        'post_type' => 'mitkin_look', 'post_status' => 'publish', 'numberposts' => -1,
        'post__in' => $ids, 'orderby' => $ids ? 'post__in' : ['menu_order' => 'ASC', 'ID' => 'ASC'],
    ]);
    wp_enqueue_script('mitkin-looks');
    ob_start();
    ?>
    <section class="ml-looks" data-endpoint="<?php echo esc_url(WC_AJAX::get_endpoint('mitkin_add_look')); ?>"
        data-cart="<?php echo esc_url(wc_get_cart_url()); ?>"
        data-money="<?php echo esc_attr(wp_json_encode([
            'format' => get_woocommerce_price_format(), 'symbol' => get_woocommerce_currency_symbol(),
            'decimals' => wc_get_price_decimals(), 'decimal' => wc_get_price_decimal_separator(),
            'thousand' => wc_get_price_thousand_separator(),
        ])); ?>">
        <?php if ($attributes['heading'] !== 'no' || $all_url) : ?>
            <header class="ml-intro">
                <?php if ($attributes['heading'] !== 'no') : ?>
                    <div><h2>Готовые образы</h2><p>Скидка 7% при покупке всех вещей из готового образа.</p></div>
                <?php endif; ?>
                <?php if ($all_url) : ?>
                    <a class="ml-button ml-all" href="<?php echo $all_url; ?>">Смотреть все образы <span aria-hidden="true">→</span></a>
                <?php endif; ?>
            </header>
        <?php endif; ?>
        <div class="ml-grid">
            <?php foreach ($looks as $look) {
                if (mitkin_looks_render($look, $size_chart_url) && $remaining !== false && --$remaining === 0) {
                    break;
                }
            } ?>
        </div>
    </section>
    <?php
    return ob_get_clean();
});

function mitkin_looks_render($look, $size_chart_url = '') {
    $rows = [];
    $base = $sale = 0;
    $complete = true;
    foreach (mitkin_looks_config($look->ID) as $product_id => $allowed) {
        $product = wc_get_product($product_id);
        $choices = mitkin_looks_choices($product, $allowed);
        if (!$product) {
            $complete = false;
            continue;
        }
        usort($choices, static function ($a, $b) {
            return (float) $a->get_price() <=> (float) $b->get_price();
        });
        $rows[] = [$product, $choices];
        if (!$choices) {
            $complete = false;
            continue;
        }
        $base += wc_get_price_to_display($choices[0]);
        $sale += wc_get_price_to_display($choices[0], ['price' => mitkin_looks_sale_price($choices[0]->get_price())]);
    }
    if (count($rows) < 2) {
        return false;
    }
    $title = get_the_title($look);
    $photo = absint(get_post_meta($look->ID, '_mitkin_photo', true)) ?: get_post_thumbnail_id($look);
    $dialog = wp_unique_id('mitkin-look-');
    ?>
    <article class="ml-card">
        <div class="ml-cover"><?php echo get_the_post_thumbnail($look, 'large') ?: wc_placeholder_img('woocommerce_single'); ?></div>
        <div class="ml-card-body">
            <h3><?php echo esc_html($title); ?></h3>
            <p class="ml-caption"><?php echo esc_html(mitkin_looks_count(count($rows))); ?>
                <?php if ($complete) : ?><em>· выгода 7%</em><?php endif; ?>
            </p>
            <ul class="ml-thumbs">
                <?php foreach ($rows as [$product]) : ?>
                    <li><?php echo $product->get_image('woocommerce_thumbnail'); ?>
                        <span class="ml-visually-hidden"><?php echo esc_html($product->get_name()); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="ml-card-price">
                <?php if ($complete) : ?><del><?php echo wp_kses_post(wc_price($base)); ?></del><span>−7%</span><?php endif; ?>
                <strong><?php echo wp_kses_post(wc_price($complete ? $sale : $base)); ?></strong>
            </div>
            <button class="ml-button ml-open" type="button" aria-haspopup="dialog" aria-controls="<?php echo esc_attr($dialog); ?>" <?php disabled(!$base && !array_filter(array_column($rows, 1))); ?>>Выбрать размеры и купить <span aria-hidden="true">›</span></button>
        </div>
        <dialog class="ml-dialog" id="<?php echo esc_attr($dialog); ?>" aria-labelledby="<?php echo esc_attr($dialog . '-title'); ?>">
            <div class="ml-dialog-layout<?php echo $photo ? ' ml-has-photo' : ''; ?>">
                <button class="ml-close" type="button" aria-label="Закрыть">×</button>
                <?php if ($photo) : ?>
                    <div class="ml-look-photo"><?php echo wp_get_attachment_image($photo, 'large', false, ['loading' => 'lazy']); ?></div>
                <?php endif; ?>
                <form class="ml-form" data-complete="<?php echo $complete ? '1' : '0'; ?>">
                    <h2 id="<?php echo esc_attr($dialog . '-title'); ?>"><?php echo esc_html($title); ?></h2>
                    <p class="ml-subtitle">Соберите образ и выберите размеры</p>
                    <?php if ($complete) : ?><p class="ml-offer"><strong>Выгода 7%</strong> <em>при покупке полного образа</em></p><?php endif; ?>
                    <input type="hidden" name="look" value="<?php echo esc_attr($look->ID); ?>">
                    <?php foreach ($rows as [$product, $choices]) {
                        mitkin_looks_render_row($product, $choices, $dialog, $size_chart_url);
                    } ?>
                    <div class="ml-totals" aria-live="polite">
                        <div><span>Стоимость выбранных вещей</span><span data-base></span></div>
                        <div class="ml-discount" hidden><span>Скидка 7%</span><span data-discount></span></div>
                        <div class="ml-grand"><strong>Итого</strong><strong data-total></strong></div>
                    </div>
                    <p class="ml-error" role="alert" hidden></p>
                    <button class="ml-button ml-add" type="submit">Добавить в корзину</button>
                </form>
            </div>
        </dialog>
    </article>
    <?php
    return true;
}

function mitkin_looks_render_row($product, array $choices, $dialog, $size_chart_url) {
    $variable = $product->is_type('variable');
    ?>
    <div class="ml-row" data-product="<?php echo esc_attr($product->get_id()); ?>">
        <input type="checkbox" class="ml-select" aria-label="<?php echo esc_attr('Включить в образ: ' . $product->get_name()); ?>" <?php checked((bool) $choices); disabled(!$choices); ?>>
        <?php echo $product->get_image('woocommerce_thumbnail'); ?>
        <div class="ml-product"><strong><?php echo esc_html($product->get_name()); ?></strong>
            <span class="ml-line-price"><?php echo $choices ? wp_kses_post(wc_price(wc_get_price_to_display($choices[0]))) : 'Нет в наличии'; ?></span>
        </div>
        <?php if ($choices) : ?>
            <?php if ($variable) : ?>
                <fieldset class="ml-size" data-price="<?php echo esc_attr(wc_get_price_to_display($choices[0])); ?>"
                    data-sale="<?php echo esc_attr(wc_get_price_to_display($choices[0], ['price' => mitkin_looks_sale_price($choices[0]->get_price())])); ?>">
                    <legend class="ml-visually-hidden"><?php echo esc_html('Размер: ' . $product->get_name()); ?></legend>
                    <div class="ml-size-heading">
                        <span>Размер</span>
                        <?php if ($size_chart_url) : ?>
                            <a class="ml-size-chart" href="<?php echo $size_chart_url; ?>" target="_blank" rel="noopener">Таблица размеров<span class="ml-visually-hidden"> (новая вкладка)</span></a>
                        <?php endif; ?>
                    </div>
                    <div class="ml-sizes">
                        <?php
                        $attribute_values = [];
                        foreach ($choices as $choice) {
                            foreach ($choice->get_attributes() as $attribute => $value) {
                                $attribute_values[$attribute][$value] = true;
                            }
                        }
                        foreach ($choices as $choice) :
                            $labels = [];
                            foreach ($choice->get_attributes() as $attribute => $value) {
                                $term = taxonomy_exists($attribute) ? get_term_by('slug', $value, $attribute) : false;
                                $labels[$attribute] = $term ? $term->name : $value;
                            }
                            foreach ($labels as $attribute => $label) {
                                if (count($attribute_values[$attribute]) === 1 && count($labels) > 1) {
                                    unset($labels[$attribute]);
                                }
                            }
                            $label = implode(' / ', $labels) ?: wc_get_formatted_variation($choice, true, false);
                            ?>
                            <label class="ml-size-option">
                                <input type="radio" class="ml-choice ml-visually-hidden"
                                    name="<?php echo esc_attr($dialog . '-' . $product->get_id()); ?>" <?php mitkin_looks_choice_attributes($choice, $choice->get_id()); ?>>
                                <span><?php echo esc_html($label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php else : ?>
                <input type="hidden" class="ml-choice" <?php mitkin_looks_choice_attributes($choices[0], 0); ?>>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

function mitkin_looks_choice_attributes($choice, $value) {
    printf('value="%d" data-price="%s" data-sale="%s"',
        $value,
        esc_attr(wc_get_price_to_display($choice)),
        esc_attr(wc_get_price_to_display($choice, ['price' => mitkin_looks_sale_price($choice->get_price())])));
}
