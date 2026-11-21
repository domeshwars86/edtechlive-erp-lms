<?php

return [
    // Leave the period unset until a retention period has been reviewed and approved.
    'contact_enquiry_retention_days' => env('CONTACT_ENQUIRY_RETENTION_DAYS'),
    'contact_enquiry_retention_enabled' => (bool) env('CONTACT_ENQUIRY_RETENTION_ENABLED', false),
];
