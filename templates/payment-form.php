<?php
/**
 * Payment form partial template
 *
 * This template can be overridden by copying it to your-theme/easycheckout/payment-form.php
 *
 * @package EasyCheckout
 * @var array $args Template arguments
 */

defined('ABSPATH') || exit;

$checkout_slug = $args['checkout_slug'] ?? '';
$payment_methods = $args['payment_methods'] ?? ['card'];
$currency = $args['currency'] ?? 'CHF';
$amount = $args['amount'] ?? 0;
$button_text = $args['button_text'] ?? __('Pay Now', 'easycheckout');
?>

<div class="easycheckout-payment-form-wrapper" data-checkout-slug="<?php echo esc_attr($checkout_slug); ?>">
    <?php if (count($payment_methods) > 1) : ?>
    <div class="easycheckout-payment-method-select">
        <label><?php _e('Payment Method', 'easycheckout'); ?></label>
        <?php foreach ($payment_methods as $method) : ?>
        <label class="easycheckout-method-option">
            <input type="radio"
                   name="ec_payment_method"
                   value="<?php echo esc_attr($method); ?>"
                   <?php checked($method, $payment_methods[0]); ?>>
            <span class="easycheckout-method-name">
                <?php echo esc_html(easycheckout_get_payment_method_name($method)); ?>
            </span>
        </label>
        <?php endforeach; ?>
    </div>
    <?php else : ?>
    <input type="hidden" name="ec_payment_method" value="<?php echo esc_attr($payment_methods[0]); ?>">
    <?php endif; ?>

    <!-- Card form -->
    <div class="easycheckout-card-form-container" data-method="card" <?php echo $payment_methods[0] !== 'card' ? 'style="display:none;"' : ''; ?>>
        <div id="ec-payment-card-element"></div>
        <div id="ec-payment-card-errors" class="easycheckout-error-text"></div>
    </div>

    <!-- TWINT info -->
    <div class="easycheckout-twint-container" data-method="twint" <?php echo $payment_methods[0] !== 'twint' ? 'style="display:none;"' : ''; ?>>
        <p class="easycheckout-info-text">
            <?php _e('You will be redirected to TWINT to complete your payment.', 'easycheckout'); ?>
        </p>
    </div>

    <!-- Bank transfer info -->
    <div class="easycheckout-bank-container" data-method="bank_transfer" <?php echo $payment_methods[0] !== 'bank_transfer' ? 'style="display:none;"' : ''; ?>>
        <p class="easycheckout-info-text">
            <?php _e('A Swiss QR-Bill will be generated for bank transfer.', 'easycheckout'); ?>
        </p>
    </div>

    <?php if ($amount > 0) : ?>
    <div class="easycheckout-amount-display">
        <span class="easycheckout-amount-label"><?php _e('Total:', 'easycheckout'); ?></span>
        <span class="easycheckout-amount-value"><?php echo esc_html($currency . ' ' . number_format($amount, 2)); ?></span>
    </div>
    <?php endif; ?>

    <button type="submit" class="easycheckout-submit-btn">
        <span class="easycheckout-btn-text"><?php echo esc_html($button_text); ?></span>
        <span class="easycheckout-btn-loading" style="display:none;">
            <?php _e('Processing...', 'easycheckout'); ?>
        </span>
    </button>

    <div class="easycheckout-form-messages"></div>
</div>

<?php
/**
 * Get payment method display name
 *
 * @param string $method
 * @return string
 */
function easycheckout_get_payment_method_name($method) {
    $names = [
        'card' => __('Credit/Debit Card', 'easycheckout'),
        'twint' => __('TWINT', 'easycheckout'),
        'bank_transfer' => __('Bank Transfer', 'easycheckout'),
    ];
    return $names[$method] ?? $method;
}
