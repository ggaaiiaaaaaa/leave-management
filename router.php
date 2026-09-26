<?php
// Safe routing for the PHP development server. Apache uses the .htaccess rules.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (preg_match('~(?:^|/)(?:\.git|config|database)(?:/|$)~i', $path)
    || preg_match('~^/uploads/attachments(?:/|$)~i', $path)
    || preg_match('~^/iclock/.*\.(?:log|txt)$~i', $path)
    || preg_match('~\.(?:sqlite|db|bak|env|log)$~i', $path)) {
    http_response_code(404);
    exit('Not found');
}
if (str_starts_with($path, '/iclock/') && !is_file(__DIR__ . $path)) {
    require __DIR__ . '/iclock/cdata.php';
    return true;
}
return false;
