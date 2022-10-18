<?php

echo form_field([
    'key'         => 'pw[min]',
    'label'       => 'Min. Length',
    'default'     => isset($item->password_rules->min) ? $item->password_rules->min : '',
    'required'    => false,
    'placeholder' => 'The minimum number of characters a password must contain.',
    'info'        => 'If this is undefined, or set to 0, then there is no minimum length',
]);

echo form_field([
    'key'         => 'pw[max]',
    'label'       => 'Max. Length',
    'default'     => isset($item->password_rules->max) ? $item->password_rules->max : '',
    'required'    => false,
    'placeholder' => 'The maximum number of characters a password must contain.',
    'info'        => 'If this is undefined, or set to 0, then there is no maximum length',
]);

echo form_field_number([
    'key'         => 'pw[expires_after]',
    'label'       => 'Expires After',
    'default'     => isset($item->password_rules->expiresAfter) ? $item->password_rules->expiresAfter : '',
    'required'    => false,
    'placeholder' => 'The expiration policy for passwords, expressed in days',
    'info'        => 'If this is undefined, or set to 0, then there is no expiration policy',
]);

echo form_field_checkbox([
    'key'      => 'pw[requirements][]',
    'label'    => 'Requirements',
    'default'  => isset($item->password_rules->requirements) ? $item->password_rules->requirements : ['symbol' => true],
    'required' => false,
    'options'  => [
        [
            'label'    => 'Must contain a symbol',
            'value'    => 'symbol',
            'selected' => !empty($item->password_rules->requirements->symbol),
        ],
        [
            'label'    => 'Must contain a number',
            'value'    => 'number',
            'selected' => !empty($item->password_rules->requirements->number),
        ],
        [
            'label'    => 'Must contain a lowercase letter',
            'value'    => 'lower_alpha',
            'selected' => !empty($item->password_rules->requirements->lower_alpha),
        ],
        [
            'label'    => 'Must contain an uppercase letter',
            'value'    => 'upper_alpha',
            'selected' => !empty($item->password_rules->requirements->upper_alpha),
        ],
    ],
]);

echo form_field([
    'key'         => 'pw[banned]',
    'label'       => 'Banned Words',
    'default'     => isset($item->password_rules->banned) ? implode(',', $item->password_rules->banned) : '',
    'required'    => false,
    'placeholder' => 'A comma separated list of words which cannot be used as a password',
]);

echo form_field_boolean([
    'key'     => 'pw[block_common]',
    'label'   => 'Disallow common passwords',
    'default' => $item->password_rules->block_common ?? false,
    'info'    => 'When enabled, common passwords will be rejected.',
]);
