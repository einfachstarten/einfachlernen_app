<?php
return [
    'DB_HOST' => 'mysqlsvr75.world4you.com',
    'DB_NAME' => '7951508db2',
    'DB_USER' => 'sql1992233',
    'DB_PASS' => 'me@pr3kv',

    // SMTP configuration (new)
    'SMTP_HOST' => 'smtp.world4you.com',
    'SMTP_PORT' => 587,
    'SMTP_USERNAME' => 'termine@einfachstarten.jetzt',
    'SMTP_PASSWORD' => 'Termine4You!!',
    'SMTP_FROM_EMAIL' => 'termine@einfachstarten.jetzt',
    'SMTP_FROM_NAME' => 'Anna Braun Lerncoaching',
    'SMTP_ENCRYPTION' => 'tls',
    'SMTP_TIMEOUT' => 30,

    // PIN Configuration
    'PIN_DURATION_MINUTES' => 15,  // Production: 525600 (1 year)
    'PIN_CLEANUP_EXPIRED' => false
];
?>
