<fieldset id="edit-user-meta">
    <legend><?=lang('accounts_edit_meta_legend')?></legend>
    <?php

        if ($user_meta) {

            foreach ($user_meta as $metaField => $value) {

                //  Always ignore some fields
                if (array_search($metaField, $ignored_fields) !== false) {

                    continue;
                }

                // --------------------------------------------------------------------------

                $dataType = isset($user_meta_cols[$metaField]['datatype']) ? $user_meta_cols[$metaField]['datatype'] : 'string';

                $field             = array();
                $field['key']      = $metaField;
                $field['type']     = isset($user_meta_cols[$metaField]['datatype']) ? $user_meta_cols[$metaField]['datatype'] : 'text';
                $field['label']    = isset($user_meta_cols[$metaField]['label']) ? $user_meta_cols[$metaField]['label'] : ucwords(str_replace('_', ' ', $metaField));
                $field['required'] = isset($user_meta_cols[$metaField]['required']) ? $user_meta_cols[$metaField]['required'] : false;
                $field['default']  = $value;

                switch ($dataType) {

                    case 'bool':
                    case 'boolean':

                        $field['class']    = 'select2';
                        $field['text_on']  = strtoupper(lang('yes'));
                        $field['text_off'] = strtoupper(lang('no'));

                        echo form_field_boolean($field);
                        break;

                    case 'date':

                        echo form_field_date($field);
                        break;

                    case 'id':

                        //  Fetch items from the joining table
                        if (isset($user_meta_cols[$metaField]['join'])) {

                            $table      = isset($user_meta_cols[$metaField]['join']['table'])     ? $user_meta_cols[$metaField]['join']['table']     : null;
                            $selectId   = isset($user_meta_cols[$metaField]['join']['id'])        ? $user_meta_cols[$metaField]['join']['id']        : null;
                            $selectName = isset($user_meta_cols[$metaField]['join']['name'])      ? $user_meta_cols[$metaField]['join']['name']      : null;
                            $orderCol   = isset($user_meta_cols[$metaField]['join']['order_col']) ? $user_meta_cols[$metaField]['join']['order_col'] : null;
                            $orderDir   = isset($user_meta_cols[$metaField]['join']['order_dir']) ? $user_meta_cols[$metaField]['join']['order_dir'] : 'ASC';

                            if ($table && $selectId && $selectName) {

                                $this->db->select($selectId . ',' . $selectName);

                                if ($orderCol) {

                                    $this->db->order_by($orderCol, $orderDir);
                                }

                                $results = $this->db->get($table)->result();
                                $options = array();

                                foreach ($results as $row) {

                                    $options[$row->{$selectId}] = $row->{$selectName};
                                }

                                $field['class'] = 'select2';

                                echo form_field_dropdown($field, $options);

                            } else {

                                echo form_field($field);
                            }

                        } else {

                            echo form_field($field);
                        }
                        break;

                    case 'file':
                    case 'upload':

                        $field['bucket'] = isset($user_meta_cols[$metaField]['bucket']) ? $user_meta_cols[$metaField]['bucket'] : false;
                        if (isset(${'upload_error_' . $field['key']})) {

                            $field['error'] = implode(' ', ${'upload_error_' . $field['key']});
                        }

                        echo form_field_mm($field);
                        break;

                    case 'string':
                    default:

                        echo form_field($field);
                        break;
                }
            }

        } else {

            echo '<p>' . lang('accounts_edit_meta_noeditable') . '</p>';
        }

    ?>
</fieldset>