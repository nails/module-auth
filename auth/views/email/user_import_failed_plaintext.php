The user import you requested was rejected; no user accounts were created.

{{error}}
{{#import.errors}}

The log records every row which could not be imported, and why. Correct them in your CSV and upload it again.
{{/import.errors}}
{{#import.warnings}}

Some accounts were created before the import stopped, but something which had to happen afterwards did not. They exist; the log identifies them, and whatever was meant to follow needs finishing by hand.
{{/import.warnings}}
{{#log_url}}

Download the log (you will need to be signed in to admin):

{{log_url}}
{{/log_url}}
{{#import.url}}

View this import in admin:

{{import.url}}
{{/import.url}}
