<?php
$files = [
    'setup.php',
    'hook.php',
    'src/autoload.php',
    'src/Config.php',
    'templates/config.html.twig',
    'public/js/chatwoot.js.php',
    'public/css/chatwoot.css',
    'front/config.php',
    'front/config.form.php',
    'ajax/test.php',
    'ajax/search_targets.php',
    'ajax/sync_contact.php',
    'public/avatar.php',
    'public/dashboard_app/dashboard_app.php',
    'public/dashboard_app/css/dashboard_app.css',
    'public/dashboard_app/js/dashboard_app.js',
    'public/dashboard_app/locales/en.json',
    'public/dashboard_app/locales/pt_BR.json',
    'public/dashboard_app/dashboard_tickets.php',
    'public/dashboard_app/dashboard_ticket_detail.php',
    'public/dashboard_app/dashboard_document.php',
    'locales/en_GB.mo',
    'locales/en_US.mo',
    'locales/pt_BR.mo',
];
foreach ($files as $f) {
    if (!is_file(__DIR__ . '/../' . $f)) {
        fwrite(STDERR, "Missing: $f\n");
        exit(1);
    }
}
echo "Sanity OK\n";
