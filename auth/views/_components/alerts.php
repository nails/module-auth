<?php

$aAlerts = [
    ['danger', $error],
    ['danger', $negative],
    ['success', $success],
    ['success', $positive],
    ['info', $info],
    ['warning', $warning],

    //  @deprecated
    ['warning', $message],
    ['info', $notice],
];

foreach ($aAlerts as $aAlert) {

    [$sClass, $oMessage] = $aAlert;
    $sMessage            = (string) $oMessage;

    if (!empty($sMessage)) {
        ?>
        <div class="alert alert--<?=$sClass?>">
            <?=$sMessage?>
        </div>
        <?php
    }
}
