<?php
/** Dedicated page chrome for the existing MIS shortcode; normal WordPress hooks and authentication remain active. */
if (!defined('ABSPATH')) { exit; }
show_admin_bar(false);
?><!doctype html>
<html lang="fa-IR" dir="rtl">
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
<style>html{margin-top:0!important}body.sn-mis-document{margin:0;padding:12px 22px;background:#edf3f5;min-height:100vh;font-family:Tahoma,Arial,sans-serif}.sn-mis-document>.sn-mis-page{max-width:1540px;margin:auto}.sn-mis-document .sn-mis-v2{margin:12px auto}.sn-mis-document .sn-mis-v2 .sn-panel-user-actions .sn-btn{background:#f0f6f7;border:1px solid #cbdfe2;color:#295d67;font-size:12px}.sn-mis-document .sn-mis-v2 .sn-panel-user-chip{background:transparent;border:0;box-shadow:none}@media(max-width:720px){body.sn-mis-document{padding:6px}.sn-mis-document .sn-mis-v2{margin:0 auto}}</style>
</head>
<body <?php body_class('sn-mis-document'); ?>>
<?php wp_body_open(); ?>
<div class="sn-mis-page">
<?php while(have_posts()):the_post();the_content();endwhile; ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
