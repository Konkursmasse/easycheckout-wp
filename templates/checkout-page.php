<?php
/**
 * Template for displaying checkout pages
 *
 * This template can be overridden by copying it to your-theme/easycheckout/checkout-page.php
 *
 * @package EasyCheckout
 */

defined('ABSPATH') || exit;

get_header();
?>

<div id="primary" class="content-area easycheckout-page">
    <main id="main" class="site-main">
        <?php
        while (have_posts()) :
            the_post();
            ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class('easycheckout-checkout-article'); ?>>
                <header class="entry-header">
                    <?php the_title('<h1 class="entry-title">', '</h1>'); ?>
                </header>

                <div class="entry-content">
                    <?php
                    the_content();

                    // The checkout form is automatically appended by Checkout_Frontend::checkout_content()
                    ?>
                </div>
            </article>
            <?php
        endwhile;
        ?>
    </main>
</div>

<?php
get_footer();
