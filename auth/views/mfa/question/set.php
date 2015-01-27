<?php

    $query              = array();
    $query['return_to'] = $return_to;
    $query['remember']  = $remember;

    $query = array_filter($query);

    if ($query) {

        $query = '?' . http_build_query($query);

    } else {

        $query = '';
    }

?>
<div class="row">
    <div class="well well-lg col-sm-6 col-sm-offset-3">
        <p>
            Please set security questions for your account.
        </p>
        <?php

            echo form_open(
                site_url(
                    'auth/mfa_question/' . $user_id . '/' . $token['salt'] . '/' . $token['token'] . $query
                )
            );

                if ($num_questions) {

                    echo '<p>';
                        echo lang('auth_twofactor_question_set_system_body');
                    echo '</p>';

                    echo '<fieldset>';

                        if ($num_custom_questions) {

                            echo '<legend style="padding-top:20px">';
                                echo lang('auth_twofactor_question_set_system_legend');
                            echo '</legend>';
                        }

                    for ($i = 0; $i < $num_questions; $i++) {

                        $field   = 'question[' . $i . '][question]';
                        $name    = 'Question ' . ($i + 1);
                        $error   = form_error($field) ? 'has-error' : NULL;
                        $options = array_merge(array('Please Choose...'), $questions);

                        echo '<br>';
                        echo '<div class="' . $error . '">';
                            echo '<label for="password">' . $name . '</label>';
                            echo form_dropdown(
                                $field,
                                $options,
                                set_value($field),
                                'class="form-control"'
                            );
                            echo form_error($field, '<span class="help-block">', '</span>');
                        echo '</div>';

                        // --------------------------------------------------------------------------

                        $field       = 'question[' . $i . '][answer]';
                        $name        = 'Answer ' . ($i + 1);
                        $error       = form_error($field) ? 'has-error' : NULL;
                        $placeholder = 'Type your answer here';
                        $options     = array_merge(array('Please Choose...'), $questions);

                        echo '<br>';
                        echo '<div class="' . $error . '">';
                            echo '<label for="password">' . $name . '</label>';
                            echo form_input(
                                $field,
                                set_value($field),
                                'autocomplete="off" class="form-control" placeholder="' . $placeholder . '"'
                            );
                            echo form_error($field, '<span class="help-block">', '</span>');
                        echo '</div>';

                        echo '<hr />';
                    }

                    echo '</fieldset>';
                }

                // --------------------------------------------------------------------------

                if ($num_custom_questions) {

                    echo '<p>';
                        echo lang('auth_twofactor_question_set_custom_body');
                    echo '</p>';

                    echo '<fieldset>';

                        if ($num_questions) {

                            echo '<legend style="padding-top:20px">';
                                echo lang('auth_twofactor_question_set_custom_legend');
                            echo '</legend>';
                        }

                        for ($i = 0; $i < $num_custom_questions; $i++) {

                            $field       = 'custom_question[' . $i . '][question]';
                            $name        = 'Question ' . ($i + 1);
                            $error       = form_error($field) ? 'has-error' : NULL;
                            $placeholder = 'Type your question here';

                            echo '<br>';
                            echo '<div class="' . $error . '">';
                                echo '<label for="password">' . $name . '</label>';
                                echo form_input(
                                    $field,
                                    set_value($field),
                                    'autocomplete="off" class="form-control" placeholder="' . $placeholder . '"'
                                );
                                echo form_error($field, '<span class="help-block">', '</span>');
                            echo '</div>';

                            // --------------------------------------------------------------------------

                            $field       = 'custom_question[' . $i . '][answer]';
                            $name        = 'Answer ' . ($i + 1);
                            $error       = form_error($field) ? 'has-error' : NULL;
                            $placeholder = 'Type your answer here';
                            $options     = array_merge(array('Please Choose...'), $questions);

                            echo '<br>';
                            echo '<div class="' . $error . '">';
                                echo '<label for="password">' . $name . '</label>';
                                echo form_input(
                                    $field,
                                    set_value($field),
                                    'autocomplete="off" class="form-control" placeholder="' . $placeholder . '"'
                                );
                                echo form_error($field, '<span class="help-block">', '</span>');
                            echo '</div>';

                            echo '<hr />';
                        }

                    echo '</fieldset>';
                }

            ?>
            <button class="btn btn-lg btn-primary btn-block" type="submit">Set Questions &amp; Sign In</button>
        <?=form_close()?>
    </div>
</div>