<p>
    The user import you requested has finished.
</p>
<table class="table table--list table--numeric">
    <tbody>
        <tr>
            <td>Rows in file</td>
            <td>{{import.row_count}}</td>
        </tr>
        <tr>
            <td>Accounts created</td>
            <td>{{import.success}}</td>
        </tr>
        <tr>
            <td>Rows with warnings</td>
            <td>{{import.warnings}}</td>
        </tr>
        <tr>
            <td>Rows with errors</td>
            <td>{{import.errors}}</td>
        </tr>
    </tbody>
</table>
{{#error}}
<pre>{{error}}</pre>
{{/error}}
{{#import.errors}}
<p class="alert alert-danger">
    Some rows could not be imported. The log records what happened to every row
    in the file, so you can correct the failures and import them again.
</p>
{{/import.errors}}
{{#import.warnings}}
<p class="alert alert-warning">
    Some accounts were created, but something which had to happen afterwards did
    not. They exist and can be signed in to; the log identifies them, and
    whatever was meant to follow needs finishing by hand.
</p>
{{/import.warnings}}
{{#log_url}}
<p>
    <a href="{{log_url}}" class="btn btn-block">Download the log</a>
</p>
{{/log_url}}
{{#import.url}}
<p>
    <a href="{{import.url}}" class="btn btn-block btn-secondary">View this import in admin</a>
</p>
{{/import.url}}
