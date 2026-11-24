jQuery(function ($) {
    let frame;
    $('#mitkin-photo-pick').on('click', function () {
        if (!frame) {
            frame = wp.media({ title: 'Фото готового образа', library: { type: 'image' }, multiple: false });
            frame.on('select', function () {
                const image = frame.state().get('selection').first().toJSON();
                $('#mitkin-photo').val(image.id);
                $('#mitkin-photo-preview').empty().append($('<img>', {
                    src: image.sizes?.thumbnail?.url || image.url,
                    alt: '', width: 150
                }));
            });
        }
        frame.open();
    });
    $('#mitkin-photo-clear').on('click', function () {
        $('#mitkin-photo').val('');
        $('#mitkin-photo-preview').empty();
    });
});
