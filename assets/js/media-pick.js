/* „Bild wählen"-Button im EasyCheckout-Einstellungsreiter (WC_Settings_Tab::field_media). */
jQuery(function ($) {
    var params = window.easycheckoutMediaPick || {};
    $(document).on('click', '.ec-media-pick', function () {
        var target = $(this).data('target');
        var frame = wp.media({ title: params.title || '', multiple: false, library: { type: 'image' } });
        frame.on('select', function () {
            var url = frame.state().get('selection').first().toJSON().url;
            $('[id="' + target + '"]').val(url);
        });
        frame.open();
    });
});
