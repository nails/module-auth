<?php

use Nails\Admin\Helper;
use Nails\Cdn\Resource\CdnObject;

/**
 * @var string[]  $aKeys
 * @var array     $aHeader
 * @var array     $aData
 * @var CdnObject $oObject
 * @var stdClass  $oAdditional
 */

?>
<div class="module-auth import import--preview">
    <div class="alert alert-warning">
        <strong>Please review the following data</strong>
        <br>Your CSV has been processed, and the following values have been ascertained. Please verify them, and when
        happy to continue, click "Import" below.
    </div>
    <?=form_open()?>
    <input type="hidden" name="object_id" value="<?=$oObject->id?>">
    <input type="hidden" name="additional" value="<?=htmlspecialchars(json_encode($oAdditional), ENT_QUOTES)?>">
    <table>
        <thead>
            <tr>
                <?php
                foreach ($aKeys as $sKey) {
                    ?>
                    <th><?=$sKey?></th>
                    <?php
                }
                ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php

            foreach ($aData as $aDatum) {
                echo '<tr>';
                foreach ($aKeys as $sKey) {
                    ?>
                    <td><?=$aDatum[$sKey] ?? '<span class="text-muted">&mdash;</span>'?></td>
                    <?php
                }
                echo '</tr>';
            }

            ?>
        </tbody>
    </table>
    <?php

    echo Helper::floatingControls([
        'save' => [
            'text'  => 'Import',
            'name'  => 'action',
            'value' => 'import',
        ],
    ]);

    ?>
    <?=form_close()?>
</div>
