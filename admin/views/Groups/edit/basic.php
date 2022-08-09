<?php

echo form_field([
    'key'         => 'label',
    'label'       => 'Label',
    'default'     => isset($item) ? $item->label : '',
    'required'    => true,
    'placeholder' => 'Type the group\'s label name here.',
]);

echo form_field([
    'key'         => 'slug',
    'label'       => 'Slug',
    'default'     => isset($item) ? $item->slug : '',
    'required'    => true,
    'placeholder' => 'Type the group\'s slug here.',
]);

echo form_field([
    'key'         => 'description',
    'label'       => 'Description',
    'default'     => isset($item) ? $item->description : '',
    'required'    => true,
    'placeholder' => 'Type the group\'s description here.',
]);

echo form_field([
    'key'         => 'default_homepage',
    'label'       => 'Default Homepage',
    'default'     => isset($item) ? $item->default_homepage : '',
    'placeholder' => 'Type the group\'s homepage here.',
    'info'        => 'This is where users are sent after login, unless a specific redirect is already in place. If not specified the user will be sent to the homepage.',
]);

echo form_field([
    'key'         => 'registration_redirect',
    'label'       => 'Registration Redirect',
    'default'     => isset($item) ? $item->registration_redirect : '',
    'placeholder' => 'Redirect new registrants of this group here.',
    'info'        => 'If not defined new registrants will be redirected to the group\'s homepage.',
]);
