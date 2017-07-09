<?php
$oView = \Nails\Factory::service('View');
?>
<div class="container nails-module-auth password forgotten forgotten-reset">
    <?php

    $oView->load('components/header');

    ?>
    <div class="row">
        <div class="col-sm-4 col-sm-offset-4">
            <div class="well well-lg">
                <p class="center">
                    <?=lang('auth_forgot_reset_ok')?>
                </p>
                <div class="row">
                    <div class="col-md-12">
                        <input type="text" value="<?=htmlentities($new_password)?>" class="form-control" id="temp-password" style="font-size:1.5em;text-align:center;"/>
                    </div>
                </div>
                <p style="margin-top:1em;">
                    <?=anchor('auth/login', lang('auth_forgot_action_proceed'), 'class="btn btn-primary btn-block"')?>
                </p>
            </div>
        </div>
    </div>
    <?php

    $oView->load('components/footer');

    ?>
</div>
<script type="text/javascript">

    var textBox     = document.getElementById('temp-password');
    textBox.onfocus = function () {
        textBox.select();

        // Work around Chrome's little problem
        textBox.onmouseup = function () {
            // Prevent further mouseup intervention
            textBox.onmouseup = null;
            return false;
        };
    };
</script>
