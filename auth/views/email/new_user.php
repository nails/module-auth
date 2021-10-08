{{#admin.id}}
<p>
    {{admin.first_name}} {{admin.last_name}}, an administrator for <?=\Nails\Config::get('APP_NAME')?>, has just created a
    new <em>{{admin.group.name}}</em> account for you.
</p>
{{/admin.id}}
{{^admin.id}}
<p>
    Thank you for registering at the <?=\Nails\Config::get('APP_NAME')?> website.
</p>
{{/admin.id}}
{{#password}}
<p>
    Your password is shown below.
    {{#temp_pw}}
    You will be asked to set this to something more memorable when you log in.
    {{/temp_pw}}
</p>
<p class="heads-up" style="font-weight:bold;font-size:1.5em;text-align:center;font-family:LucidaConsole, Monaco, monospace;">
    {{password}}
</p>
{{/password}}
<p>
    <a href="{{siteUrl('auth/login')}}" class="btn">
        Click here to log in
    </a>
</p>
