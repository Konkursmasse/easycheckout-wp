/* Produkt-Metabox im Checkout-Editor: Zeilen hinzufuegen und entfernen. */
jQuery(function ($) {
    var $list = $('#ec-products-list');
    if (!$list.length) {
        return;
    }
    var index = parseInt($list.data('next-index'), 10) || 0;

    $('#ec-add-product').on('click', function () {
        var template = $('#ec-product-template').html();
        $list.append(template.replace(/\{\{INDEX\}\}/g, index));
        index++;
    });

    $(document).on('click', '.ec-remove-product', function () {
        $(this).closest('.ec-product-row').remove();
    });
});
