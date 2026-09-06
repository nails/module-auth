The user import you requested has finished.

Rows in file:       {{import.row_count}}
Accounts created:   {{import.success}}
Rows with warnings: {{import.warnings}}
Rows with errors:   {{import.errors}}
{{#error}}
{{error}}
{{/error}}

{{#import.errors}}
Some rows could not be imported. The log records what happened to every row in the file, so you can correct the failures and import them again.
{{/import.errors}}

{{#import.warnings}}
Some accounts were created, but something which had to happen afterwards did not. They exist and can be signed in to; the log identifies them, and whatever was meant to follow needs finishing by hand.
{{/import.warnings}}

{{#log_url}}
Download the log (you will need to be signed in to admin):

{{log_url}}
{{/log_url}}
{{#import.url}}

View this import in admin:

{{import.url}}
{{/import.url}}
