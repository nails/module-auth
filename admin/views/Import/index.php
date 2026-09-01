<?php

use Nails\Admin\Helper;
use Nails\Common\Factory\Model\Field;

/**
 * @var array<int, string|Closure|Field> $additionalFields
 */

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
                'admin/auth/import/template',
                'Download a template CSV file',
                'class="btn btn-xs btn-primary"'
            ),
            'accept' => 'text/csv',
        ]);
        ?>
        <p class="alert alert-info">
            <strong>Please note:</strong> The CSV you supply should be in the correct format, as per the template
            which you can download above. Remember to include the header rows describing each column.
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
            'text'  => 'Preview',
            'name'  => 'action',
            'value' => 'preview',
        ],
    ]);

    echo form_close();

    ?>
</div>
