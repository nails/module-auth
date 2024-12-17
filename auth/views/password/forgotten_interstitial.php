<div class="nails-auth forgotten-password u-center-screen">
    <noscript>
        <p>
            <a href="<?=current_url()?>/process" class="btn btn--primary">
                Click to reset password
            </a>
        </p>
    </noscript>
    <?=scriptOpen()?>
    window.location.href = '<?=current_url()?>/process';
    <?=scriptClose()?>
</div>
