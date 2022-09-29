<div class="module-auth import">
    <?=form_open_multipart()?>
    <fieldset>
        <legend>File</legend>
        <?php
        echo form_field_upload([
            'key'    => 'csv',
            'label'  => 'CSV',
            'info'   => anchor(\Nails\Auth\Admin\Controller\Import::url('template'), 'Download a template CSV file', 'style="text-decoration: underline;"'),
            'accept' => 'text/csv',
        ]);
        ?>
    </fieldset>
    <fieldset>
        <legend>Options</legend>
        <?php
        echo form_field_boolean([
            'key'   => 'skip_existing',
            'label' => 'Skip Existing',
            'info'  => 'If a user is already registered, then skip rather than error',
        ]);
        ?>
    </fieldset>
    <p class="alert alert-warning">
        <strong>Please note:</strong> The CSV you supply should be in the correct format, as per the template which you
        can download above. Remember to include the header rows describing each column.
    </p>
    <?=\Nails\Admin\Helper::floatingControls([
        'save' => [
            'text'  => 'Preview',
            'name'  => 'action',
            'value' => 'preview',
        ],
    ])?>
    <?=form_close()?>
</div>
