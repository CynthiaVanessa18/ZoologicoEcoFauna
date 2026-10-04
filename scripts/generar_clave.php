<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
echo bin2hex(random_bytes(32)), PHP_EOL;
