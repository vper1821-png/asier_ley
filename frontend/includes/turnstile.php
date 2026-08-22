<?php 
// Solo cargar Turnstile si hay una site key real configurada
if (defined('TURNSTILE_SITE_KEY') && TURNSTILE_SITE_KEY && TURNSTILE_SITE_KEY !== ''):
?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<style>
.cf-turnstile {
    margin: 16px auto;
    min-height: 65px;
    display: block !important;
    visibility: visible !important;
}
</style>
<?php endif; ?>