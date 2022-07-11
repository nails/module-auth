<div class="group-accounts groups overview">
    <?=adminHelper('loadSearch', $search)?>
    <?=adminHelper('loadPagination', $pagination)?>
    <div class="table-responsive">
        <table>
            <thead>
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
                        <?=adminHelper('loadBoolCell', $oGroup->is_default)?>
                        <td class="actions">
                            <?php

                            if (userHasPermission('admin:auth:groups:edit')) {
                                echo anchor(
                                    'admin/auth/groups/edit/' . $oGroup->id,
                                    lang('action_edit'),
                                    'class="btn btn-xs btn-primary"'
                                );
                            }

                            if (userHasPermission('admin:auth:groups:delete')) {
                                echo anchor(
                                    'admin/auth/groups/delete/' . $oGroup->id,
                                    lang('action_delete'),
                                    'class="btn btn-xs btn-danger confirm" data-body="This action is also not undoable." data-title="Confirm Delete"'
                                );
                            }

                            if (userHasPermission('admin:auth:groups:setDefault') && !$oGroup->is_default) {
                                echo anchor(
                                    'admin/auth/groups/set_default/' . $oGroup->id,
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
    </div>
    <?=adminHelper('loadPagination', $pagination)?>
</div>
