<div class="group-accounts groups overview">
    <?=Nails\Admin\Helper::loadSearch($search)?>
    <?=Nails\Admin\Helper::loadPagination($pagination)?>
    <table class="table table-striped table-hover table-bordered table-responsive">
        <thead class="table-dark">
            <tr>
                <th class="label">Name and Description</th>
                <th class="homepage">Homepage</th>
                <th class="default text-center" width="50">Default</th>
                <th class="actions" width="250">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php

            foreach ($items as $oGroup) {
                ?>
                <tr>
                    <td class="label">
                        <strong><?=$oGroup->label?></strong>
                        <small><?=$oGroup->description?></small>
                    </td>
                    <td class="homepage">
                        <code>
                            <span style="color:#ccc">
                                <?=substr(siteUrl(), 0, -1)?>
                            </span>
                            <?=$oGroup->default_homepage?>
                        </code>
                    </td>
                    <?=Nails\Admin\Helper::loadBoolCell($oGroup->is_default)?>
                    <td class="actions">
                        <?php

                        if (userHasPermission(\Nails\Auth\Admin\Permission\Groups\Edit::class)) {
                            echo anchor(
                                \Nails\Auth\Admin\Controller\Groups::url('edit/' . $oGroup->id),
                                lang('action_edit'),
                                'class="btn btn-xs btn-primary"'
                            );
                        }

                        if (userHasPermission(\Nails\Auth\Admin\Permission\Groups\Delete::class)) {
                            echo anchor(
                                \Nails\Auth\Admin\Controller\Groups::url('delete/' . $oGroup->id),
                                lang('action_delete'),
                                'class="btn btn-xs btn-danger confirm" data-body="This action is also not undoable." data-title="Confirm Delete"'
                            );
                        }

                        if (userHasPermission(\Nails\Auth\Admin\Permission\Groups\SetDefault::class) && !$oGroup->is_default) {
                            echo anchor(
                                \Nails\Auth\Admin\Controller\Groups::url('set_default/' . $oGroup->id),
                                'Set As Default',
                                'class="btn btn-xs btn-success"'
                            );
                        }

                        ?>
                    </td>
                </tr>
                <?php
            }

            ?>
        </tbody>
    </table>
    <?=Nails\Admin\Helper::loadPagination($pagination)?>
</div>
