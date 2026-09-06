<?php

use Nails\Admin\Helper;
use Nails\Auth\Admin\Controller\Import;
use Nails\Common\Factory\Model\Field;

/**
 * @var array<int, string|Closure|Field> $additionalFields
 * @var bool                             $bTemplateUnusable
 */

/**
 * The controller has already reported why; offering a form which cannot work
 * would only invite an upload that is certain to be rejected.
 */
if (!empty($bTemplateUnusable)) {
    ?>
    <div class="module-auth import">
        <p class="alert alert-danger">
            User import is unavailable until the template is corrected.
        </p>
    </div>
    <?php
    return;
}

?>
<div class="module-auth import">
    <?=form_open_multipart()?>
    <fieldset>
        <legend>File</legend>
        <?php

        echo form_field_upload([
            'key'    => 'csv',
            'label'  => 'CSV',
            'info'   => anchor(
                Import::url('template'),
                'Download a template CSV file',
                'class="btn btn-xs btn-primary"'
            ),
            'accept' => 'text/csv',
        ]);
        ?>
        <p class="alert alert-info">
            <strong>Please note:</strong> The CSV you supply should be in the correct format, as per the template
            which you can download above. Remember to include the header rows describing each column.
            <br>Every row is validated when you upload; if any of them cannot be imported the file is rejected
            and nothing is created, so you can correct it and try again.
        </p>
    </fieldset>
    <?php

    if (!empty($additionalFields)) {
        ?>
        <fieldset>
            <legend>Additional Fields</legend>
            <p class="alert alert-info">
                These fields are applied to each user at the point of import.
            </p>
            <?php

            foreach ($additionalFields as $field) {

                if ($field instanceof Field && !preg_match('/^additional\[/', $field->getKey())) {
                    $field->setKey('additional[' . $field->getKey() . ']');
                }

                if (is_string($field)) {
                    echo $field;

                } elseif ($field instanceof \Closure) {
                    echo call_user_func($field);

                } elseif (is_callable('\Nails\Common\Helper\Form\Field::' . $field->getType())) {
                    echo call_user_func('\Nails\Common\Helper\Form\Field::' . $field->getType(), (array) $field);
                }
            }

            ?>
        </fieldset>
        <?php
    }

    echo Helper::floatingControls([
        'save' => [
            'text' => 'Upload &amp; Preview',
        ],
    ]);

    echo form_close();

    ?>
    <div id="user-import-list" class="hidden">
        <hr>
        <h2>Recent Imports</h2>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th class="field field--id">ID</th>
                        <th class="field field--file">File</th>
                        <th class="field field--status">Status</th>
                        <th class="field field--progress">Progress</th>
                        <th class="field field--requested">Requested</th>
                        <th class="field field--finished">Finished</th>
                        <th class="actions">Actions</th>
                    </tr>
                </thead>
                <tbody id="user-import-list-body">
                </tbody>
            </table>
        </div>
    </div>
</div>
