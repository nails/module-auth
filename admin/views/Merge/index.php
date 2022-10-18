<div class="group-accounts merge">
    <?=form_open(null, 'id="theForm"')?>
    <fieldset>
        <legend>
            User to Keep
        </legend>
        <div class="alert alert-warning mb-0">
            This user will be kept, their data is authoritative.
        </div>
        <div class="field">
            <input type="text" name="user_id" class="user-search" value="<?=set_value('user_id')?>" style="width: 100%;" />
            <?=form_error('user_id', '<p class="alert alert-danger">', '</p>')?>
        </div>
    </fieldset>
    <fieldset>
        <legend>
            Users to merge
        </legend>
        <div class="alert alert-warning mb-0">
            These users will be have associated data reassigned to the user above. Meta data (e.g. name, password, etc) will be deleted.
        </div>
        <div class="field">
            <input type="text" id="merge-ids" name="merge_ids" class="user-search" data-multiple="true" value="<?=set_value('merge_ids')?>" style="width: 100%;" />
            <?=form_error('merge_ids', '<p class="alert alert-danger">', '</p>')?>
        </div>
    </fieldset>
    <?=\Nails\Admin\Helper::floatingControls([
        'save' => [
            'text' => 'Merge Users',
        ],
    ])?>
    <?=form_close()?>
</div>
