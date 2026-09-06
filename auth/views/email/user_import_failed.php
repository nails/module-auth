<p>
    The user import you requested was rejected; <strong>no user accounts were created</strong>.
</p>
{{#error}}
<pre>{{error}}</pre>
{{/error}}
{{#import.errors}}
<p>
    The log records every row which could not be imported, and why. Correct them
    in your CSV and upload it again.
</p>
{{/import.errors}}
{{#import.warnings}}
<p>
    Some accounts were created before the import stopped, but something which had
    to happen afterwards did not. They exist; the log identifies them, and
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
