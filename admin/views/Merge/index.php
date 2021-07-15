<div class="group-accounts merge">
    <?=form_open(null, 'id="theForm"')?>
    <fieldset>
        <legend>User to Keep</legend>
        <p class="alert alert-info">
            This user will be kept, their data is authoritative.
        </p>
        <p>
            <input type="text" name="user_id" class="user-search" value="<?=set_value('user_id')?>" />
        </p>
        <?=form_error('user_id', '<p class="alert alert-danger">', '</p>')?>
    </fieldset>
    <fieldset>
        <legend>Users to merge</legend>
        <p class="alert alert-info">
            These users will be have associated data reassigned to the user above. Meta data (e.g. name, password, etc)
            will be deleted.
        </p>
        <p>
            <input type="text" id="merge-ids" name="merge_ids" class="user-search" data-multiple="true" value="<?=set_value('merge_ids')?>" />
        </p>
        <?=form_error('merge_ids', '<p class="alert alert-danger">', '</p>')?>
    </fieldset>
    <?=\Nails\Admin\Helper::floatingControls([
        'save' => [
            'text' => 'Merge Users',
        ],
    ])?>
    <?=form_close()?>
</div>
