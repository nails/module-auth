{{#admin.id}}
{{admin.first_name}} {{admin.last_name}}, an administrator for the <?=\Nails\Config::get('APP_NAME')?> website, has just created a new {{admin.group.name}} account for you.
{{/admin.id}}
{{^admin.id}}
Thank you for registering at the <?=\Nails\Config::get('APP_NAME')?> website.
{{/admin.id}}

{{#password}}
Your password is shown below. {{#temp_pw}}You will be asked to set this to something more memorable when you log in.{{/temp_pw}}

{{password}}

{{/password}}

You can log in using the link below:

<?=siteUrl('auth/login')?>

{{#verifyUrl}}
Additionally, we would appreciate it if you could verify your email address using the link below, we do this to maintain the integrity of our database.

{{verifyUrl}}
{{/verifyUrl}}